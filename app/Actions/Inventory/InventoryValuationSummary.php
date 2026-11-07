<?php

namespace App\Actions\Inventory;

use App\Models\User;
use App\Support\Sales\Money;
use App\Support\Sales\SalesReports;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class InventoryValuationSummary
{
    /** @return array<string, mixed> */
    public function build(User $actor, ?int $branchId = null): array
    {
        Gate::forUser($actor)->authorize('inventory.view');
        Gate::forUser($actor)->authorize('sales.view_cost');
        $ids = SalesReports::branches($actor, false)->modelKeys();
        if ($branchId !== null) {
            abort_unless(in_array($branchId, $ids, true), 403);
            $ids = [$branchId];
        }
        $history = DB::table('stock_movements')->whereIn('branch_id', $ids)->where('stock_bucket', 'available')
            ->groupBy('branch_id', 'product_variant_id')->select('branch_id', 'product_variant_id')
            ->selectRaw('SUM(quantity_change) AS history_quantity, SUM(value_change_minor) AS history_value, SUM(CASE WHEN value_change_minor IS NULL THEN 1 ELSE 0 END) AS unknown_values');
        $quarantineHistory = DB::table('stock_movements')->whereIn('branch_id', $ids)->where('stock_bucket', 'quarantine')
            ->groupBy('branch_id', 'product_variant_id')->select('branch_id', 'product_variant_id')
            ->selectRaw('SUM(quantity_change) AS history_quantity, SUM(value_change_minor) AS history_value, SUM(CASE WHEN value_change_minor IS NULL THEN 1 ELSE 0 END) AS unknown_values');
        $stock = DB::table('inventories as stocks')->whereIn('stocks.branch_id', $ids)
            ->leftJoinSub($history, 'history', fn ($join) => $join->on('stocks.branch_id', '=', 'history.branch_id')->on('stocks.product_variant_id', '=', 'history.product_variant_id'))
            ->leftJoinSub($quarantineHistory, 'quarantine_history', fn ($join) => $join->on('stocks.branch_id', '=', 'quarantine_history.branch_id')->on('stocks.product_variant_id', '=', 'quarantine_history.product_variant_id'))
            ->selectRaw('COUNT(*) AS stock_rows, COALESCE(SUM(stocks.value_minor),0) AS available_value, COALESCE(SUM(stocks.quarantined_value_minor),0) AS quarantine_value')
            ->selectRaw('COALESCE(SUM(CASE WHEN stocks.value_minor IS NULL OR stocks.quarantined_value_minor IS NULL THEN 1 ELSE 0 END),0) AS unknown_values')
            ->selectRaw('COALESCE(SUM(CASE WHEN stocks.quantity < 0 OR stocks.quarantined_quantity < 0 OR stocks.value_minor < 0 OR stocks.quarantined_value_minor < 0 OR (stocks.quantity = 0 AND stocks.value_minor <> 0) OR (stocks.quarantined_quantity = 0 AND stocks.quarantined_value_minor <> 0) THEN 1 ELSE 0 END),0) AS invalid_rows')
            ->selectRaw('COALESCE(SUM(CASE WHEN stocks.quantity <> COALESCE(history.history_quantity,0) OR COALESCE(history.unknown_values,0)>0 OR (stocks.value_minor IS NOT NULL AND stocks.value_minor <> COALESCE(history.history_value,0)) OR stocks.quarantined_quantity <> COALESCE(quarantine_history.history_quantity,0) OR COALESCE(quarantine_history.unknown_values,0)>0 OR (stocks.quarantined_value_minor IS NOT NULL AND stocks.quarantined_value_minor <> COALESCE(quarantine_history.history_value,0)) THEN 1 ELSE 0 END),0) AS history_issues')->first();
        $canViewLedger = $actor->can('finance.view');
        $lines = $canViewLedger ? DB::table('journal_lines as lines')->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('financial_accounts as accounts', 'accounts.id', '=', 'lines.financial_account_id')
            ->whereIn('entries.branch_id', $ids)->whereIn('accounts.code', ['inventory', 'quarantine'])
            ->groupBy('accounts.code')->select('accounts.code')->selectRaw('SUM(lines.debit_minor) AS debits, SUM(lines.credit_minor) AS credits')->get()->keyBy('code') : collect();
        $unknown = (int) $stock->unknown_values;
        $invalid = (int) $stock->invalid_rows;
        $valid = $unknown === 0 && $invalid === 0;
        $available = $valid ? Money::integer((string) $stock->available_value) : null;
        $quarantine = $valid ? Money::integer((string) $stock->quarantine_value) : null;
        $reconciliation = [];
        foreach ($canViewLedger ? ['inventory' => $available, 'quarantine' => $quarantine] : [] as $code => $value) {
            $line = $lines->get($code);
            $ledger = Money::add((int) ($line?->debits ?? 0), -(int) ($line?->credits ?? 0));
            $reconciliation[$code] = ['stock_minor' => $value, 'ledger_minor' => $ledger, 'difference_minor' => $value === null ? null : Money::add($value, -$ledger)];
        }

        return ['branch_ids' => $ids, 'at' => now()->toIso8601String(), 'rows' => (int) $stock->stock_rows,
            'value_minor' => $valid ? Money::add($available, $quarantine) : null, 'unknown_rows' => $unknown,
            'invalid_rows' => $invalid, 'history_issues' => (int) $stock->history_issues, 'reconciliation' => $reconciliation,
            'ledger_access' => $canViewLedger,
            'complete' => $valid && (int) $stock->history_issues === 0
                && (! $canViewLedger || ($reconciliation['inventory']['difference_minor'] === 0 && $reconciliation['quarantine']['difference_minor'] === 0))];
    }
}
