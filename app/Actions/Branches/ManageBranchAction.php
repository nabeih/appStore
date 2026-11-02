<?php

namespace App\Actions\Branches;

use App\Actions\Access\FirstRun\InstallationReadiness;
use App\Models\Branch;
use App\Models\User;
use App\Support\Sales\OperationContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageBranchAction
{
    public function __construct(private CreateBranchAction $create, private BranchDependencies $dependencies) {}

    /** @param array{name: string, address?: ?string, phone?: ?string} $data */
    public function save(User $actor, array $data, ?Branch $branch = null): Branch
    {
        try {
            return OperationContext::run(function () use ($actor, $data, $branch): Branch {
                $this->assertOnboardingFinished();
                $fresh = User::with('role.permissions')->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($fresh)->authorize($branch ? 'branches.update' : 'branches.create');
                if ($branch) {
                    $current = OperationContext::branch($fresh, $branch->id, 'branches.update', false);
                    $current = Branch::whereKey($current->id)->lockForUpdate()->firstOrFail();
                    $before = $current->only(['name', 'address', 'phone']);
                    $current->forceFill(['name' => $data['name'], 'address' => $data['address'] ?? null, 'phone' => $data['phone'] ?? null]);
                    if (! $current->isDirty()) {
                        return $current;
                    }
                    $current->save();
                } else {
                    Gate::forUser($fresh)->authorize('sales.all_branches');
                    $before = null;
                    $current = $this->create->execute($data);
                }
                OperationContext::audit($current, $fresh, $branch ? 'branch.updated' : 'branch.created', null,
                    ['before' => $before, 'after' => $current->only(['name', 'address', 'phone', 'type', 'is_active'])]);

                return $current;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'اسم الفرع مستخدم بالفعل؛ اختر اسمًا آخر.']);
        }
    }

    public function status(User $actor, Branch $branch, bool $active, string $reason): Branch
    {
        return OperationContext::run(function () use ($actor, $branch, $active, $reason): Branch {
            $this->assertOnboardingFinished();
            $fresh = User::with('role.permissions')->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            OperationContext::branch($fresh, $branch->id, 'branches.manage_status', false);
            $current = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            if ((bool) $current->is_active === $active) {
                return $current;
            }
            if (! $active && ($blockers = $this->dependencies->blockers($current))) {
                throw ValidationException::withMessages(['branch' => $blockers]);
            }
            $before = (bool) $current->is_active;
            $current->forceFill(['is_active' => $active])->save();
            OperationContext::audit($current, $fresh, 'branch.status_changed', $reason, ['before' => $before, 'after' => $active]);

            return $current;
        });
    }

    private function assertOnboardingFinished(): void
    {
        $state = app(InstallationReadiness::class)->state();
        abort_if($state?->first_branch_required && $state?->first_branch_completed_at === null, 409, 'أكمل معالج الفرع الأول؛ لا يُنشأ من إدارة الفروع.');
    }
}
