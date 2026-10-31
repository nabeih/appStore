<?php

namespace App\Http\Controllers\Web\Access;

use App\Actions\Access\ManageAccessAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Access\AssignUserRoleRequest;
use App\Http\Requests\Access\ResetEmployeePasswordRequest;
use App\Http\Requests\Access\SaveEmployeeRequest;
use App\Http\Requests\Access\SaveRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\AccessPermissions;
use App\Support\Sales\SalesReports;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AccessController extends Controller
{
    public function roles(Request $request, ManageAccessAction $access, ?Role $role = null): View
    {
        Gate::authorize('access.roles.manage');
        $roles = Role::with('permissions')->withCount('users')->orderBy('id')->get();
        $selected = $role?->load('permissions');
        $grantable = $access->grantable($request->user());
        $groups = Permission::whereIn('name', $grantable)->orderBy('name')->get()->groupBy(fn (Permission $permission): string => AccessPermissions::group($permission->name));

        return view('access.roles', compact('roles', 'selected', 'groups', 'grantable'));
    }

    public function store(SaveRoleRequest $request, ManageAccessAction $access): RedirectResponse
    {
        $access->saveRole($request->user(), $request->validated());

        return to_route('access.roles.index')->with('status', 'تم إنشاء الدور.');
    }

    public function update(SaveRoleRequest $request, Role $role, ManageAccessAction $access): RedirectResponse
    {
        $access->saveRole($request->user(), $request->validated(), $role);

        return to_route('access.roles.index')->with('status', 'تم حفظ الدور وصلاحياته.');
    }

    public function users(Request $request, ManageAccessAction $access): View
    {
        Gate::authorize('access.users.manage');
        $branches = SalesReports::branches($request->user());
        $users = User::with('role.permissions', 'branch')->when(! AccessPermissions::isOwner($request->user()), fn ($query) => $query->whereIn('branch_id', $branches->modelKeys()))->orderBy('id')->paginate(20);
        $grantable = $access->grantable($request->user());
        $roles = $this->employeeRoles($request, $access);

        return view('access.users', compact('users', 'roles', 'branches', 'grantable'));
    }

    public function createEmployee(Request $request, ManageAccessAction $access): View
    {
        Gate::authorize('access.users.manage');

        return $this->employeeForm($request, $access);
    }

    public function editEmployee(Request $request, User $user, ManageAccessAction $access): View
    {
        $access->authorizeEmployee($request->user(), $user);

        return $this->employeeForm($request, $access, $user);
    }

    private function employeeForm(Request $request, ManageAccessAction $access, ?User $employee = null): View
    {
        $branches = SalesReports::branches($request->user());
        $roles = $this->employeeRoles($request, $access);

        return view('access.employee-form', compact('employee', 'roles', 'branches'));
    }

    private function employeeRoles(Request $request, ManageAccessAction $access): Collection
    {
        $grantable = $access->grantable($request->user());

        return Role::with('permissions')->where('is_active', true)->where('name', '!=', 'sales_owner')->get()
            ->filter(fn (Role $role): bool => array_diff($role->permissions->pluck('name')->all(), $grantable) === []);
    }

    public function storeEmployee(SaveEmployeeRequest $request, ManageAccessAction $access): RedirectResponse
    {
        $access->createEmployee($request->user(), $request->validated());

        return to_route('access.users.index')->with('status', 'تم إنشاء حساب الموظف. سلّمه اسم المستخدم وكلمة المرور التي اخترتها عبر وسيلة خاصة.');
    }

    public function updateEmployee(SaveEmployeeRequest $request, User $user, ManageAccessAction $access): RedirectResponse
    {
        $access->updateEmployee($request->user(), $user, $request->validated());

        return to_route('access.users.index')->with('status', 'تم تحديث بيانات الموظف ووصوله.');
    }

    public function resetEmployeePassword(ResetEmployeePasswordRequest $request, User $user, ManageAccessAction $access): RedirectResponse
    {
        $access->resetPassword($request->user(), $user, $request->validated('password'));

        return to_route('access.users.edit', $user)->with('status', 'تم تعيين كلمة مرور جديدة وإبطال الجلسات القديمة في طلبها التالي. سلّم الموظف الكلمة الجديدة عبر وسيلة خاصة.');
    }

    public function assign(AssignUserRoleRequest $request, User $user, ManageAccessAction $access): RedirectResponse
    {
        $access->assign($request->user(), $user, $request->validated());

        return to_route('access.users.index')->with('status', 'تم تحديث وصول الموظف. تسري التغييرات في الطلب التالي.');
    }
}
