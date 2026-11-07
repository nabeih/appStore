<?php

namespace Tests\Feature;

use App\Actions\Finance\FinanceReports;
use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Inventory\ProgressiveOperation;
use App\Actions\Inventory\ProgressiveStockProof;
use App\Actions\Inventory\ReviewInventoryAction;
use App\Actions\Inventory\Setup\CorrectInventoryAction;
use App\Actions\Inventory\SetupReadinessReport;
use App\Actions\Purchases\ApprovePurchaseInvoiceAction;
use App\Actions\Purchases\SavePurchaseInvoiceAction;
use App\Actions\Sales\PostSaleAction;
use App\Actions\Sales\ReturnSaleAction;
use App\Actions\Sales\SaveSaleAction;
use App\Actions\Store\ManageStoreOrder;
use App\Actions\Store\StoreOrderFinance;
use App\Actions\Store\StorePricing;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Models\FinancialAccount;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Models\ProductVariant;
use App\Models\SaleInvoice;
use App\Models\StoreListing;
use App\Models\StoreOrder;
use App\Models\StoreSetting;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Mobile\MobileRevision;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Mobile\MobileTestCase;

class ProgressiveOperationTest extends MobileTestCase
{
    protected bool $salesFixtureStarted = false;

    private function progressive(): ProgressiveOperation
    {
        return app(ProgressiveOperation::class);
    }

    private function report(): array
    {
        return $this->progressive()->report(Branch::findOrFail($this->branchId));
    }

    private function activationData(): array
    {
        return ['revision' => $this->report()['revision'], 'reason' => 'مراجعة جاهزية التشغيل الآمن', 'confirmed' => true, 'external_movements_confirmed' => true];
    }

