<?php

namespace App\Actions\Finance;

use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Support\Sales\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class PostJournalAction
{
    /** @param list<array{account: int|string, amount: int, customer_id?: ?int, sale_invoice_id?: ?int, sale_return_id?: ?int}> $lines */
    public function execute(Model $source, string $event, int $branchId, User $actor, string $date, array $lines, string $description, ?int $reversalOf = null): JournalEntry
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Financial posting requires a transaction.');
        }
        $lines = array_values(array_filter($lines, fn (array $line): bool => $line['amount'] !== 0));
        if (Money::sum(array_column($lines, 'amount')) !== 0) {
            throw new LogicException('Unbalanced financial journal.');
        }
        $entry = JournalEntry::where('source_type', $source->getMorphClass())->where('source_id', $source->id)->where('event', $event)->first();
        if ($entry) {
            return $entry;
        }
        $codes = array_values(array_filter(array_column($lines, 'account'), 'is_string'));
        $accounts = FinancialAccount::whereIn('code', $codes)->get()->keyBy('code');
        $entry = JournalEntry::create(['source_type' => $source->getMorphClass(), 'source_id' => $source->id,
            'event' => $event, 'branch_id' => $branchId, 'created_by' => $actor->id, 'transaction_date' => $date,
            'description' => $description, 'reversal_of_id' => $reversalOf]);
        foreach ($lines as $line) {
            $accountId = is_int($line['account']) ? $line['account'] : $accounts->get($line['account'])?->id;
            if (! $accountId) {
                throw ValidationException::withMessages(['account' => 'أكمل تعريف الحسابات المالية قبل الترحيل.']);
            }
            JournalLine::create(array_intersect_key($line, array_flip(['supplier_id', 'purchase_invoice_id', 'expense_id'])) + ['journal_entry_id' => $entry->id, 'financial_account_id' => $accountId,
                'customer_id' => $line['customer_id'] ?? null, 'sale_invoice_id' => $line['sale_invoice_id'] ?? null,
                'sale_return_id' => $line['sale_return_id'] ?? null, 'debit_minor' => max(0, $line['amount']), 'credit_minor' => max(0, -$line['amount'])]);
        }

        return $entry;
    }

    public function reverse(JournalEntry $entry, User $actor, string $reason, string $date): JournalEntry
    {
        if ($reversed = $entry->reversals()->first()) {
            return $reversed;
        }
        $lines = $entry->lines()->get()->map(fn (JournalLine $line): array => array_intersect_key($line->getAttributes(), array_flip(['supplier_id', 'purchase_invoice_id', 'expense_id'])) + ['account' => $line->financial_account_id,
            'amount' => (int) $line->credit_minor - (int) $line->debit_minor, 'customer_id' => $line->customer_id,
            'sale_invoice_id' => $line->sale_invoice_id, 'sale_return_id' => $line->sale_return_id])->all();

        return $this->execute($entry, 'reversal', $entry->branch_id, $actor, $date, $lines, $reason, $entry->id);
    }

    public function balance(int $accountId): int
    {
        return (int) JournalLine::where('financial_account_id', $accountId)->selectRaw('COALESCE(SUM(debit_minor),0) - COALESCE(SUM(credit_minor),0) AS balance')->value('balance');
    }
}
