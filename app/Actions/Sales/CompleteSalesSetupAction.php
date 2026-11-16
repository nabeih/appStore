<?php

namespace App\Actions\Sales;

use App\Actions\Finance\ManageFinancialAccountsAction;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Support\Access\AccessPermissions;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompleteSalesSetupAction
{
    /** @param list<int> $branchIds
     * @return array<int,int>
     */
    public static function selections(array $branchIds): array
    {
        return AuditEvent::where('subject_type', Branch::class)->whereIn('subject_id', $branchIds)->where('action', 'sales.setup_completed')->latest('id')->get()
            ->unique('subject_id')->mapWithKeys(fn ($event): array => [(int) $event->subject_id => (int) $event->data['receiving_account_id']])->all();
    }

    public function execute(User $owner, int $branchId, ?int $accountId = null): FinancialAccount
    {
        abort_unless(AccessPermissions::isOwner($owner), 403);

        return OperationContext::run(function () use ($owner, $branchId, $accountId): FinancialAccount {
            DB::table('product_code_counters')->where('id', 1)->lockForUpdate()->first();
            $branch = OperationContext::branch($owner, $branchId, 'inventory.close_setup');
            Branch::whereKey($branchId)->lockForUpdate()->firstOrFail();
            $accounts = FinancialAccount::where('branch_id', $branchId)->where('type', 'cash')->where('is_active', true)->orderBy('id')->lockForUpdate()->get();
            if ($accountId === null) {
                $previous = self::selections([$branchId])[$branchId] ?? null;
                $accountId = $accounts->contains('id', $previous) ? $previous : null;
            }
            if ($accountId !== null) {
                $account = $accounts->firstWhere('id', $accountId);
                abort_unless($account && $account->is_posting !== false, 422, 'اختر درجًا تفصيليًا فعالًا في الفرع.');
            } elseif ($accounts->count() > 1) {
                throw ValidationException::withMessages(['account_id' => 'توجد أدراج مستقلة: '.$accounts->map(fn ($row): string => $row->name.' #'.$row->id)->implode('، ').'. اختر الحساب المقصود؛ لا دمج تلقائي.']);
            } else {
                $account = $accounts->first();
            }
            if (! $account) {
                $account = FinancialAccount::firstOrCreate(['code' => 'CASH-'.$branchId], ['name' => 'درج '.$branch->name, 'type' => 'cash', 'branch_id' => $branchId]);
            }
            abort_unless($account->is_active && $account->is_posting !== false && (int) $account->branch_id === $branchId && $account->type === 'cash', 422, 'حساب الدرج النظامي موجود لكنه غير جاهز؛ راجعه بدل إنشاء بديل مكرر.');
            ManageFinancialAccountsAction::provision();
            $owner->forceFill(['role_id' => SalesAccess::provisionRole('sales_owner')->id])->save();
            DB::table('sales_controls')->where('id', 1)->update(['owner_id' => $owner->id, 'activated_at' => DB::raw('COALESCE(activated_at, CURRENT_TIMESTAMP)')]);
            OperationContext::audit($branch, $owner, 'sales.setup_completed', null, ['receiving_account_id' => $account->id]);

            return $account;
        });
    }
}
