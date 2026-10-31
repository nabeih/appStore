<?php

namespace App\Actions\Finance;

use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Models\OpeningStockEntry;
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
        $unsupported = function ($query, string $table, string $class, string $event): void {
            $query->selectRaw('1')->from('journal_entries as posted')->whereColumn('posted.source_id', $table.'.id')
                ->whereColumn('posted.branch_id', $table.'.branch_id')
                ->where('posted.source_type', $class)->where('posted.event', $event)
                ->whereNotExists(fn ($reversal) => $reversal->selectRaw('1')->from('journal_entries as reversed')->whereColumn('reversed.reversal_of_id', 'posted.id'));
        };
        $invoices = PurchaseInvoice::where('status', 'approved')->where(fn ($q) => $q->whereNull('financial_posted_at')
            ->orWhereNotExists(fn ($proof) => $unsupported($proof, 'purchase_invoices', PurchaseInvoice::class, 'purchase')))
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))->count();
        $payments = SupplierPayment::where('status', 'approved')->where(fn ($q) => $q->whereNull('financial_posted_at')
            ->orWhereNotExists(fn ($proof) => $unsupported($proof, 'supplier_payments', SupplierPayment::class, 'supplier_payment')))
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))->count();
        $openings = OpeningStockEntry::where('status', 'approved')->whereNotExists(fn ($q) => $unsupported($q, 'opening_stock_entries', OpeningStockEntry::class, 'opening'))
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))->count();
        $unknownAccounts = FinancialAccount::whereIn('type', ['cash', 'bank', 'wallet'])->where('is_active', true)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->whereNotExists(fn ($q) => $unsupported($q, 'financial_accounts', FinancialAccount::class, 'opening'))->count();
        $missingAccounts = array_diff(array_keys(ManageFinancialAccountsAction::SYSTEM), FinancialAccount::where('type', 'system')->pluck('code')->all());
        $history = DB::table('stock_movements')->where('stock_bucket', 'available')->groupBy('branch_id', 'product_variant_id')
            ->select('branch_id', 'product_variant_id')->selectRaw('SUM(quantity_change) AS quantity, SUM(value_change_minor) AS value_minor, SUM(value_change_minor IS NULL) AS unknown_values');
        $unreadyStocks = DB::table('inventories as stock')->leftJoinSub($history, 'history', fn ($join) => $join->on('history.branch_id', '=', 'stock.branch_id')->on('history.product_variant_id', '=', 'stock.product_variant_id'))
            ->when($branchIds !== null, fn ($q) => $q->whereIn('stock.branch_id', $branchIds))
            ->where(fn ($q) => $q->whereNull('stock.value_minor')->orWhereRaw('stock.quantity <> COALESCE(history.quantity,0)')
                ->orWhereRaw('stock.value_minor <> COALESCE(history.value_minor,0)')->orWhere('history.unknown_values', '>', 0))->count();
        $complete = $invoices === 0 && $payments === 0 && $openings === 0 && $unknownAccounts === 0 && $missingAccounts === [] && $unreadyStocks === 0;

        return ['complete' => $complete, 'legacy_invoices' => $invoices, 'legacy_payments' => $payments,
            'legacy_openings' => $openings, 'unknown_account_openings' => $unknownAccounts, 'missing_system_accounts' => array_values($missingAccounts),
            'unready_inventory_positions' => $unreadyStocks,
            'message' => ! $complete ? 'بيانات مالية غير مكتملة: توجد مصادر بلا قيد فعال أو أرصدة بداية غير معتمدة. الأرقام جزئية وليست رصيدًا أو ربحًا نهائيًا؛ راجع الانتقال وجاهزية المخزون.' : 'المصادر المسجلة مدمجة؛ مطابقة الرصيد بالنقد وكشف البنك حكم مستقل.'];
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
