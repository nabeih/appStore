<?php

namespace Tests\Feature;

use App\Actions\Notifications\DispatchAppAlerts;
use App\Models\AppAlert;
use App\Models\AppAlertEvent;
use App\Models\Inventory;
use App\Models\Permission;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\SalePriceException;
use App\Models\User;
use App\Support\Mobile\MobileRevision;
use App\Support\Sales\SalesAccess;
use Database\Factories\BranchFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Mobile\MobileTestCase;

class MinimumPriceAlertsTest extends MobileTestCase
{
    private ProductVariant $variant;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'appstore_golive_20261031_tests') {
            throw new \LogicException('Isolated MySQL tests only.');
        }
        $this->variant = $this->stock('35.00', '20.00', 10, 'MINIMUM-TEST');
        $this->variant->update(['minimum_selling_price' => '34.00']);
        $this->cashier = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]);
        $this->actingAs($this->cashier);
    }

    private function discounted(array $changes = []): array
    {
        return array_replace($this->saleData($this->variant), ['rows' => [['product_variant_id' => $this->variant->id, 'quantity' => 1]], 'discount' => ['type' => 'amount', 'value' => '2.00']], $changes);
    }

    public function test_cashier_sees_minimum_without_cost_and_can_confirm_exception_without_manager_permission(): void
    {
        $catalog = $this->getJson(route('sales.catalog', ['branch_id' => $this->branchId]))->assertOk();
        $catalog->assertJsonPath('data.0.variants.0.minimum_selling_price', '34.00')->assertJsonMissingPath('data.0.variants.0.unit_cost');
        $data = $this->discounted();
        $this->postJson(route('sales.invoices.complete'), $data)->assertUnprocessable()->assertJsonValidationErrors('below_minimum_confirmed');
        $this->assertDatabaseCount('sale_invoices', 0);
        $data['below_minimum_confirmed'] = true;
        $this->postJson(route('sales.invoices.complete'), $data)->assertOk();
        $this->postJson(route('sales.invoices.complete'), $data)->assertOk();
        $this->assertDatabaseCount('sale_invoices', 1);
        $this->assertDatabaseCount('sale_price_exceptions', 1);
        $this->assertDatabaseCount('app_alert_events', 1);
        $exception = SalePriceException::firstOrFail();
        $this->assertSame(3400, (int) $exception->minimum_unit_minor);
        $this->assertSame(3300, (int) $exception->effective_total_minor);
        $this->assertSame($this->cashier->id, $exception->user_id);
        $this->assertFalse($this->cashier->can('sales.below_cost'));
    }

    public function test_minimum_uses_exact_total_after_invoice_allocation_without_rounding_unit_price(): void
    {
        $data = $this->discounted(['rows' => [['product_variant_id' => $this->variant->id, 'quantity' => 3]], 'discount' => ['type' => 'amount', 'value' => '4.00'], 'below_minimum_confirmed' => true]);
        $this->postJson(route('sales.invoices.complete'), $data)->assertOk();
        $exception = SalePriceException::firstOrFail();
        $this->assertSame(3, (int) $exception->quantity);
        $this->assertSame(10100, (int) $exception->effective_total_minor);
        $this->variant->update(['minimum_selling_price' => '60.00']);
        $this->assertSame(3400, (int) $exception->fresh()->minimum_unit_minor);
    }

    public function test_minimum_confirmation_does_not_grant_below_cost_or_discount_override_permissions(): void
    {
        $data = $this->discounted(['discount' => ['type' => 'amount', 'value' => '20.00'], 'below_minimum_confirmed' => true]);
        $this->postJson(route('sales.invoices.complete'), $data)->assertForbidden();
        $this->assertDatabaseCount('sale_invoices', 0);
        $this->assertDatabaseCount('app_alert_events', 0);
    }

    public function test_alert_event_is_durable_and_dispatch_is_idempotent_and_authorized(): void
    {
        $noRecipientBeforeCommit = false;
        Event::listen('eloquent.created: '.AppAlertEvent::class, function () use (&$noRecipientBeforeCommit): void {
            $noRecipientBeforeCommit = AppAlert::count() === 0;
        });
        $this->postJson(route('sales.invoices.complete'), $this->discounted(['below_minimum_confirmed' => true]))->assertOk();
        Event::forget('eloquent.created: '.AppAlertEvent::class);
        $this->assertTrue($noRecipientBeforeCommit);
        app(DispatchAppAlerts::class)->pending();
        app(DispatchAppAlerts::class)->pending();
        $this->assertDatabaseCount('app_alerts', 1);
        $this->assertSame($this->owner->id, AppAlert::firstOrFail()->user_id);
        $this->getJson(route('alerts.index'))->assertOk()->assertJsonPath('unread', 0);
        $alert = AppAlert::firstOrFail();
        $this->postJson(route('alerts.read', $alert))->assertNotFound();
        $this->actingAs($this->owner)->getJson(route('alerts.index'))->assertOk()->assertJsonPath('unread', 1);
        $this->postJson(route('alerts.read-all'))->assertOk();
        $this->getJson(route('alerts.index'))->assertJsonPath('unread', 0);
    }

    public function test_rejected_sale_does_not_leave_notifications_and_owner_can_set_all_minimums(): void
    {
        $this->postJson(route('sales.invoices.complete'), $this->discounted())->assertUnprocessable();
        $this->assertDatabaseCount('app_alert_events', 0);
        $this->get(route('catalog.minimum.edit', $this->variant->product_id))->assertForbidden();
        $this->actingAs($this->owner)->get(route('catalog.minimum.edit', $this->variant->product_id))->assertOk();
        $this->put(route('catalog.minimum.update', $this->variant->product_id), ['apply_all' => true, 'minimum' => '31.25',
            'variants' => [['id' => $this->variant->id, 'minimum' => '34.00']]])->assertRedirect();
        $this->assertSame('31.25', $this->variant->fresh()->minimum_selling_price);
        $this->assertDatabaseCount('sale_invoices', 0);
    }

    public function test_mobile_exposes_minimum_and_confirmation_is_required_and_receipt_is_not_duplicated(): void
    {
        $this->mobileLogin($this->cashier);
        $this->mobile('GET', 'data/variants?branch_id='.$this->branchId)->assertOk()->assertJsonPath('data.0.minimum_selling_price', '34.00');
        $stock = Inventory::where('product_variant_id', $this->variant->id)->firstOrFail();
        $payload = ['customer_id' => $this->customer->id, 'payment_mode' => 'credit', 'discount' => ['type' => 'amount', 'value' => '2.00'],
            'rows' => [['product_variant_id' => $this->variant->id, 'quantity' => 1, 'expected_revision' => MobileRevision::of($stock)]]];
        $bad = $this->operation('sale.create', $payload);
        $this->push([$bad])->assertJsonPath('results.0.status', 'validation_failed');
        $good = $this->operation('sale.create', $payload + ['below_minimum_confirmed' => true]);
        $this->push([$good])->assertJsonPath('results.0.status', 'accepted');
        $this->push([$good])->assertJsonPath('results.0.status', 'already_applied');
        $this->assertDatabaseCount('sale_invoices', 1);
        $this->assertDatabaseCount('sale_price_exceptions', 1);
        $this->assertDatabaseCount('app_alert_events', 1);
    }

    public function test_notification_results_hide_other_branches_and_revoked_abilities(): void
    {
        $role = Role::create(['name' => 'price_alert_test', 'display_name' => 'تنبيهات تجريبية', 'is_active' => true]);
        $role->permissions()->sync(Permission::whereIn('name', ['sales.view', 'notifications.price_alerts'])->pluck('id'));
        $user = User::factory()->create(['role_id' => $role->id, 'branch_id' => $this->branchId]);
        $other = BranchFactory::new()->create();
        app(DispatchAppAlerts::class)->record('local-price', 'minimum_price', $this->branchId, 1, 'تنبيه محلي', 'مسموح');
        app(DispatchAppAlerts::class)->record('foreign-price', 'minimum_price', $other->id, 2, 'FOREIGN-ALERT-SECRET', 'محظور');
        app(DispatchAppAlerts::class)->pending();
        $this->actingAs($user)->getJson(route('alerts.index'))->assertOk()->assertJsonPath('unread', 1)->assertDontSee('FOREIGN-ALERT-SECRET');
        $role->permissions()->detach(Permission::where('name', 'notifications.price_alerts')->value('id'));
        $this->getJson(route('alerts.index'))->assertOk()->assertJsonPath('unread', 0)->assertJsonCount(0, 'data');
    }
}
