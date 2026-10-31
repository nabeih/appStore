<?php

namespace App\Console\Commands\Finance;

use App\Actions\Finance\FinanceContext;
use App\Actions\Finance\FinanceReports;
use App\Models\Expense;
use App\Models\JournalLine;
use App\Models\PurchaseInvoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditFinanceCommand extends Command
{
    protected $signature = 'app:finance-audit {--json}';

    protected $description = 'Read-only unified-ledger reconciliation; never posts or repairs historical data';

    public function handle(): int
    {
        if (! FinanceContext::installed()) {
            $report = ['installed' => false, 'read_only' => true, 'complete' => false, 'external_reconciliation' => 'not_performed', 'message' => 'Finance migration requires explicit deployment approval.'];
        } else {
            $supplierOperational = PurchaseInvoice::where('status', 'approved')->select('supplier_id', 'branch_id')->selectRaw('SUM(total_minor)-SUM(paid_minor) AS due')->groupBy('supplier_id', 'branch_id')->get()->keyBy(fn ($row) => $row->supplier_id.':'.$row->branch_id);
            $supplierLedger = DB::table('journal_lines as lines')->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')->join('financial_accounts as accounts', 'accounts.id', '=', 'lines.financial_account_id')
                ->where('accounts.code', 'supplier_payables')->select('lines.supplier_id', 'entries.branch_id')->selectRaw('SUM(lines.credit_minor)-SUM(lines.debit_minor) AS due')->groupBy('lines.supplier_id', 'entries.branch_id')->get()->keyBy(fn ($row) => $row->supplier_id.':'.$row->branch_id);
            $supplierDifferences = [];
            foreach ($supplierOperational->keys()->merge($supplierLedger->keys())->unique() as $key) {
                $operational = (int) ($supplierOperational->get($key)?->due ?? 0);
                $ledger = (int) ($supplierLedger->get($key)?->due ?? 0);
                if ($operational !== $ledger) {
                    $supplierDifferences[] = ['supplier_branch' => $key, 'operational_minor' => $operational, 'ledger_minor' => $ledger, 'difference_minor' => $operational - $ledger];
                }
            }
            $expenseLedger = JournalLine::whereHas('account', fn ($q) => $q->where('code', 'expense_payables'))->groupBy('expense_id')->select('expense_id')->selectRaw('SUM(credit_minor)-SUM(debit_minor) AS due')->pluck('due', 'expense_id');
            $expenseDifferences = [];
            Expense::withSum(['payments as actual_paid' => fn ($q) => $q->where('status', 'approved')], 'amount_minor')->chunkById(100, function ($expenses) use ($expenseLedger, &$expenseDifferences): void {
                foreach ($expenses as $expense) {
                    $expected = $expense->status === 'approved' ? $expense->amount_minor - (int) $expense->actual_paid : 0;
                    $actual = (int) $expenseLedger->get($expense->id, 0);
                    if ($expected !== $actual) {
                        $expenseDifferences[] = ['expense_id' => $expense->id, 'operational_minor' => $expected, 'ledger_minor' => $actual];
                    }
                }
            });
            $paymentDrift = PurchaseInvoice::withSum(['payments as actual_paid' => fn ($q) => $q->where('status', 'approved')], 'amount_minor')->get(['id', 'paid_minor'])->filter(fn ($invoice) => (int) $invoice->paid_minor !== (int) $invoice->actual_paid)->pluck('id')->all();
            $unbalanced = JournalLine::select('journal_entry_id')->groupBy('journal_entry_id')->havingRaw('SUM(debit_minor)<>SUM(credit_minor)')->pluck('journal_entry_id')->all();
            $report = ['installed' => true, 'read_only' => true, 'completeness' => app(FinanceReports::class)->completeness(), 'supplier_differences' => $supplierDifferences, 'expense_differences' => $expenseDifferences, 'payment_cache_drift' => $paymentDrift, 'unbalanced_journals' => $unbalanced, 'external_reconciliation' => 'not_performed'];
        }
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return ! empty($report['supplier_differences']) || ! empty($report['unbalanced_journals']) || ! empty($report['expense_differences']) || ! empty($report['payment_cache_drift']) ? self::FAILURE : self::SUCCESS;
    }
}
