<?php

namespace Tests\Feature;

use App\Actions\Customers\RecordCustomerReceiptAction;
use App\Actions\Finance\Expenses\ManageExpenseAction;
use App\Actions\Finance\FinanceReports;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Store\ManageStoreContent;
use App\Actions\Store\StoreOrderFinance;
use App\Actions\Store\StorePricing;
use App\Models\AuditEvent;
use App\Models\DeliveryZone;
use App\Models\Expense;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\StoreListing;
use App\Models\StoreOffer;
use App\Models\StoreOrder;
use App\Models\StoreSetting;
use App\Models\User;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Sales\SalesTestCase;

class StoreCommerceTest extends SalesTestCase
{
    private ProductVariant $variant;

    private DeliveryZone $zone;

    private StoreSetting $settings;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'appstore_golive_20261031_tests') {
            throw new \LogicException('Isolated MySQL store tests only.');
        }
        $this->variant = $this->stock('35.00', '20.00', 10, 'STORE-TEST');
        $this->settings = StoreSetting::factory()->create(['branch_id' => $this->branchId]);
        $this->zone = DeliveryZone::factory()->create();
        StoreListing::factory()->create(['product_id' => $this->variant->product_id, 'slug' => 'store-test']);
        app(StoreOrderFinance::class)->provision();
        $this->actingAs($this->owner);
    }

    private function orderData(array $changes = []): array
    {
        $data = array_replace(['client_uuid' => (string) Str::uuid(), 'customer_name' => 'عميل موقع تجريبي', 'phone' => '0599000123', 'address' => 'عنوان تجريبي واضح',
            'delivery_zone_id' => $this->zone->id, 'accepted' => true, 'policy_version' => hash('sha256', $this->settings->policy),
            'rows' => [['product_variant_id' => $this->variant->id, 'quantity' => 1]]], $changes);
        $pricing = app(StorePricing::class);
        $data['quote_revision'] = $pricing->revision($pricing->quote($data));

        return $data;
    }

    private function order(array $changes = []): StoreOrder
    {
        $this->postJson(route('store.place'), $this->orderData($changes))->assertOk();

        return StoreOrder::latest('id')->firstOrFail();
    }

    public function test_checkout_rejects_price_change_after_quote_without_creating_an_order(): void
    {
        $data = $this->orderData();
        $this->variant->update(['selling_price' => '45.00']);
        $this->postJson(route('store.place'), $data)->assertUnprocessable()->assertJsonValidationErrors('quote_revision');
        $this->assertDatabaseCount('store_orders', 0);
        $fresh = $this->orderData();
        $response = $this->postJson(route('store.place'), $fresh)->assertOk();
        $this->getJson(route('store.status', $fresh['client_uuid']))->assertOk()->assertJsonPath('url', $response->json('url'));
        $this->flushSession();
        $this->getJson(route('store.status', $fresh['client_uuid']))->assertNotFound();
    }

    private function step(StoreOrder $order, string $action, array $changes = []): TestResponse
    {
        return $this->postJson(route('store.orders.transition', $order), array_replace(['operation_uuid' => (string) Str::uuid(), 'version' => $order->fresh()->version, 'action' => $action, 'reason' => 'مراجعة تجريبية موثقة'], $changes));
    }

    private function dispatched(): StoreOrder
    {
        $order = $this->order();
        $this->step($order, 'confirm')->assertRedirect();
        $this->step($order, 'dispatch', ['courier_name' => 'مزود مستقل تجريبي'])->assertRedirect();

        return $order->fresh();
    }

    private function pay(StoreOrder $order, string $kind, string $amount, array $changes = []): TestResponse
    {
        return $this->postJson(route('store.orders.payment', $order), array_replace(['operation_uuid' => (string) Str::uuid(), 'kind' => $kind, 'received_by' => 'store', 'amount' => $amount,
            'method' => 'cash', 'account_id' => $this->cash->id, 'reason' => 'تحصيل وتسوية تجريبية موثقة'], $changes));
    }

    public function test_public_pages_admin_forms_and_sitemap_use_actual_published_data_without_cost(): void
    {
        foreach (['store.home', 'store.checkout'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('store.product', 'store-test'))->assertOk()->assertSee('STORE-TEST')->assertDontSee('average_cost')->assertDontSee('minimum_selling_price')->assertSee('application/ld+json', false);
        $this->get(route('store.sitemap'))->assertOk()->assertSee('/shop/products/store-test')->assertDontSee('/site-admin')->assertDontSee('/checkout');
        foreach (array_keys(ManageStoreContent::TYPES) as $type) {
            $this->get(route('store.admin.content.index', $type))->assertOk();
            $this->get(route('store.admin.content.create', $type))->assertOk();
        }
        $this->get(route('store.orders.index'))->assertOk();
        $order = $this->order();
        $this->get(route('store.orders.show', $order))->assertOk();
        $this->get(route('store.admin.orders.show', $order))->assertOk();
    }

    public function test_new_order_has_no_reservation_or_financial_effect_and_replay_is_once(): void
    {
        $before = [DB::table('journal_entries')->count(), DB::table('stock_movements')->count()];
        $data = $this->orderData();
        $this->postJson(route('store.place'), $data)->assertOk();
        $this->postJson(route('store.place'), $data)->assertOk();
        $this->assertDatabaseCount('store_orders', 1);
        $this->assertDatabaseCount('store_order_items', 1);
        $this->assertDatabaseCount('app_alert_events', 1);
        $this->assertSame($before, [DB::table('journal_entries')->count(), DB::table('stock_movements')->count()]);
        $this->assertDatabaseCount('store_reservations', 0);
        $this->assertDatabaseCount('sale_invoices', 0);
        $data['address'] = 'عنوان مختلف';
        $this->postJson(route('store.place'), $data)->assertConflict();
    }

    public function test_confirmation_reserves_stock_and_blocks_pos_but_does_not_post_sale(): void
    {
        $order = $this->order(['rows' => [['product_variant_id' => $this->variant->id, 'quantity' => 10]]]);
        $this->step($order, 'confirm')->assertRedirect();
        $stock = Inventory::where('product_variant_id', $this->variant->id)->firstOrFail();
        $this->assertSame(10, (int) $stock->quantity);
        $this->assertSame(10, (int) $stock->reserved_quantity);
        $this->postJson(route('sales.invoices.complete'), $this->saleData($this->variant))->assertUnprocessable();
        $this->assertDatabaseCount('sale_invoices', 0);
    }

    public function test_confirmed_cancellation_releases_once_and_stale_transitions_are_rejected(): void
    {
        $order = $this->order();
        $this->step($order, 'confirm')->assertRedirect();
        $uuid = (string) Str::uuid();
        $version = $order->fresh()->version;
        $this->step($order, 'cancel', ['operation_uuid' => $uuid, 'version' => $version])->assertRedirect();
        $this->step($order, 'cancel', ['operation_uuid' => $uuid, 'version' => $version])->assertRedirect();
        $this->assertSame(0, (int) Inventory::where('product_variant_id', $this->variant->id)->value('reserved_quantity'));
        $this->assertDatabaseCount('store_order_events', 3);
        $this->step($order, 'confirm', ['version' => $version])->assertConflict();
    }

    public function test_dispatched_cancellation_keeps_goods_unavailable_until_physical_return(): void
    {
        $order = $this->dispatched();
        $this->step($order, 'cancel')->assertRedirect();
        $this->assertSame('return_pending', $order->fresh()->status);
        $this->assertSame(1, (int) Inventory::where('product_variant_id', $this->variant->id)->value('reserved_quantity'));
        $this->step($order, 'return_received')->assertRedirect();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(0, (int) Inventory::where('product_variant_id', $this->variant->id)->value('reserved_quantity'));
        $this->assertDatabaseCount('sale_invoices', 0);
    }

    public function test_delivery_reuses_sale_and_posts_fee_and_provider_cost_once_then_settles_separately(): void
    {
        $order = $this->dispatched();
        $uuid = (string) Str::uuid();
        $version = $order->version;
        $this->step($order, 'deliver', ['operation_uuid' => $uuid, 'version' => $version])->assertRedirect();
        $this->step($order, 'deliver', ['operation_uuid' => $uuid, 'version' => $version])->assertRedirect();
        $order->refresh();
        $this->assertDatabaseCount('sale_invoices', 1);
        $this->assertDatabaseCount('expenses', 1);
        $this->assertSame(9, (int) Inventory::where('product_variant_id', $this->variant->id)->value('quantity'));
        $this->assertSame(3500, $order->invoice->due_minor);
        $this->assertSame(2000, (int) $order->fee_due_minor);
        $this->assertSame(1200, (int) $order->courier_due_minor);
        $paymentUuid = (string) Str::uuid();
        $this->pay($order, 'collect', '55.00', ['received_by' => 'courier', 'operation_uuid' => $paymentUuid])->assertRedirect();
        $this->pay($order, 'collect', '55.00', ['received_by' => 'courier', 'operation_uuid' => $paymentUuid])->assertRedirect();
        $this->assertSame(0, $order->invoice->fresh()->due_minor);
        $this->assertSame(0, app(PostJournalAction::class)->balance($this->cash->id));
        $this->pay($order, 'remit', '55.00')->assertRedirect();
        $this->pay($order, 'provider_pay', '12.00')->assertRedirect();
        $this->assertSame(4300, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertSame('settled', $order->fresh()->settlement_status);
        $this->assertDatabaseCount('customer_receipts', 1);
        $this->assertSame((int) DB::table('journal_lines')->sum('debit_minor'), (int) DB::table('journal_lines')->sum('credit_minor'));
        $period = app(FinanceReports::class)->period(['branch_id' => $this->branchId, 'from' => OperationContext::today(), 'to' => OperationContext::today()]);
        $this->assertSame(2000, $period['delivery_revenue_minor']);
        $this->assertSame(2300, $period['result_minor']);
    }

    public function test_refusal_charges_original_delivery_even_when_free_without_selling_goods(): void
    {
        StoreOffer::factory()->create(['minimum_minor' => 0]);
        $order = $this->dispatched();
        $this->assertSame(0, (int) $order->delivery_minor);
        $this->step($order, 'refuse')->assertRedirect();
        $order->refresh();
        $this->assertSame(2000, (int) $order->fee_due_minor);
        $this->assertSame(700, (int) $order->courier_due_minor);
        $this->assertDatabaseCount('sale_invoices', 0);
        $this->assertSame(1, (int) Inventory::where('product_variant_id', $this->variant->id)->value('reserved_quantity'));
        $this->pay($order, 'collect', '20.00')->assertRedirect();
        $this->step($order, 'return_received')->assertRedirect();
        $this->assertSame(10, (int) Inventory::where('product_variant_id', $this->variant->id)->value('quantity'));
        $this->assertDatabaseCount('customer_receipts', 0);
    }

    public function test_delivery_offer_threshold_uses_net_products_and_explicit_inclusive_boundary(): void
    {
        $this->variant->update(['selling_price' => '100.00']);
        StoreOffer::factory()->create(['type' => 'product', 'percent_basis_points' => 1000]);
        $offer = StoreOffer::factory()->create(['minimum_minor' => 18000, 'minimum_inclusive' => true, 'zone_ids' => [$this->zone->id]]);
        $input = ['delivery_zone_id' => $this->zone->id, 'rows' => [['product_variant_id' => $this->variant->id, 'quantity' => 2]]];
        $quote = app(StorePricing::class)->quote($input);
        $this->assertSame(18000, $quote['products_minor']);
        $this->assertSame(0, $quote['delivery_minor']);
        $offer->update(['minimum_inclusive' => false]);
        $this->assertSame(2000, app(StorePricing::class)->quote($input)['delivery_minor']);
        $offer->update(['minimum_inclusive' => true, 'ends_at' => now()->subMinute()]);
        $this->assertSame(2000, app(StorePricing::class)->quote($input)['delivery_minor']);
    }

    public function test_provider_prices_and_policy_are_frozen_after_confirmation(): void
    {
        $order = $this->dispatched();
        $this->zone->update(['fee_minor' => 9000, 'courier_delivery_minor' => 8000]);
        $this->settings->update(['policy' => 'سياسة مختلفة تخص الطلبات الجديدة فقط.']);
        $this->step($order, 'deliver')->assertRedirect();
        $order->refresh();
        $this->assertSame(2000, (int) $order->fee_due_minor);
        $this->assertSame(1200, (int) $order->courier_due_minor);
        $this->assertNotSame($this->settings->policy, $order->accepted_policy);
        $this->assertSame(StoreOrderFinance::POLICY, $order->delivery_snapshot['fee_policy']);
    }

    public function test_failure_rolls_back_delivery_invoice_stock_journal_reservation_and_cost(): void
    {
        $order = $this->dispatched();
        $before = DB::table('journal_entries')->count();
        Event::listen('eloquent.creating: '.AuditEvent::class, function (AuditEvent $event): void {
            if ($event->action === 'store_order.deliver') {
                throw new \RuntimeException('Injected audit failure');
            }
        });
        try {
            $this->step($order, 'deliver')->assertStatus(500);
        } finally {
            Event::forget('eloquent.creating: '.AuditEvent::class);
        }
        $this->assertSame('dispatched', $order->fresh()->status);
        $this->assertDatabaseCount('sale_invoices', 0);
        $this->assertDatabaseCount('expenses', 0);
        $this->assertSame($before, DB::table('journal_entries')->count());
        $this->assertSame(1, (int) Inventory::where('product_variant_id', $this->variant->id)->value('reserved_quantity'));
    }

    public function test_cashier_cannot_manage_website_or_read_orders_and_receipts_are_session_scoped(): void
    {
        $order = $this->order();
        $this->get(route('store.receipt', $order))->assertOk();
        $this->flushSession();
        $this->get(route('store.receipt', $order))->assertNotFound();
        $cashier = User::factory()->create(['role_id' => SalesAccess::provisionRole('sales_cashier')->id, 'branch_id' => $this->branchId]);
        $this->actingAs($cashier);
        foreach (['store.orders.index', 'store.admin.home'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->get(route('store.orders.show', $order))->assertForbidden();
    }

    public function test_unknown_provider_cost_prevents_dispatch_and_quote_policy_change_prevents_checkout(): void
    {
        $this->zone->update(['courier_refusal_minor' => null]);
        $order = $this->order();
        $this->step($order, 'confirm')->assertRedirect();
        $this->step($order, 'dispatch', ['courier_name' => 'مزوّد تجريبي'])->assertUnprocessable();
        $this->assertSame('confirmed', $order->fresh()->status);
        $data = $this->orderData();
        $data['policy_version'] = str_repeat('0', 64);
        $this->postJson(route('store.place'), $data)->assertUnprocessable();
    }

    public function test_existing_collection_and_expense_screens_remain_authoritative_for_order_balances(): void
    {
        $order = $this->dispatched();
        $this->step($order, 'deliver')->assertRedirect();
        $order->refresh();
        app(RecordCustomerReceiptAction::class)->execute([
            'client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'customer_id' => $order->customer_id,
            'allocations' => [['sale_invoice_id' => $order->sale_invoice_id, 'amount' => '35.00']],
            'payments' => [['account_id' => $this->cash->id, 'amount' => '35.00']],
        ], $this->owner);
        app(ManageExpenseAction::class)->pay(Expense::findOrFail($order->courier_expense_id), [
            'client_uuid' => (string) Str::uuid(), 'amount' => '12.00', 'account_id' => $this->cash->id, 'reason' => 'سداد من شاشة المصروفات القائمة',
        ], $this->owner);
        $balances = app(StoreOrderFinance::class)->balances($order->fresh());
        $this->assertSame(1200, $balances['provider_paid_minor']);
        $this->assertSame(0, $balances['provider_due_minor']);
        $this->assertSame(3500, $balances['collected_minor']);
        $this->assertSame(5500, $balances['agreed_total_minor']);
        $this->assertSame(2000, $balances['fee_remaining_minor']);
        $this->assertSame(2000, $balances['due_minor']);
        $this->assertSame('partial', $order->fresh(['invoice'])->collection_state);
        $this->pay($order, 'provider_pay', '1.00')->assertUnprocessable();
        $this->pay($order, 'collect', '20.00')->assertRedirect();
        $this->assertSame(4300, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertSame('settled', $order->fresh()->settlement_status);
        $this->get(route('store.orders.show', $order))->assertOk()->assertViewHas('balances', fn (array $values): bool => $values['collected_minor'] === 5500 && $values['provider_due_minor'] === 0);
    }
}
