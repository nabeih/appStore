<?php

namespace Tests\Feature;

use App\Actions\Finance\FinanceReports;
use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Finance\ManageSupplierDebtSettlement;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Inventory\ProgressiveOperation;
use App\Actions\Inventory\SetupReadinessReport;
use App\Actions\Purchases\RecordSupplierPaymentAction;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PurchaseInvoice;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierDebtSettlement;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestCase;

class SupplierDebtSettlementTest extends SalesTestCase
{
    private Supplier $supplier;

    public function refreshDatabase(): void
    {
        $connection = DB::connection();
        if (! app()->environment('testing') || app()->configurationIsCached() || $connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== 'appstore_account_status_20261118_tests'
            || $connection->getConfig('host') !== '127.0.0.1' || $connection->getConfig('url')
            || ! RefreshDatabaseState::$migrated) {
            throw new \LogicException('Use the guarded MySQL sandbox runner; this test must never migrate or reset a database.');
        }
        parent::refreshDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('appstore_account_status_20261118_tests', DB::connection()->getDatabaseName());
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '10000', 'reason' => 'تمويل اختبار معزول'], $this->owner);
        $this->stock();
        $this->postJson(route('suppliers.store'), ['name' => 'أبو أحمد'])->assertCreated();
        $this->supplier = Supplier::sole();
    }

    private function data(array $changes = []): array
    {
        return array_replace(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'amount' => '5000.00',
            'original_date' => now()->subYears(2)->toDateString(), 'tracking_started_on' => app(ManageSupplierDebtSettlement::class)->cutoff($this->branchId),
            'posting_date' => now()->toDateString(), 'reference' => 'OLD-SUPPLIER-001', 'notes' => 'دين افتتاحي لم يسجل، مثبت بكشف المورد السابق.',
            'evidence' => UploadedFile::fake()->create('statement.pdf', 2, 'application/pdf')], $changes);
    }

    private function draft(array $changes = []): SupplierDebtSettlement
    {
        $this->postJson(route('suppliers.debts.store', $this->supplier), $this->data($changes))->assertOk();

        return SupplierDebtSettlement::latest('id')->firstOrFail();
    }

    private function approval(SupplierDebtSettlement $document): array
    {
        return ['revision' => app(ManageSupplierDebtSettlement::class)->review($document->fresh(), $this->owner)['revision'], 'opening_confirmed' => true, 'unrecorded_confirmed' => true];
    }

    private function approved(): SupplierDebtSettlement
    {
        $document = $this->draft();
        $this->postJson(route('suppliers.debts.approve', $document), $this->approval($document))->assertOk();

        return $document->fresh();
    }

    public function test_invalid_original_date_explains_both_dates_in_arabic_without_writing_a_document(): void
    {
        $tracking = app(ManageSupplierDebtSettlement::class)->cutoff($this->branchId);
        $response = $this->postJson(route('suppliers.debts.store', $this->supplier), $this->data(['original_date' => $tracking]));
        $response->assertUnprocessable()->assertJsonValidationErrors('original_date');
        $message = $response->json('errors.original_date.0');
        $this->assertStringContainsString('تاريخ نشوء الدين', $message);
        $this->assertStringContainsString('بداية تتبع التزامات الموردين', $message);
        $this->assertStringContainsString($tracking, $message);
        $this->assertStringNotContainsString('original date', $message);
        $this->assertStringNotContainsString('tracking started', $message);
        $this->assertSame(0, SupplierDebtSettlement::count());
    }

    public function test_tracking_date_after_actual_branch_evidence_is_explained_on_its_field(): void
    {
        $cutoff = now()->subDays(30)->toDateString();
        DB::table('branches')->where('id', $this->branchId)->update(['created_at' => $cutoff.' 00:00:00']);
        $response = $this->postJson(route('suppliers.debts.store', $this->supplier), $this->data(['tracking_started_on' => now()->subDays(5)->toDateString()]));
        $response->assertUnprocessable()->assertJsonValidationErrors('tracking_started_on');
        $this->assertStringContainsString($cutoff, $response->json('errors.tracking_started_on.0'));
        $this->assertStringContainsString('أول دليل مسجل للفرع', $response->json('errors.tracking_started_on.0'));
        $this->assertSame(0, SupplierDebtSettlement::count());
    }

    public function test_html_validation_retains_values_and_shows_date_guidance_and_field_errors(): void
    {
        $tracking = app(ManageSupplierDebtSettlement::class)->cutoff($this->branchId);
        $url = route('suppliers.debts.create', $this->supplier);
        $this->from($url)->post(route('suppliers.debts.store', $this->supplier), $this->data(['original_date' => $tracking]))
            ->assertRedirect($url);
        $html = $this->get($url);
        file_put_contents(storage_path('framework/testing/validation-debt.html'), $html->getContent());
        $html->assertOk()->assertSee('field-error-original_date')->assertSee('aria-invalid="true"', false)
            ->assertSee('تاريخ تسجيل القيد يمكن أن يكون لاحقًا')->assertSee('5000.00');
    }

    public function test_core_form_screens_include_shared_validation_without_altering_business_data(): void
    {
        $directory = storage_path('framework/testing/validation-pages');
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        foreach (['customers.index', 'suppliers.index', 'purchases.invoices.create', 'finance.expenses.index', 'catalog.attributes.index', 'inventory.index', 'sales.cashier', 'access.users.create', 'access.roles.index', 'branches.create', 'profile.show', 'finance.accounts.index', 'finance.treasury.index', 'finance.chart.index', 'inventory.operation.index'] as $name) {
            $response = $this->get(route($name));
            $response->assertOk()->assertSee('data-validation-errors', false)->assertSee('assets/admin/js/validation.js', false);
            file_put_contents($directory.'/'.$name.'.html', $response->getContent());
        }
        foreach (['settings', 'sections', 'products', 'banners', 'offers', 'zones'] as $type) {
            $response = $this->get(route('store.admin.content.create', ['type' => $type]));
            $response->assertOk()->assertSee('data-validation-errors', false)->assertSee('assets/admin/js/validation.js', false);
            file_put_contents($directory.'/store.admin.'.$type.'.html', $response->getContent());
        }
    }

    private function payment(string $amount = '1500'): array
    {
        return ['client_uuid' => (string) Str::uuid(), 'amount' => $amount, 'account_id' => $this->cash->id, 'payment_date' => now()->toDateString()];
    }

    private function assertMatched(int $expected): void
    {
        $report = app(FinanceReports::class)->supplier($this->supplier->id, $this->branchId);
        $this->assertSame($expected, $report['operational_minor']);
        $this->assertSame($expected, $report['ledger_minor']);
        $this->assertSame(0, $report['difference_minor']);
        $this->assertSame([], $report['settlement_payment_cache_drift']);
        $this->assertFalse(JournalLine::select('journal_entry_id')->groupBy('journal_entry_id')->havingRaw('SUM(debit_minor) <> SUM(credit_minor)')->exists());
    }

    public function test_5000_debt_partial_and_full_payment_replay_and_unchanged_stock(): void
    {
        $inventory = Inventory::all()->toArray();
        $movements = StockMovement::all()->toArray();
        $journals = JournalEntry::count();
        $this->get(route('suppliers.debts.create', $this->supplier))->assertOk()->assertSee('إضافة مديونية سابقة');
        $document = $this->draft();
        $this->assertSame($journals, JournalEntry::count());
        $this->assertMatched(0);
        $this->get(route('suppliers.debts.show', $document))->assertOk()->assertSee('معاينة الأثر قبل الاعتماد');
        $approval = $this->approval($document);
        $this->postJson(route('suppliers.debts.approve', $document), $approval)->assertOk();
        $this->postJson(route('suppliers.debts.approve', $document), $approval)->assertOk();
        $this->assertSame($journals + 1, JournalEntry::count());
        $this->assertMatched(500000);
        $payment = $this->payment();
        $this->postJson(route('suppliers.debts.pay', $document), $payment)->assertOk();
        $this->postJson(route('suppliers.debts.pay', $document), $payment)->assertOk();
        $this->assertMatched(350000);
        $this->assertSame(850000, $this->balance('CASH-TEST'));
        $this->get(route('suppliers.show', $this->supplier))->assertOk()->assertViewHas('opening', '5000.00')->assertViewHas('paid', '1500.00')->assertViewHas('balance', '3500.00')->assertSee($document->number);
        $this->get(route('suppliers.index'))->assertOk()->assertSee('3500.00');
        $this->get(route('search.index', ['type' => 'supplier_payments', 'q' => $document->number]))->assertOk()->assertSee('دفعة مورد');
        $this->get(route('finance.suppliers.show', [$this->supplier, 'branch_id' => $this->branchId]))->assertOk()->assertViewHas('reconciliation', fn ($report): bool => $report['difference_minor'] === 0);
        $this->postJson(route('suppliers.debts.pay', $document), $this->payment('3500'))->assertOk();
        $this->assertMatched(0);
        $this->assertSame(500000, $this->balance('CASH-TEST'));
        $this->assertSame(500000, $document->fresh()->paid_minor);
        $this->assertSame($inventory, Inventory::all()->toArray());
        $this->assertSame($movements, StockMovement::all()->toArray());
        $this->assertDatabaseCount('purchase_invoices', 0);
        $this->assertDatabaseCount('supplier_payments', 2);
        $this->assertSame(1, AuditEvent::where('action', 'supplier_debt.approved')->count());
    }

    public function test_reversal_requires_reversing_payments_and_preserves_history(): void
    {
        $document = $this->approved();
        $this->postJson(route('suppliers.debts.pay', $document), $this->payment())->assertOk();
        $this->postJson(route('suppliers.debts.reverse', $document), ['reason' => 'تصحيح رصيد موثق'])->assertUnprocessable();
        $payment = SupplierPayment::sole();
        $this->postJson(route('suppliers.debts.payment-reverse', $payment), ['reason' => 'عكس سند اختبار'])->assertOk();
        $this->postJson(route('suppliers.debts.payment-reverse', $payment), ['reason' => 'عكس سند اختبار'])->assertOk();
        $this->assertMatched(500000);
        $this->assertSame(1000000, $this->balance('CASH-TEST'));
        $this->postJson(route('suppliers.debts.reverse', $document), ['reason' => 'تصحيح رصيد موثق'])->assertOk();
        $count = JournalEntry::count();
        $this->postJson(route('suppliers.debts.reverse', $document), ['reason' => 'تصحيح رصيد موثق'])->assertOk();
        $this->assertSame($count, JournalEntry::count());
        $this->assertMatched(0);
        $this->assertDatabaseCount('supplier_debt_settlements', 1);
        $this->assertDatabaseCount('supplier_payments', 1);
        $this->assertSame('reversed', $document->fresh()->status);
        $this->assertSame(500000, $document->fresh()->amount_minor);
        $this->assertSame(2, JournalEntry::whereNotNull('reversal_of_id')->count());
        $this->postJson(route('suppliers.debts.pay', $document), $this->payment())->assertConflict();
    }

    public function test_uuid_reference_and_single_active_opening_prevent_duplicate_debt(): void
    {
        $data = $this->data();
        $this->postJson(route('suppliers.debts.store', $this->supplier), $data)->assertOk();
        $this->postJson(route('suppliers.debts.store', $this->supplier), $data)->assertOk();
        $this->assertDatabaseCount('supplier_debt_settlements', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('supplier-debt-evidence'));
        $this->postJson(route('suppliers.debts.store', $this->supplier), array_replace($data, ['amount' => '5001']))->assertConflict();
        $this->postJson(route('suppliers.debts.store', $this->supplier), $this->data())->assertUnprocessable();
        $document = SupplierDebtSettlement::sole();
        $this->postJson(route('suppliers.debts.approve', $document), $this->approval($document))->assertOk();
        $second = $this->draft(['reference' => 'ANOTHER-OLD-REF', 'amount' => '125']);
        $this->postJson(route('suppliers.debts.approve', $second), $this->approval($second))->assertUnprocessable();
        $this->assertMatched(500000);
    }

    public function test_new_debts_closed_periods_and_missing_confirmations_are_rejected(): void
    {
        $this->postJson(route('suppliers.debts.store', $this->supplier), $this->data(['original_date' => now()->toDateString()]))->assertUnprocessable();
        $document = $this->draft();
        $this->postJson(route('suppliers.debts.approve', $document), array_replace($this->approval($document), ['opening_confirmed' => false]))->assertUnprocessable();
        DB::table('branches')->where('id', $this->branchId)->update(['financial_closed_through' => now()->toDateString()]);
        $this->postJson(route('suppliers.debts.approve', $document), $this->approval($document))->assertUnprocessable();
        $this->assertSame('draft', $document->fresh()->status);
        $this->assertMatched(0);
    }

    public function test_existing_invoice_blocks_a_second_recognition(): void
    {
        $document = $this->draft();
        PurchaseInvoice::factory()->create(['supplier_id' => $this->supplier->id, 'branch_id' => $this->branchId, 'status' => 'approved', 'total_minor' => 500000, 'paid_minor' => 0]);
        $this->postJson(route('suppliers.debts.approve', $document), $this->approval($document))->assertUnprocessable();
        $this->assertSame('draft', $document->fresh()->status);
        $this->assertSame(0, JournalEntry::where('event', 'supplier_debt_settlement')->count());
    }

    public function test_unlinked_supplier_ledger_balance_blocks_new_debt(): void
    {
        $document = $this->draft();
        DB::transaction(fn () => app(PostJournalAction::class)->execute($this->supplier, 'test_existing', $this->branchId, $this->owner, now()->toDateString(), [
            ['account' => 'opening_equity', 'amount' => 500000], ['account' => 'supplier_payables', 'amount' => -500000, 'supplier_id' => $this->supplier->id],
        ], 'Existing unlinked legacy proof'));
        $this->postJson(route('suppliers.debts.approve', $document), $this->approval($document))->assertUnprocessable();
        $this->assertSame('draft', $document->fresh()->status);
    }

    public function test_stale_review_and_missing_evidence_are_rejected(): void
    {
        $document = $this->draft();
        $approval = $this->approval($document);
        $this->draft(['reference' => 'SECOND-DRAFT']);
        $this->postJson(route('suppliers.debts.approve', $document), $approval)->assertConflict();
        Storage::disk('local')->delete($document->evidence_path);
        $this->postJson(route('suppliers.debts.approve', $document), $this->approval($document))->assertUnprocessable();
    }

    public function test_overpayment_wrong_branch_account_and_unauthorized_actor_are_rejected(): void
    {
        $document = $this->approved();
        $this->postJson(route('suppliers.debts.pay', $document), $this->payment('5000.01'))->assertUnprocessable();
        $otherAccount = FinancialAccount::factory()->create();
        $this->postJson(route('suppliers.debts.pay', $document), array_replace($this->payment(), ['account_id' => $otherAccount->id]))->assertUnprocessable();
        $this->actingAs(User::factory()->create(['role_id' => SalesAccess::provisionRole('sales_cashier')->id, 'branch_id' => $this->branchId]));
        $this->get(route('suppliers.debts.show', $document))->assertForbidden();
        $this->get(route('suppliers.debts.evidence', $document))->assertForbidden();
        $this->postJson(route('suppliers.debts.pay', $document), $this->payment())->assertForbidden();
        $this->actingAs($this->owner);
        $this->assertMatched(500000);
    }

    public function test_journal_failure_rolls_back_approval_and_payment_completely(): void
    {
        $document = $this->draft();
        $approval = $this->approval($document);
        $entries = JournalEntry::count();
        JournalLine::creating(function (): void {
            throw new \RuntimeException('Injected posting failure');
        });
        try {
            app(ManageSupplierDebtSettlement::class)->approve($document, $approval, $this->owner);
            $this->fail('Expected posting failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected posting failure', $exception->getMessage());
        } finally {
            JournalLine::flushEventListeners();
        }
        $this->assertSame('draft', $document->fresh()->status);
        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame(0, AuditEvent::where('action', 'supplier_debt.approved')->count());
        app(ManageSupplierDebtSettlement::class)->approve($document->fresh(), $approval, $this->owner);
        JournalLine::creating(function (): void {
            throw new \RuntimeException('Injected payment failure');
        });
        try {
            app(RecordSupplierPaymentAction::class)->execute($document->fresh(), $this->payment(), $this->owner->id);
            $this->fail('Expected payment failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected payment failure', $exception->getMessage());
        } finally {
            JournalLine::flushEventListeners();
        }
        $this->assertDatabaseCount('supplier_payments', 0);
        $this->assertSame(0, $document->fresh()->paid_minor);
        $this->assertMatched(500000);
        $this->assertSame(1000000, $this->balance('CASH-TEST'));
    }

    public function test_database_protects_approved_document_from_mutation(): void
    {
        $document = $this->approved();
        try {
            DB::table('supplier_debt_settlements')->where('id', $document->id)->update(['amount_minor' => 999]);
            $this->fail('Approved amount must be immutable.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
        $this->assertSame(500000, $document->fresh()->amount_minor);
        $this->deleteJson(route('suppliers.archive', $this->supplier))->assertUnprocessable();
        $this->get(route('suppliers.debts.evidence', $document))->assertOk()->assertHeader('cache-control', 'no-store, private');
    }

    public function test_branch_scope_applies_to_view_evidence_approval_and_payments(): void
    {
        $document = $this->draft();
        $approval = $this->approval($document);
        $role = $this->owner->role->replicate();
        $role->name = 'scoped_finance_'.Str::random(8);
        $role->save();
        $role->permissions()->sync($this->owner->role->permissions->where('name', '!=', 'sales.all_branches')->pluck('id'));
        $otherBranch = Branch::factory()->create();
        $this->actingAs(User::factory()->create(['role_id' => $role->id, 'branch_id' => $otherBranch->id]));
        $this->get(route('suppliers.debts.show', $document))->assertForbidden();
        $this->get(route('suppliers.debts.evidence', $document))->assertForbidden();
        $this->postJson(route('suppliers.debts.approve', $document), $approval)->assertForbidden();
        $this->postJson(route('suppliers.debts.pay', $document), $this->payment())->assertForbidden();
        $this->get(route('suppliers.show', $this->supplier))->assertOk()->assertDontSee($document->number)->assertViewHas('balance', '0.00');
        $this->actingAs($this->owner);
        $this->assertMatched(0);
    }

    public function test_open_period_posting_date_and_progressive_supplier_readiness(): void
    {
        $document = $this->draft(['tracking_started_on' => now()->subDays(30)->toDateString(), 'posting_date' => now()->subDays(5)->toDateString()]);
        DB::table('branches')->where('id', $this->branchId)->update(['financial_closed_through' => now()->subDays(10)->toDateString()]);
        $this->postJson(route('suppliers.debts.approve', $document), $this->approval($document))->assertOk();
        $this->assertSame(now()->subDays(5)->toDateString(), JournalEntry::where('event', 'supplier_debt_settlement')->sole()->transaction_date->toDateString());
        $branch = Branch::findOrFail($this->branchId);
        $this->assertNotNull($branch->opening_stock_closed_at);
        $readiness = app(SetupReadinessReport::class)->build($branch);
        $this->assertSame(0, collect($readiness['suppliers'])->firstWhere('supplier_id', $this->supplier->id)['difference_minor']);
        $report = app(ProgressiveOperation::class)->report($branch);
        $this->assertTrue(collect($report['parties']['supplier'])->firstWhere('id', $this->supplier->id)['ready']);
        $this->assertMatched(500000);
    }

    public function test_archived_supplier_cannot_gain_hidden_debt_and_closed_period_blocks_payment(): void
    {
        $document = $this->draft();
        $approval = $this->approval($document);
        $this->delete(route('suppliers.archive', $this->supplier))->assertRedirect();
        $this->postJson(route('suppliers.debts.approve', $document), $approval)->assertUnprocessable();
        $this->post(route('suppliers.restore', $this->supplier))->assertRedirect();
        $this->postJson(route('suppliers.debts.approve', $document), $this->approval($document))->assertOk();
        DB::table('branches')->where('id', $this->branchId)->update(['financial_closed_through' => now()->toDateString()]);
        $this->postJson(route('suppliers.debts.pay', $document), $this->payment())->assertUnprocessable();
        $this->assertMatched(500000);
        $this->assertDatabaseCount('supplier_payments', 0);
    }

    public function test_failed_draft_audit_leaves_no_document_or_orphaned_evidence(): void
    {
        $data = $this->data();
        $file = $data['evidence'];
        unset($data['evidence']);
        AuditEvent::creating(function ($event): void {
            if ($event->action === 'supplier_debt.draft') {
                throw new \RuntimeException('Injected evidence transaction failure');
            }
        });
        try {
            app(ManageSupplierDebtSettlement::class)->save($this->supplier, $data, $file, $this->owner);
            $this->fail('Expected audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected evidence transaction failure', $exception->getMessage());
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('supplier_debt_settlements', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('supplier-debt-evidence'));
    }

    public function test_without_attachment_posts_and_pays_5000_in_two_parts_without_stock_changes(): void
    {
        $inventory = Inventory::all()->toArray();
        $movements = StockMovement::all()->toArray();
        $document = $this->draft(['evidence' => null]);
        $this->assertNull($document->evidence_path);
        $this->assertNull($document->evidence_name);
        $this->assertNull($document->evidence_sha256);
        $this->assertFalse($document->hasEvidence());
        $this->get(route('suppliers.debts.show', $document))->assertOk()->assertSee('إرفاق المستند اختياري')->assertDontSee('تنزيل المستند الداعم');
        $this->get(route('suppliers.debts.evidence', $document))->assertNotFound();
        $this->postJson(route('suppliers.debts.approve', $document), $this->approval($document))->assertOk();
        $audit = AuditEvent::where('action', 'supplier_debt.approved')->sole();
        $this->assertFalse($audit->data['evidence_attached']);
        $this->assertNull($audit->data['evidence_sha256']);
        $payment = $this->payment();
        $this->postJson(route('suppliers.debts.pay', $document), $payment)->assertOk();
        $this->postJson(route('suppliers.debts.pay', $document), $payment)->assertOk();
        $this->assertMatched(350000);
        $this->postJson(route('suppliers.debts.pay', $document), $this->payment('3500'))->assertOk();
        $this->assertMatched(0);
        $this->assertSame(500000, $this->balance('CASH-TEST'));
        $this->assertSame($inventory, Inventory::all()->toArray());
        $this->assertSame($movements, StockMovement::all()->toArray());
    }

    public function test_without_attachment_supports_formal_reversal_after_payment(): void
    {
        $document = $this->draft(['evidence' => null]);
        $this->postJson(route('suppliers.debts.approve', $document), $this->approval($document))->assertOk();
        $this->postJson(route('suppliers.debts.pay', $document), $this->payment())->assertOk();
        $this->postJson(route('suppliers.debts.payment-reverse', SupplierPayment::sole()), ['reason' => 'عكس سداد مديونية بلا مرفق'])->assertOk();
        $this->postJson(route('suppliers.debts.reverse', $document), ['reason' => 'عكس المديونية دون حذف تاريخها'])->assertOk();
        $this->assertMatched(0);
        $this->assertNull($document->fresh()->evidence_path);
        $this->assertSame('reversed', $document->fresh()->status);
        $this->assertSame(1000000, $this->balance('CASH-TEST'));
    }

    public function test_attachment_does_not_change_origin_fingerprint_or_allow_duplicate_debt(): void
    {
        $data = $this->data();
        unset($data['evidence']);
        $this->postJson(route('suppliers.debts.store', $this->supplier), $data)->assertOk();
        $this->postJson(route('suppliers.debts.store', $this->supplier), $data + ['evidence' => null])->assertOk();
        $document = SupplierDebtSettlement::sole();
        $expected = OperationContext::hash([$this->supplier->id, $this->branchId, mb_strtolower(trim($data['reference']))]);
        $this->assertSame($expected, $document->origin_fingerprint);
        $this->postJson(route('suppliers.debts.store', $this->supplier), $data + ['evidence' => UploadedFile::fake()->create('proof.pdf', 2, 'application/pdf')])->assertConflict();
        $this->postJson(route('suppliers.debts.store', $this->supplier), $this->data())->assertUnprocessable();
        $this->assertDatabaseCount('supplier_debt_settlements', 1);
        $this->assertSame([], Storage::disk('local')->allFiles('supplier-debt-evidence'));
    }

    public function test_no_attachment_does_not_access_storage_and_financial_confirmation_stays_required(): void
    {
        $manager = Storage::getFacadeRoot();
        Storage::shouldReceive('disk')->never();
        try {
            $document = $this->draft(['evidence' => null]);
            $approval = $this->approval($document);
            $this->postJson(route('suppliers.debts.approve', $document), array_replace($approval, ['opening_confirmed' => false]))->assertUnprocessable();
            $this->postJson(route('suppliers.debts.approve', $document), $approval)->assertOk();
            $this->get(route('suppliers.debts.evidence', $document))->assertNotFound();
        } finally {
            Storage::swap($manager);
        }
    }

    public function test_optional_upload_ui_and_file_validation_preserve_required_debt_fields(): void
    {
        $page = $this->get(route('suppliers.debts.create', $this->supplier))->assertOk()->assertSee('اختياري');
        preg_match('/<input[^>]*name="evidence"[^>]*>/', $page->getContent(), $input);
        $this->assertArrayHasKey(0, $input);
        $this->assertDoesNotMatchRegularExpression('/\brequired\b/', $input[0]);
        foreach (['amount', 'reference', 'original_date', 'notes'] as $field) {
            $data = $this->data(['evidence' => null]);
            unset($data[$field]);
            $this->postJson(route('suppliers.debts.store', $this->supplier), $data)->assertUnprocessable();
        }
        $this->postJson(route('suppliers.debts.store', $this->supplier), $this->data(['evidence' => UploadedFile::fake()->create('bad.txt', 2, 'text/plain')]))->assertUnprocessable();
        $this->assertDatabaseCount('supplier_debt_settlements', 0);
    }
}
