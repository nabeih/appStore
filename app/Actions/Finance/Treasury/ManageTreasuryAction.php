<?php

namespace App\Actions\Finance\Treasury;

use App\Actions\Finance\FinanceContext;
use App\Actions\Finance\PostJournalAction;
use App\Models\CashCount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\TreasuryDocument;
use App\Models\User;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;

class ManageTreasuryAction
{
    public function post(array $data, User $actor): TreasuryDocument
    {
        return OperationContext::run(function () use ($data, $actor): TreasuryDocument {
            $date = FinanceContext::authorize($actor, (int) $data['branch_id'], 'finance.treasury.manage', $data['transaction_date'] ?? null);
            $hash = OperationContext::hash($data);
            if ($existing = TreasuryDocument::where('client_uuid', $data['client_uuid'])->first()) {
                return OperationContext::replay($existing, $hash);
            }
            $kind = $data['kind'];
            if (! in_array($kind, ['transfer', 'owner_contribution', 'owner_withdrawal'], true)) {
                FinanceContext::fail('نوع الحركة غير مسموح من هذا المسار.');
            }
            $amount = FinanceContext::amount($data['amount']);
            $from = $kind === 'owner_contribution' ? null : FinanceContext::account((int) ($data['from_account_id'] ?? 0), (int) $data['branch_id']);
            $to = $kind === 'owner_withdrawal' ? null : FinanceContext::account((int) ($data['to_account_id'] ?? 0), (int) $data['branch_id']);
            if ($from && $to && $from->id === $to->id) {
                FinanceContext::fail('اختر حسابين مختلفين. التحويل الحالي داخل الفرع نفسه.');
            }
            if ($from) {
                FinanceContext::funds($from, $amount);
            }
            if (mb_strlen(trim($data['reason'])) < 3) {
                FinanceContext::fail('سبب الحركة مطلوب.');
            }
            $document = TreasuryDocument::create(['client_uuid' => $data['client_uuid'], 'request_hash' => $hash, 'branch_id' => $data['branch_id'], 'created_by' => $actor->id,
                'kind' => $kind, 'transaction_date' => $date, 'amount_minor' => $amount, 'from_account_id' => $from?->id, 'to_account_id' => $to?->id, 'reference' => $data['reference'] ?? null, 'reason' => $data['reason']]);
            app(PostJournalAction::class)->execute($document, 'treasury', $document->branch_id, $actor, $date, [
                ['account' => $to?->id ?? 'owner_equity', 'amount' => $amount], ['account' => $from?->id ?? 'owner_equity', 'amount' => -$amount],
            ], 'حركة حساب '.$document->number);
            OperationContext::audit($document, $actor, 'treasury.approved', $data['reason']);

            return $document;
        });
    }

    public function count(array $data, User $actor): CashCount
    {
        return OperationContext::run(function () use ($data, $actor): CashCount {
            $date = FinanceContext::authorize($actor, (int) $data['branch_id'], 'finance.reconcile');
            $hash = OperationContext::hash($data);
            if ($existing = CashCount::where('client_uuid', $data['client_uuid'])->first()) {
                return OperationContext::replay($existing, $hash);
            }
            $account = FinanceContext::account((int) $data['account_id'], (int) $data['branch_id']);
            if ($account->type !== 'cash') {
                FinanceContext::fail('الجرد النقدي يخص الصندوق؛ كشف البنك والمحفظة يراجع من سجل الحساب.');
            }
            $expected = app(PostJournalAction::class)->balance($account->id);
            $actual = Money::minor($data['actual']);
            $count = CashCount::create(['client_uuid' => $data['client_uuid'], 'request_hash' => $hash, 'branch_id' => $data['branch_id'], 'financial_account_id' => $account->id,
                'created_by' => $actor->id, 'transaction_date' => $date, 'expected_minor' => $expected, 'actual_minor' => $actual, 'difference_minor' => Money::add($actual, -$expected),
                'last_journal_line_id' => (int) JournalLine::where('financial_account_id', $account->id)->max('id'), 'reason' => $data['reason']]);
            OperationContext::audit($count, $actor, 'cash_count.recorded', $data['reason'], ['balance_unchanged' => true]);

            return $count;
        });
    }

