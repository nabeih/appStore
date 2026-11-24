<?php

namespace Tests\Feature;

use App\Actions\Finance\LegacyFinanceTransitionAction;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Finance\ReconcileOpeningCorrections;
use App\Actions\Inventory\OpeningStock\OpeningFinancialSupport;
use App\Actions\Inventory\OpeningStock\PostOpeningStockAction;
use App\Actions\Inventory\ProgressiveOperation;
use App\Actions\Inventory\ProgressiveStockProof;
use App\Actions\Inventory\Setup\CorrectInventoryAction;
use App\Actions\Inventory\SetupReadinessReport;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Color;
use App\Models\Inventory;
use App\Models\InventorySetupChange;
use App\Models\JournalEntry;
use App\Models\OpeningStockEntry;
use App\Models\SaleInvoice;
use App\Models\Size;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Inventory\Setup\InventoryState;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Sales\SalesTestCase;

class OpeningReconciliationTest extends SalesTestCase
{
    protected bool $salesFixtureStarted = false;

    public function refreshDatabase(): void
    {
        $connection = DB::connection();
        if (! app()->environment('testing') || app()->configurationIsCached() || $connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== 'appstore_account_status_20261118_tests' || $connection->getConfig('host') !== '127.0.0.1'
            || $connection->getConfig('url') || ! RefreshDatabaseState::$migrated) {
            throw new \LogicException('Only the guarded, already migrated isolated MySQL sandbox is allowed.');
        }
        parent::refreshDatabase();
    }

    private function fixture(bool $missing = true, bool $delete = true): array
    {
        config(['finance.legacy_transition_enabled' => true, 'inventory.allow_setup_changes' => true]);
        $category = Category::create(['name' => 'أصناف التسوية الاختبارية']);
        $color = Color::create(['name' => 'لون الاختبار', 'code' => 'REC']);
        $size = Size::create(['name' => 'مقاس الاختبار', 'code' => 'M']);
        $rows = [];
        foreach (['RECON-A', 'RECON-B'] as $code) {
            $rows[] = ['name' => $code, 'code' => $code, 'category_id' => $category->id, 'color_id' => $color->id, 'size_id' => $size->id,
                'qty' => 3, 'cost' => '100.00', 'price' => '150.00'];
        }
        $entry = app(PostOpeningStockAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId,
            'opening_date' => OperationContext::today(), 'rows' => $rows], $this->owner->id);
        $stock = Inventory::orderBy('id')->firstOrFail();
        $data = $this->correction($stock, ['quantity' => 1]);
        if ($missing) {
            $this->mock(PostJournalAction::class)->shouldReceive('execute')->once()->andReturn(new JournalEntry);
        }
        if ($delete) {
            app(CorrectInventoryAction::class)->delete($stock, $data, $this->owner->id);
        } else {
            app(CorrectInventoryAction::class)->update($stock, $data, $this->owner->id);
        }
        $this->app->forgetInstance(PostJournalAction::class);

