<?php

namespace App\Console\Commands\Sales;

use App\Actions\Inventory\ReviewInventoryAction;
use App\Models\JournalLine;
use App\Models\SaleInvoice;
use App\Models\SaleReturn;
use App\Support\Sales\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class AuditSalesCommand extends Command
{
    protected $signature = 'app:sales-audit {--json : Return a machine-readable report}';

    protected $description = 'Read-only inventory and sales/receivables/journal reconciliation';

    public function handle(ReviewInventoryAction $review): int
    {
        $issues = [];
        $review->query()->orderBy('inventories.id')->chunk(200, function ($stocks) use (&$issues): void {
            foreach ($stocks as $stock) {
                $problems = [];
                if ($stock->value_minor === null) {
                    $problems[] = 'missing_exact_value_requires_owner_review';
                }
                try {
                    Money::multiply((int) $stock->quantity, Money::minor((string) $stock->average_cost));
                    if ($stock->value_minor !== null && ! (int) $stock->missing_values
                        && Money::integer((string) ($stock->history_value_minor ?? 0)) !== Money::integer((string) $stock->value_minor)) {
                        $problems[] = 'stock_value_history_mismatch';
                    }
                } catch (ValidationException $exception) {
                    $problems[] = 'value_exceeds_supported_range';
                }
                if ((int) $stock->history_quantity !== (int) $stock->quantity) {
                    $problems[] = 'quantity_history_mismatch';
                }
                if ((int) $stock->missing_costs > 0) {
                    $problems[] = 'missing_historical_movement_cost';
                }
                if ($problems) {
                    $issues[] = ['inventory_id' => $stock->id, 'branch_id' => $stock->branch_id, 'sku' => $stock->productVariant?->sku,
                        'quantity' => $stock->quantity, 'average_cost' => (string) $stock->average_cost, 'issues' => $problems];
                }
            }
        });
        $unbalanced = JournalLine::select('journal_entry_id')->groupBy('journal_entry_id')
            ->havingRaw('SUM(debit_minor) <> SUM(credit_minor)')->pluck('journal_entry_id')->all();
        $badInvoices = [];
        SaleInvoice::whereNotNull('posted_at')->withSum('items as line_net', 'net_minor')->withSum('items as line_cost', 'cost_minor')
            ->withSum(['allocations as allocated_paid' => fn ($query) => $query->whereHas('receipt', fn ($receipt) => $receipt->where('status', 'approved'))], 'amount_minor')
            ->withSum(['returns as active_returned' => fn ($query) => $query->where('status', 'approved')], 'total_minor')
            ->withSum(['returns as active_released' => fn ($query) => $query->where('status', 'approved')], 'credit_minor')
            ->orderBy('id')->chunk(200, function ($invoices) use (&$badInvoices): void {
                $balances = JournalLine::whereIn('sale_invoice_id', $invoices->pluck('id'))
                    ->whereHas('account', fn ($query) => $query->where('code', 'receivables'))
                    ->groupBy('sale_invoice_id')->select('sale_invoice_id')
                    ->selectRaw('COALESCE(SUM(debit_minor),0) - COALESCE(SUM(credit_minor),0) AS balance')->pluck('balance', 'sale_invoice_id');
                foreach ($invoices as $invoice) {
                    if ((int) $invoice->line_net !== (int) $invoice->total_minor || (int) $invoice->line_cost !== (int) $invoice->cost_minor
                        || $invoice->cost_minor === null || (int) $balances->get($invoice->id, 0) !== $invoice->due_minor
                        || (int) $invoice->allocated_paid !== (int) $invoice->paid_minor
                        || (int) $invoice->active_returned !== (int) $invoice->returned_minor
                        || (int) $invoice->active_released !== (int) $invoice->released_minor) {
                        $badInvoices[] = $invoice->id;
                    }
                }
            });
        $returned = DB::table('sale_return_items as returned')->join('sale_returns as returns', 'returns.id', '=', 'returned.sale_return_id')
            ->where('returns.status', 'approved')->groupBy('returned.sale_item_id')->select('returned.sale_item_id')
            ->selectRaw('SUM(returned.quantity) AS quantity');
        $badReturnQuantities = DB::table('sale_items as items')->leftJoinSub($returned, 'actual_returns', 'actual_returns.sale_item_id', '=', 'items.id')
            ->whereRaw('items.returned_quantity <> COALESCE(actual_returns.quantity,0)')->pluck('items.id')->all();
        $badCredits = [];
        SaleReturn::withSum(['creditUsages as used_minor' => fn ($query) => $query->whereHas('receipt', fn ($receipt) => $receipt->where('status', 'approved'))], 'amount_minor')
            ->orderBy('id')->chunk(200, function ($returns) use (&$badCredits): void {
                $balances = JournalLine::whereIn('sale_return_id', $returns->pluck('id'))
                    ->whereHas('account', fn ($query) => $query->where('code', 'customer_credit'))->groupBy('sale_return_id')
                    ->select('sale_return_id')->selectRaw('COALESCE(SUM(credit_minor),0) - COALESCE(SUM(debit_minor),0) AS balance')->pluck('balance', 'sale_return_id');
                foreach ($returns as $return) {
                    $expected = $return->status === 'approved' ? (int) $return->credit_minor - (int) $return->used_minor : 0;
                    if ($expected < 0 || $expected !== (int) $balances->get($return->id, 0)) {
                        $badCredits[] = $return->id;
                    }
                }
            });
        $report = ['generated_at' => now()->toIso8601String(), 'historical_data_modified' => false,
            'inventory_issues' => $issues, 'inventory_ready' => $issues === [], 'unbalanced_journals' => $unbalanced,
            'inconsistent_sale_invoices' => $badInvoices, 'inconsistent_return_quantities' => $badReturnQuantities,
            'inconsistent_customer_credits' => $badCredits,
            'legacy_supplier_payments_not_in_sales_ledger' => DB::table('supplier_payments')->when(Schema::hasColumn('supplier_payments', 'financial_posted_at'), fn ($q) => $q->whereNull('financial_posted_at'))->count(),
            'approved_purchases_outside_sales_financial_ledger' => DB::table('purchase_invoices')->where('status', 'approved')->when(Schema::hasColumn('purchase_invoices', 'financial_posted_at'), fn ($q) => $q->whereNull('financial_posted_at'))->count(),
            'notes' => ['Customer/account opening balances are not inferred.',
                'Historical values before explicit legacy valuation review remain unknown.',
                'New purchases, supplier payments, expenses and treasury documents use the unified ledger after the finance migration. Legacy documents require approved transition.',
                'Ledger completeness and reconciliation with actual cash/bank are independent. See app:finance-audit.',
                'Online fulfilment is outside this release.']];
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            $this->info('مشكلات مراجعة المخزون: '.count($issues).'؛ قيود غير متوازنة: '.count($unbalanced).'؛ فواتير غير متطابقة: '.count($badInvoices));
        }

        return $unbalanced || $badInvoices || $badReturnQuantities || $badCredits ? self::FAILURE : self::SUCCESS;
    }
}
