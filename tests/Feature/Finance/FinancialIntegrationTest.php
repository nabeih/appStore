<?php

namespace Tests\Feature\Finance;

use App\Actions\Customers\RecordCustomerReceiptAction;
use App\Actions\Finance\Expenses\ManageExpenseAction;
use App\Actions\Finance\FinanceReports;
use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Actions\Finance\PostJournalAction;
use App\Actions\Finance\Treasury\ManageTreasuryAction;
use App\Actions\Purchases\ApprovePurchaseInvoiceAction;
use App\Actions\Purchases\RecordSupplierPaymentAction;
use App\Actions\Purchases\SavePurchaseInvoiceAction;
use App\Models\Category;
use App\Models\Color;
use App\Models\FinancialAccount;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\Supplier;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Sales\SalesTestCase;

class FinancialIntegrationTest extends SalesTestCase
{
    protected function freshVariant(): ProductVariant
    {
        $category = Category::create(['name' => 'ملابس جديدة']);
        $color = Color::create(['name' => 'أسود', 'code' => 'BLK']);
        $size = Size::create(['name' => 'L', 'code' => 'L']);
        $product = Product::create(['name' => 'قميص تكامل', 'code' => 'NEW', 'category_id' => $category->id]);

        return $product->variants()->create(['sku' => 'NEW-BLK-L', 'color_id' => $color->id, 'size_id' => $size->id, 'selling_price' => '35']);
    }

    protected function expenseData(): array
    {
        return ['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'title' => 'كهرباء الفترة', 'category' => 'utilities',
            'period_start' => OperationContext::today(), 'period_end' => OperationContext::today(), 'transaction_date' => OperationContext::today(), 'amount' => '50'];
    }

    protected function payData(string $amount): array
    {
        return ['client_uuid' => (string) Str::uuid(), 'account_id' => $this->cash->id, 'amount' => $amount, 'transaction_date' => OperationContext::today(), 'reason' => 'سداد موثق'];
    }

