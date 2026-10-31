<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Inventory\ReviewInventoryAction;
use App\Actions\Inventory\Setup\CorrectInventoryAction;
use App\Actions\Inventory\SetupReadinessReport;
use App\Actions\Purchases\ApprovePurchaseInvoiceAction;
use App\Actions\Purchases\SavePurchaseInvoiceAction;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Inventory\Setup\InventoryState;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Sales\SalesTestCase;

class SetupCompletionTest extends SalesTestCase
{
    protected bool $salesFixtureStarted = false;

    private function report(): array
    {
        return app(SetupReadinessReport::class)->build(Branch::findOrFail($this->branchId));
    }

    private function closeData(): array
    {
        return ['revision' => $this->report()['revision'], 'start_date' => OperationContext::today(),
            'reason' => 'انتهى الجرد والمراجعة', 'evidence' => 'جرد البضاعة وكشف النقد والذمم بتاريخ البدء',
            'confirmed' => true, 'accounts_confirmed' => true, 'account_scope_confirmed' => true, 'parties_confirmed' => true];
    }

    private function confirmCashZero(): void
    {
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '0', 'reason' => 'جرد بداية موثق بقيمة صفر'], $this->owner);
    }

    private function baselineData(Inventory $stock): array
    {
        $stock->load('productVariant.product');

        return ['revision' => InventoryState::revision($stock, $stock->productVariant, $stock->productVariant->product),
            'approved_value' => '200', 'basis' => 'documents', 'evidence' => 'مطابقة مستند أول المدة الأصلي',
            'reason' => 'استكمال قيمة مفقودة من الدليل', 'confirmed' => true];
    }

    public function test_open_setup_blocks_sales_with_a_distinct_reason_even_when_stock_and_ledger_match(): void
    {
        $variant = $this->stock();
        $this->getJson(route('sales.catalog', ['branch_id' => $this->branchId]))->assertOk()
            ->assertJsonPath('readiness.setup_open', true)->assertJsonPath('readiness.blocked', 0);
        $this->postJson(route('sales.invoices.complete'), $this->saleData($variant))->assertUnprocessable()->assertJsonValidationErrors('setup');
        $this->assertDatabaseCount('sale_invoices', 0);
        $this->assertSame(20000, $this->balance('inventory'));
    }

    public function test_close_rejects_quantity_value_financial_and_unknown_cash_blockers_without_writes(): void
    {
        $this->stock();
        Inventory::query()->update(['quantity' => 9, 'value_minor' => null]);
        $report = $this->report();
        $codes = array_column($report['blockers'], 'code');
        foreach (['quantity_mismatch', 'unapproved_inventory_value', 'inventory_ledger_mismatch', 'unknown_account_openings'] as $code) {
            $this->assertContains($code, $codes);
        }
        $before = DB::table('journal_entries')->count();
        $this->postJson(route('inventory.review.close', $this->branchId), $this->closeData())->assertUnprocessable();
        $this->assertNull(Branch::findOrFail($this->branchId)->opening_stock_closed_at);
        $this->assertDatabaseCount('journal_entries', $before);
        $this->assertDatabaseHas('inventories', ['quantity' => 9, 'value_minor' => null]);
    }

    public function test_successful_close_is_audited_replay_safe_blocks_direct_edits_and_allows_a_correct_sale(): void
    {
        $variant = $this->stock();
        $this->confirmCashZero();
        $report = $this->report();
        $this->assertTrue($report['can_close']);
        $this->assertSame(0, $report['reconciliation']['inventory']['difference_minor']);
        $this->get(route('inventory.review.index'))->assertOk()->assertSee('مراجعة إنهاء الإعداد');
        $export = $this->get(route('inventory.review.export', ['setup' => 1, 'branch_id' => $this->branchId]))->assertOk()->assertDownload('setup-readiness.json');
        $this->assertSame(20000, json_decode($export->streamedContent(), true, 512, JSON_THROW_ON_ERROR)['branches'][0]['reconciliation']['inventory']['stock_minor']);
        $data = $this->closeData();
        $before = DB::table('journal_entries')->count();
        $this->postJson(route('inventory.review.close', $this->branchId), $data)->assertOk();
        $this->postJson(route('inventory.review.close', $this->branchId), $data)->assertOk();
        $event = AuditEvent::where('action', 'inventory.setup_closed')->sole();
        $this->assertSame($this->owner->id, $event->user_id);
        $this->assertSame(OperationContext::today(), $event->data['start_date']);
        $this->assertSame(20000, $event->data['approved_balances']['reconciliation']['inventory']['stock_minor']);
        $this->assertDatabaseCount('journal_entries', $before);
        $stock = Inventory::firstOrFail();
        $this->deleteJson(route('inventory.destroy', $stock), ['revision' => $this->baselineData($stock)['revision']])->assertUnprocessable();
        $this->postJson(route('sales.invoices.complete'), $this->saleData($variant))->assertOk();
        $this->assertDatabaseHas('inventories', ['quantity' => 8, 'value_minor' => 16000]);
        $this->assertSame(16000, $this->balance('inventory'));
        $this->assertSame(4000, $this->balance('cogs'));
        $this->assertSame(0, $this->report()['reconciliation']['inventory']['difference_minor']);
    }

    public function test_baseline_rebuilds_only_proven_values_once_with_before_after_audit_and_no_financial_reposting(): void
    {
        $this->stock();
        Inventory::query()->update(['value_minor' => null, 'average_cost' => '99']);
        DB::table('stock_movements')->update(['value_change_minor' => null]);
        $stock = Inventory::firstOrFail();
        $data = $this->baselineData($stock);
        $journals = DB::table('journal_entries')->count();
        $this->postJson(route('inventory.review.baseline', $stock), $data)->assertOk();
        $this->postJson(route('inventory.review.baseline', $stock), $data)->assertOk();
        $this->assertDatabaseHas('inventories', ['value_minor' => 20000, 'average_cost' => '20.00', 'quantity' => 10]);
        $this->assertDatabaseHas('stock_movements', ['value_change_minor' => 20000]);
        $this->assertDatabaseCount('journal_entries', $journals);
        $event = AuditEvent::where('action', 'inventory.valuation_reviewed')->sole();
        $this->assertNull($event->data['before']['value_minor']);
        $this->assertSame(20000, $event->data['after']['value_minor']);
        $this->assertFalse($event->data['financial_posting_performed']);
        $data['approved_value'] = '201';
        $this->postJson(route('inventory.review.baseline', $stock), $data)->assertConflict();
    }

    public function test_value_approval_alone_does_not_complete_the_ledger_and_quantity_conflict_cannot_be_hidden(): void
    {
        $this->stock();
        DB::table('journal_lines')->delete();
        DB::table('journal_entries')->delete();
        Inventory::query()->update(['value_minor' => null, 'quantity' => 9]);
        $stock = Inventory::firstOrFail();
        $this->postJson(route('inventory.review.baseline', $stock), $this->baselineData($stock))->assertUnprocessable();
        $stock->update(['quantity' => 10]);
        $this->postJson(route('inventory.review.baseline', $stock), $this->baselineData($stock))->assertOk();
        $report = $this->report();
        $this->assertSame(20000, $report['reconciliation']['inventory']['difference_minor']);
        $this->assertContains('unposted_stock_sources', array_column($report['blockers'], 'code'));
        $this->assertFalse($report['can_close']);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_source_correction_updates_stock_source_and_ledger_together_before_closing(): void
    {
        $this->stock();
        $stock = Inventory::with('productVariant.product')->firstOrFail();
        $variant = $stock->productVariant;
        app(CorrectInventoryAction::class)->update($stock, [
            'revision' => InventoryState::revision($stock, $variant, $variant->product),
            'quantity' => 12, 'average_cost' => '22', 'selling_price' => '35',
            'name' => $variant->product->name, 'code' => $variant->product->code, 'category_id' => $variant->product->category_id,
            'color_id' => $variant->color_id, 'size_id' => $variant->size_id, 'reason' => 'تصحيح المستند بعد مطابقة الجرد',
        ], $this->owner->id);
        $this->assertDatabaseHas('inventories', ['quantity' => 12, 'value_minor' => 26400]);
        $this->assertDatabaseHas('opening_stock_items', ['quantity' => 12, 'line_total' => '264.00']);
        $this->assertSame(26400, $this->balance('inventory'));
        $this->assertSame(0, $this->report()['reconciliation']['inventory']['difference_minor']);
    }

    public function test_stale_review_missing_confirmations_and_foreign_permissions_cannot_close(): void
    {
        $this->stock();
        $stale = $this->closeData();
        $this->confirmCashZero();
        $this->postJson(route('inventory.review.close', $this->branchId), $stale)->assertConflict();
        $data = $this->closeData();
        unset($data['parties_confirmed']);
        $this->postJson(route('inventory.review.close', $this->branchId), $data)->assertUnprocessable();
        $cashier = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]);
        $this->actingAs($cashier)->get(route('inventory.review.index'))->assertForbidden();
        $this->postJson(route('inventory.review.close', $this->branchId), $this->closeData())->assertForbidden();
        $this->assertNull(Branch::findOrFail($this->branchId)->opening_stock_closed_at);
    }

    public function test_close_and_baseline_roll_back_completely_when_audit_persistence_fails(): void
    {
        $this->stock();
        $this->confirmCashZero();
        AuditEvent::creating(function (AuditEvent $event): void {
            if (in_array($event->action, ['inventory.setup_closed', 'inventory.valuation_reviewed'], true)) {
                throw new RuntimeException('audit persistence failure');
            }
        });
        try {
            try {
                app(ReviewInventoryAction::class)->close(Branch::findOrFail($this->branchId), $this->closeData(), $this->owner);
                $this->fail('Expected audit failure.');
            } catch (RuntimeException $error) {
                $this->assertSame('audit persistence failure', $error->getMessage());
            }
            $this->assertNull(Branch::findOrFail($this->branchId)->opening_stock_closed_at);
            Inventory::query()->update(['value_minor' => null]);
            DB::table('stock_movements')->update(['value_change_minor' => null]);
            $stock = Inventory::firstOrFail();
            try {
                app(ReviewInventoryAction::class)->baseline($stock, $this->baselineData($stock), $this->owner);
                $this->fail('Expected audit failure.');
            } catch (RuntimeException $error) {
                $this->assertSame('audit persistence failure', $error->getMessage());
            }
            $this->assertNull($stock->fresh()->value_minor);
            $this->assertNull(DB::table('stock_movements')->value('value_change_minor'));
            $this->assertSame(20000, $this->balance('inventory'));
        } finally {
            AuditEvent::flushEventListeners();
        }
    }

    public function test_quarantine_discrepancy_and_unresolved_drafts_block_closing(): void
    {
        $this->stock();
        $this->confirmCashZero();
        Inventory::query()->update(['quarantined_quantity' => 1, 'quarantined_value_minor' => 2000]);
        DB::table('opening_stock_entries')->update(['status' => 'draft']);
        $codes = array_column($this->report()['blockers'], 'code');
        $this->assertContains('quarantine_mismatch', $codes);
        $this->assertContains('opening_drafts', $codes);
        $this->postJson(route('inventory.review.close', $this->branchId), $this->closeData())->assertUnprocessable();
    }

    public function test_an_inventory_row_without_source_history_cannot_be_valued_to_hide_its_quantity_difference(): void
    {
        $this->stock();
        DB::table('stock_movements')->delete();
        Inventory::query()->update(['value_minor' => null]);
        $stock = Inventory::firstOrFail();
        $this->postJson(route('inventory.review.baseline', $stock), $this->baselineData($stock))->assertUnprocessable();
        $report = $this->report();
        $this->assertSame(10, $report['inventory'][0]['difference']);
        $this->assertNull($report['inventory'][0]['reconstructed_value_minor']);
        $this->assertContains('document_movement_mismatch', array_column($report['blockers'], 'code'));
        $this->assertNull($stock->fresh()->value_minor);
    }

    public function test_start_date_cannot_precede_the_reviewed_movements_and_closed_balance_cannot_be_revalued(): void
    {
        $this->stock();
        $this->confirmCashZero();
        $data = $this->closeData();
        $data['start_date'] = now()->subDay()->toDateString();
        $this->postJson(route('inventory.review.close', $this->branchId), $data)->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->postJson(route('inventory.review.close', $this->branchId), $this->closeData())->assertOk();
        $stock = Inventory::firstOrFail();
        $this->postJson(route('inventory.review.baseline', $stock), $this->baselineData($stock))->assertUnprocessable();
    }

    public function test_supplier_reconciliation_detects_payment_journal_loss_even_if_posted_markers_are_present(): void
    {
        $variant = $this->stock();
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '100', 'reason' => 'رصيد نقد موثق'], $this->owner);
        $invoice = app(SavePurchaseInvoiceAction::class)->execute([
            'client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId,
            'supplier_id' => Supplier::factory()->create()->id, 'invoice_date' => OperationContext::today(),
            'initial_payment' => '10', 'payment_method' => 'cash', 'payment_details' => ['account_id' => $this->cash->id],
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 3, 'unit_cost' => '10']],
        ], $this->owner->id);
        $invoice = app(ApprovePurchaseInvoiceAction::class)->execute($invoice, $invoice->version, $this->owner->id);
        $this->assertTrue($this->report()['can_close']);
        $paymentJournal = DB::table('journal_entries')->where('event', 'supplier_payment')->value('id');
        DB::table('journal_lines')->where('journal_entry_id', $paymentJournal)->delete();
        $report = $this->report();
        $this->assertNotNull($invoice->fresh()->financial_posted_at);
        $this->assertSame(-1000, $report['suppliers'][0]['difference_minor']);
        $this->assertContains('supplier_ledger_mismatch', array_column($report['blockers'], 'code'));
        $this->assertContains('purchase_detail_mismatch', array_column($report['blockers'], 'code'));
        $this->assertFalse($report['can_close']);
    }

    public function test_zero_opening_is_explicit_known_and_cannot_be_changed_or_posted_twice(): void
    {
        $this->assertFalse($this->report()['accounts'][0]['opening_known']);
        $this->confirmCashZero();
        $journals = DB::table('journal_entries')->count();
        $this->confirmCashZero();
        $this->assertTrue($this->report()['accounts'][0]['opening_known']);
        $this->assertSame(0, $this->report()['accounts'][0]['ledger_minor']);
        $this->assertDatabaseCount('journal_entries', $journals);
        $this->postJson(route('finance.accounts.opening', $this->cash), [
            'opening_balance' => '10', 'reason' => 'مبلغ مختلف', 'confirm_opening' => true,
        ])->assertConflict();
    }
}
