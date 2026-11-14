<?php

namespace App\Actions\Inventory\OpeningStock;

use App\Models\InventorySetupChange;
use App\Models\JournalEntry;
use App\Models\OpeningStockEntry;
use App\Support\Sales\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpeningFinancialSupport
{
    /** Verify existing evidence only; never create or repair historical financial effects. */
    public function assertValid(OpeningStockEntry $entry, bool $lock = false): void
    {
        if ($lock && DB::transactionLevel() === 0) {
            throw new \LogicException('Opening evidence locks require a transaction.');
        }
        $items = $entry->items()->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $total = Money::minor($entry->total_cost);
        if ($entry->status !== 'approved' || $entry->opening_date === null || $entry->total_cost === null || $items->isEmpty() || $total < 0
            || (int) $items->sum('quantity') !== (int) $entry->total_quantity
            || Money::sum($items->map(fn ($item): int => Money::minor($item->line_total))->all()) !== $total
            || $items->contains(fn ($item): bool => $item->unit_cost === null || $item->line_total === null || Money::minor($item->unit_cost) < 0
                || Money::minor($item->line_total) !== Money::multiply((int) $item->quantity, Money::minor($item->unit_cost)))) {
            $this->fail();
        }
        $original = JournalEntry::where('source_type', $entry->getMorphClass())->where('source_id', $entry->id)
            ->with(['lines.account', 'reversals'])->when($lock, fn ($query) => $query->lockForUpdate())->get();
        if ($original->count() !== 1) {
            $this->fail();
        }
        $changes = InventorySetupChange::where('opening_stock_entry_id', $entry->id)->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get();
        $journals = JournalEntry::where('source_type', (new InventorySetupChange)->getMorphClass())->whereIn('source_id', $changes->modelKeys())
            ->with(['lines.account', 'reversals'])->when($lock, fn ($query) => $query->lockForUpdate())->get()->groupBy('source_id');
        $correctionTotal = 0;
        foreach ($changes as $change) {
            $before = $change->before_state['value_minor'] ?? null;
            $after = $change->after_state === null ? 0 : ($change->after_state['value_minor'] ?? null);
            if (! is_int($before) || ! is_int($after) || $before < 0 || $after < 0 || (int) $change->branch_id !== (int) $entry->branch_id) {
                $this->fail();
            }
            $delta = Money::add((int) $after, -(int) $before);
            $matches = $journals->get($change->id, collect());
            if ($matches->count() > 1 || ($matches->isEmpty() && $delta !== 0)
                || ($matches->isNotEmpty() && ! $this->matches($matches->first(), 'opening_correction', $entry->branch_id, $delta))) {
                $this->fail();
            }
            $correctionTotal = Money::add($correctionTotal, $delta);
        }
        $initial = Money::add($total, -$correctionTotal);
        if ($initial < 0 || ! $this->matches($original->first(), 'opening', $entry->branch_id, $initial)
            || $original->first()->transaction_date->toDateString() !== $entry->opening_date->toDateString()) {
            $this->fail();
        }
    }

    private function matches(JournalEntry $journal, string $event, int $branchId, int $amount): bool
    {
        if ($journal->event !== $event || (int) $journal->branch_id !== $branchId || $journal->reversal_of_id !== null || $journal->reversals->isNotEmpty()) {
            return false;
        }
        $expected = $amount === 0 ? [] : [
            'inventory' => [max(0, $amount), max(0, -$amount)],
            'opening_equity' => [max(0, -$amount), max(0, $amount)],
        ];
        if ($journal->lines->count() !== count($expected)) {
            return false;
        }
        foreach ($journal->lines as $line) {
            $code = $line->account?->code;
            if (! array_key_exists($code ?? '', $expected) || $line->account->type !== 'system' || $line->account->branch_id !== null
                || [(int) $line->debit_minor, (int) $line->credit_minor] !== $expected[$code]) {
                return false;
            }
            foreach (['supplier_id', 'purchase_invoice_id', 'expense_id', 'customer_id', 'sale_invoice_id', 'sale_return_id'] as $dimension) {
                if ($line->{$dimension} !== null) {
                    return false;
                }
            }
            unset($expected[$code]);
        }

        return $expected === [];
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['inventory' => 'الأثر المالي للمستند الافتتاحي أو تصحيحاته مفقود أو غير مطابق أو معكوس. راجع الانتقال المالي قبل التصحيح؛ لم تتغير البضاعة ولم يُنشأ قيد تاريخي.']);
    }
}
