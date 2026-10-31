<?php

namespace App\Actions\Finance;

use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageFinancialAccountsAction
{
    public const SYSTEM = [
        'supplier_payables' => 'ذمم الموردين', 'expense_payables' => 'مصروفات مستحقة', 'operating_expenses' => 'مصروفات الفترة',
        'owner_equity' => 'حركات المالك', 'cash_variance' => 'فروق الجرد النقدي المعتمدة',
        'inventory' => 'مخزون متاح للبيع', 'quarantine' => 'بضاعة قيد الفحص', 'inventory_loss' => 'انخفاض قيمة بضاعة مرتجعة',
        'cogs' => 'تكلفة البضاعة المباعة', 'sales' => 'إيراد المبيعات', 'sales_returns' => 'مردودات المبيعات',
        'receivables' => 'ذمم العملاء', 'customer_credit' => 'مبالغ لصالح العملاء', 'opening_equity' => 'مقابل الأرصدة الافتتاحية',
    ];

    public static function provision(): void
    {
        foreach (self::SYSTEM as $code => $name) {
            FinancialAccount::firstOrCreate(['code' => $code], ['name' => $name, 'type' => 'system']);
        }
    }

    public function execute(array $data, User $actor): FinancialAccount
    {
        return OperationContext::run(function () use ($data, $actor): FinancialAccount {
            $branch = OperationContext::branch($actor, (int) $data['branch_id'], 'sales.manage_accounts');
            $date = OperationContext::date($branch);
            $account = FinancialAccount::create(['code' => 'ACC-'.Str::upper(Str::random(16)), 'name' => trim($data['name']),
                'type' => $data['type'], 'branch_id' => $branch->id, 'reference' => $data['reference'] ?? null]);
            $opening = Money::minor($data['opening_balance'] ?? '0');
            if ($opening > 0) {
                if (! ($data['confirm_opening'] ?? false) || blank($data['reason'] ?? null)) {
                    throw ValidationException::withMessages(['opening_balance' => 'تأكيد الرصيد الافتتاحي وسبب اعتماده مطلوبان.']);
                }
                app(PostJournalAction::class)->execute($account, 'opening', $branch->id, $actor, $date, [
                    ['account' => $account->id, 'amount' => $opening], ['account' => 'opening_equity', 'amount' => -$opening],
                ], 'رصيد افتتاحي لحساب الاستلام');
            }
            OperationContext::audit($account, $actor, 'account.created', $data['reason'] ?? null, ['opening_minor' => $opening]);

            return $account;
        });
    }

    /** @return array{parts: array, paid: int, change: int} */
    public function payments(array $input, int $branchId, int $limit, bool $allowCashChange = false): array
    {
        $accounts = FinancialAccount::whereIn('id', array_column($input, 'account_id'))->where('branch_id', $branchId)
            ->whereIn('type', ['cash', 'bank', 'wallet'])->where('is_active', true)->get()->keyBy('id');
        $parts = [];
        $seen = [];
        foreach ($input as $part) {
            $account = $accounts->get($part['account_id']);
            if (! $account || isset($seen[$account->id])) {
                throw ValidationException::withMessages(['payments' => 'اختر حسابات استلام فعالة في الفرع، دون تكرار.']);
            }
            $seen[$account->id] = true;
            if ($account->type !== 'cash' && ! ($part['confirmed'] ?? false)) {
                throw ValidationException::withMessages(['payments' => 'أكّد وصول التحويل إلى الحساب قبل إثبات قبضه. الإشعار وحده ليس تأكيدًا.']);
            }
            $amount = isset($part['amount']) ? Money::minor($part['amount']) : (count($input) === 1 ? $limit : -1);
            if ($amount === 0 && ! isset($part['amount']) && $limit === 0) {
                continue;
            }
            if ($amount <= 0) {
                throw ValidationException::withMessages(['payments' => 'أدخل مبلغًا موجبًا لكل وسيلة قبض.']);
            }
            $parts[] = ['financial_account_id' => $account->id, 'amount_minor' => $amount, 'tendered_minor' => $amount,
                'change_minor' => 0, 'reference' => $part['reference'] ?? null, 'type' => $account->type];
        }
        $total = array_sum(array_column($parts, 'amount_minor'));
        $change = max(0, $total - $limit);
        if ($change > 0) {
            $cash = array_keys(array_filter($parts, fn (array $part): bool => $part['type'] === 'cash'));
            if (! $allowCashChange || count($cash) !== 1 || $parts[$cash[0]]['amount_minor'] < $change) {
                throw ValidationException::withMessages(['payments' => 'المقبوض يتجاوز المستحق. تُقبل الزيادة النقدية كباقٍ يعاد للعميل فقط.']);
            }
            $parts[$cash[0]]['amount_minor'] -= $change;
            $parts[$cash[0]]['change_minor'] = $change;
            $total -= $change;
        }

        return ['parts' => $parts, 'paid' => $total, 'change' => $change];
    }

    /** @param list<array> $parts */
    public function assertFunds(array $parts): void
    {
        foreach ($parts as $part) {
            if (app(PostJournalAction::class)->balance((int) $part['financial_account_id']) < $part['amount_minor']) {
                throw ValidationException::withMessages(['payments' => 'رصيد حساب رد الأموال غير كافٍ. راجع الحساب الفعلي أو رصيده الافتتاحي المعتمد.']);
            }
        }
    }

    public function opening(FinancialAccount $account, array $data, User $actor): void
    {
        OperationContext::run(function () use ($account, $data, $actor): void {
            if (! in_array($account->type, ['cash', 'bank', 'wallet'], true) || ! $account->branch_id) {
                throw ValidationException::withMessages(['account' => 'رصيد البداية متاح لحسابات الاستلام الفعلية فقط.']);
            }
            $branch = OperationContext::branch($actor, $account->branch_id, 'sales.manage_accounts');
            $date = OperationContext::date($branch);
            $current = FinancialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $opening = JournalEntry::where('source_type', $current->getMorphClass())->where('source_id', $current->id)->where('event', 'opening')->first();
            if ($opening) {
                $existingAmount = (int) $opening->lines()->where('financial_account_id', $current->id)->sum('debit_minor');
                abort_unless($existingAmount === Money::minor($data['opening_balance']), 409, 'رصيد البداية معتمد بالفعل بمبلغ مختلف.');

                return;
            }
            if (! in_array($current->type, ['cash', 'bank', 'wallet'], true) || $current->lines()->exists()) {
                throw ValidationException::withMessages(['account' => 'رصيد البداية يحدد قبل أول حركة فقط. بعد التشغيل استخدم مستندًا ماليًا مناسبًا.']);
            }
            $amount = Money::minor($data['opening_balance']);
            app(PostJournalAction::class)->execute($current, 'opening', $branch->id, $actor, $date, [
                ['account' => $current->id, 'amount' => $amount], ['account' => 'opening_equity', 'amount' => -$amount],
            ], 'اعتماد رصيد بداية حساب');
            OperationContext::audit($current, $actor, 'account.opening_approved', $data['reason'], ['amount_minor' => $amount]);
        });
    }
}