    public function test_required_eight_step_scenario_reconciles_documents_inventory_accounts_and_profit(): void
    {
        $day = OperationContext::today();
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '1000', 'reason' => 'رصيد موثق'], $this->owner);
        $bank = FinancialAccount::factory()->create(['branch_id' => $this->branchId, 'type' => 'bank']);
        $variant = $this->freshVariant();
        $supplier = Supplier::factory()->create();
        $purchase = app(SavePurchaseInvoiceAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'branch_id' => $this->branchId, 'invoice_date' => $day,
            'initial_payment' => '80', 'payment_method' => 'cash', 'payment_details' => ['account_id' => $this->cash->id],
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => '20']]], $this->owner->id);
        $purchase = app(ApprovePurchaseInvoiceAction::class)->execute($purchase, $purchase->version, $this->owner->id);
        $this->assertSame(20000, $this->balance('inventory'));
        $this->assertSame(-12000, $this->balance('supplier_payables'));
        $this->assertCash(92000);
        $this->assertSame(12000, app(FinanceReports::class)->supplier($supplier->id, $this->branchId)['ledger_minor']);
        $sale = $this->sale($variant, ['payment_mode' => 'partial', 'payments' => [['account_id' => $this->cash->id, 'amount' => '30']]]);
        $this->assertDatabaseHas('inventories', ['product_variant_id' => $variant->id, 'quantity' => 8, 'value_minor' => 16000]);
        $this->assertSame(16000, $this->balance('inventory'));
        $this->assertSame(4000, $this->balance('cogs'));
        $this->assertSame(-7000, $this->balance('sales'));
        $this->assertSame(4000, $sale->due_minor);
        $this->assertCash(95000);
        $service = app(ManageExpenseAction::class);
        $expense = $service->approve($service->save($this->expenseData(), $this->owner), $this->owner);
        $this->assertCash(95000);
        $this->assertSame(-5000, $this->balance('expense_payables'));
        $service->pay($expense, $this->payData('20'), $this->owner);
        $this->assertCash(93000);
        $this->assertSame(-3000, $this->balance('expense_payables'));
        app(RecordSupplierPaymentAction::class)->execute($purchase, ['client_uuid' => (string) Str::uuid(), 'account_id' => $this->cash->id, 'amount' => '40', 'payment_date' => $day], $this->owner->id);
        $this->assertCash(89000);
        $this->assertSame(8000, $purchase->fresh()->total_minor - $purchase->fresh()->paid_minor);
        $this->assertSame(-8000, $this->balance('supplier_payables'));
        app(RecordCustomerReceiptAction::class)->execute(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'customer_id' => $this->customer->id,
            'allocations' => [['sale_invoice_id' => $sale->id, 'amount' => '40']], 'payments' => [['account_id' => $this->cash->id, 'amount' => '40']]], $this->owner);
        $this->assertCash(93000);
        $this->assertSame(0, $sale->fresh()->due_minor);
        app(ManageTreasuryAction::class)->post(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'kind' => 'transfer',
            'from_account_id' => $this->cash->id, 'to_account_id' => $bank->id, 'amount' => '100', 'reason' => 'إيداع بنكي موثق'], $this->owner);
        $this->assertCash(83000);
        $this->assertSame(10000, app(PostJournalAction::class)->balance($bank->id));
        $report = app(FinanceReports::class)->period(['branch_id' => $this->branchId, 'from' => $day, 'to' => $day]);
        $this->assertSame(7000, $report['net_sales_minor']);
        $this->assertSame(4000, $report['cogs_minor']);
        $this->assertSame(3000, $report['gross_profit_minor']);
        $this->assertSame(5000, $report['expenses_minor']);
        $this->assertSame(-2000, $report['result_minor']);
        $match = app(FinanceReports::class)->supplier($supplier->id, $this->branchId);
        $this->assertSame(8000, $match['operational_minor']);
        $this->assertSame(0, $match['difference_minor']);
        $this->assertSame([], $match['payment_cache_drift']);
        $this->assertSame(0, (int) DB::table('journal_lines')->sum('debit_minor') - (int) DB::table('journal_lines')->sum('credit_minor'));
        $this->artisan('app:finance-audit', ['--json' => true])->assertSuccessful();
    }

    private function assertCash(int $expected): void
    {
        $this->assertSame($expected, app(PostJournalAction::class)->balance($this->cash->id));
    }

    public function test_expense_payment_replay_and_reversals_preserve_exact_accrual_without_new_expense(): void
    {
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '100', 'reason' => 'رصيد اختبار'], $this->owner);
        $service = app(ManageExpenseAction::class);
        $expense = $service->approve($service->save($this->expenseData(), $this->owner), $this->owner);
        $data = $this->payData('20');
        $payment = $service->pay($expense, $data, $this->owner);
        $this->assertSame($payment->id, $service->pay($expense, $data, $this->owner)->id);
        $this->assertSame(5000, $this->balance('operating_expenses'));
        $this->assertSame(-3000, $this->balance('expense_payables'));
        $service->reversePayment($payment, $this->owner, 'إلغاء سداد خاطئ');
        $service->reversePayment($payment, $this->owner, 'إلغاء سداد خاطئ');
        $this->assertCash(10000);
        $service->reverse($expense, $this->owner, 'إلغاء إثبات خاطئ');
        $service->reverse($expense, $this->owner, 'إلغاء إثبات خاطئ');
        $this->assertSame(0, $this->balance('operating_expenses'));
        $this->assertSame(0, $this->balance('expense_payables'));
        $this->assertDatabaseCount('expense_payments', 1);
    }

    public function test_cash_count_never_changes_money_until_separate_approved_settlement(): void
    {
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '100', 'reason' => 'رصيد اختبار'], $this->owner);
        $service = app(ManageTreasuryAction::class);
        $count = $service->count(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'account_id' => $this->cash->id, 'actual' => '95', 'reason' => 'جرد فعلي موثق'], $this->owner);
        $this->assertSame(-500, $count->difference_minor);
        $this->assertCash(10000);
        $data = ['client_uuid' => (string) Str::uuid(), 'confirmed' => true, 'reason' => 'فرق مستقل بعد مراجعة مستندات الأصل'];
        $document = $service->settle($count, $data, $this->owner);
        $this->assertCash(9500);
        $this->assertSame(500, $this->balance('cash_variance'));
        $this->assertSame($document->id, $service->settle($count, $data, $this->owner)->id);
        $service->reverse($document, $this->owner, 'إلغاء تسوية خاطئة');
        $service->reverse($document, $this->owner, 'إلغاء تسوية خاطئة');
        $this->assertCash(10000);
    }

    public function test_multi_period_prepaid_expense_is_rejected_instead_of_charged_to_one_month(): void
    {
        $data = $this->expenseData();
        $data['period_start'] = now()->startOfMonth()->subMonth()->toDateString();
        $this->expectException(ValidationException::class);
        app(ManageExpenseAction::class)->save($data, $this->owner);
    }

    public function test_purchase_accrual_date_is_separate_from_actual_initial_payment_today(): void
    {
        app(ManageFinancialAccountsAction::class)->opening($this->cash, ['opening_balance' => '1000', 'reason' => 'رصيد موثق'], $this->owner);
        $variant = $this->freshVariant();
        $invoice = app(SavePurchaseInvoiceAction::class)->execute([
            'client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'supplier_id' => Supplier::factory()->create()->id,
            'invoice_date' => now(config('sales.timezone'))->subDay()->toDateString(), 'initial_payment' => '80', 'payment_method' => 'cash',
            'payment_details' => ['account_id' => $this->cash->id],
            'rows' => [['product_variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => '20']],
        ], $this->owner->id);
        $invoice = app(ApprovePurchaseInvoiceAction::class)->execute($invoice, $invoice->version, $this->owner->id);
        $this->assertSame(OperationContext::today(), $invoice->payments()->firstOrFail()->payment_date->toDateString());
        $this->assertCash(92000);
        $this->expectException(ValidationException::class);
        app(RecordSupplierPaymentAction::class)->execute($invoice, [
            'client_uuid' => (string) Str::uuid(), 'account_id' => $this->cash->id, 'amount' => '10',
            'payment_date' => $invoice->invoice_date->toDateString(),
        ], $this->owner->id);
    }

    public function test_owner_movements_change_equity_and_cash_without_changing_period_profit(): void
    {
        $service = app(ManageTreasuryAction::class);
        $service->post(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'kind' => 'owner_contribution',
            'to_account_id' => $this->cash->id, 'amount' => '200', 'reason' => 'تمويل من المالك'], $this->owner);
        $service->post(['client_uuid' => (string) Str::uuid(), 'branch_id' => $this->branchId, 'kind' => 'owner_withdrawal',
            'from_account_id' => $this->cash->id, 'amount' => '50', 'reason' => 'سحب للمالك'], $this->owner);
        $this->assertCash(15000);
        $this->assertSame(-15000, $this->balance('owner_equity'));
        $this->assertSame(0, app(FinanceReports::class)->period(['branch_id' => $this->branchId,
            'from' => OperationContext::today(), 'to' => OperationContext::today()])['result_minor']);
    }
}
