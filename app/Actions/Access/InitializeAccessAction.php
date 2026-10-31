<?php

namespace App\Actions\Access;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\AccessPermissions;
use App\Support\Sales\SalesAccess;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InitializeAccessAction
{
    public function execute(): void
    {
        DB::transaction(function (): void {
            DB::table('product_code_counters')->where('id', 1)->lockForUpdate()->first();
            $roles = Role::with('permissions')->orderBy('id')->lockForUpdate()->get();
            $owner = $roles->firstWhere('name', 'sales_owner');
            if (User::withTrashed()->exists() && (! $owner?->is_active || ! User::where('role_id', $owner->id)->where('is_active', true)->exists())) {
                throw new RuntimeException('لم يوجد Owner فعّال موثّق؛ لم تتغير الأدوار أو الحسابات.');
            }
            $newPermissions = [];
            foreach (SalesAccess::PERMISSIONS as $name => $label) {
                $permission = Permission::firstOrCreate(['name' => $name], ['display_name' => $label, 'group' => AccessPermissions::group($name)]);
                if ($permission->wasRecentlyCreated) {
                    $newPermissions[] = $name;
                }
            }
            foreach ($roles as $role) {
                $old = $role->permissions->pluck('name');
                $grants = [];
                if ($old->contains('inventory.manage_catalog') || $old->contains('sales.sell')) {
                    $grants = ['catalog.search', 'inventory.view'];
                }
                if ($old->contains('sales.view_cost')) {
                    array_push($grants, 'purchases.view', 'purchases.manage');
                }
                $role->permissions()->syncWithoutDetaching(Permission::whereIn('name', array_intersect($grants, $newPermissions))->pluck('id'));
            }
            foreach (AccessPermissions::defaults() as $name => $definition) {
                $role = Role::firstOrCreate(['name' => $name], ['display_name' => $definition['label'], 'is_active' => true]);
                if ($role->wasRecentlyCreated || $name === 'sales_owner') {
                    $role->permissions()->syncWithoutDetaching(Permission::whereIn('name', $definition['permissions'])->pluck('id'));
                }
            }
        });
    }
}
