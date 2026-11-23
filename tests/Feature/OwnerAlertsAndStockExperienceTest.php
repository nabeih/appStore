<?php

namespace Tests\Feature;

use App\Actions\Finance\Expenses\ManageExpenseAction;
use App\Actions\Inventory\OpeningStock\PostOpeningStockAction;
use App\Actions\Notifications\DispatchAppAlerts;
use App\Actions\Purchases\ApprovePurchaseInvoiceAction;
use App\Actions\Purchases\SavePurchaseInvoiceAction;
use App\Actions\Sales\PrepareSaleAction;
use App\Models\AppAlert;
use App\Models\AppAlertEvent;
use App\Models\Category;
use App\Models\Color;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Models\Permission;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\SaleInvoice;
use App\Models\Size;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Inventory\Setup\InventoryState;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestCase;

class OwnerAlertsAndStockExperienceTest extends SalesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || ! in_array(DB::connection()->getDatabaseName(), ['appstore_golive_20261031_tests', 'appstore_account_status_20261118_tests'], true)) {
            throw new \LogicException('Only the guarded isolated MySQL sandbox is permitted.');
        }
    }

    public function test_cashier_confirms_discounted_cart_without_manager_and_changed_cart_requires_a_new_quote(): void
    {
        $variant = $this->stock();
        $variant->update(['minimum_selling_price' => '34']);
        $cashier = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]);
        $this->actingAs($cashier);
        $data = $this->saleData($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]], 'discount' => ['type' => 'amount', 'value' => '2']]);
        $preview = $this->postJson(route('sales.preview'), $data)->assertOk()->assertJsonPath('minimum_warning.items.0.effective_total', '33.00')
            ->assertJsonMissingPath('cost')->assertJsonMissingPath('minimum_warning.items.0.cost_minor');
        $data['below_minimum_confirmed'] = true;
        $data['below_minimum_confirmation'] = $preview->json('minimum_warning.token');
        $changed = $data;
        $changed['discount']['value'] = '2.50';
        $this->postJson(route('sales.invoices.complete'), $changed)->assertUnprocessable()->assertJsonValidationErrors('below_minimum_confirmed');
        $response = $this->postJson(route('sales.invoices.complete'), $data)->assertOk();
        $this->postJson(route('sales.invoices.complete'), $data)->assertOk()->assertJsonPath('id', $response->json('id'));
        $this->assertDatabaseCount('sale_invoices', 1);
        $this->assertSame(1, AppAlertEvent::where('type', 'minimum_price')->count());
        $this->assertSame(9, (int) Inventory::sum('quantity'));
        $this->actingAs($this->owner);
        $alerts = $this->getJson(route('alerts.index'))->assertOk()->assertJsonPath('unread', 1)->assertSee($cashier->name);
        $this->assertStringContainsString('ربح القطعة 13.000000', $alerts->json('data.0.details.0'));
        $this->postJson(route('alerts.read', $alerts->json('data.0.id')))->assertOk();
        $this->getJson(route('alerts.index'))->assertOk()->assertJsonPath('unread', 0);
    }

    public function test_low_stock_and_empty_alerts_only_recur_after_recovery_and_respect_read_count(): void
    {
        config(['inventory.low_stock_threshold' => 5]);
        $variant = $this->stock('35', '20', 6);
        foreach ([1, 1, 4] as $quantity) {
            $this->sale($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => $quantity]]]);
        }
        $alerts = app(DispatchAppAlerts::class);
        $alerts->scan();
        $alerts->scan();
        $alerts->pending();
        $this->assertSame(1, AppAlertEvent::where('type', 'stock_low')->count());
        $this->assertSame(1, AppAlertEvent::where('type', 'stock_empty')->count());
        $this->getJson(route('alerts.index'))->assertOk()->assertJsonPath('unread', 2);
        $this->postJson(route('alerts.read-all'))->assertOk();
        $alerts->scan();
        $alerts->pending();
        $this->getJson(route('alerts.index'))->assertOk()->assertJsonPath('unread', 0);
        $draft = app(SavePurchaseInvoiceAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId,
            'supplier_id' => Supplier::factory()->create()->id, 'invoice_date' => OperationContext::today(), 'initial_payment' => '0',
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => '20']]], $this->owner->id);
        app(ApprovePurchaseInvoiceAction::class)->execute($draft, $draft->version, $this->owner->id);
        $this->sale($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 6]]]);
        $this->assertSame(2, AppAlertEvent::where('type', 'stock_low')->count());
        $alerts->pending();
        $this->getJson(route('alerts.index'))->assertOk()->assertJsonPath('unread', 1);
    }

    public function test_actionable_expense_notification_is_deduplicated_and_not_delivered_to_cashier(): void
    {
        app(ManageExpenseAction::class)->save(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId,
            'title' => 'مصروف للمراجعة', 'category' => 'other', 'transaction_date' => OperationContext::today(),
            'period_start' => OperationContext::today(), 'period_end' => OperationContext::today(), 'amount' => '100'], $this->owner);
        $alerts = app(DispatchAppAlerts::class);
        $alerts->scan();
        $alerts->scan();
        $alerts->pending();
        $this->assertSame(1, AppAlertEvent::where('type', 'expense_review')->count());
        $this->getJson(route('alerts.index'))->assertOk()->assertJsonPath('unread', 1);
        $cashier = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]);
        $this->actingAs($cashier)->getJson(route('alerts.index'))->assertOk()->assertJsonPath('unread', 0);
    }

    public function test_alert_profit_uses_sale_snapshot_and_distinguishes_break_even_loss_unknown_and_no_cost_permission(): void
    {
        $variant = $this->stock('35', '35', 10);
        $variant->update(['minimum_selling_price' => '36']);
        $data = $this->saleData($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]]]);
        $plan = app(PrepareSaleAction::class)->execute($data, $this->owner, false, false, [], true);
        $data += ['below_minimum_confirmed' => true, 'below_minimum_confirmation' => $plan['minimum_warning']['token']];
        $response = $this->postJson(route('sales.invoices.complete'), $data)->assertOk();
        $invoice = SaleInvoice::findOrFail($response->json('id'));
        $variant->update(['selling_price' => '90', 'minimum_selling_price' => '95']);
        $this->assertStringContainsString('تعادل القطعة 0.000000', $this->getJson(route('alerts.index'))->assertOk()->json('data.0.details.0'));
        $invoice->items()->update(['cost_minor' => 4000]);
        $this->assertStringContainsString('خسارة القطعة 5.000000', $this->getJson(route('alerts.index'))->assertOk()->json('data.0.details.0'));
        $invoice->items()->update(['cost_minor' => null]);
        $this->assertStringContainsString('التكلفة المثبتة مجهولة', $this->getJson(route('alerts.index'))->assertOk()->json('data.0.details.0'));
        $role = Role::create(['name' => 'alerts_without_cost', 'display_name' => 'مراجع التنبيهات', 'is_active' => true]);
        $role->permissions()->sync(Permission::whereIn('name', ['notifications.price_alerts', 'sales.view'])->pluck('id'));
        $recipient = User::factory()->create(['role_id' => $role->id, 'branch_id' => $this->branchId]);
        AppAlert::create(['user_id' => $recipient->id, 'app_alert_event_id' => AppAlertEvent::where('type', 'minimum_price')->sole()->id]);
        $text = $this->actingAs($recipient)->getJson(route('alerts.index'))->assertOk()->assertJsonPath('unread', 1)->json('data.0.details.0');
        $this->assertStringContainsString('35.000000', $text);
        $this->assertStringNotContainsString('التكلفة', $text);
        $this->assertStringNotContainsString('ربح', $text);
        $this->assertStringNotContainsString('خسارة', $text);
    }

    public function test_rendered_pages_supply_the_confirmation_empty_state_and_delete_preflight_browser_fixtures(): void
    {
        $variant = $this->stock();
        $variant->update(['minimum_selling_price' => '34']);
        $data = $this->saleData($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]], 'discount' => ['type' => 'amount', 'value' => '2']]);
        $quote = $this->postJson(route('sales.preview'), $data)->assertOk()->json();
        $catalog = $this->getJson(route('sales.catalog', ['branch_id' => $this->branchId]))->assertOk()->json();
        $cashier = $this->get(route('sales.cashier'))->assertOk()->assertSee('cashier-minimum-dialog', false)->getContent();
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => null]);
        config(['inventory.allow_setup_changes' => true]);
        $inventory = $this->get(route('inventory.index', ['branch_id' => $this->branchId]))->assertOk()->assertSee('readiness_url')->getContent();
        $options = $this->getJson(route('inventory.opening-stock.options'))->assertOk()->json();
        if (getenv('OWNER_EXPERIENCE_UI_FIXTURE') === '1') {
            file_put_contents(storage_path('app/reports/owner-experience-browser-fixture.json'), json_encode(compact('cashier', 'inventory', 'quote', 'catalog', 'options'), JSON_THROW_ON_ERROR));
        }
    }

    public function test_exchange_uses_the_same_minimum_challenge_and_posts_once(): void
    {
        $variant = $this->stock();
        $invoice = $this->sale($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]]]);
        $variant->update(['minimum_selling_price' => '36']);
        $data = ['client_uuid' => (string) Str::uuid(), 'reason' => 'استبدال موثق', 'settlement_mode' => 'credit',
            'return_rows' => [['sale_item_id' => $invoice->items()->sole()->id, 'quantity' => 1, 'condition' => 'resellable']],
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]], 'payments' => [], 'refund_payments' => []];
        $quote = $this->postJson(route('sales.exchanges.preview', $invoice), $data)->assertOk()->json('minimum_warning');
        $this->assertNotNull($quote);
        $this->postJson(route('sales.exchanges.store', $invoice), $data)->assertUnprocessable();
        $data += ['below_minimum_confirmed' => true, 'below_minimum_confirmation' => $quote['token']];
        $this->postJson(route('sales.exchanges.store', $invoice), $data)->assertOk();
        $this->postJson(route('sales.exchanges.store', $invoice), $data)->assertOk();
        $this->assertDatabaseCount('sale_invoices', 2);
        $this->assertSame(1, AppAlertEvent::where('type', 'minimum_price')->count());
    }

    public function test_red_opening_is_not_a_source_for_brown_variant_and_preflight_preserves_delete_financial_checks(): void
    {
        $red = Color::create(['name' => 'أحمر', 'code' => 'RED']);
        $brown = Color::create(['name' => 'بني', 'code' => 'BRN']);
        $size = Size::create(['name' => 'M', 'code' => 'M']);
        $entry = app(PostOpeningStockAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId,
            'opening_date' => OperationContext::today(), 'rows' => [['name' => 'موديل 120', 'code' => '120', 'category_id' => Category::create(['name' => 'ملابس'])->id,
                'color_id' => $red->id, 'size_id' => $size->id, 'qty' => 4, 'cost' => '65', 'price' => '80']]], $this->owner->id);
        $variant = ProductVariant::firstOrFail();
        $brownVariant = $variant->product->variants()->create(['color_id' => $brown->id, 'size_id' => $size->id, 'sku' => '120-BRN-M', 'selling_price' => '80']);
        $brownStock = Inventory::create(['branch_id' => $this->branchId, 'product_variant_id' => $brownVariant->id, 'quantity' => 2, 'average_cost' => '65', 'value_minor' => 13000]);
        $redStock = Inventory::where('product_variant_id', $variant->id)->firstOrFail();
        $before = [Inventory::sum('quantity'), JournalEntry::count(), $this->balance('inventory')];
        $blocked = $this->getJson(route('inventory.correction-readiness', $brownStock))->assertOk()->assertJsonPath('allowed', false);
        $this->assertStringContainsString('رصيد قديم بلا مستند افتتاحي موثّق', $blocked->json('reason'));
        $this->getJson(route('inventory.correction-readiness', $redStock))->assertOk()->assertJsonPath('allowed', true);
        $this->assertSame($before, [Inventory::sum('quantity'), JournalEntry::count(), $this->balance('inventory')]);
        $this->deleteJson(route('inventory.destroy', $brownStock), ['revision' => InventoryState::revision($brownStock, $brownVariant, $brownVariant->product), 'reason' => 'محاولة حذف غير مثبتة'])->assertUnprocessable();
        $this->deleteJson(route('inventory.destroy', $redStock), ['revision' => InventoryState::revision($redStock, $variant, $variant->product), 'reason' => 'تصحيح افتتاح موثق'])->assertOk();
        $this->assertSame(0, $this->balance('inventory'));
        $this->assertSame(2, (int) $brownStock->fresh()->quantity);
        $this->assertSame(1, DB::table('inventory_setup_changes')->count());
    }
}
