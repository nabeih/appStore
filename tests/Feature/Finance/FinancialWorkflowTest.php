<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\Expenses\ManageExpenseAction;
use App\Actions\Finance\FinanceReports;
use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Finance\ReversePurchaseAction;
use App\Actions\Finance\ReverseSupplierPaymentAction;
use App\Actions\Purchases\ApprovePurchaseInvoiceAction;
use App\Actions\Purchases\RecordSupplierPaymentAction;
use App\Actions\Purchases\SavePurchaseInvoiceAction;
use App\Models\Expense;
use App\Models\Permission;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Sales\SalesTestCase;

class FinancialWorkflowTest extends SalesTestCase
{
    private function expense(): Expense
    {
        return app(ManageExpenseAction::class)->save(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'title' => 'مصروف واجهة', 'category' => 'other',
            'amount' => '50', 'period_start' => OperationContext::today(), 'period_end' => OperationContext::today(), 'transaction_date' => OperationContext::today()], $this->owner);
    }

    public function test_financial_screens_render_and_http_expense_workflow_posts_only_on_approval(): void
    {
        foreach (['finance.expenses.index', 'finance.treasury.index', 'finance.transition.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $expense = $this->expense();
        $this->get(route('finance.expenses.show', $expense))->assertOk()->assertSee('مصروف واجهة');
        $this->assertSame(0, $this->balance('operating_expenses'));
        $this->postJson(route('finance.expenses.approve', $expense))->assertOk();
        $this->assertSame(5000, $this->balance('operating_expenses'));
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '100', 'reason' => 'رصيد موثق'], $this->owner);
        $data = ['client_uuid' => (string) Str::uuid(), 'account_id' => $this->cash->id, 'amount' => '20', 'reason' => 'صرف موثق'];
        $this->postJson(route('finance.expenses.pay', $expense), $data)->assertOk();
        $this->postJson(route('finance.expenses.pay', $expense), $data)->assertOk();
        $this->get(route('finance.expenses.show', $expense))->assertOk()->assertSee('30.00');
        $this->assertSame(8000, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertSame(-3000, $this->balance('expense_payables'));
    }

    public function test_cashier_and_foreign_branch_cannot_read_or_post_financial_documents(): void
    {
        $expense = $this->expense();
        $cashier = User::factory()->create(['role_id' => SalesAccess::provisionRole('sales_cashier')->id, 'branch_id' => $this->branchId]);
        $this->actingAs($cashier);
        foreach (['finance.expenses.index', 'finance.treasury.index', 'finance.transition.index'] as $route) {
            $this->get(route($route))->assertForbidden();
        }
        $this->postJson(route('finance.expenses.approve', $expense))->assertForbidden();
        $role = SalesAccess::provisionRole('finance_branch_test');
        $role->permissions()->sync(Permission::whereIn('name', ['finance.view', 'finance.expenses.approve'])->pluck('id'));
        $other = DB::table('branches')->insertGetId(['name' => 'فرع آخر']);
        $user = User::factory()->create(['role_id' => $role->id, 'branch_id' => $other]);
        $this->actingAs($user);
        $this->get(route('finance.expenses.show', $expense))->assertForbidden();
        $this->get(route('finance.treasury.index', ['branch_id' => $this->branchId]))->assertForbidden();
        $this->postJson(route('finance.expenses.approve', $expense))->assertForbidden();
        $this->assertSame('draft', $expense->fresh()->status);
    }

    public function test_supplier_payment_and_purchase_reverse_once_and_reconcile_without_restocking_twice(): void
    {
        $variant = $this->stock();
        $supplier = Supplier::factory()->create();
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '100', 'reason' => 'رصيد اختبار'], $this->owner);
        $purchase = app(SavePurchaseInvoiceAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'supplier_id' => $supplier->id,
            'invoice_date' => OperationContext::today(), 'initial_payment' => '0', 'payment_method' => 'cash', 'rows' => [['product_variant_id' => $variant->id, 'quantity' => 2, 'unit_cost' => '20']]], $this->owner->id);
        $purchase = app(ApprovePurchaseInvoiceAction::class)->execute($purchase, $purchase->version, $this->owner->id);
        $payment = app(RecordSupplierPaymentAction::class)->execute($purchase, ['client_uuid' => (string) Str::uuid(), 'account_id' => $this->cash->id, 'amount' => '20', 'payment_date' => OperationContext::today()], $this->owner->id);
        app(ReverseSupplierPaymentAction::class)->execute($payment, $this->owner, 'إلغاء دفعة خاطئة');
        app(ReverseSupplierPaymentAction::class)->execute($payment, $this->owner, 'إلغاء دفعة خاطئة');
        $this->assertSame(0, $purchase->fresh()->paid_minor);
        $this->assertSame(10000, app(PostJournalAction::class)->balance($this->cash->id));
        $this->assertSame(0, app(FinanceReports::class)->supplier($supplier->id, $this->branchId)['difference_minor']);
        app(ReversePurchaseAction::class)->execute($purchase, $this->owner, 'رد البضاعة المستلمة');
        app(ReversePurchaseAction::class)->execute($purchase, $this->owner, 'رد البضاعة المستلمة');
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $variant->id, 'quantity' => 10, 'value_minor' => 20000]);
        $this->assertSame(0, $this->balance('supplier_payables'));
        $this->assertSame(20000, $this->balance('inventory'));
        $this->get(route('purchases.invoices.show', $purchase))->assertOk();
    }
}
