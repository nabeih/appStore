<?php

namespace App\Actions\Finance;

use App\Models\JournalLine;
use App\Models\PurchaseInvoice;
use App\Models\SupplierPayment;
use App\Support\Sales\Money;
use App\Support\Sales\SalesReports;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FinanceReports
{
    public function supplier(int $supplierId, int $branchId): array
    {
        FinanceContext::ready();
        $operational = (int) PurchaseInvoice::where('supplier_id', $supplierId)->where('branch_id', $branchId)->where('status', 'approved')->selectRaw('COALESCE(SUM(total_minor),0)-COALESCE(SUM(paid_minor),0) AS due')->value('due');
        $ledger = Money::integer((string) JournalLine::where('supplier_id', $supplierId)->whereHas('entry', fn ($query) => $query->where('branch_id', $branchId))
            ->whereHas('account', fn ($query) => $query->where('code', 'supplier_payables'))->selectRaw('COALESCE(SUM(credit_minor),0)-COALESCE(SUM(debit_minor),0) AS due')->value('due'));
        $invoices = PurchaseInvoice::where('supplier_id', $supplierId)->where('branch_id', $branchId)->where('status', 'approved')->withSum(['payments as actual_paid' => fn ($query) => $query->where('status', 'approved')], 'amount_minor')->get(['id', 'paid_minor']);

        return ['operational_minor' => $operational, 'ledger_minor' => $ledger, 'difference_minor' => $operational - $ledger,
            'payment_cache_drift' => $invoices->filter(fn ($invoice) => (int) $invoice->paid_minor !== (int) $invoice->actual_paid)->pluck('id')->all()];
    }

    public function completeness(?array $branchIds = null): array
    {
        if (! FinanceContext::installed()) {
            return ['complete' => false, 'legacy_invoices' => null, 'legacy_payments' => null, 'message' => 'التحديث المالي لم يُطبق على هذه القاعدة بعد.'];
        }
        $invoices = PurchaseInvoice::where('status', 'approved')->whereNull('financial_posted_at')->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))->count();
        $payments = SupplierPayment::whereNull('financial_posted_at')->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))->count();

        return ['complete' => $invoices === 0 && $payments === 0, 'legacy_invoices' => $invoices, 'legacy_payments' => $payments,
            'message' => $invoices || $payments ? 'رصيد الدفتر غير مكتمل: توجد مصادر قديمة خارج الدفتر. لا يعني الرصيد مطابقته للنقد أو كشف البنك.' : 'المصادر المسجلة الجديدة مدمجة؛ مطابقة الرصيد بالنقد وكشف البنك حكم مستقل.'];
    }

    public function period(array $filters): array
    {
        $sales = app(SalesReports::class)->summary($filters);
        $base = DB::table('journal_lines as lines')->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('financial_accounts as accounts', 'accounts.id', '=', 'lines.financial_account_id')
            ->where('entries.branch_id', $filters['branch_id'])->where('entries.transaction_date', '>=', $filters['from'])->where('entries.transaction_date', '<', CarbonImmutable::parse($filters['to'])->addDay()->toDateString());
        $expenses = Money::integer((string) (clone $base)->where('accounts.code', 'operating_expenses')->selectRaw('COALESCE(SUM(debit_minor),0)-COALESCE(SUM(credit_minor),0) AS amount')->value('amount'));
        $variance = Money::integer((string) (clone $base)->where('accounts.code', 'cash_variance')->selectRaw('COALESCE(SUM(debit_minor),0)-COALESCE(SUM(credit_minor),0) AS amount')->value('amount'));

        return $sales + ['expenses_minor' => $expenses, 'variance_minor' => $variance, 'result_minor' => $sales['gross_profit_minor'] - $expenses - $sales['inventory_loss_minor'] - $variance];
    }
}
