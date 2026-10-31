<?php

namespace App\Actions\Access;

use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\AccessPermissions;
use App\Support\Sales\OperationContext;
use App\Support\Sales\SalesAccess;
use App\Support\Sales\SalesReports;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageAccessAction
{
    /** @param array{display_name: string, is_active: bool|int|string, permissions?: list<string>} $data */
    public function saveRole(User $actor, array $data, ?Role $role = null): Role
    {
        return DB::transaction(function () use ($actor, $data, $role): Role {
            $actor = $this->actor($actor, 'access.roles.manage');
            $current = $role ? Role::whereKey($role->id)->lockForUpdate()->firstOrFail() : null;
            abort_if($current?->name === 'sales_owner', 403, 'دور المالك محمي من التعديل والتعطيل.');
            abort_if($current && (int) $actor->role_id === $current->id, 403, 'لا يمكن تعديل الدور المسند إليك.');
            $permissions = array_values(array_unique($data['permissions'] ?? []));
            $this->assertGrantable($actor, $permissions);
            if ($current) {
                if (! AccessPermissions::isOwner($actor)) {
                    $branchIds = SalesReports::branches($actor, false)->modelKeys();
                    abort_if($current->users()->where(fn ($query) => $query->whereNotIn('branch_id', $branchIds)->orWhereNull('branch_id'))->exists(), 403, 'الدور مرتبط بموظفين خارج نطاق فروعك.');
                }
                $this->assertGrantable($actor, $current->permissions()->pluck('name')->all());
            }
            $before = $current ? ['label' => $current->display_name, 'active' => $current->is_active, 'permissions' => $current->permissions()->pluck('name')->all()] : null;
            if (! $current) {
                $current = new Role(['name' => 'custom_'.Str::uuid()]);
            }
            $current->fill(['display_name' => $data['display_name'], 'is_active' => (bool) $data['is_active']])->save();
            $current->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));
            $this->assertOwner();
            OperationContext::audit($current, $actor, 'access.role_saved', null, ['before' => $before, 'after' => ['label' => $current->display_name, 'active' => $current->is_active, 'permissions' => $permissions]]);

            return $current;
        });
    }

    /** @param array{role_id: int|string, branch_id?: int|string|null, is_active: bool|int|string} $data */
    public function assign(User $actor, User $target, array $data): User
    {
        return DB::transaction(function () use ($actor, $target, $data): User {
            $actor = $this->actor($actor, 'access.users.manage');
            $current = User::whereKey($target->id)->lockForUpdate()->firstOrFail();
            $this->assertEmployeeTarget($actor, $current);
            $newRole = Role::whereKey($data['role_id'])->where('is_active', true)->lockForUpdate()->firstOrFail();
            abort_if($newRole->name === 'sales_owner', 403, 'إسناد دور الملكية محمي.');
            $this->assertGrantable($actor, $newRole->permissions()->pluck('name')->all());
            $branchId = $data['branch_id'] ?? null;
            if (! AccessPermissions::isOwner($actor)) {
                abort_unless(SalesReports::branches($actor)->contains('id', (int) $branchId), 403);
            }
            if ($branchId !== null) {
                Branch::whereKey($branchId)->where('is_active', true)->firstOrFail();
            } elseif (! $newRole->permissions()->where('name', 'sales.all_branches')->exists()) {
                throw ValidationException::withMessages(['branch_id' => 'حدد فرعًا لهذا الدور؛ صلاحية عامة لا تمنح كل الفروع.']);
            }
            $before = ['role_id' => $current->role_id, 'branch_id' => $current->branch_id, 'is_active' => $current->is_active];
            $current->forceFill(['role_id' => $newRole->id, 'branch_id' => $branchId, 'is_active' => (bool) $data['is_active']])->save();
            $this->assertOwner();
            OperationContext::audit($current, $actor, 'access.user_assigned', null, ['before' => $before, 'after' => ['role_id' => $current->role_id, 'branch_id' => $current->branch_id, 'is_active' => $current->is_active]]);

            return $current->unsetRelation('role')->unsetRelation('branch');
        });
    }

    /** @param array<string, mixed> $data */
    public function createEmployee(User $actor, array $data): User
    {
        try {
            return DB::transaction(function () use ($actor, $data): User {
                $actor = $this->actor($actor, 'access.users.manage');
                $employee = new User([
                    'name' => $data['name'], 'username' => $data['username'],
                    'email' => $data['email'] ?? null, 'password' => Hash::make($data['password']),
                ]);
                $employee->forceFill(['branch_id' => $data['branch_id']])->save();
                $employee = $this->assign($actor, $employee, $data);
                OperationContext::audit($employee, $actor, 'access.employee_created', null, ['after' => $this->employeeDetails($employee)]);

                return $employee;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['username' => 'اسم المستخدم أو البريد الإلكتروني مستخدم بالفعل. لم يُنشأ حساب مكرر.']);
        }
    }

    /** @param array<string, mixed> $data */
    public function updateEmployee(User $actor, User $employee, array $data): User
    {
        try {
            return DB::transaction(function () use ($actor, $employee, $data): User {
                $actor = $this->actor($actor, 'access.users.manage');
                $current = User::whereKey($employee->id)->lockForUpdate()->firstOrFail();
                $before = $this->employeeDetails($current);
                $current = $this->assign($actor, $current, $data);
                $current->fill(['name' => $data['name'], 'username' => $data['username'], 'email' => $data['email'] ?? null])->save();
                OperationContext::audit($current, $actor, 'access.employee_updated', null, ['before' => $before, 'after' => $this->employeeDetails($current)]);

                return $current;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['username' => 'اسم المستخدم أو البريد الإلكتروني مستخدم بالفعل. لم تتغير بيانات الحساب.']);
        }
    }

    public function resetPassword(User $actor, User $employee, string $password): void
    {
        DB::transaction(function () use ($actor, $employee, $password): void {
            $actor = $this->actor($actor, 'access.users.manage');
            $current = User::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $this->assertEmployeeTarget($actor, $current);
            $current->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            $this->assertOwner();
            OperationContext::audit($current, $actor, 'access.employee_password_reset', null, ['password_changed' => true]);
        });
    }

    /** @return array{name: string, username: string, email: ?string, role_id: ?int, branch_id: ?int, is_active: bool} */
    private function employeeDetails(User $employee): array
    {
        return ['name' => $employee->name, 'username' => $employee->username, 'email' => $employee->email,
            'role_id' => $employee->role_id, 'branch_id' => $employee->branch_id, 'is_active' => $employee->is_active];
    }

    private function assertEmployeeTarget(User $actor, User $employee): void
    {
        abort_if($employee->id === $actor->id, 403, 'لا يمكنك تغيير بيانات حسابك أو وصولك من شاشة الموظفين.');
        abort_if($employee->role?->name === 'sales_owner', 403, 'حساب المالك محمي من تعديل الموظفين وإعادة تعيين كلمات المرور.');
        $this->assertGrantable($actor, $employee->role?->permissions()->pluck('name')->all() ?? []);
        if (! AccessPermissions::isOwner($actor)) {
            abort_unless(SalesReports::branches($actor, false)->contains('id', $employee->branch_id), 403);
        }
    }

    public function authorizeEmployee(User $actor, User $employee): void
    {
        Gate::forUser($actor)->authorize('access.users.manage');
        $this->assertEmployeeTarget($actor, $employee);
    }

    /** @return list<string> */
    public function grantable(User $actor): array
    {
        $actor->loadMissing('role.permissions');
        $permissions = $actor->role?->permissions->pluck('name')->all() ?? [];

        return AccessPermissions::isOwner($actor) ? array_keys(SalesAccess::PERMISSIONS) : array_values(array_diff($permissions, AccessPermissions::PROTECTED));
    }

    private function actor(User $actor, string $ability): User
    {
        DB::table('product_code_counters')->where('id', 1)->lockForUpdate()->first();
        $fresh = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
        $fresh->load('role.permissions');
        Gate::forUser($fresh)->authorize($ability);

        return $fresh;
    }

    /** @param list<string> $permissions */
    private function assertGrantable(User $actor, array $permissions): void
    {
        abort_if(array_diff($permissions, $this->grantable($actor)) !== [], 403, 'لا يمكنك منح أو تعديل صلاحيات تتجاوز نطاق إدارتك.');
    }

    private function assertOwner(): void
    {
        abort_unless(User::where('is_active', true)->whereHas('role', fn ($query) => $query->where('name', 'sales_owner')->where('is_active', true))->exists(), 409, 'يجب الحفاظ على مالك فعّال.');
    }
}
