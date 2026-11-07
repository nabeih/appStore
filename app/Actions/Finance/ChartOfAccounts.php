<?php

namespace App\Actions\Finance;

use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Models\User;
use App\Support\Sales\Money;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesReports;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ChartOfAccounts
{
    public const GROUPS = ['asset' => 'الأصول', 'liability' => 'الالتزامات', 'equity' => 'حقوق الملكية', 'revenue' => 'الإيرادات', 'expense' => 'المصروفات وتكلفة المبيعات'];

    /** @return array{account_group:string, normal_balance:string}|null */
    public static function classification(string $code, string $type): ?array
    {
        $group = match (true) {
            in_array($type, ['cash', 'bank', 'wallet'], true), in_array($code, ['inventory', 'quarantine', 'receivables', 'courier_receivables'], true) => 'asset',
            in_array($code, ['supplier_payables', 'expense_payables', 'customer_credit'], true) => 'liability',
            in_array($code, ['owner_equity', 'opening_equity'], true) => 'equity',
            in_array($code, ['sales', 'sales_returns', 'delivery_revenue'], true) => 'revenue',
            in_array($code, ['operating_expenses', 'inventory_loss', 'cogs', 'cash_variance'], true) => 'expense',
            default => null,
        };

        return $group === null ? null : ['account_group' => $group, 'normal_balance' => in_array($group, ['asset', 'expense'], true) || $code === 'sales_returns' ? 'debit' : 'credit'];
    }

    public function installed(): bool
    {
        return Schema::hasColumn('financial_accounts', 'is_posting');
    }

    /** @return Collection<int, FinancialAccount> */
    public function accounts(User $actor, ?int $branchId = null): Collection
    {
        Gate::forUser($actor)->authorize('finance.view');
        Gate::forUser($actor)->authorize('sales.view_cost');
        $branches = SalesReports::branches($actor, false)->modelKeys();
        if ($branchId !== null) {
            abort_unless(in_array($branchId, $branches, true), 403);
            $branches = [$branchId];
        }
        $balances = JournalLine::whereHas('entry', fn ($query) => $query->whereIn('branch_id', $branches))
            ->select('financial_account_id')->selectRaw('SUM(debit_minor) AS debits, SUM(credit_minor) AS credits')->groupBy('financial_account_id')->get()->keyBy('financial_account_id');

        return FinancialAccount::where(fn ($query) => $query->whereNull('branch_id')->orWhereIn('branch_id', $branches))->orderBy('code')->get()
            ->each(function (FinancialAccount $account) use ($balances): void {
                $meta = self::classification($account->code, $account->type);
                $account->setAttribute('account_group', $account->account_group ?? $meta['account_group'] ?? null);
                $account->setAttribute('normal_balance', $account->normal_balance ?? $meta['normal_balance'] ?? 'debit');
                $account->setAttribute('is_posting', $account->is_posting ?? true);
                $account->setAttribute('is_protected', $account->is_protected ?? true);
                $balance = $balances->get($account->id);
                $account->setAttribute('balance_minor', Money::add((int) ($balance?->debits ?? 0), -(int) ($balance?->credits ?? 0)));
            });
    }

    /** Five virtual display roots never insert accounts or journals on a GET. */
    public function tree(Collection $accounts, string $search = ''): array
    {
        $children = $accounts->groupBy(fn ($account) => $account->parent_id ?? 0);
        $visit = function (FinancialAccount $account, array $path = []) use (&$visit, $children, $search): ?array {
            if (in_array($account->id, $path, true)) {
                throw new \LogicException('Invalid account hierarchy; manual review required.');
            }
            $path[] = $account->id;
            $nodes = [];
            $balance = (int) $account->balance_minor;
            foreach ($children->get($account->id, collect()) as $child) {
                $node = $visit($child, $path);
                if ($node) {
                    $nodes[] = $node;
                }
                $balance = Money::add($balance, (int) $child->tree_balance_minor);
            }
            $account->setAttribute('tree_balance_minor', $balance);
            $match = $search === '' || mb_stripos($account->name, $search) !== false || mb_stripos($account->code, $search) !== false;

            return $match || $nodes !== [] ? ['account' => $account, 'children' => $nodes, 'balance_minor' => $balance] : null;
        };
        $groups = [];
        foreach (self::GROUPS as $key => $label) {
            $nodes = [];
            foreach ($children->get(0, collect())->where('account_group', $key) as $account) {
                if ($node = $visit($account)) {
                    $nodes[] = $node;
                }
            }
            $groups[] = ['key' => $key, 'label' => $label, 'nodes' => $nodes];
        }

        return $groups;
    }

    public function save(array $data, User $actor, ?FinancialAccount $account = null): FinancialAccount
    {
        foreach (['finance.view', 'sales.view_cost', 'sales.manage_accounts'] as $ability) {
            Gate::forUser($actor)->authorize($ability);
        }
        abort_unless($this->installed(), 409, 'يلزم تطبيق تحديث شجرة الحسابات المعتمد أولًا.');

        return OperationContext::run(function () use ($data, $actor, $account): FinancialAccount {
            $branchId = empty($data['branch_id']) ? null : (int) $data['branch_id'];
            $this->authorizeBranch($actor, $branchId);
            $current = $account ? FinancialAccount::whereKey($account->id)->lockForUpdate()->firstOrFail() : new FinancialAccount;
            if ($current->exists) {
                $this->authorizeBranch($actor, $current->branch_id);
            }
            $before = $current->getAttributes();
            $parent = empty($data['parent_id']) ? null : FinancialAccount::whereKey($data['parent_id'])->lockForUpdate()->firstOrFail();
            $group = $data['account_group'];
            $normal = $data['normal_balance'];
            $posting = (bool) $data['is_posting'];
            $active = (bool) $data['is_active'];
            if ($parent && ($parent->is_posting || ! $parent->is_active || $parent->account_group !== $group || ($parent->branch_id !== null && (int) $parent->branch_id !== $branchId))) {
                $this->fail('الحساب الأب يجب أن يكون تجميعيًا فعالًا من المجموعة والفرع نفسيهما، أو عامًا لجميع الفروع.');
            }
            $seen = [$current->id];
            $cursor = $parent;
            while ($cursor) {
                if (in_array($cursor->id, $seen, true)) {
                    $this->fail('لا يمكن ربط الحساب بنفسه أو بأحد حساباته الفرعية.');
                }
                $seen[] = $cursor->id;
                $cursor = $cursor->parent_id ? FinancialAccount::findOrFail($cursor->parent_id) : null;
            }
            if ($current->exists) {
                $structural = $current->code !== $data['code'] || $current->account_group !== $group || $current->normal_balance !== $normal
                    || (bool) $current->is_posting !== $posting || $current->parent_id != ($parent?->id) || $current->branch_id != $branchId;
                if ($current->is_protected && ($structural || ! $active)) {
                    $this->fail('حساب مرتبط بالنظام: لا يُغير رمزه أو تصنيفه أو حالته من شجرة الحسابات.');
                }
                if (($current->lines()->exists() && ($structural || ! $active)) || ($current->children()->exists() && ($structural || ! $active))) {
                    $this->fail('لا يُغير هيكل أو تصنيف حساب له قيود أو حسابات فرعية، ولا يُعطل.');
                }
            }
            if (FinancialAccount::where('code', $data['code'])->when($current->exists, fn ($query) => $query->whereKeyNot($current->id))->exists()) {
                $this->fail('رمز الحساب مستخدم بالفعل.');
            }
            $current->forceFill(['code' => $data['code'], 'name' => trim($data['name']), 'type' => $current->type ?? 'system',
                'branch_id' => $branchId, 'parent_id' => $parent?->id, 'account_group' => $group, 'normal_balance' => $normal,
                'is_posting' => $posting, 'is_active' => $active, 'is_protected' => $current->exists ? $current->is_protected : false])->save();
            OperationContext::audit($current, $actor, 'account.chart_saved', $data['reason'], ['before' => $before, 'after' => $current->getAttributes()]);

            return $current;
        });
    }

    private function authorizeBranch(User $actor, ?int $branchId): void
    {
        if ($branchId === null) {
            Gate::forUser($actor)->authorize('sales.all_branches');
        } else {
            OperationContext::branch($actor, $branchId, 'sales.manage_accounts', false);
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['account' => $message]);
    }
}
