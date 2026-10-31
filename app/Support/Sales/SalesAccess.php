<?php

namespace App\Support\Sales;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\AccessPermissions;

class SalesAccess
{
    public const PERMISSIONS = [
        'sales.sell' => 'إتمام البيع', 'sales.view' => 'عرض المبيعات', 'sales.customers' => 'إدارة العملاء',
        'sales.collect' => 'تحصيل الذمم', 'sales.return' => 'اعتماد المرتجع', 'sales.exchange' => 'اعتماد الاستبدال',
        'sales.reverse' => 'عكس العمليات', 'sales.override_price' => 'تغيير سعر البيع',
        'sales.discount_override' => 'تجاوز حد الخصم', 'sales.below_cost' => 'الموافقة على البيع دون التكلفة',
        'sales.view_cost' => 'عرض التكلفة والربح', 'sales.reports' => 'تقارير المبيعات',
        'sales.manage_accounts' => 'تعريف حسابات الاستلام', 'sales.all_branches' => 'العمل بجميع الفروع',
        'sales.manage_access' => 'تعيين صلاحيات المبيعات', 'inventory.close_setup' => 'إنهاء إعداد الأرصدة',
        'inventory.correct_setup' => 'تصحيح مستند أول المدة', 'inventory.valuation_review' => 'مراجعة الأرصدة القديمة',
        'inventory.manage_catalog' => 'تعديل بيانات المنتجات',
        'finance.view' => 'عرض الدفتر والصناديق', 'finance.purchase_post' => 'اعتماد مالية الشراء', 'finance.supplier_pay' => 'سداد الموردين',
        'finance.expenses.manage' => 'إعداد المصروفات', 'finance.expenses.approve' => 'اعتماد المصروفات', 'finance.expenses.pay' => 'سداد المصروفات',
        'finance.treasury.manage' => 'التحويلات وحركات المالك', 'finance.reconcile' => 'الجرد والتسوية', 'finance.reverse' => 'عكس المستندات المالية',
        'finance.transition_review' => 'مراجعة الانتقال المالي',
    ] + AccessPermissions::EXTRA;

    public static function allows(User $user, string $ability): bool
    {
        if (! $user->is_active || $user->trashed() || ! $user->role_id) {
            return false;
        }
        $user->loadMissing('role.permissions');

        return $user->role?->is_active && $user->role->permissions->contains('name', $ability);
    }

    public static function provisionRole(string $name): Role
    {
        $definition = AccessPermissions::defaults()[$name] ?? ['label' => $name, 'permissions' => []];
        foreach (self::PERMISSIONS as $permission => $label) {
            Permission::firstOrCreate(['name' => $permission], ['display_name' => $label, 'group' => AccessPermissions::group($permission)]);
        }
        $role = Role::firstOrCreate(['name' => $name], ['display_name' => $definition['label'], 'is_active' => true]);
        if ($role->wasRecentlyCreated || $name === 'sales_owner') {
            $role->permissions()->syncWithoutDetaching(Permission::whereIn('name', $definition['permissions'])->pluck('id'));
        }

        return $role;
    }
}
