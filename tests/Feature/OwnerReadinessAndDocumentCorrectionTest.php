<?php

namespace Tests\Feature;

use App\Actions\Finance\FinanceReports;
use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Inventory\CorrectDocumentItemAction;
use App\Actions\Inventory\OpeningStock\PostOpeningStockAction;
use App\Actions\Inventory\ProgressiveOperation;
use App\Actions\Inventory\ProgressiveStockProof;
use App\Actions\Inventory\SetupReadinessReport;
use App\Actions\Inventory\StockLedger;
use App\Actions\Purchases\ApprovePurchaseInvoiceAction;
use App\Actions\Purchases\RecordSupplierPaymentAction;
use App\Actions\Purchases\SavePurchaseInvoiceAction;
use App\Actions\Sales\ReturnSaleAction;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Color;
use App\Models\FinancialAccount;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OpeningStockEntry;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Sales\SalesTestCase;

class OwnerReadinessAndDocumentCorrectionTest extends SalesTestCase
{
    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'mysql' || ! in_array(getenv('DB_DATABASE'), ['appstore_golive_20261031_tests', 'appstore_account_status_20261118_tests'], true)) {
            throw new \LogicException('Guarded isolated MySQL only.');
        }
        parent::setUp();
    }

    public function test_owner_sales_setup_reuses_selected_drawer_preserves_opening_and_is_repeatable(): void
    {
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '100', 'reason' => 'جرد بداية موثق'], $this->owner);
        DB::table('sales_controls')->where('id', 1)->update(['activated_at' => null]);
        $journalCount = JournalEntry::count();
        $data = ['confirmed' => true, 'account_id' => $this->cash->id];
        $this->postJson(route('inventory.operation.sales-setup', $this->branchId), $data)->assertOk();
        $this->postJson(route('inventory.operation.sales-setup', $this->branchId), $data)->assertOk();
        $this->artisan('app:sales-setup', ['--owner' => $this->owner->username, '--branch' => $this->branchId, '--account' => $this->cash->id])->assertExitCode(0);
        $this->assertSame(1, FinancialAccount::where('branch_id', $this->branchId)->where('type', 'cash')->count());
        $this->assertSame($journalCount, JournalEntry::count());
        $this->assertSame(10000, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertNull(Branch::findOrFail($this->branchId)->opening_stock_closed_at);
        $this->assertNull(app(ProgressiveOperation::class)->activation($this->branchId));
    }

    public function test_similar_drawers_remain_independent_until_owner_selects_one(): void
    {
        $other = FinancialAccount::create(['code' => 'SECOND-CASH', 'name' => $this->cash->name, 'branch_id' => $this->branchId, 'type' => 'cash']);
        $this->postJson(route('inventory.operation.sales-setup', $this->branchId), ['confirmed' => true])->assertUnprocessable()->assertJsonValidationErrors('account_id');
        $this->postJson(route('inventory.operation.sales-setup', $this->branchId), ['confirmed' => true, 'account_id' => $other->id])->assertOk();
        $this->postJson(route('inventory.operation.sales-setup', $this->branchId), ['confirmed' => true])->assertOk();
        $this->assertSame(2, FinancialAccount::where('type', 'cash')->count());
        $this->get(route('sales.cashier'))->assertOk()->assertViewHas('accounts', fn ($accounts): bool => $accounts->first()->id === $other->id);
        $page = $this->get(route('inventory.operation.index', ['branch_id' => $this->branchId]))->assertOk();
        $page->assertSee('#'.$other->id)->assertSee('#'.$this->cash->id)->assertSee('إكمال إعداد المبيعات');
        $cashier = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]);
        $this->actingAs($cashier)->postJson(route('inventory.operation.sales-setup', $this->branchId), ['confirmed' => true])->assertForbidden();
    }

    public function test_documented_zero_is_approved_once_and_different_amount_reports_the_approved_opening(): void
    {
        $data = ['opening_balance' => '0', 'confirm_opening' => true, 'reason' => 'جرد نقد بداية موثق صفر'];
        $this->postJson(route('finance.accounts.opening', $this->cash), $data)->assertOk();
        $this->postJson(route('finance.accounts.opening', $this->cash), $data)->assertOk();
        $this->assertSame(1, JournalEntry::where('source_type', FinancialAccount::class)->where('source_id', $this->cash->id)->count());
        $row = app(ProgressiveOperation::class)->accountRows($this->branchId)[$this->cash->id];
        $this->assertTrue($row['ready']);
        $this->assertSame(0, $row['opening_minor']);
        $this->assertSame(0, $row['balance_minor']);
        $variant = $this->stock('20', '10', 10);
        $this->sale($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 2]]]);
        $row = app(ProgressiveOperation::class)->accountRows($this->branchId)[$this->cash->id];
        $this->assertSame(0, $row['opening_minor']);
        $this->assertSame(4000, $row['balance_minor']);
        $this->postJson(route('finance.accounts.opening', $this->cash), array_replace($data, ['opening_balance' => '12']))->assertConflict()->assertSee('0.00')->assertSee('#'.$this->cash->id);
        $this->get(route('finance.accounts.index'))->assertOk()->assertSee('0.00');
    }

    public function test_closed_branch_retains_opening_history_but_rejects_input_draft_approval_and_editing(): void
    {
        $variant = $this->stock('20', '10', 10);
        $entry = OpeningStockEntry::firstOrFail();
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => null]);
        $data = ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'opening_date' => OperationContext::today(),
            'rows' => [['name' => 'بضاعة مسودة مستقلة', 'code' => 'DRAFT-ONLY', 'category_id' => $variant->product->category_id,
                'color_id' => $variant->color_id, 'size_id' => $variant->size_id, 'qty' => 1, 'cost' => '10', 'price' => '20']]];
        $draft = app(PostOpeningStockAction::class)->execute($data, $this->owner->id, true);
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => now()]);
        $before = [Inventory::sum('quantity'), JournalEntry::count(), OpeningStockEntry::count()];
        $this->get(route('inventory.opening-stock.index', ['branch_id' => $this->branchId]))->assertOk()->assertSee('سجل البضاعة الافتتاحية')->assertDontSee('quickOpeningStockModal', false);
        $this->get(route('inventory.opening-stock.show', $entry))->assertOk()->assertSee('طباعة المستند');
        $this->get(route('inventory.opening-stock.show', $draft))->assertOk()->assertDontSee('id="opening-draft-edit"', false);
        $data['client_uuid'] = (string) Str::uuid();
        $this->postJson(route('inventory.opening-stock.preview'), $data)->assertUnprocessable();
        $this->postJson(route('inventory.opening-stock.drafts.store'), $data)->assertUnprocessable();
        $this->postJson(route('inventory.opening-stock.store'), $data)->assertUnprocessable();
        $this->postJson(route('inventory.opening-stock.drafts.approve', $draft), ['version' => $draft->version])->assertUnprocessable();
        $this->putJson(route('inventory.opening-stock.drafts.update', $draft), ['version' => $draft->version, 'action' => 'cancel'])->assertUnprocessable();
        $this->assertSame($before, [Inventory::sum('quantity'), JournalEntry::count(), OpeningStockEntry::count()]);
    }

    public function test_opening_quantity_and_cost_correction_after_sale_preserves_originals_and_return_uses_documented_adjustment(): void
    {
        $variant = $this->stock('20', '10', 10);
        $sale = $this->sale($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 4]]]);
        $entry = OpeningStockEntry::firstOrFail();
        $item = $entry->items()->sole();
        $originalJournal = JournalEntry::where('source_type', OpeningStockEntry::class)->where('source_id', $entry->id)->sole();
        $originalLines = $originalJournal->lines()->get()->toArray();
        $data = $this->correctionData($entry, $item->id);
        $this->assertSame(6000, $data['plan']['inventory_delta_minor']);
        $this->assertSame(2000, $data['plan']['cogs_delta_minor']);
        unset($data['plan']);
        $url = route('inventory.document-corrections.store', ['opening', $entry->id, $item->id]);
        $response = $this->postJson($url, $data)->assertOk();
        $this->postJson($url, $data)->assertOk()->assertJsonPath('id', $response->json('id'));
        $this->assertSame($originalLines, $originalJournal->lines()->get()->toArray());
        $this->assertSame(10, $item->fresh()->quantity);
        $this->assertSame('10.00', $item->fresh()->unit_cost);
        $this->assertSame(4000, (int) $sale->items()->sole()->cost_minor);
        $this->assertInventory(8, 12000);
        $this->assertSame(6000, $this->balance('cogs'));
        $this->assertSame(1, AuditEvent::where('action', 'inventory.document_corrected')->count());
        $this->assertTrue(app(ProgressiveStockProof::class)->build($this->branchId)['rows'][$variant->id]['ready']);
        $report = app(SetupReadinessReport::class)->build(Branch::findOrFail($this->branchId));
        $this->assertSame([], $report['document_movement_issues']);
        $this->assertSame('reviewed', $report['inventory'][0]['state']);
        $this->assertTrue(collect($report['inventory'][0]['sources'])->firstWhere('kind', 'document_correction')['financial_posted']);
        app(ReturnSaleAction::class)->execute($sale, $this->returnData($sale, 1), $this->owner);
        $this->assertInventory(9, 13500);
        $this->assertSame(4500, $this->balance('cogs'));
        $this->get(route('inventory.opening-stock.show', $entry))->assertOk()->assertSee('تصحيح #'.$response->json('id'));
    }

    public function test_purchase_correction_after_sale_updates_payable_without_rewriting_invoice_and_accepts_correct_payment(): void
    {
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '1000', 'reason' => 'رصيد تجربة معزولة'], $this->owner);
        $product = Product::create(['name' => 'منتج شراء مستقل', 'code' => 'PURCHASE-ONLY', 'category_id' => Category::create(['name' => 'تصنيف الشراء'])->id, 'is_active' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'color_id' => Color::create(['name' => 'أسود', 'code' => 'BLK'])->id,
            'size_id' => Size::create(['name' => 'M', 'code' => 'M'])->id, 'sku' => 'PURCHASE-ONLY-BLK-M', 'selling_price' => '20', 'is_active' => true]);
        $draft = app(SavePurchaseInvoiceAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId,
            'supplier_id' => Supplier::factory()->create()->id, 'invoice_date' => OperationContext::today(), 'initial_payment' => '0',
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => '10']]], $this->owner->id);
        $invoice = app(ApprovePurchaseInvoiceAction::class)->execute($draft, $draft->version, $this->owner->id);
        $stock = Inventory::where('product_variant_id', $variant->id)->sole();
        OperationContext::audit($stock, $this->owner, 'operation.zero_opening_confirmed', 'بداية اختبار موثقة صفر قبل الشراء', ['amount_minor' => 0]);
        $this->sale($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 4]]]);
        $data = $this->correctionData($invoice, $invoice->items()->sole()->id);
        unset($data['plan']);
        $this->postJson(route('inventory.document-corrections.store', ['purchase', $invoice->id, $invoice->items()->sole()->id]), $data)->assertOk();
        $this->assertSame(10000, (int) $invoice->fresh()->total_minor);
        $this->assertSame(18000, $invoice->fresh()->effective_total_minor);
        $this->assertInventory(8, 12000);
        $this->assertSame(-18000, $this->balance('supplier_payables'));
        $this->assertSame(0, app(FinanceReports::class)->supplier($invoice->supplier_id, $this->branchId)['difference_minor']);
        $this->get(route('suppliers.show', $invoice->supplier_id))->assertOk()->assertViewHas('balance', '180.00');
        $this->get(route('suppliers.index'))->assertOk()->assertViewHas('entries', fn ($entries): bool => (int) $entries->first()->balance_minor === 18000);
        $this->delete(route('suppliers.archive', $invoice->supplier_id))->assertSessionHasErrors();
        $this->flushSession();
        $this->actingAs($this->owner);
        app(RecordSupplierPaymentAction::class)->execute($invoice, ['client_uuid' => (string) Str::uuid(), 'amount' => '180', 'account_id' => $this->cash->id, 'payment_date' => OperationContext::today()], $this->owner->id);
        $this->assertSame('0.00', $invoice->fresh()->remaining_amount);
        $this->assertSame(0, $this->balance('supplier_payables'));
        $this->get(route('inventory.document-corrections.show', ['purchase', $invoice->id, $invoice->items()->sole()->id]).'?'.http_build_query([
            'quantity' => 12, 'unit_cost' => '14', 'reason' => 'تصحيح يتجاوز قيمة المستحق بعد السداد',
        ]))->assertOk()->assertSee('المسدد أكبر من المستحق');
        $this->assertSame(1, AuditEvent::where('action', 'inventory.document_corrected')->count());
        $this->assertInventory(8, 12000);
    }

    public function test_correction_rechecks_stale_preview_and_rolls_back_when_journal_fails(): void
    {
        $variant = $this->stock('20', '10', 10);
        $entry = OpeningStockEntry::firstOrFail();
        $item = $entry->items()->sole();
        $data = $this->correctionData($entry, $item->id);
        unset($data['plan']);
        $this->sale($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]]]);
        $url = route('inventory.document-corrections.store', ['opening', $entry->id, $item->id]);
        $this->postJson($url, $data)->assertConflict();
        $data = $this->correctionData($entry, $item->id);
        unset($data['plan']);
        $before = [Inventory::sum('quantity'), Inventory::sum('value_minor'), JournalEntry::count(), AuditEvent::count(), DB::table('stock_movements')->count()];
        $this->mock(PostJournalAction::class)->shouldReceive('execute')->once()->andThrow(new RuntimeException('Simulated journal failure'));
        try {
            app(CorrectDocumentItemAction::class)->execute($entry, $item->id, $data, $this->owner);
            $this->fail('Expected a financial posting failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated journal failure', $exception->getMessage());
        }
        $this->assertSame($before, [Inventory::sum('quantity'), Inventory::sum('value_minor'), JournalEntry::count(), AuditEvent::count(), DB::table('stock_movements')->count()]);
    }

    public function test_stock_failure_rolls_back_the_correction_journal_and_audit(): void
    {
        $this->stock('20', '10', 10);
        $entry = OpeningStockEntry::firstOrFail();
        $item = $entry->items()->sole();
        $data = $this->correctionData($entry, $item->id);
        unset($data['plan']);
        $before = [Inventory::sum('quantity'), Inventory::sum('value_minor'), JournalEntry::count(), AuditEvent::count(), DB::table('stock_movements')->count()];
        $this->partialMock(StockLedger::class)->shouldReceive('move')->once()->andThrow(new RuntimeException('Simulated stock failure'));
        try {
            app(CorrectDocumentItemAction::class)->execute($entry, $item->id, $data, $this->owner);
            $this->fail('Expected a stock posting failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated stock failure', $exception->getMessage());
        }
        $this->assertSame($before, [Inventory::sum('quantity'), Inventory::sum('value_minor'), JournalEntry::count(), AuditEvent::count(), DB::table('stock_movements')->count()]);
        $this->assertInventory(10, 10000);
    }

    public function test_quantity_only_decrease_then_separate_cost_correction_apply_differences_without_duplication(): void
    {
        $variant = $this->stock('20', '10', 10);
        $this->sale($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 4]]]);
        $entry = OpeningStockEntry::firstOrFail();
        $item = $entry->items()->sole();
        $action = app(CorrectDocumentItemAction::class);
        $data = ['quantity' => 8, 'unit_cost' => '10.00', 'reason' => 'تصحيح كمية زائدة أدخلت بالخطأ'];
        $plan = $action->preview($entry, $item->id, $data, $this->owner);
        $action->execute($entry, $item->id, $data + ['revision' => $plan['revision'], 'operation_uuid' => (string) Str::uuid(), 'confirmed' => true], $this->owner);
        $this->assertInventory(4, 4000);
        $this->assertSame(4000, $this->balance('cogs'));
        $data['unit_cost'] = '15.00';
        $plan = $action->preview($entry, $item->id, $data, $this->owner);
        $request = $data + ['revision' => $plan['revision'], 'operation_uuid' => (string) Str::uuid(), 'confirmed' => true];
        $action->execute($entry, $item->id, $request, $this->owner);
        $action->execute($entry, $item->id, $request, $this->owner);
        $this->assertInventory(4, 6000);
        $this->assertSame(6000, $this->balance('cogs'));
        $this->assertSame(2, AuditEvent::where('action', 'inventory.document_corrected')->count());
        $this->assertSame(10, $item->fresh()->quantity);
        $this->assertTrue(app(ProgressiveStockProof::class)->build($this->branchId)['rows'][$variant->id]['ready']);
    }

    public function test_mixed_receipt_sources_require_review_without_guessing_a_cost_allocation(): void
    {
        $variant = $this->stock('20', '10', 10);
        $entry = OpeningStockEntry::firstOrFail();
        $draft = app(SavePurchaseInvoiceAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId,
            'supplier_id' => Supplier::factory()->create()->id, 'invoice_date' => OperationContext::today(), 'initial_payment' => '0',
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 2, 'unit_cost' => '12']]], $this->owner->id);
        app(ApprovePurchaseInvoiceAction::class)->execute($draft, $draft->version, $this->owner->id);
        $url = route('inventory.document-corrections.show', ['opening', $entry->id, $entry->items()->sole()->id]);
        $this->get($url.'?'.http_build_query(['quantity' => 12, 'unit_cost' => '15', 'reason' => 'تصحيح مصادر تكلفة مختلطة']))->assertOk()->assertSee('مصادر تكلفة مختلطة');
        $this->assertSame(0, AuditEvent::where('action', 'inventory.document_corrected')->count());
        $this->assertInventory(12, 12400);
    }

    public function test_legacy_unproven_source_closed_period_and_cashier_cannot_correct(): void
    {
        $variant = $this->stock('20', '10', 10);
        $entry = OpeningStockEntry::firstOrFail();
        $item = $entry->items()->sole();
        $url = route('inventory.document-corrections.show', ['opening', $entry->id, $item->id]);
        DB::table('branches')->where('id', $this->branchId)->update(['financial_closed_through' => OperationContext::today()]);
        $this->get($url.'?'.http_build_query(['quantity' => 12, 'unit_cost' => '15', 'reason' => 'تصحيح اختبار فترة مغلقة']))->assertOk()->assertSee('مغلقة');
        DB::table('branches')->where('id', $this->branchId)->update(['financial_closed_through' => null]);
        DB::table('journal_lines')->delete();
        DB::table('journal_entries')->delete();
        $this->get($url.'?'.http_build_query(['quantity' => 12, 'unit_cost' => '15', 'reason' => 'مصدر قديم غير مثبت']))->assertOk()->assertSee('غير مثبت');
        $cashier = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]);
        $this->actingAs($cashier)->get($url)->assertForbidden();
        $this->assertSame(0, AuditEvent::where('action', 'inventory.document_corrected')->count());
    }

    private function correctionData(Model $document, int $item): array
    {
        $data = ['quantity' => 12, 'unit_cost' => '15.00', 'reason' => 'تصحيح خطأ إدخال بدليل موثق'];
        $plan = app(CorrectDocumentItemAction::class)->preview($document, $item, $data, $this->owner);

        return $data + ['operation_uuid' => (string) Str::uuid(), 'revision' => $plan['revision'], 'confirmed' => true, 'plan' => $plan];
    }

    private function assertInventory(int $quantity, int $value): void
    {
        $this->assertSame($quantity, (int) Inventory::sum('quantity'));
        $this->assertSame($value, (int) Inventory::sum('value_minor'));
        $this->assertSame($value, $this->balance('inventory'));
        $this->assertSame($quantity, (int) DB::table('stock_movements')->where('stock_bucket', 'available')->sum('quantity_change'));
        $this->assertSame($value, (int) DB::table('stock_movements')->where('stock_bucket', 'available')->sum('value_change_minor'));
        $this->assertSame(0, JournalLine::select('journal_entry_id')->groupBy('journal_entry_id')->havingRaw('SUM(debit_minor) <> SUM(credit_minor)')->get()->count());
    }
}