    public function settle(CashCount $count, array $data, User $actor): TreasuryDocument
    {
        return OperationContext::run(function () use ($count, $data, $actor): TreasuryDocument {
            $date = FinanceContext::authorize($actor, $count->branch_id, 'finance.reconcile');
            $current = CashCount::whereKey($count->id)->lockForUpdate()->firstOrFail();
            $hash = OperationContext::hash([$current->id, $data]);
            if ($existing = TreasuryDocument::where('client_uuid', $data['client_uuid'])->first()) {
                return OperationContext::replay($existing, $hash);
            }
            if ($current->settlement()->exists()) {
                FinanceContext::fail('لهذا الجرد تسوية سابقة؛ لا تُكرر أثرها.');
            }
            $account = FinanceContext::account($current->financial_account_id, $current->branch_id);
            if ($current->difference_minor === 0) {
                FinanceContext::fail('لا يوجد فرق للتسوية.');
            }
            if ((int) JournalLine::where('financial_account_id', $account->id)->max('id') !== (int) $current->last_journal_line_id) {
                FinanceContext::fail('توجد حركة بعد الجرد؛ أعد الجرد قبل اعتماد تسوية الفرق.');
            }
            if (! ($data['confirmed'] ?? false) || mb_strlen(trim($data['reason'])) < 5) {
                FinanceContext::fail('راجع المستندات الأصلية وأكّد أن الفرق مستقل مع ذكر السبب.');
            }
            $difference = (int) $current->difference_minor;
            if ($difference < 0) {
                FinanceContext::funds($account, abs($difference));
            }
            $document = TreasuryDocument::create(['client_uuid' => $data['client_uuid'], 'request_hash' => $hash, 'branch_id' => $current->branch_id, 'created_by' => $actor->id,
                'kind' => 'variance_adjustment', 'transaction_date' => $date, 'amount_minor' => abs($difference), 'cash_count_id' => $current->id,
                'from_account_id' => $difference < 0 ? $account->id : null, 'to_account_id' => $difference > 0 ? $account->id : null, 'reason' => $data['reason']]);
            app(PostJournalAction::class)->execute($document, 'treasury', $current->branch_id, $actor, $date, [
                ['account' => $account->id, 'amount' => $difference], ['account' => 'cash_variance', 'amount' => -$difference],
            ], 'تسوية مستقلة للجرد '.$current->number);
            OperationContext::audit($document, $actor, 'cash_count.settled', $data['reason'], ['cash_count_id' => $current->id, 'difference_minor' => $difference]);

            return $document;
        });
    }

    public function reverse(TreasuryDocument $document, User $actor, string $reason): TreasuryDocument
    {
        return OperationContext::run(function () use ($document, $actor, $reason): TreasuryDocument {
            $date = FinanceContext::authorize($actor, $document->branch_id, 'finance.reverse');
            if (mb_strlen(trim($reason)) < 3) {
                FinanceContext::fail('سبب العكس مطلوب.');
            }
            $current = TreasuryDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'reversed') {
                return $current;
            }
            if ($current->to_account_id) {
                FinanceContext::funds(FinanceContext::account($current->to_account_id, $current->branch_id), $current->amount_minor);
            }
            $entry = JournalEntry::where('source_type', $current->getMorphClass())->where('source_id', $current->id)->where('event', 'treasury')->firstOrFail();
            app(PostJournalAction::class)->reverse($entry, $actor, $reason, $date);
            $current->update(['status' => 'reversed', 'reversed_at' => now()]);
            OperationContext::audit($current, $actor, 'treasury.reversed', $reason);

            return $current;
        });
    }
}