    private function start(): void
    {
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '0', 'reason' => 'بداية نقد حقيقية صفر موثقة'], $this->owner);
        $data = $this->activationData();
        $this->postJson(route('inventory.operation.activate', $this->branchId), $data)->assertOk();
    }

    private function cashSale(ProductVariant $variant, array $changes = []): array
    {
        return $this->saleData($variant, array_replace(['customer_id' => null, 'rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]]], $changes));
    }

    private function counts(): array
    {
        return [DB::table('stock_movements')->count(), JournalEntry::count(), DB::table('sale_invoices')->count(), DB::table('customer_receipts')->count()];
    }

    public function test_setup_progressive_and_full_are_explicit_and_activation_replay_does_not_post_stock_or_money(): void
    {
        $variant = $this->stock();
        $this->get(route('inventory.operation.index'))->assertOk()->assertSee('حالة تشغيل المحل')->assertSee('إعداد فقط');
        $this->postJson(route('sales.invoices.complete'), $this->cashSale($variant))->assertUnprocessable()->assertJsonValidationErrors('setup');
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '0', 'reason' => 'صفر نقد معتمد'], $this->owner);
        $before = $this->counts();
        $data = $this->activationData();
        $this->postJson(route('inventory.operation.activate', $this->branchId), $data)->assertOk();
        $this->postJson(route('inventory.operation.activate', $this->branchId), $data)->assertOk();
        $this->assertSame($before, $this->counts());
        $this->assertSame('progressive', $this->report()['state']);
        $this->assertNull(Branch::find($this->branchId)->opening_stock_closed_at);
        $this->postJson(route('sales.invoices.complete'), $this->cashSale($variant))->assertSuccessful();
        $this->assertSame(9, (int) Inventory::where('product_variant_id', $variant->id)->value('quantity'));
        $this->assertSame(1, AuditEvent::where('action', 'operation.progressive_started')->count());
        $this->assertSame(0, DB::table('journal_lines')->select('journal_entry_id')->groupBy('journal_entry_id')->havingRaw('SUM(debit_minor)<>SUM(credit_minor)')->count());
        $this->assertTrue($this->report()['stocks'][$variant->id]['ready']);
        $zero = ['reason' => 'بداية عميل جديد فعلًا', 'evidence' => 'مراجعة تؤكد عدم وجود ذمة قديمة', 'confirmed' => true];
        $this->postJson(route('inventory.operation.zero', [$this->branchId, 'customer', $this->customer->id]), $zero)->assertOk();
        $closure = app(SetupReadinessReport::class)->build(Branch::find($this->branchId));
        $this->assertTrue($closure['can_close'], json_encode($closure['blockers'], JSON_UNESCAPED_UNICODE));
        $closeData = ['revision' => $closure['revision'], 'start_date' => OperationContext::today(), 'reason' => 'اكتمال الأرصدة والمطابقة',
            'evidence' => 'مطابقة المخزون والصندوق والجهات', 'confirmed' => true, 'accounts_confirmed' => true, 'parties_confirmed' => true, 'account_scope_confirmed' => true];
        $beforeClose = $this->counts();
        app(ReviewInventoryAction::class)->close(Branch::find($this->branchId), $closeData, $this->owner);
        app(ReviewInventoryAction::class)->close(Branch::find($this->branchId), $closeData, $this->owner);
        $this->assertSame($beforeClose, $this->counts());
        $this->assertSame('full', $this->report()['state']);
    }

    public function test_known_unposted_opening_is_isolated_without_blocking_an_independently_proven_sku(): void
    {
        $ready = $this->stock('35', '20', 5, 'READY');
        DB::table('sales_controls')->where('id', 1)->update(['activated_at' => null]);
        $bad = $this->stock('35', '10', 25, 'UNPOSTED');
        DB::table('sales_controls')->where('id', 1)->update(['activated_at' => now()]);
        $proof = app(ProgressiveStockProof::class)->build($this->branchId);
        $this->assertTrue($proof['rows'][$ready->id]['ready']);
        $this->assertFalse($proof['rows'][$bad->id]['ready']);
        $this->assertSame(0, $proof['unexplained_financial_minor']);
        $this->start();
        $before = $this->counts();
        $this->postJson(route('sales.invoices.complete'), $this->cashSale($bad))->assertUnprocessable()->assertJsonValidationErrors('readiness');
        $this->assertSame($before, $this->counts());
        $this->postJson(route('sales.invoices.complete'), $this->cashSale($ready))->assertSuccessful();
        $this->assertFalse($this->report()['stocks'][$bad->id]['ready']);
    }

    public function test_unknown_cost_quantity_drift_and_readiness_change_after_draft_are_rechecked_atomically(): void
    {
        $variant = $this->stock();
        $this->start();
        $draft = app(SaveSaleAction::class)->execute($this->cashSale($variant), $this->owner);
        Inventory::where('product_variant_id', $variant->id)->update(['value_minor' => null]);
        $before = $this->counts();
        try {
            app(PostSaleAction::class)->execute($draft, $this->owner);
            $this->fail('Missing current cost must reject approval.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->counts());
        }
        $this->assertSame('draft', $draft->fresh()->status);
        Inventory::where('product_variant_id', $variant->id)->update(['value_minor' => 20000, 'quantity' => 11]);
        $this->postJson(route('sales.invoices.complete'), $this->cashSale($variant))->assertUnprocessable();
        $this->assertSame($before, $this->counts());
    }

    public function test_explicit_zero_cost_differs_from_unknown_and_does_not_change_the_stock_value(): void
    {
        $free = $this->stock('35', '0', 2, 'FREE');
        $row = $this->report()['stocks'][$free->id];
        $this->assertFalse($row['ready']);
        $input = ['revision' => OperationContext::hash($row), 'reason' => 'عينات مجانية معتمدة', 'evidence' => 'مستند استلام عينات مجانية بتكلفة صفر', 'confirmed' => true];
        $this->postJson(route('inventory.operation.zero', [$this->branchId, 'zero_cost', $row['inventory_id']]), $input)->assertOk();
        $this->assertTrue($this->report()['stocks'][$free->id]['ready']);
        $this->start();
        $this->postJson(route('sales.invoices.complete'), $this->cashSale($free))->assertSuccessful();
        $this->assertSame(0, $this->balance('cogs'));
    }

    public function test_unready_account_split_payment_and_unreviewed_customer_cannot_bypass_guards(): void
    {
        $variant = $this->stock();
        $this->start();
        $bank = FinancialAccount::create(['code' => 'UNREADY-BANK', 'name' => 'بنك غير معتمد', 'type' => 'bank', 'branch_id' => $this->branchId]);
        $data = $this->cashSale($variant, ['payments' => [['account_id' => $this->cash->id, 'amount' => '10'], ['account_id' => $bank->id, 'amount' => '25', 'confirmed' => true]]]);
        $before = $this->counts();
        $this->postJson(route('sales.invoices.complete'), $data)->assertUnprocessable()->assertJsonValidationErrors('readiness');
        $credit = $this->cashSale($variant, ['customer_id' => $this->customer->id, 'payments' => [], 'payment_mode' => 'credit']);
        $this->postJson(route('sales.invoices.complete'), $credit)->assertUnprocessable()->assertJsonValidationErrors('readiness');
        $this->assertSame($before, $this->counts());
        $zero = ['reason' => 'عميل جديد دون تاريخ سابق', 'evidence' => 'تأكيد العميل والمالك أن بداية الذمة صفر', 'confirmed' => true];
        $this->postJson(route('inventory.operation.zero', [$this->branchId, 'customer', $this->customer->id]), $zero)->assertOk();
        $this->postJson(route('sales.invoices.complete'), $credit)->assertSuccessful();
        $this->assertSame(3500, $this->balance('receivables'));
    }

    public function test_rollback_on_financial_failure_and_replay_preserve_exact_quantities_and_historical_cost(): void
    {
        $variant = $this->stock();
        $this->start();
        $input = $this->cashSale($variant);
        $before = $this->counts();
        Event::listen('eloquent.creating: '.JournalEntry::class, function (JournalEntry $entry): void {
            if ($entry->event === 'sale') {
                throw new \RuntimeException('Isolated financial failure');
            }
        });
        try {
            $this->withoutExceptionHandling()->postJson(route('sales.invoices.complete'), $input);
            $this->fail('Journal failure must roll back.');
        } catch (\RuntimeException $failure) {
            $this->assertSame('Isolated financial failure', $failure->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.JournalEntry::class);
        }
        $this->assertSame($before, $this->counts());
        $this->withExceptionHandling()->postJson(route('sales.invoices.complete'), $input)->assertSuccessful();
        $posted = $this->counts();
        $this->postJson(route('sales.invoices.complete'), $input)->assertSuccessful();
        $this->assertSame($posted, $this->counts());
        $cost = DB::table('sale_items')->value('cost_minor');
        $this->assertSame(2000, (int) $cost);
        $this->assertFalse(app(FinanceReports::class)->completeness([$this->branchId])['complete']);
        $this->assertStringContainsString('التشغيل تدريجي', app(FinanceReports::class)->completeness([$this->branchId])['message']);
    }

    public function test_activation_requires_owner_current_revision_ready_shared_accounts_and_open_period(): void
    {
        $this->stock();
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '0', 'reason' => 'بداية صفر موثقة'], $this->owner);
        $data = $this->activationData();
        DB::table('sales_controls')->where('id', 1)->update(['activated_at' => null]);
        $this->postJson(route('inventory.operation.activate', $this->branchId), $data)->assertConflict();
        $this->assertNull($this->progressive()->activation($this->branchId));
        DB::table('sales_controls')->where('id', 1)->update(['activated_at' => now()]);
        $other = User::factory()->create();
        $this->actingAs($other)->postJson(route('inventory.operation.activate', $this->branchId), $this->activationData())->assertForbidden();
        $this->actingAs($this->owner);
        DB::table('branches')->where('id', $this->branchId)->update(['financial_closed_through' => OperationContext::today()]);
        $this->assertFalse($this->report()['can_activate']);
    }

    public function test_cashier_can_sell_ready_walk_in_without_cost_permission_but_cannot_activate(): void
    {
        $variant = $this->stock();
        $this->start();
        $cashier = User::factory()->create(['role_id' => SalesAccess::provisionRole('sales_cashier')->id, 'branch_id' => $this->branchId]);
        $this->actingAs($cashier)->get(route('inventory.operation.index'))->assertForbidden();
        $this->getJson(route('sales.catalog', ['branch_id' => $this->branchId]))->assertOk()
            ->assertJsonPath('readiness.operation_state', 'progressive')->assertJsonMissingPath('data.0.variants.0.unit_cost');
        $this->postJson(route('sales.invoices.complete'), $this->cashSale($variant))->assertSuccessful();
        $this->assertSame(0, $this->balance('receivables'));
        $this->assertSame(3500, app(PostJournalAction::class)->balance($this->cash->id));
    }

    public function test_late_opening_needs_cutover_snapshot_and_cannot_duplicate_or_reprice_sold_stock(): void
    {
        $variant = $this->stock();
        $this->start();
        $this->postJson(route('sales.invoices.complete'), $this->cashSale($variant))->assertSuccessful();
        $oldCost = (int) DB::table('sale_items')->value('cost_minor');
        $data = ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'opening_date' => OperationContext::today(),
            'rows' => [['name' => 'صنف افتتاحي متأخر', 'code' => 'LATE-NEW', 'category_id' => $variant->product->category_id,
                'color_id' => $variant->color_id, 'size_id' => $variant->size_id, 'qty' => 3, 'cost' => '8', 'price' => '25']]];
        $before = $this->counts();
        $this->postJson(route('inventory.opening-stock.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('readiness');
        $cutover = $this->progressive()->activation($this->branchId)->data['cutover_at'];
        $data['progressive_opening'] = ['counted_at' => $cutover, 'evidence' => 'لقطة جرد أصلية وقت الانتقال دون حركات خارجية ناقصة', 'confirmed' => true];
        $late = $data;
        $late['progressive_opening']['counted_at'] = CarbonImmutable::parse($cutover)->addMinute()->toISOString();
        $this->postJson(route('inventory.opening-stock.store'), $late)->assertUnprocessable();
        $this->assertSame($before, $this->counts());
        $this->postJson(route('inventory.opening-stock.store'), $data)->assertCreated();
        $posted = $this->counts();
        $this->postJson(route('inventory.opening-stock.store'), $data)->assertOk();
        $this->assertSame($posted, $this->counts());
        $this->assertSame($oldCost, (int) DB::table('sale_items')->value('cost_minor'));
        $lateVariant = ProductVariant::where('sku', 'like', 'LATE-NEW-%')->firstOrFail();
        $this->assertTrue($this->report()['stocks'][$lateVariant->id]['ready']);
        config(['inventory.allow_setup_changes' => true]);
        try {
            app(CorrectInventoryAction::class)->delete(Inventory::where('product_variant_id', $variant->id)->first(), [], $this->owner->id);
            $this->fail('Direct correction after cutover must fail.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('readiness', $error->errors());
        }
        $duplicate = $data;
        $duplicate['client_uuid'] = (string) Str::uuid();
        $duplicate['rows'][0] = ['name' => $variant->product->name, 'code' => $variant->product->code, 'category_id' => $variant->product->category_id,
            'color_id' => $variant->color_id, 'size_id' => $variant->size_id, 'qty' => 3, 'cost' => '20', 'price' => '35'];
        $this->postJson(route('inventory.opening-stock.store'), $duplicate)->assertUnprocessable();
        $this->assertSame($posted, $this->counts());
    }

    public function test_documented_refund_uses_ready_account_and_original_cost_without_duplicate_return(): void
    {
        $variant = $this->stock();
        $this->start();
        $this->postJson(route('sales.invoices.complete'), $this->cashSale($variant))->assertSuccessful();
        $invoice = SaleInvoice::firstOrFail();
        $input = ['client_uuid' => (string) Str::uuid(), 'rows' => [['sale_item_id' => $invoice->items()->first()->id, 'quantity' => 1, 'condition' => 'resellable']],
            'refund_payments' => [['account_id' => $this->cash->id, 'amount' => '35']], 'reason' => 'إرجاع موثق لنفس فاتورة التطبيق'];
        app(ReturnSaleAction::class)->execute($invoice, $input, $this->owner);
        $counts = $this->counts();
        app(ReturnSaleAction::class)->execute($invoice, $input, $this->owner);
        $this->assertSame($counts, $this->counts());
        $this->assertSame(2000, (int) $invoice->items()->first()->cost_minor);
        $this->assertSame(10, (int) Inventory::where('product_variant_id', $variant->id)->value('quantity'));
        $this->assertTrue($this->report()['stocks'][$variant->id]['ready']);
        $this->assertSame(0, $this->balance('cogs'));
        $this->postJson('/sales/invoices/999999999/returns', $input)->assertNotFound();
    }

    public function test_progressive_exchange_checks_replacement_and_rolls_back_the_return_when_unready(): void
    {
        $old = $this->stock('35', '20', 5, 'EX-OLD');
        $new = $this->stock('40', '25', 5, 'EX-NEW');
        $this->start();
        $this->postJson(route('inventory.operation.zero', [$this->branchId, 'customer', $this->customer->id]),
            ['confirmed' => true, 'reason' => 'New exchange customer', 'evidence' => 'No prior balance or financial documents'])->assertOk();
        $this->postJson(route('sales.invoices.complete'), $this->cashSale($old))->assertSuccessful();
        $invoice = SaleInvoice::firstOrFail();
        $data = ['client_uuid' => (string) Str::uuid(), 'reason' => 'Progressive exchange', 'customer_id' => $this->customer->id, 'settlement_mode' => 'paid',
            'return_rows' => [['sale_item_id' => $invoice->items()->first()->id, 'quantity' => 1, 'condition' => 'resellable']],
            'rows' => [['product_variant_id' => $new->id, 'quantity' => 1]], 'payments' => [['account_id' => $this->cash->id, 'amount' => '5']]];
        $before = $this->counts();
        $new->update(['is_active' => false]);
        $this->postJson(route('sales.exchanges.store', $invoice), $data)->assertUnprocessable()->assertJsonValidationErrors('readiness');
        $this->assertSame($before, $this->counts());
        $this->assertDatabaseCount('sale_returns', 0);
        $new->update(['is_active' => true]);
        $this->postJson(route('sales.exchanges.store', $invoice), $data)->assertSuccessful();
        $after = $this->counts();
        $this->postJson(route('sales.exchanges.store', $invoice), $data)->assertSuccessful();
        $this->assertSame($after, $this->counts());
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertSame(5, (int) Inventory::where('product_variant_id', $old->id)->value('quantity'));
        $this->assertSame(4, (int) Inventory::where('product_variant_id', $new->id)->value('quantity'));
    }

    public function test_new_purchase_requires_supplier_opening_and_keeps_prior_sale_cost_unchanged(): void
    {
        $variant = $this->stock();
        $this->start();
        $this->postJson(route('sales.invoices.complete'), $this->cashSale($variant))->assertSuccessful();
        $oldCost = (int) DB::table('sale_items')->value('cost_minor');
        $supplier = Supplier::factory()->create();
        $invoice = app(SavePurchaseInvoiceAction::class)->execute([
            'client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'supplier_id' => $supplier->id,
            'invoice_date' => OperationContext::today(), 'initial_payment' => '0', 'payment_method' => 'cash',
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 2, 'unit_cost' => '30']],
        ], $this->owner->id);
        $before = $this->counts();
        try {
            app(ApprovePurchaseInvoiceAction::class)->execute($invoice, $invoice->version, $this->owner->id);
            $this->fail('An unreviewed supplier cannot create a financial liability.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('readiness', $error->errors());
        }
        $this->assertSame($before, $this->counts());
        $this->postJson(route('inventory.operation.zero', [$this->branchId, 'supplier', $supplier->id]),
            ['confirmed' => true, 'reason' => 'New supplier opening', 'evidence' => 'Confirmed no earlier supplier liabilities'])->assertOk();
        app(ApprovePurchaseInvoiceAction::class)->execute($invoice, $invoice->version, $this->owner->id);
        $stock = Inventory::where('product_variant_id', $variant->id)->firstOrFail();
        $this->assertSame(11, (int) $stock->quantity);
        $this->assertSame(24000, (int) $stock->value_minor);
        $this->assertSame($oldCost, (int) DB::table('sale_items')->value('cost_minor'));
        $this->assertTrue($this->report()['stocks'][$variant->id]['ready']);
    }

    public function test_unproven_legacy_sale_return_is_blocked_before_any_posting(): void
    {
        $variant = $this->stock();
        $this->start();
        $this->postJson(route('sales.invoices.complete'), $this->cashSale($variant))->assertSuccessful();
        $invoice = SaleInvoice::firstOrFail();
        JournalEntry::where('source_type', SaleInvoice::class)->where('source_id', $invoice->id)->update(['event' => 'legacy_unproven']);
        $before = $this->counts();
        try {
            app(ReturnSaleAction::class)->execute($invoice, ['client_uuid' => (string) Str::uuid(),
                'rows' => [['sale_item_id' => $invoice->items()->first()->id, 'quantity' => 1, 'condition' => 'resellable']],
                'refund_payments' => [['account_id' => $this->cash->id, 'amount' => '35']], 'reason' => 'Unproven legacy return'], $this->owner);
            $this->fail('A legacy invoice without its original journal cannot be returned.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('readiness', $error->errors());
        }
        $this->assertSame($before, $this->counts());
        $this->assertDatabaseCount('sale_returns', 0);
    }

    public function test_inactive_variant_and_other_branch_cannot_bypass_posting_readiness(): void
    {
        $variant = $this->stock();
        $this->start();
        $variant->update(['is_active' => false]);
        $before = $this->counts();
        $this->postJson(route('sales.invoices.complete'), $this->cashSale($variant))->assertUnprocessable();
        $this->assertSame($before, $this->counts());
        $other = Branch::factory()->create();
        $cashier = User::factory()->create(['role_id' => SalesAccess::provisionRole('sales_cashier')->id, 'branch_id' => $this->branchId]);
        $this->mobileLogin($cashier);
        $this->mobile('GET', 'operation-state?branch_id='.$other->id)->assertForbidden();
    }

    public function test_mobile_sync_rechecks_financial_readiness_even_when_stock_revision_has_not_changed(): void
    {
        $variant = $this->stock();
        $this->start();
        $this->mobileLogin();
        $this->mobile('GET', 'operation-state?branch_id='.$this->branchId)->assertOk()->assertJsonPath('data.mode', 'progressive')->assertJsonPath('data.sales_allowed', true);
        $payload = $this->cashSale($variant);
        unset($payload['client_uuid'], $payload['branch_id']);
        $stock = Inventory::where('product_variant_id', $variant->id)->first();
        $payload['rows'][0]['expected_revision'] = MobileRevision::of($stock);
        $operation = $this->operation('sale.create', $payload);
        $this->push([$operation])->assertJsonPath('results.0.status', 'accepted');
        $counts = $this->counts();
        $this->push([$operation])->assertJsonPath('results.0.status', 'already_applied');
        $this->assertSame($counts, $this->counts());
        $payload['rows'][0]['expected_revision'] = MobileRevision::of($stock->fresh());
        $offline = $this->operation('sale.create', $payload);
        DB::table('opening_stock_entries')->update(['status' => 'draft']);
        $this->push([$offline])->assertJsonPath('results.0.status', 'conflict_requires_review')->assertJsonPath('results.0.result.code', 'readiness_changed');
        $this->assertSame($counts, $this->counts());
        $this->push([$offline])->assertJsonPath('results.0.status', 'conflict_requires_review');
        $this->assertSame($counts, $this->counts());
    }

    public function test_store_reservation_and_delivery_recheck_without_partial_release_or_sale(): void
    {
        $variant = $this->stock();
        $this->start();
        $setting = StoreSetting::factory()->create(['branch_id' => $this->branchId]);
        $zone = DeliveryZone::factory()->create(['courier_delivery_minor' => 1000, 'courier_refusal_minor' => 500]);
        StoreListing::factory()->create(['product_id' => $variant->product_id]);
        app(StoreOrderFinance::class)->provision();
        $input = ['client_uuid' => (string) Str::uuid(), 'customer_name' => 'عميل المتجر', 'phone' => '0599000123', 'address' => 'عنوان التسليم',
            'delivery_zone_id' => $zone->id, 'accepted' => true, 'policy_version' => hash('sha256', $setting->policy),
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]]];
        $pricing = app(StorePricing::class);
        $input['quote_revision'] = $pricing->revision($pricing->quote($input));
        $stock = Inventory::where('product_variant_id', $variant->id)->firstOrFail();
        DB::table('inventories')->where('id', $stock->id)->update(['value_minor' => null]);
        $this->postJson('/shop/orders', $input)->assertUnprocessable();
        $this->assertDatabaseCount('store_orders', 0);
        DB::table('inventories')->where('id', $stock->id)->update(['value_minor' => 20000]);
        $this->postJson('/shop/orders', $input)->assertOk();
        $order = StoreOrder::firstOrFail();
        $manage = app(ManageStoreOrder::class);
        $change = fn ($action, $extra = []) => $manage->transition($order->fresh(), $extra + ['operation_uuid' => (string) Str::uuid(),
            'version' => $order->fresh()->version, 'action' => $action, 'reason' => 'اختبار دورة متجر موثقة'], $this->owner);
        $change('confirm');
        $this->assertSame(10, (int) $stock->fresh()->quantity);
        $this->assertSame(1, (int) $stock->fresh()->reserved_quantity);
        $this->assertSame(0, SaleInvoice::count());
        $this->progressive()->confirmZero(Branch::find($this->branchId), 'customer', $order->fresh()->customer_id,
            ['confirmed' => true, 'reason' => 'عميل جديد خاص بالطلب', 'evidence' => 'لم يكن له رصيد سابق قبل هذا الطلب'], $this->owner);
        $change('dispatch', ['courier_name' => 'مزوّد الاختبار']);
        DB::table('inventories')->where('id', $stock->id)->update(['value_minor' => null]);
        $before = $this->counts();
        try {
            $change('deliver');
            $this->fail('Delivery must recheck readiness.');
        } catch (ValidationException) {
            $this->assertSame($before, $this->counts());
        }
        $this->assertSame('dispatched', $order->fresh()->status);
        $this->assertSame(1, (int) $stock->fresh()->reserved_quantity);
        DB::table('inventories')->where('id', $stock->id)->update(['value_minor' => 20000]);
        $change('deliver');
        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame(9, (int) $stock->fresh()->quantity);
        $this->assertSame(0, (int) $stock->fresh()->reserved_quantity);
    }
}
