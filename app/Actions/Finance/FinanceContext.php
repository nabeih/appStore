<?php

namespace App\Actions\Finance;

use App\Models\FinancialAccount;
use App\Models\User;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class FinanceContext
{
    public static function installed(): bool
    {
        return Schema::hasTable('expenses') && Schema::hasTable('expense_payments') && Schema::hasTable('cash_counts') && Schema::hasTable('treasury_documents') && Schema::hasColumn('supplier_payments', 'financial_account_id') && Schema::hasColumn('journal_lines', 'expense_id');
    }

    public static function ready(): void
    {
        abort_unless(self::installed(), 503, 'التحديث المالي مجهز ولم يُطبق على هذه القاعدة؛ يلزم اعتماد نشره أولًا.');
    }

    public static function authorize(User $actor, int $branchId, string $ability, ?string $date = null, bool $todayOnly = true): string
    {
        self::ready();
        $branch = OperationContext::branch($actor, $branchId, $ability);
        ManageFinancialAccountsAction::provision();

        return OperationContext::date($branch, $date, $todayOnly);
    }

    public static function account(int $id, int $branchId): FinancialAccount
    {
        $account = FinancialAccount::whereKey($id)->where('branch_id', $branchId)->whereIn('type', ['cash', 'bank', 'wallet'])->where('is_active', true)->lockForUpdate()->first();
        if (! $account) {
            self::fail('اختر حسابًا فعليًا فعالًا في الفرع.');
        }

        return $account;
    }

    public static function funds(FinancialAccount $account, int $amount): void
    {
        if (app(PostJournalAction::class)->balance($account->id) < $amount) {
            self::fail('رصيد الدفتر في الحساب المحدد غير كافٍ. لا تضف رصيدًا افتتاحيًا لتجاوز فرق غير مفسر.');
        }
    }

    public static function amount(string|int $amount): int
    {
        $minor = Money::minor($amount);
        if ($minor <= 0) {
            self::fail('أدخل مبلغًا موجبًا.');
        }

        return $minor;
    }

    public static function fail(string $message): never
    {
        throw ValidationException::withMessages(['finance' => $message]);
    }
}