        return [$entry->fresh(), InventorySetupChange::sole()];
    }

    private function correction(Inventory $stock, array $changes = []): array
    {
        $stock->refresh()->load('productVariant.product');
        $variant = $stock->productVariant;
        $product = $variant->product;

        return array_replace(['revision' => InventoryState::revision($stock, $variant, $product), 'name' => $product->name, 'code' => $product->code,
            'category_id' => $product->category_id, 'color_id' => $variant->color_id, 'size_id' => $variant->size_id, 'quantity' => $stock->quantity,
            'average_cost' => $stock->average_cost, 'selling_price' => $variant->selling_price, 'reason' => 'تصحيح اصطناعي موثق أثناء التجهيز'], $changes);
    }

    private function request(InventorySetupChange $change, array $changes = []): array
    {
        return array_replace(['change_id' => $change->id, 'classification' => 'entry_error', 'posting_date' => OperationContext::today(),
            'evidence' => 'مرجع مستند إثبات اصطناعي', 'reason' => 'اعتماد معالجة اختبارية دون تغيير المخزون', 'operation_uuid' => (string) Str::uuid(), 'confirmed' => true], $changes);
    }

    private function apply(OpeningStockEntry $entry, array $data): JournalEntry
    {
        $action = app(ReconcileOpeningCorrections::class);
        $plan = $action->preview($entry, $data, $this->owner);

        return $action->execute($entry, $data + ['revision' => $plan['revision']], $this->owner);
    }

    private function revenue(): JournalEntry
    {
        return OperationContext::run(function (): JournalEntry {
            $source = AuditEvent::create(['subject_type' => Branch::class, 'subject_id' => $this->branchId, 'branch_id' => $this->branchId,
                'user_id' => $this->owner->id, 'action' => 'fixture.external_revenue', 'reason' => 'إثبات إيراد اصطناعي مستقل']);

            return app(PostJournalAction::class)->execute($source, 'external_revenue', $this->branchId, $this->owner, OperationContext::today(),
                [['account' => $this->cash->id, 'amount' => 45000], ['account' => 'sales', 'amount' => -45000]], 'إيراد بيع خارج التطبيق - اختبار');
        });
    }

    private function assertBalanced(): void
    {
        foreach (JournalEntry::with('lines')->get() as $journal) {
            $this->assertSame($journal->lines->sum('debit_minor'), $journal->lines->sum('credit_minor'));
        }
        $proof = app(ProgressiveStockProof::class)->build($this->branchId);
        $this->assertSame(0, $proof['unexplained_financial_minor']);
        $this->assertTrue(collect($proof['rows'])->every('ready', true));
        $this->assertSame((int) Inventory::sum('value_minor'), $this->balance('inventory'));
        $this->assertSame((int) Inventory::sum('value_minor'), (int) StockMovement::sum('value_change_minor'));
    }

    private function markAsHistoricalTransition(OpeningStockEntry $entry, int $minor): JournalEntry
    {
        $journal = JournalEntry::where('event', 'opening')->with('lines.account')->sole();
        foreach ($journal->lines as $line) {
            $line->update($line->account->code === 'inventory'
                ? ['debit_minor' => $minor, 'credit_minor' => 0]
                : ['debit_minor' => 0, 'credit_minor' => $minor]);
        }
        $entry->update(['opening_date' => now()->subDay()->toDateString()]);
        AuditEvent::create(['subject_type' => $entry->getMorphClass(), 'subject_id' => $entry->id, 'branch_id' => $entry->branch_id,
            'user_id' => $this->owner->id, 'action' => 'finance.legacy_posted', 'reason' => 'ØªØ±Ø­ÙŠÙ„ ØªØ§Ø±ÙŠØ®ÙŠ Ø§ØµØ·Ù†Ø§Ø¹ÙŠ Ù…ÙˆØ«Ù‚', 'data' => [
                'journal_id' => $journal->id, 'request_hash' => str_repeat('a', 64), 'preview' => [
                    'source_kind' => 'opening', 'source_id' => $entry->id, 'branch_id' => $entry->branch_id,
                    'posting_date' => $journal->transaction_date->toDateString(),
                    'lines' => [['account' => 'inventory', 'amount' => $minor], ['account' => 'opening_equity', 'amount' => -$minor]],
                ],
            ]]);

        return $journal->fresh('lines.account');
    }

    public function test_legacy_metadata_corrections_without_value_minor_are_non_financial(): void
    {
        [$entry] = $this->fixture(false, false);
        $item = $entry->items()->orderBy('id')->firstOrFail();
        $state = ['quantity' => $item->quantity, 'average_cost' => $item->unit_cost, 'product_code' => 'OLD-CODE'];
        foreach ([1, 2, 3] as $index) {
            InventorySetupChange::create(['inventory_id' => Inventory::where('product_variant_id', $item->product_variant_id)->sole()->id,
                'branch_id' => $entry->branch_id, 'product_variant_id' => $item->product_variant_id, 'opening_stock_entry_id' => $entry->id,
                'opening_stock_item_id' => $item->id, 'user_id' => $this->owner->id, 'operation' => 'updated',
                'reason' => 'ØªØ¹Ø¯ÙŠÙ„ ØªØ¹Ø±ÙŠÙÙŠ ØªØ§Ø±ÙŠØ®ÙŠ '.$index, 'before_state' => $state,
                'after_state' => array_replace($state, ['product_code' => 'OLD-CODE-'.$index])]);
        }
        $review = app(OpeningFinancialSupport::class)->review($entry->fresh());
        $this->assertSame(3, collect($review['changes'])->where('status', 'non_financial')->count());
        $this->assertSame(0, JournalEntry::where('event', 'opening_reconciliation')->count());
    }

    public function test_read_only_command_scans_every_opening_document_and_separates_human_review(): void
    {
        $this->fixture();
        $this->artisan('app:opening-corrections-reconcile', ['--actor' => $this->owner->id, '--branch' => $this->branchId, '--all' => true])
            ->expectsOutputToContain('requires_human_review')->assertSuccessful();
    }

    public function test_repeated_future_product_corrections_keep_document_stock_and_journals_aligned(): void
    {
        [$entry] = $this->fixture(false, false);
        $stock = Inventory::orderBy('id')->firstOrFail();
        $stock = app(CorrectInventoryAction::class)->update($stock, $this->correction($stock, ['name' => 'RECON-NAME-1']), $this->owner->id);
        $stock = app(CorrectInventoryAction::class)->update($stock, $this->correction($stock, ['code' => 'RECON-RENAMED']), $this->owner->id);
        $stock = app(CorrectInventoryAction::class)->update($stock, $this->correction($stock, ['selling_price' => '175.00']), $this->owner->id);
        $stock = app(CorrectInventoryAction::class)->update($stock, $this->correction($stock, ['average_cost' => '90.00']), $this->owner->id);
        $stock = app(CorrectInventoryAction::class)->update($stock, $this->correction($stock, ['quantity' => 2]), $this->owner->id);
        app(CorrectInventoryAction::class)->delete($stock, $this->correction($stock), $this->owner->id);
        app(OpeningFinancialSupport::class)->assertValid($entry->fresh());
        $this->assertBalanced();
        $this->assertSame(0, JournalEntry::where('event', 'opening_reconciliation')->count());
    }

    public function test_correction_included_in_documented_historical_transition_cannot_post_a_duplicate_journal(): void
    {
        [$entry, $change] = $this->fixture();
        DB::table('inventory_setup_changes')->where('id', $change->id)->update(['created_at' => now()->subMinute()]);
        $journal = $this->markAsHistoricalTransition($entry->fresh(), Money::minor($entry->fresh()->total_cost));
        $review = app(OpeningFinancialSupport::class)->review($entry->fresh());
        $this->assertSame('included_in_transition', $review['changes'][0]['status']);
        $plan = app(ReconcileOpeningCorrections::class)->preview($entry->fresh(), $this->request($change->fresh()), $this->owner);
        $this->assertSame('already_posted', $plan['status']);
        $this->assertSame($journal->id, $this->apply($entry->fresh(), $this->request($change->fresh()))->id);
        $this->assertSame(0, JournalEntry::where('event', 'opening_reconciliation')->count());
        $this->assertBalanced();
    }

    public function test_post_transition_missing_correction_requires_one_independent_settlement(): void
    {
        [$entry, $change] = $this->fixture();
        DB::table('inventory_setup_changes')->where('id', $change->id)->update(['created_at' => now()->subMinute()]);
        $this->markAsHistoricalTransition($entry->fresh(), Money::minor($entry->fresh()->total_cost));
        $stock = Inventory::sole();
        $this->mock(PostJournalAction::class)->shouldReceive('execute')->once()->andReturn(new JournalEntry);
        app(CorrectInventoryAction::class)->update($stock, $this->correction($stock, ['average_cost' => '90.00']), $this->owner->id);
        $this->app->forgetInstance(PostJournalAction::class);
        $later = InventorySetupChange::latest('id')->firstOrFail();
        $plan = app(ReconcileOpeningCorrections::class)->preview($entry->fresh(), $this->request($later), $this->owner);
        $this->assertSame('eligible', $plan['status']);
        $this->apply($entry->fresh(), $this->request($later));
        $this->assertSame(1, JournalEntry::where('event', 'opening_reconciliation')->count());
    }

    public function test_conflicting_historical_transition_evidence_remains_blocked(): void
    {
        [$entry, $change] = $this->fixture();
        DB::table('inventory_setup_changes')->where('id', $change->id)->update(['created_at' => now()->subMinute()]);
        $this->markAsHistoricalTransition($entry->fresh(), 99999);
        $this->expectException(ValidationException::class);
        app(OpeningFinancialSupport::class)->review($entry->fresh(), false, true);
    }

    public function test_missing_600_to_300_correction_is_blocked_then_settled_without_changing_stock_or_original_journal(): void
    {
        [$entry, $change] = $this->fixture();
        $original = JournalEntry::where('event', 'opening')->with('lines')->sole()->toArray();
        $stock = Inventory::sole()->getAttributes();
        $moves = StockMovement::get()->toArray();
        $this->assertSame(60000, $original['lines'][0]['debit_minor']);
        $this->assertFalse(collect(app(ProgressiveStockProof::class)->build($this->branchId)['rows'])->every('ready', true));
        $data = $this->request($change);
        $plan = app(ReconcileOpeningCorrections::class)->preview($entry, $data, $this->owner);
        $this->assertSame(-30000, $plan['delta_minor']);
        $this->assertSame(60000, $plan['original_minor']);
        $journal = $this->apply($entry, $data);
        $this->assertSame('opening_reconciliation', $journal->event);
        $this->assertSame($original, JournalEntry::where('event', 'opening')->with('lines')->sole()->toArray());
        $this->assertSame($stock, Inventory::sole()->getAttributes());
        $this->assertSame($moves, StockMovement::get()->toArray());
        $this->assertSame(-30000, $this->balance('opening_equity'));
        $this->assertBalanced();
    }

    public function test_external_sale_requires_revenue_and_records_cost_without_a_second_stock_exit_or_fake_invoice(): void
    {
        [$entry, $change] = $this->fixture();
        $data = $this->request($change, ['classification' => 'external_sale']);
        try {
            app(ReconcileOpeningCorrections::class)->preview($entry, $data, $this->owner);
            $this->fail('Unproven revenue must block the treatment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('revenue_journal_id', $exception->errors());
        }
        $revenue = $this->revenue();
        $before = [Inventory::sum('quantity'), Inventory::sum('value_minor'), StockMovement::count(), app(PostJournalAction::class)->balance($this->cash->id)];
        $this->apply($entry, $data + ['revenue_journal_id' => $revenue->id]);
        $this->assertSame($before, [Inventory::sum('quantity'), Inventory::sum('value_minor'), StockMovement::count(), app(PostJournalAction::class)->balance($this->cash->id)]);
        $this->assertSame(30000, $this->balance('cogs'));
        $this->assertSame(-60000, $this->balance('opening_equity'));
        $this->assertSame(0, SaleInvoice::count());
        $this->assertBalanced();
    }

    public function test_equity_correction_can_be_reclassified_without_touching_its_original_journal(): void
    {
        [$entry, $change] = $this->fixture(false);
        $original = JournalEntry::where('event', 'opening_correction')->with('lines')->sole()->toArray();
        $revenue = $this->revenue();
        $this->apply($entry, $this->request($change, ['classification' => 'external_sale', 'revenue_journal_id' => $revenue->id]));
        $this->assertSame($original, JournalEntry::where('event', 'opening_correction')->with('lines')->sole()->toArray());
        $this->assertSame(30000, $this->balance('cogs'));
        $this->assertSame(-60000, $this->balance('opening_equity'));
        $this->assertBalanced();
    }

    public function test_cost_correction_and_subsequent_correction_preserve_the_complete_chain(): void
    {
        [$entry, $change] = $this->fixture();
        $this->apply($entry, $this->request($change));
        $stock = Inventory::sole();
        app(CorrectInventoryAction::class)->update($stock, $this->correction($stock, ['average_cost' => '80.00']), $this->owner->id);
        app(OpeningFinancialSupport::class)->assertValid($entry->fresh());
        $this->assertSame(24000, $this->balance('inventory'));
        app(CorrectInventoryAction::class)->update($stock->fresh(), $this->correction($stock->fresh(), ['average_cost' => '90.00']), $this->owner->id);
        $this->assertSame(27000, $this->balance('inventory'));
        $this->assertSame(3, InventorySetupChange::count());
        $this->assertBalanced();
        $plan = app(LegacyFinanceTransitionAction::class)->preview(['branch_id' => $this->branchId, 'source_kind' => 'opening', 'source_id' => $entry->id,
            'posting_date' => $entry->opening_date->toDateString(), 'evidence' => 'مراجعة أصل القيد وتسوياته'], $this->owner);
        $this->assertSame('already_posted', $plan['status']);
    }

    public function test_replay_and_new_operation_cannot_duplicate_an_existing_settlement(): void
    {
        [$entry, $change] = $this->fixture();
        $action = app(ReconcileOpeningCorrections::class);
        $data = $this->request($change);
        $data['revision'] = $action->preview($entry, $data, $this->owner)['revision'];
        $first = $action->execute($entry, $data, $this->owner);
        $this->assertSame($first->id, $action->execute($entry, $data, $this->owner)->id);
        $data['operation_uuid'] = (string) Str::uuid();
        $this->assertSame($first->id, $action->execute($entry, $data, $this->owner)->id);
        $this->assertSame(1, JournalEntry::where('event', 'opening_reconciliation')->count());
        $this->assertSame(1, AuditEvent::where('action', 'finance.opening_reconciled')->count());
        $this->assertBalanced();
    }

    public function test_journal_failure_and_audit_failure_roll_back_every_settlement_effect(): void
    {
        [$entry, $change] = $this->fixture();
        $data = $this->request($change);
        $data['revision'] = app(ReconcileOpeningCorrections::class)->preview($entry, $data, $this->owner)['revision'];
        $before = [JournalEntry::count(), AuditEvent::count(), Inventory::sum('value_minor'), StockMovement::count()];
        AuditEvent::creating(function ($audit): void {
            if ($audit->action === 'finance.opening_reconciled') {
                throw new RuntimeException('Synthetic audit failure');
            }
        });
        try {
            app(ReconcileOpeningCorrections::class)->execute($entry, $data, $this->owner);
            $this->fail('Expected failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertSame($before, [JournalEntry::count(), AuditEvent::count(), Inventory::sum('value_minor'), StockMovement::count()]);
        $this->mock(PostJournalAction::class)->shouldReceive('execute')->once()->andThrow(new RuntimeException('Synthetic journal failure'));
        try {
            app(ReconcileOpeningCorrections::class)->execute($entry, $data, $this->owner);
            $this->fail('Expected failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic journal failure', $exception->getMessage());
        }
        $this->assertSame($before, [JournalEntry::count(), AuditEvent::count(), Inventory::sum('value_minor'), StockMovement::count()]);
    }

    public function test_missing_cost_adjustment_requires_equity_treatment_and_legacy_preview_uses_effective_journals(): void
    {
        [$entry] = $this->fixture(false);
        $stock = Inventory::sole();
        $this->mock(PostJournalAction::class)->shouldReceive('execute')->once()->andReturn(new JournalEntry);
        app(CorrectInventoryAction::class)->update($stock, $this->correction($stock, ['average_cost' => '50.01']), $this->owner->id);
        $this->app->forgetInstance(PostJournalAction::class);
        $change = InventorySetupChange::latest('id')->firstOrFail();
        $legacyData = ['branch_id' => $this->branchId, 'source_kind' => 'opening', 'source_id' => $entry->id,
            'posting_date' => $entry->opening_date->toDateString(), 'evidence' => 'معاينة أصل القيد وتسوياته'];
        $this->assertSame('rejected', app(LegacyFinanceTransitionAction::class)->preview($legacyData, $this->owner)['status']);
        try {
            app(ReconcileOpeningCorrections::class)->preview($entry->fresh(), $this->request($change, ['classification' => 'external_sale']), $this->owner);
            $this->fail('Cost editing is not a physical sale.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('classification', $exception->errors());
        }
        $this->apply($entry->fresh(), $this->request($change));
        $this->assertSame(15003, $this->balance('inventory'));
        $this->assertSame(0, $this->balance('cogs'));
        $plan = app(LegacyFinanceTransitionAction::class)->preview($legacyData, $this->owner);
        $this->assertSame('already_posted', $plan['status']);
        $this->assertSame(15003, collect($plan['lines'])->firstWhere('account', 'inventory')['amount']);
        $this->assertBalanced();
    }

    public function test_incomplete_evidence_and_reversed_original_remain_blocked_without_automatic_repairs(): void
    {
        [$entry, $change] = $this->fixture();
        $attributes = $change->before_state;
        $change->update(['before_state' => array_replace($attributes, ['value_minor' => 1])]);
        try {
            app(ReconcileOpeningCorrections::class)->preview($entry, $this->request($change), $this->owner);
            $this->fail('Invalid historical evidence must not be guessed.');
        } catch (ValidationException) {
            $this->assertSame(0, JournalEntry::where('event', 'opening_reconciliation')->count());
        }
        $change->update(['before_state' => $attributes]);
        OperationContext::run(fn () => app(PostJournalAction::class)->reverse(JournalEntry::where('event', 'opening')->sole(), $this->owner, 'عكس اصطناعي لاختبار منع المعالجة', OperationContext::today()));
        $this->expectException(ValidationException::class);
        app(ReconcileOpeningCorrections::class)->preview($entry, $this->request($change->fresh()), $this->owner);
    }

    public function test_cli_inspection_is_read_only_and_execution_requires_explicit_enablement(): void
    {
        [$entry, $change] = $this->fixture();
        $before = [JournalEntry::count(), AuditEvent::count(), Inventory::sum('quantity'), Inventory::sum('value_minor'), StockMovement::count()];
        config(['finance.legacy_transition_enabled' => false]);
        $this->artisan('app:opening-corrections-reconcile', ['--actor' => $this->owner->id, '--branch' => $this->branchId, '--document' => $entry->id])
            ->expectsOutputToContain('read_only')->assertSuccessful();
        $this->assertSame($before, [JournalEntry::count(), AuditEvent::count(), Inventory::sum('quantity'), Inventory::sum('value_minor'), StockMovement::count()]);
        try {
            $this->apply($entry, $this->request($change));
            $this->fail('Explicit enablement is required.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($before, [JournalEntry::count(), AuditEvent::count(), Inventory::sum('quantity'), Inventory::sum('value_minor'), StockMovement::count()]);
    }

    public function test_external_sale_cannot_be_sent_to_the_input_error_correction_path(): void
    {
        config(['inventory.allow_setup_changes' => true]);
        $this->stock('150', '100', 6, 'GUARDED');
        $stock = Inventory::sole();
        $this->expectException(ValidationException::class);
        app(CorrectInventoryAction::class)->update($stock, $this->correction($stock, ['classification' => 'external_sale', 'quantity' => 3]), $this->owner->id);
    }

    public function test_receipt_can_be_replayed_after_the_period_closes_without_posting_again(): void
    {
        [$entry, $change] = $this->fixture();
        $data = $this->request($change);
        $data['revision'] = app(ReconcileOpeningCorrections::class)->preview($entry, $data, $this->owner)['revision'];
        $journal = app(ReconcileOpeningCorrections::class)->execute($entry, $data, $this->owner);
        Branch::whereKey($this->branchId)->update(['financial_closed_through' => OperationContext::today()]);
        $this->travelTo(now()->addDay());
        try {
            $this->assertSame($journal->id, app(ReconcileOpeningCorrections::class)->execute($entry, $data, $this->owner)->id);
            $this->assertSame(1, JournalEntry::where('event', 'opening_reconciliation')->count());
        } finally {
            $this->travelBack();
        }
    }

    public function test_settlement_after_full_go_live_does_not_reopen_setup_or_change_stock(): void
    {
        [$entry, $change] = $this->fixture();
        Branch::whereKey($this->branchId)->update(['opening_stock_closed_at' => now(), 'opening_stock_closed_by' => $this->owner->id]);
        $branch = Branch::findOrFail($this->branchId)->getAttributes();
        $variantId = Inventory::sole()->product_variant_id;
        try {
            app(ProgressiveOperation::class)->assertStocks($this->branchId, [$variantId]);
            $this->fail('Full mode must not bypass an unresolved corrected opening.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $this->apply($entry, $this->request($change));
        app(ProgressiveOperation::class)->assertStocks($this->branchId, [$variantId]);
        $this->assertSame($branch, Branch::findOrFail($this->branchId)->getAttributes());
        $this->assertBalanced();
    }

    public function test_fully_deleted_opening_is_still_verified_even_when_no_stock_rows_remain(): void
    {
        [$entry, $change] = $this->fixture();
        $this->apply($entry, $this->request($change));
        $stock = Inventory::sole();
        $this->mock(PostJournalAction::class)->shouldReceive('execute')->once()->andReturn(new JournalEntry);
        app(CorrectInventoryAction::class)->delete($stock, $this->correction($stock), $this->owner->id);
        $this->app->forgetInstance(PostJournalAction::class);
        $second = InventorySetupChange::latest('id')->firstOrFail();
        $this->assertSame('cancelled', $entry->fresh()->status);
        $proof = app(ProgressiveStockProof::class)->build($this->branchId);
        $this->assertContains($entry->id, $proof['unverified_opening_documents']);
        $this->apply($entry->fresh(), $this->request($second));
        $this->assertSame(0, Inventory::count());
        $this->assertBalanced();
        $audit = AuditEvent::where('action', 'finance.opening_reconciled')->latest('id')->firstOrFail();
        $audit->update(['data' => array_replace($audit->data, ['evidence' => ''])]);
        $proof = app(ProgressiveStockProof::class)->build($this->branchId);
        $this->assertSame(0, $proof['unexplained_financial_minor']);
        $this->assertContains($entry->id, $proof['unverified_opening_documents']);
        $report = app(SetupReadinessReport::class)->build(Branch::findOrFail($this->branchId));
        $this->assertTrue(collect($report['blockers'])->contains('code', 'opening_corrections_unsettled'));
    }

    public function test_stale_preview_missing_permission_and_closed_period_are_blocked(): void
    {
        [$entry, $change] = $this->fixture();
        $data = $this->request($change);
        $data['revision'] = str_repeat('0', 64);
        try {
            app(ReconcileOpeningCorrections::class)->execute($entry, $data, $this->owner);
            $this->fail('Expected stale revision.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $cashier = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]);
        try {
            app(ReconcileOpeningCorrections::class)->preview($entry, $data, $cashier);
            $this->fail('Expected denied permission.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        Branch::whereKey($this->branchId)->update(['financial_closed_through' => OperationContext::today()]);
        $this->expectException(ValidationException::class);
        app(ReconcileOpeningCorrections::class)->preview($entry, $data, $this->owner);
    }
}
