<?php

namespace App\Actions\Inventory\OpeningStock;

use App\Models\AuditEvent;
use App\Models\InventorySetupChange;
use App\Models\JournalEntry;
use App\Models\OpeningStockEntry;
use App\Models\OpeningStockItem;
use App\Models\StockMovement;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpeningFinancialSupport
{
    /** Verify existing evidence only; never create or repair historical financial effects. */
    public function assertValid(OpeningStockEntry $entry, bool $lock = false): void
    {
        $this->review($entry, $lock);
    }

    /** @return array<string, mixed> */
    public function review(OpeningStockEntry $entry, bool $lock = false, bool $allowMissing = false): array
    {
        if ($lock && DB::transactionLevel() === 0) {
            throw new \LogicException('Opening evidence locks require a transaction.');
        }
        $items = $entry->items()->when($lock, fn ($query) => $query->lockForUpdate())->get();
        if ($entry->total_cost === null || $items->contains(fn ($item): bool => $item->unit_cost === null || $item->line_total === null)) {
            $this->fail();
        }
        $total = Money::minor($entry->total_cost);
        if (! in_array($entry->status, ['approved', 'cancelled'], true) || $entry->opening_date === null || $entry->total_cost === null || ($items->isEmpty() && ($total !== 0 || (int) $entry->total_quantity !== 0)) || $total < 0
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
        $audits = AuditEvent::where('subject_type', (new InventorySetupChange)->getMorphClass())->whereIn('subject_id', $changes->modelKeys())
            ->where('action', 'finance.opening_reconciled')->when($lock, fn ($query) => $query->lockForUpdate())->get()->groupBy('subject_id');
        $correctionTotal = 0;
        $plans = [];
        $lastStates = [];
        foreach ($changes as $change) {
            $data = $this->changeData($change);
            if ((int) $change->branch_id !== (int) $entry->branch_id || ! $change->opening_stock_item_id) {
                $this->fail();
            }
            $itemId = (int) $change->opening_stock_item_id;
            if (array_key_exists($itemId, $lastStates) && $lastStates[$itemId] !== $data['before']) {
                $this->fail();
            }
            $lastStates[$itemId] = $data['after'];
            $delta = $data['delta_minor'];
            $records = $journals->get($change->id, collect());
            $base = $records->where('event', 'opening_correction');
            $settlements = $records->where('event', 'opening_reconciliation');
            if ($records->count() !== $base->count() + $settlements->count() || $base->count() > 1 || $settlements->count() > 1
                || ($base->isNotEmpty() && ! $this->matches($base->first(), 'opening_correction', $entry->branch_id, $delta))) {
                $this->fail();
            }
            $audit = $audits->get($change->id, collect());
            $status = $base->isNotEmpty() || $delta === 0 ? 'posted' : 'missing';
            if ($audit->isNotEmpty() || $settlements->isNotEmpty()) {
                if ($audit->count() !== 1 || $settlements->count() !== 1) {
                    $this->fail();
                }
                $record = $audit->first();
                $decision = $record->data;
                if ((int) $record->branch_id !== (int) $entry->branch_id || ! $record->user_id || mb_strlen(trim($record->reason ?? '')) < 5
                    || ($decision['change_hash'] ?? '') !== $data['change_hash'] || ($decision['entry_id'] ?? null) !== $entry->id
                    || ($decision['base_journal_id'] ?? null) !== $base->first()?->id || ($decision['journal_id'] ?? null) !== $settlements->first()->id
                    || mb_strlen(trim($decision['evidence'] ?? '')) < 5) {
                    $this->fail();
                }
                $lines = $this->settlementLines($change, $decision['classification'] ?? '', $base->isNotEmpty(), $decision['revenue_journal_id'] ?? null);
                if (! $this->matchesLines($settlements->first(), 'opening_reconciliation', $entry->branch_id, $lines)
                    || $settlements->first()->transaction_date->toDateString() !== ($decision['posting_date'] ?? null)) {
                    $this->fail();
                }
                $status = 'reconciled';
            }
            if ($status === 'missing' && ! $allowMissing) {
                $this->fail();
            }
            $plans[] = $data + ['id' => $change->id, 'variant_id' => $change->product_variant_id, 'item_id' => $itemId,
                'status' => $status, 'base_journal_id' => $base->first()?->id, 'settlement_journal_id' => $settlements->first()?->id];
            $correctionTotal = Money::add($correctionTotal, $delta);
        }
        $movementGroups = StockMovement::where('source_type', (new OpeningStockItem)->getMorphClass())
            ->whereIn('source_id', array_unique(array_merge($items->modelKeys(), array_keys($lastStates))))->get()->groupBy('source_id');
        foreach ($lastStates as $id => $state) {
            $item = $items->firstWhere('id', $id);
            if ($state === null ? $item !== null : (! $item || $state !== ['quantity' => (int) $item->quantity, 'value_minor' => Money::minor($item->line_total), 'unit_cost_minor' => Money::minor($item->unit_cost)])) {
                $this->fail();
            }
            if ($state === null && $movementGroups->has($id)) {
                $this->fail();
            }
        }
        foreach ($items as $item) {
            $movements = $movementGroups->get($item->id, collect());
            if ($movements->count() !== 1 || (int) $movements->first()->branch_id !== (int) $entry->branch_id
                || (int) $movements->first()->product_variant_id !== (int) $item->product_variant_id || $movements->first()->movement_type !== 'opening_stock'
                || $movements->first()->stock_bucket !== 'available' || (int) $movements->first()->quantity_change !== (int) $item->quantity
                || $movements->first()->value_change_minor === null || (int) $movements->first()->value_change_minor !== Money::minor($item->line_total)) {
                $this->fail();
            }
        }
        $initial = Money::add($total, -$correctionTotal);
        if ($initial < 0 || ! $this->matches($original->first(), 'opening', $entry->branch_id, $initial)
            || $original->first()->transaction_date->toDateString() !== $entry->opening_date->toDateString()) {
            $this->fail();
        }

        $effective = [];
        foreach (collect([$original->first()])->concat($journals->flatten(1)) as $journal) {
            foreach ($journal->lines as $line) {
                $code = $line->account->code;
                $effective[$code] = Money::add($effective[$code] ?? 0, (int) $line->debit_minor - (int) $line->credit_minor);
            }
        }
        $effectiveLines = [];
        foreach ($effective as $code => $amount) {
            if ($amount !== 0) {
                $effectiveLines[] = ['account' => $code, 'amount' => $amount];
            }
        }

        return ['effective_lines' => $effectiveLines, 'entry_id' => $entry->id, 'branch_id' => $entry->branch_id, 'original_journal_id' => $original->first()->id,
            'original_minor' => $initial, 'current_minor' => $total, 'changes' => $plans,
            'basis' => OperationContext::hash([$entry->getAttributes(), $items->toArray(), $changes->toArray(), $journals->toArray(), $audits->toArray()])];
    }

    /** @return array<string, mixed> */
    public function changeData(InventorySetupChange $change): array
    {
        if (! is_array($change->before_state) || ($change->after_state !== null && ! is_array($change->after_state))) {
            $this->fail();
        }
        $before = $this->state($change->before_state);
        $after = $change->after_state === null ? null : $this->state($change->after_state);
        if (($change->operation === 'deleted') !== ($after === null) || ! in_array($change->operation, ['deleted', 'updated'], true)) {
            $this->fail();
        }

        return ['before' => $before, 'after' => $after, 'delta_minor' => Money::add($after['value_minor'] ?? 0, -$before['value_minor']),
            'quantity_delta' => ($after['quantity'] ?? 0) - $before['quantity'], 'change_hash' => OperationContext::hash($change->getAttributes())];
    }

    /** @return array<string, int> */
    private function state(array $state): array
    {
        if (! is_int($state['quantity'] ?? null) || ! is_int($state['value_minor'] ?? null) || ! is_string($state['average_cost'] ?? null)
            || $state['quantity'] < 0 || $state['value_minor'] < 0 || Money::multiply($state['quantity'], Money::minor($state['average_cost'])) !== $state['value_minor']) {
            $this->fail();
        }

        return ['quantity' => $state['quantity'], 'value_minor' => $state['value_minor'], 'unit_cost_minor' => Money::minor($state['average_cost'])];
    }

    /** @return list<array{account:string,amount:int}> */
    public function settlementLines(InventorySetupChange $change, string $classification, bool $hasBase, ?int $revenueId): array
    {
        $data = $this->changeData($change);
        $delta = $data['delta_minor'];
        if ($classification === 'entry_error') {
            if ($revenueId !== null) {
                $this->fail();
            }

            return $hasBase ? [] : [['account' => 'inventory', 'amount' => $delta], ['account' => 'opening_equity', 'amount' => -$delta]];
        }
        if ($classification !== 'external_sale' || $delta >= 0 || $data['quantity_delta'] >= 0
            || ($data['after'] !== null && $data['after']['unit_cost_minor'] !== $data['before']['unit_cost_minor'])
            || $delta !== Money::multiply($data['quantity_delta'], $data['before']['unit_cost_minor'])) {
            throw ValidationException::withMessages(['classification' => 'البيع الخارجي يخص خروج كمية بتكلفتها المثبتة فقط. لا تجمع تصحيح التكلفة مع خروج البضاعة؛ يلزم فصل الأثرين بمراجعة مستقلة.']);
        }
        $revenue = JournalEntry::with(['lines.account', 'reversals'])->find($revenueId ?? 0);
        if (! $revenue || (int) $revenue->branch_id !== (int) $change->branch_id || $revenue->reversal_of_id !== null || $revenue->reversals->isNotEmpty()
            || $revenue->transaction_date->toDateString() > OperationContext::today() || $revenue->transaction_date->toDateString() < OpeningStockEntry::find($change->opening_stock_entry_id)?->opening_date?->toDateString()
            || $revenue->lines->contains(fn ($line): bool => ! $line->account || ($line->account->branch_id !== null && (int) $line->account->branch_id !== (int) $change->branch_id))
            || $revenue->lines->sum('debit_minor') !== $revenue->lines->sum('credit_minor')
            || $revenue->lines->where('credit_minor', '>', 0)->contains(fn ($line): bool => $line->account?->code !== 'sales')
            || $revenue->lines->where('debit_minor', '>', 0)->contains(fn ($line): bool => ! in_array($line->account?->type, ['cash', 'bank', 'wallet'], true) && $line->account?->code !== 'receivables')
            || (int) $revenue->lines->sum('credit_minor') <= 0) {
            throw ValidationException::withMessages(['revenue_journal_id' => 'أرفق قيد إيراد مستقلًا ومتوازنًا لنفس الفرع مقابل قبض أو ذمم، دون تكلفة مخزون مكررة. إذا لم يثبت الإيراد والقبض أو الذمة، تتوقف التسوية لحين تحديد المعالجة المحاسبية.']);
        }
        $used = AuditEvent::where('action', 'finance.opening_reconciled')->where('data->revenue_journal_id', $revenueId)
            ->where(fn ($query) => $query->where('subject_type', '!=', $change->getMorphClass())->orWhere('subject_id', '!=', $change->id))->exists();
        if ($used) {
            throw ValidationException::withMessages(['revenue_journal_id' => 'قيد الإيراد مستخدم لتسوية أخرى؛ لا يعاد تخصيصه دون مراجعة تجميعية مستقلة.']);
        }

        return $hasBase ? [['account' => 'cogs', 'amount' => -$delta], ['account' => 'opening_equity', 'amount' => $delta]]
            : [['account' => 'inventory', 'amount' => $delta], ['account' => 'cogs', 'amount' => -$delta]];
    }

    private function matches(JournalEntry $journal, string $event, int $branchId, int $amount): bool
    {
        if ($journal->event !== $event || (int) $journal->branch_id !== $branchId || $journal->reversal_of_id !== null || $journal->reversals->isNotEmpty()) {
            return false;
        }

        return $this->matchesLines($journal, $event, $branchId, [['account' => 'inventory', 'amount' => $amount], ['account' => 'opening_equity', 'amount' => -$amount]]);
    }

    /** @param list<array{account:string,amount:int}> $lines */
    private function matchesLines(JournalEntry $journal, string $event, int $branchId, array $lines): bool
    {
        if ($journal->event !== $event || (int) $journal->branch_id !== $branchId || $journal->reversal_of_id !== null || $journal->reversals->isNotEmpty()) {
            return false;
        }
        $expected = [];
        foreach ($lines as $line) {
            if ($line['amount'] !== 0) {
                $expected[$line['account']] = [max(0, $line['amount']), max(0, -$line['amount'])];
            }
        }
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
