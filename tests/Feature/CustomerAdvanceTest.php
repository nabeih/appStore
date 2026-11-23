<?php

namespace Tests\Feature;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Notifications\DispatchAppAlerts;
use App\Actions\Sales\ReverseSalesOperationAction;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\Permission;
use App\Models\User;
use App\Support\Sales\SalesAccess;
use Database\Factories\BranchFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Sales\SalesTestCase;

class CustomerAdvanceTest extends SalesTestCase
{
    private function deposit(): CustomerReceipt
    {
        DB::table('branches')->where('id', $this->branchId)->update(['opening_stock_closed_at' => now()]);
        $data = ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'customer_id' => $this->customer->id,
            'kind' => 'advance', 'amount' => '100', 'payments' => [['account_id' => $this->cash->id, 'amount' => '100']]];
        $this->postJson(route('customers.receipts.store'), $data)->assertOk();
        $this->postJson(route('customers.receipts.store'), $data)->assertOk();

        return CustomerReceipt::where('kind', 'advance')->sole();
    }

    private function refund(CustomerReceipt $advance, string $amount = '100'): array
    {
        return ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'customer_id' => $this->customer->id,
            'kind' => 'refund', 'refund_confirmed' => true, 'credits' => [['advance_receipt_id' => $advance->id, 'amount' => $amount]],
            'payments' => [['account_id' => $this->cash->id, 'amount' => $amount]]];
    }

    public function test_deposit_partial_cashier_usage_refund_and_replay_are_balanced(): void
    {
        $variant = $this->stock();
        $advance = $this->deposit();
        $this->assertSame(10000, $this->balance('CASH-TEST'));
        $this->assertSame(-10000, $this->balance('customer_credit'));
        $this->assertSame(0, $this->balance('sales'));
        $this->get(route('customers.show', [$this->customer, 'branch_id' => $this->branchId]))->assertOk()->assertSee('إيداع رصيد مقدم');
        $credits = [['advance_receipt_id' => $advance->id, 'amount' => '20']];
        $sale = $this->saleData($variant, ['customer_credits' => $credits, 'rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            'payments' => [['account_id' => $this->cash->id, 'amount' => '15']]]);
        $this->postJson(route('sales.preview'), $sale)->assertOk()->assertJsonPath('paid', '35.00')->assertJsonPath('due', '0.00');
        $this->postJson(route('sales.invoices.complete'), $sale)->assertOk();
        $this->postJson(route('sales.invoices.complete'), $sale)->assertOk();
        $this->assertDatabaseCount('sale_invoices', 1);
        $this->assertSame(-8000, $this->balance('customer_credit'));
        $data = $this->refund($advance, '80');
        $this->postJson(route('customers.receipts.store'), $data)->assertOk();
        $this->postJson(route('customers.receipts.store'), $data)->assertOk();
        $this->assertSame(0, $this->balance('customer_credit'));
        $this->assertSame(3500, $this->balance('CASH-TEST'));
        $this->assertSame(1, DB::table('app_alert_events')->where('type', 'customer_refund')->count());
        $this->get(route('customers.receipts.show', CustomerReceipt::where('kind', 'refund')->sole()))->assertOk()->assertSee($advance->number);
        $this->assertBalanced();
    }

    public function test_full_credit_sale_needs_no_new_cash_and_drafts_do_not_consume_credit(): void
    {
        $variant = $this->stock('100');
        $advance = $this->deposit();
        $sale = $this->saleData($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
            'customer_credits' => [['advance_receipt_id' => $advance->id, 'amount' => '100']]]);
        $this->postJson(route('sales.invoices.store'), $sale)->assertOk();
        $this->assertDatabaseCount('credit_usages', 0);
        $this->postJson(route('sales.invoices.complete'), $sale)->assertOk();
        $this->assertSame(10000, $this->balance('CASH-TEST'));
        $this->assertSame(0, $this->balance('customer_credit'));
        $this->postJson(route('customers.receipts.store'), $this->refund($advance))->assertUnprocessable();
        $this->postJson(route('customers.receipts.reverse', $advance), ['client_uuid' => (string) Str::uuid(), 'reason' => 'محاولة إلغاء رصيد مستخدم',
            'funds_confirmed' => true, 'goods_confirmed' => false])->assertUnprocessable();
        $this->assertSame('approved', $advance->fresh()->status);
        $this->assertBalanced();
    }

    public function test_confirmation_overdraw_foreign_customer_and_unauthorized_account_are_rejected(): void
    {
        $advance = $this->deposit();
        $data = $this->refund($advance);
        $this->postJson(route('customers.receipts.store'), array_replace($data, ['refund_confirmed' => false]))->assertUnprocessable();
        $this->postJson(route('customers.receipts.store'), $this->refund($advance, '101'))->assertUnprocessable();
        $this->postJson(route('customers.receipts.store'), array_replace($data, ['customer_id' => Customer::factory()->create()->id]))->assertUnprocessable();
        $other = FinancialAccount::factory()->create(['branch_id' => BranchFactory::new()->create()->id]);
        $this->postJson(route('customers.receipts.store'), array_replace($data, ['payments' => [['account_id' => $other->id, 'amount' => '100']]]))->assertUnprocessable();
        app(ManageFinancialAccountsAction::class)->setActive($this->cash, false, 'تجميد حساب الاختبار', $this->owner);
        $this->postJson(route('customers.receipts.store'), $data)->assertUnprocessable();
        $cashier = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]);
        $this->actingAs($cashier)->postJson(route('customers.receipts.store'), $data)->assertForbidden();
        $this->assertDatabaseCount('customer_receipts', 1);
        $this->assertDatabaseCount('credit_usages', 0);
        $this->assertSame(0, DB::table('app_alert_events')->where('type', 'customer_refund')->count());
    }

    public function test_authorized_employee_refunds_immediately_and_receipt_reversal_restores_credit(): void
    {
        $advance = $this->deposit();
        $employee = User::factory()->create(['branch_id' => $this->branchId, 'role_id' => SalesAccess::provisionRole('sales_cashier')->id]);
        $employee->role->permissions()->syncWithoutDetaching([Permission::where('name', 'sales.return')->sole()->id]);
        $this->actingAs($employee)->postJson(route('customers.receipts.store'), $this->refund($advance))->assertOk();
        $refund = CustomerReceipt::where('kind', 'refund')->sole();
        app(DispatchAppAlerts::class)->pending();
        $this->actingAs($this->owner)->getJson(route('alerts.index'))->assertOk()->assertJsonPath('unread', 1)
            ->assertJsonPath('data.0.url', route('customers.receipts.show', $refund));
        $this->actingAs($employee)->getJson(route('alerts.index'))->assertOk()->assertJsonPath('unread', 0);
        app(ReverseSalesOperationAction::class)->receipt($refund, ['reason' => 'إلغاء صرف موثق', 'funds_confirmed' => true, 'client_uuid' => (string) Str::uuid()], $this->owner);
        $this->assertSame(-10000, $this->balance('customer_credit'));
        app(ReverseSalesOperationAction::class)->receipt($advance, ['reason' => 'إلغاء إيداع موثق', 'funds_confirmed' => true, 'client_uuid' => (string) Str::uuid()], $this->owner);
        $this->assertSame(0, $this->balance('customer_credit'));
        $this->assertSame(0, $this->balance('CASH-TEST'));
        $this->assertBalanced();
    }

    public function test_posting_failure_rolls_back_refund_usage_cash_and_alert(): void
    {
        $advance = $this->deposit();
        $this->partialMock(PostJournalAction::class, function ($mock): void {
            $mock->shouldReceive('execute')->once()->andThrow(ValidationException::withMessages(['journal' => 'فشل مصطنع']));
        });
        $this->postJson(route('customers.receipts.store'), $this->refund($advance))->assertUnprocessable();
        $this->assertDatabaseCount('customer_receipts', 1);
        $this->assertDatabaseCount('credit_usages', 0);
        $this->assertSame(-10000, $this->balance('customer_credit'));
        $this->assertSame(0, DB::table('app_alert_events')->where('type', 'customer_refund')->count());
    }

    public function test_cancelling_credit_funded_sale_refunds_once_without_releasing_the_spent_advance_twice(): void
    {
        $variant = $this->stock();
        $advance = $this->deposit();
        $invoice = $this->sale($variant, ['rows' => [['product_variant_id' => $variant->id, 'quantity' => 1]], 'payments' => [],
            'customer_credits' => [['advance_receipt_id' => $advance->id, 'amount' => '35']]]);
        $data = ['client_uuid' => (string) Str::uuid(), 'reason' => 'إلغاء بيع وإعادة البضاعة فعليًا', 'goods_confirmed' => true, 'funds_confirmed' => true,
            'refund_payments' => [['account_id' => $this->cash->id, 'amount' => '35']]];
        $action = app(ReverseSalesOperationAction::class);
        $action->sale($invoice, $data, $this->owner);
        $action->sale($invoice, $data, $this->owner);
        $this->assertDatabaseCount('sale_returns', 1);
        $this->assertSame(6500, $this->balance('CASH-TEST'));
        $this->assertSame(-6500, $this->balance('customer_credit'));
        $this->assertSame(0, $this->balance('sales') + $this->balance('sales_returns'));
        $this->assertSame(0, $this->balance('cogs'));
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $variant->id, 'quantity' => 10, 'value_minor' => 20000]);
        $this->getJson(route('customers.credits', [$this->customer, 'branch_id' => $this->branchId]))->assertOk()->assertJsonPath('data.0.available', '65.00');
        $this->assertBalanced();
    }

    private function assertBalanced(): void
    {
        foreach (JournalEntry::with('lines')->get() as $journal) {
            $this->assertSame((int) $journal->lines->sum('debit_minor'), (int) $journal->lines->sum('credit_minor'));
        }

    }
}
