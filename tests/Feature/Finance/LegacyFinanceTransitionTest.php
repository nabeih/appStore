<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\FinanceReports;
use App\Actions\Finance\LegacyFinanceTransitionAction;
use App\Actions\Finance\LegacyTransitionReport;
use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Inventory\InventoryReadinessReport;
use App\Actions\Inventory\SetupReadinessReport;
use App\Actions\Purchases\ApprovePurchaseInvoiceAction;
use App\Actions\Purchases\SavePurchaseInvoiceAction;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Models\OpeningStockEntry;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestCase;

class LegacyFinanceTransitionTest extends SalesTestCase
{
    protected bool $salesFixtureStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['finance.legacy_transition_enabled' => true]);
    }

    public function test_transition_form_renders_the_sources_and_explicit_approval_controls(): void
    {
        $this->legacyPurchase();
        $response = $this->get(route('finance.transition.index'))->assertOk()
            ->assertSee('معاينة الانتقال المالي التاريخي')->assertSee('data-transition-post', false);
        if (getenv('GO_LIVE_BROWSER_PREVIEW') === '1') {
            $directory = storage_path('app/reports/go-live-preview');
            File::ensureDirectoryExists($directory);
            file_put_contents($directory.'/transition.html', $response->getContent());
        }
    }

    private function input(string $kind, int $id, array $extra = []): array
    {
        return array_replace(['source_kind' => $kind, 'source_id' => $id, 'branch_id' => $this->branchId,
            'posting_date' => OperationContext::today(), 'evidence' => 'دليل انتقال تجريبي موثق', 'reason' => 'اعتماد مراجعة تجريبية',
            'confirmed' => true], $extra);
    }

    private function apply(array $input): JournalEntry
    {
        $action = app(LegacyFinanceTransitionAction::class);
        $plan = $action->preview($input, $this->owner);
        $this->assertSame('eligible', $plan['status'], implode(' ', $plan['issues']));

        return $action->execute($input + ['revision' => $plan['revision']], $this->owner);
    }

    private function legacyPurchase(): PurchaseInvoice
    {
        $variant = $this->stock();
        $supplier = Supplier::factory()->create();
        $invoice = app(SavePurchaseInvoiceAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId,
            'supplier_id' => $supplier->id, 'invoice_date' => OperationContext::today(), 'initial_payment' => '0',
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 2, 'unit_cost' => '20']]], $this->owner->id);
        $invoice = app(ApprovePurchaseInvoiceAction::class)->execute($invoice, $invoice->version, $this->owner->id);
        $journal = JournalEntry::where('source_type', PurchaseInvoice::class)->where('source_id', $invoice->id)->firstOrFail();
        $journal->lines()->delete();
        $journal->delete();
        $invoice->update(['financial_posted_at' => null]);

        return $invoice;
    }

    public function test_preview_and_replay_preserve_the_existing_440_opening_without_writes(): void
    {
        $this->stock('35', '22', 20);
        $entry = OpeningStockEntry::firstOrFail();
        $data = $this->input('opening', $entry->id);
        $before = [DB::table('journal_entries')->count(), DB::table('stock_movements')->count(), DB::table('audit_events')->count()];
        $preview = app(LegacyFinanceTransitionAction::class)->preview($data, $this->owner);
        $this->assertSame('already_posted', $preview['status']);
        $response = $this->postJson(route('finance.transition.preview'), $data)->assertOk()->assertJsonPath('status', 'already_posted');
        $this->postJson(route('finance.transition.post'), $data + ['revision' => $response->json('revision')])->assertOk();
        $this->assertSame($before, [DB::table('journal_entries')->count(), DB::table('stock_movements')->count(), DB::table('audit_events')->count()]);
        $this->assertSame(44000, $this->balance('inventory'));
    }

    public function test_historical_purchase_posts_finance_once_without_stock_or_paid_changes_and_rejects_stale_preview(): void
    {
        $invoice = $this->legacyPurchase();
        $data = $this->input('purchase', $invoice->id);
        $before = [DB::table('stock_movements')->get()->toArray(), DB::table('inventories')->get()->toArray(), $invoice->paid_minor];
        $preview = $this->postJson(route('finance.transition.preview'), $data)->assertOk()->assertJsonPath('status', 'eligible')->json();
        $invoice->update(['notes' => 'مستند تغير بعد المراجعة']);
        $this->postJson(route('finance.transition.post'), $data + ['revision' => $preview['revision']])->assertConflict();
        $entry = $this->apply($data);
        $this->postJson(route('finance.transition.post'), $data + ['revision' => $preview['revision']])->assertOk()->assertJsonPath('journal_id', $entry->id);
        $this->assertEquals($before, [DB::table('stock_movements')->get()->toArray(), DB::table('inventories')->get()->toArray(), $invoice->fresh()->paid_minor]);
        $this->assertSame(-4000, $this->balance('supplier_payables'));
        $this->assertSame(24000, $this->balance('inventory'));
        $this->postJson(route('finance.transition.post'), array_replace($data, ['evidence' => 'دليل آخر مختلف']))->assertConflict();
    }

    public function test_cash_method_is_not_funding_and_a_documented_payment_does_not_create_a_second_receipt(): void
    {
        $invoice = $this->legacyPurchase();
        $this->apply($this->input('purchase', $invoice->id));
        $payment = SupplierPayment::create(['client_uuid' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64),
            'purchase_invoice_id' => $invoice->id, 'supplier_id' => $invoice->supplier_id, 'branch_id' => $this->branchId,
            'created_by' => $this->owner->id, 'amount_minor' => 3000, 'method' => 'cash', 'payment_date' => OperationContext::today(), 'status' => 'approved']);
        $invoice->update(['paid_minor' => 3000]);
        $pending = app(LegacyTransitionReport::class)->build([$this->branchId]);
        $this->assertContains($invoice->id, array_column($pending['invoices'], 'id'));
        $data = $this->input('payment', $payment->id);
        $this->postJson(route('finance.transition.preview'), $data)->assertOk()->assertJsonPath('status', 'rejected');
        $data += ['account_id' => $this->cash->id, 'funding_confirmed' => true, 'opening_inclusion' => 'excluded', 'funding_evidence' => 'تمويل مثبت بمستند تجريبي'];
        $this->postJson(route('finance.transition.preview'), $data)->assertOk()->assertJsonPath('status', 'rejected');
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '100', 'reason' => 'رصيد تمويل للاختبار فقط'], $this->owner);
        $entry = $this->apply($data);
        $this->postJson(route('finance.transition.post'), $data)->assertOk()->assertJsonPath('journal_id', $entry->id);
        $this->assertDatabaseCount('supplier_payments', 1);
        $this->assertSame(3000, $invoice->fresh()->paid_minor);
        $this->assertSame(-1000, $this->balance('supplier_payables'));
        $this->assertSame(7000, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertSame(0, app(FinanceReports::class)->supplier($invoice->supplier_id, $this->branchId)['difference_minor']);
        $this->assertNotContains($invoice->id, array_column(app(LegacyTransitionReport::class)->build([$this->branchId])['invoices'], 'id'));
    }

    public function test_financial_only_reverse_is_audited_once_and_reopens_readiness_without_changing_stock(): void
    {
        $this->stock();
        $opening = OpeningStockEntry::firstOrFail();
        $journal = JournalEntry::where('source_type', OpeningStockEntry::class)->firstOrFail();
        $journal->lines()->delete();
        $journal->delete();
        $entry = $this->apply($this->input('opening', $opening->id));
        $quantity = Inventory::firstOrFail()->quantity;
        $reverse = $this->postJson(route('finance.transition.reverse', $entry), ['reason' => 'عكس اختبار موثق'])->assertOk()->json('journal_id');
        $this->postJson(route('finance.transition.reverse', $entry), ['reason' => 'عكس اختبار موثق'])->assertOk()->assertJsonPath('journal_id', $reverse);
        $this->assertSame($quantity, Inventory::firstOrFail()->quantity);
        $this->assertSame(0, $this->balance('inventory'));
        $this->assertFalse(app(FinanceReports::class)->completeness([$this->branchId])['complete']);
        $report = app(InventoryReadinessReport::class)->annotate(collect([Inventory::firstOrFail()]))->first()->readiness;
        $this->assertFalse($report['sources'][0]['financial_posted']);
        $setup = app(SetupReadinessReport::class)->build(Branch::findOrFail($this->branchId));
        $this->assertContains('unposted_stock_sources', array_column($setup['blockers'], 'code'));
        $this->assertSame(1, AuditEvent::where('action', 'finance.legacy_reversed')->count());
        $this->postJson(route('finance.transition.preview'), $this->input('opening', $opening->id))->assertOk()->assertJsonPath('status', 'rejected');
    }

    public function test_invalid_source_evidence_permissions_and_disabled_execution_cannot_post(): void
    {
        $invoice = $this->legacyPurchase();
        $data = $this->input('purchase', $invoice->id);
        DB::table('stock_movements')->where('source_type', PurchaseInvoiceItem::class)->update(['quantity_change' => 999]);
        $this->postJson(route('finance.transition.preview'), $data)->assertOk()->assertJsonPath('status', 'rejected');
        $this->postJson(route('finance.transition.post'), $data)->assertUnprocessable();
        config(['finance.legacy_transition_enabled' => false]);
        $this->postJson(route('finance.transition.post'), $data)->assertForbidden();
        $this->actingAs(User::factory()->create());
        $this->postJson(route('finance.transition.preview'), $data)->assertForbidden();
    }

    public function test_audit_failure_rolls_back_the_entire_transition(): void
    {
        $invoice = $this->legacyPurchase();
        $data = $this->input('purchase', $invoice->id);
        $data['revision'] = app(LegacyFinanceTransitionAction::class)->preview($data, $this->owner)['revision'];
        $count = JournalEntry::count();
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->action === 'finance.legacy_posted') {
                throw new \RuntimeException('Simulated audit failure');
            }
        });
        try {
            app(LegacyFinanceTransitionAction::class)->execute($data, $this->owner);
            $this->fail('Failure must roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated audit failure', $exception->getMessage());
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertSame($count, JournalEntry::count());
        $this->assertNull($invoice->fresh()->financial_posted_at);
    }

    public function test_completeness_requires_journal_evidence_even_with_posted_flags_and_includes_orphan_payments(): void
    {
        $invoice = $this->legacyPurchase();
        $invoice->update(['financial_posted_at' => now()]);
        $report = app(FinanceReports::class)->completeness([$this->branchId]);
        $this->assertSame(1, $report['legacy_invoices']);
        $this->assertFalse($report['complete']);
        $this->get(route('finance.treasury.index'))->assertOk()->assertSee('غير معتمدة');
        $this->get(route('sales.reports'))->assertOk()->assertSee('غير معتمد');
    }
}
