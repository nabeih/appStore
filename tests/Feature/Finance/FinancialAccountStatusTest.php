<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\ChartOfAccounts;
use App\Actions\Finance\FinanceContext;
use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Sales\PostSaleAction;
use App\Actions\Sales\SaveSaleAction;
use App\Models\AuditEvent;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use Database\Factories\BranchFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Sales\SalesTestCase;

class FinancialAccountStatusTest extends SalesTestCase
{
    private function data(bool $active): array
    {
        return ['is_active' => $active, 'confirmed' => true, 'reason' => 'مراجعة تشغيل حساب القبض'];
    }

    private function statusUrl(FinancialAccount $account): string
    {
        return route('finance.accounts.status', $account);
    }

    private function approveOpening(string $amount = '500.00'): JournalEntry
    {
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => $amount, 'reason' => 'رصيد اختبار موثق'], $this->owner);

        return JournalEntry::where('source_id', $this->cash->id)->where('source_type', $this->cash->getMorphClass())->where('event', 'opening')->sole();
    }

    public function test_freeze_and_reactivate_preserve_history_and_balance_and_audit_once_per_change(): void
    {
        $entry = $this->approveOpening();
        $history = [$entry->getAttributes(), $entry->lines()->orderBy('id')->get()->toArray()];
        $this->get(route('finance.accounts.index'))->assertOk()->assertSee('نشط')->assertSee('تجميد الحساب');
        $this->postJson($this->statusUrl($this->cash), $this->data(false))->assertOk();
        $this->assertFalse($this->cash->fresh()->is_active);
        $this->get(route('finance.accounts.index'))->assertOk()->assertSee('مجمّد')->assertSee('تفعيل الحساب');
        $this->postJson($this->statusUrl($this->cash), $this->data(false))->assertOk();
        $this->assertSame(1, AuditEvent::where('action', 'account.frozen')->count());
        $audit = AuditEvent::where('action', 'account.frozen')->sole();
        $this->assertSame($this->owner->id, $audit->user_id);
        $this->assertSame($this->branchId, $audit->branch_id);
        $this->assertSame($this->data(false)['reason'], $audit->reason);
        $this->assertEquals(['before_active' => true, 'after_active' => false], $audit->data);
        $this->postJson($this->statusUrl($this->cash), $this->data(true))->assertOk();
        $this->postJson($this->statusUrl($this->cash), $this->data(true))->assertOk();
        $this->assertTrue($this->cash->fresh()->is_active);
        $this->assertSame(1, AuditEvent::where('action', 'account.activated')->count());
        $this->assertSame(50000, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertSame($history, [$entry->fresh()->getAttributes(), $entry->lines()->orderBy('id')->get()->toArray()]);
        $this->assertDatabaseCount('journal_entries', 1);
    }

    public function test_frozen_account_blocks_posting_and_reversal_atomically_until_reactivation(): void
    {
        $entry = $this->approveOpening();
        $manage = app(ManageFinancialAccountsAction::class);
        $manage->setActive($this->cash, false, 'تجميد للاختبار', $this->owner);
        $post = app(PostJournalAction::class);
        foreach ([
            fn () => $post->execute($this->cash, 'frozen-attempt', $this->branchId, $this->owner, OperationContext::today(),
                [['account' => $this->cash->id, 'amount' => 100], ['account' => 'opening_equity', 'amount' => -100]], 'قيد ممنوع'),
            fn () => $post->reverse($entry, $this->owner, 'محاولة عكس على حساب مجمد', OperationContext::today()),
        ] as $operation) {
            try {
                OperationContext::run($operation);
                $this->fail('Frozen accounts must reject all new postings, including reversals.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('journal_entries', 1);
                $this->assertDatabaseCount('journal_lines', 2);
                $this->assertSame(50000, $post->balance($this->cash->id));
            }
        }
        $manage->setActive($this->cash, true, 'تفعيل للاختبار', $this->owner);
        $reversal = OperationContext::run(fn () => $post->reverse($entry, $this->owner, 'عكس مسموح بعد التفعيل', OperationContext::today()));
        $this->assertSame($entry->id, $reversal->reversal_of_id);
        $this->assertSame(0, $post->balance($this->cash->id));
        $this->assertSame((int) $reversal->lines()->sum('debit_minor'), (int) $reversal->lines()->sum('credit_minor'));
    }

    public function test_zero_opening_cannot_bypass_freeze(): void
    {
        $this->postJson($this->statusUrl($this->cash), $this->data(false))->assertOk();
        $this->postJson(route('finance.accounts.opening', $this->cash), ['opening_balance' => '0.00', 'confirm_opening' => true, 'reason' => 'اعتماد صفر موثق'])
            ->assertUnprocessable()->assertJsonValidationErrors('account');
        $this->assertDatabaseCount('journal_entries', 0);
        $this->postJson($this->statusUrl($this->cash), $this->data(true))->assertOk();
        $this->postJson(route('finance.accounts.opening', $this->cash), ['opening_balance' => '0.00', 'confirm_opening' => true, 'reason' => 'اعتماد صفر موثق'])->assertOk();
        $this->assertDatabaseCount('journal_entries', 1);
        $this->assertDatabaseCount('journal_lines', 0);
    }

    public function test_database_guards_block_frozen_posting_structure_changes_and_system_freezing(): void
    {
        $entry = $this->approveOpening();
        $this->postJson($this->statusUrl($this->cash), $this->data(false))->assertOk();
        foreach ([
            fn () => DB::table('journal_lines')->insert(['journal_entry_id' => $entry->id, 'financial_account_id' => $this->cash->id, 'debit_minor' => 100, 'credit_minor' => 0]),
            fn () => DB::table('financial_accounts')->where('id', $this->cash->id)->update(['code' => 'CHANGED']),
            fn () => DB::table('financial_accounts')->where('code', 'opening_equity')->update(['is_active' => false]),
        ] as $operation) {
            try {
                $operation();
                $this->fail('The MySQL accounting guard must reject this mutation.');
            } catch (QueryException $exception) {
                $this->assertSame('45000', $exception->errorInfo[0]);
            }
        }
        $this->assertSame('CASH-TEST', $this->cash->fresh()->code);
        $this->assertDatabaseCount('journal_lines', 2);
        $this->assertDatabaseHas('financial_accounts', ['code' => 'opening_equity', 'is_active' => true]);
    }

    public function test_cashier_and_other_branch_cannot_change_status(): void
    {
        $cashier = User::factory()->create(['role_id' => SalesAccess::provisionRole('sales_cashier')->id, 'branch_id' => $this->branchId]);
        $this->actingAs($cashier)->postJson($this->statusUrl($this->cash), $this->data(false))->assertForbidden();
        $role = Role::create(['name' => 'branch_account_manager', 'display_name' => 'مدير حسابات فرع', 'is_active' => true]);
        $role->permissions()->sync(Permission::where('name', 'sales.manage_accounts')->pluck('id'));
        $other = BranchFactory::new()->create();
        $manager = User::factory()->create(['role_id' => $role->id, 'branch_id' => $other->id]);
        $this->actingAs($manager)->postJson($this->statusUrl($this->cash), $this->data(false))->assertForbidden();
        $this->assertTrue($this->cash->fresh()->is_active);
        $this->assertSame(0, AuditEvent::where('action', 'account.frozen')->count());
    }

    public function test_confirmation_and_reason_are_required_and_hidden_fields_cannot_change_balance(): void
    {
        $this->postJson($this->statusUrl($this->cash), ['is_active' => false])->assertUnprocessable()->assertJsonValidationErrors(['reason', 'confirmed']);
        $this->postJson($this->statusUrl($this->cash), array_replace($this->data(false), ['is_active' => 'invalid']))->assertUnprocessable();
        $this->approveOpening();
        $this->postJson($this->statusUrl($this->cash), $this->data(false) + ['code' => 'FORGED', 'branch_id' => 9999, 'opening_balance' => '99999'])->assertOk();
        $this->assertSame('CASH-TEST', $this->cash->fresh()->code);
        $this->assertSame($this->branchId, $this->cash->fresh()->branch_id);
        $this->assertSame(50000, app(PostJournalAction::class)->balance($this->cash->id));
    }

    public function test_system_and_aggregate_accounts_cannot_be_frozen(): void
    {
        $system = FinancialAccount::where('code', 'opening_equity')->sole();
        $this->postJson($this->statusUrl($system), $this->data(false))->assertUnprocessable();
        $group = new FinancialAccount(['code' => 'GROUP-TEST', 'name' => 'حساب تجميعي', 'type' => 'cash', 'branch_id' => $this->branchId]);
        $group->forceFill(['is_posting' => false])->save();
        $this->postJson($this->statusUrl($group), $this->data(false))->assertUnprocessable()->assertJsonValidationErrors('account');
        $this->assertTrue($group->fresh()->is_active);
    }

    public function test_audit_failure_rolls_back_status_change(): void
    {
        $this->approveOpening();
        Event::listen('eloquent.creating: '.AuditEvent::class, function (): never {
            throw new \RuntimeException('Simulated audit failure');
        });
        try {
            app(ManageFinancialAccountsAction::class)->setActive($this->cash, false, 'اختبار التراجع', $this->owner);
            $this->fail('Audit failure must roll back the status.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated audit failure', $exception->getMessage());
            $this->assertTrue($this->cash->fresh()->is_active);
            $this->assertSame(50000, app(PostJournalAction::class)->balance($this->cash->id));
            $this->assertSame(0, AuditEvent::where('action', 'account.frozen')->count());
        }
    }

    public function test_shared_payment_resolver_rejects_frozen_account(): void
    {
        $this->postJson($this->statusUrl($this->cash), $this->data(false))->assertOk();
        $this->expectException(ValidationException::class);
        OperationContext::run(fn () => FinanceContext::account($this->cash->id, $this->branchId));
    }

    public function test_pending_sale_rechecks_frozen_payment_account_without_partial_stock_or_financial_effects(): void
    {
        $variant = $this->stock();
        $this->approveOpening('0.00');
        $draft = app(SaveSaleAction::class)->execute($this->saleData($variant), $this->owner);
        $counts = [JournalEntry::count(), DB::table('journal_lines')->count(), DB::table('stock_movements')->count()];
        $inventory = DB::table('inventories')->where('product_variant_id', $variant->id)->first();
        $this->postJson($this->statusUrl($this->cash), $this->data(false))->assertOk();
        try {
            app(PostSaleAction::class)->execute($draft, $this->owner);
            $this->fail('A prepared sale must recheck account availability at approval.');
        } catch (ValidationException) {
            $this->assertSame('draft', $draft->fresh()->status);
            $this->assertEquals($inventory, DB::table('inventories')->where('product_variant_id', $variant->id)->first());
            $this->assertSame($counts, [JournalEntry::count(), DB::table('journal_lines')->count(), DB::table('stock_movements')->count()]);
        }
        $this->postJson($this->statusUrl($this->cash), $this->data(true))->assertOk();
        $approved = app(PostSaleAction::class)->execute($draft, $this->owner);
        $this->assertSame('approved', $approved->status);
        $after = [JournalEntry::count(), DB::table('stock_movements')->count()];
        $this->assertSame($approved->id, app(PostSaleAction::class)->execute($draft, $this->owner)->id);
        $this->assertSame($after, [JournalEntry::count(), DB::table('stock_movements')->count()]);
    }

    public function test_bank_and_wallet_status_changes_keep_opening_balance_and_chart_metadata_editable(): void
    {
        foreach (['bank', 'wallet'] as $type) {
            $account = app(ManageFinancialAccountsAction::class)->execute(['branch_id' => $this->branchId, 'type' => $type, 'name' => 'حساب '.$type,
                'opening_balance' => '500.00', 'confirm_opening' => true, 'reason' => 'رصيد بداية موثق'], $this->owner);
            $this->postJson($this->statusUrl($account), $this->data(false))->assertOk();
            $frozen = $account->fresh();
            app(ChartOfAccounts::class)->save(['code' => $frozen->code, 'name' => 'اسم أوضح', 'account_group' => $frozen->account_group,
                'normal_balance' => $frozen->normal_balance, 'parent_id' => $frozen->parent_id, 'branch_id' => $frozen->branch_id,
                'is_posting' => true, 'is_active' => false, 'reason' => 'توضيح الاسم دون تغيير الحالة'], $this->owner, $frozen);
            $this->assertSame('اسم أوضح', $account->fresh()->name);
            $this->assertFalse($account->fresh()->is_active);
            $this->postJson($this->statusUrl($account), $this->data(true))->assertOk();
            $this->assertSame(50000, app(PostJournalAction::class)->balance($account->id));
        }
    }
}
