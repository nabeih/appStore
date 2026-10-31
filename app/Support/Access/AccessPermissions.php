<?php

namespace App\Support\Access;

use App\Models\User;
use App\Support\Sales\SalesAccess;

class AccessPermissions
{
    public const EXTRA = [
        'catalog.search' => 'البحث عن المنتجات',
        'inventory.view' => 'عرض كميات المخزون',
        'purchases.view' => 'عرض المشتريات والموردين',
        'purchases.manage' => 'إنشاء وإدارة مستندات الشراء',
        'access.roles.manage' => 'إدارة الأدوار والصلاحيات',
        'access.users.manage' => 'إسناد الأدوار وتعطيل الموظفين',
    ];

    public const PROTECTED = ['access.roles.manage', 'access.users.manage', 'sales.manage_access'];

    /** @return array<string, array{label: string, permissions: list<string>}> */
    public static function defaults(): array
    {
        return [
            'sales_owner' => ['label' => 'مالك المتجر', 'permissions' => array_keys(SalesAccess::PERMISSIONS)],
            'sales_manager' => ['label' => 'المدير التشغيلي', 'permissions' => ['catalog.search', 'inventory.view', 'sales.sell', 'sales.view', 'sales.customers', 'sales.collect', 'sales.return', 'sales.exchange']],
            'sales_cashier' => ['label' => 'كاشير', 'permissions' => ['catalog.search', 'inventory.view', 'sales.sell', 'sales.view', 'sales.customers', 'sales.collect']],
            'inventory_viewer' => ['label' => 'موظف مخزون — قراءة فقط', 'permissions' => ['catalog.search', 'inventory.view']],
        ];
    }

    public static function isOwner(User $actor): bool
    {
        $actor->loadMissing('role');

        return $actor->is_active && ! $actor->trashed() && $actor->role?->is_active && $actor->role->name === 'sales_owner';
    }

    public static function group(string $permission): string
    {
        return match (true) {
            str_starts_with($permission, 'access.'), $permission === 'sales.manage_access' => 'المستخدمون والأدوار',
            $permission === 'catalog.search', $permission === 'inventory.manage_catalog' => 'المنتجات والكتالوج',
            str_starts_with($permission, 'inventory.') => 'المخزون والإعداد',
            str_starts_with($permission, 'purchases.'), in_array($permission, ['finance.purchase_post', 'finance.supplier_pay'], true) => 'المشتريات والموردون',
            in_array($permission, ['sales.return', 'sales.exchange', 'sales.reverse'], true) => 'المرتجعات والعكس',
            $permission === 'sales.customers' => 'العملاء',
            $permission === 'sales.collect' => 'التحصيل',
            $permission === 'sales.all_branches' => 'الفروع',
            in_array($permission, ['sales.view_cost', 'sales.reports'], true) => 'التكلفة والتقارير',
            str_starts_with($permission, 'finance.'), $permission === 'sales.manage_accounts' => 'المالية والصناديق',
            default => 'المبيعات',
        };
    }
}
