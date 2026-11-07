<?php

namespace App\Support\Navigation;

use App\Models\User;
use Illuminate\Http\Request;

class SidebarNavigation
{
    /** @return list<array{key: string, label: string, icon: string, links: list<array<string, mixed>>, active: bool}> */
    public function groups(User $actor, Request $request): array
    {
        $definitions = [
            ['sales', 'المبيعات', 'fa-cash-register', [
                ['sales.cashier', 'الكاشير', 'fa-cash-register', ['sales.view', 'sales.sell'], ['sales.cashier', 'sales.invoices.edit']],
                ['sales.invoices.index', 'فواتير المبيعات والمرتجعات', 'fa-receipt', ['sales.view'], ['sales.invoices.*', 'sales.returns.*', 'sales.exchanges.*']],
                ['customers.index', 'العملاء والتحصيل', 'fa-users', ['sales.customers'], ['customers.*']],
            ]],
            ['inventory', 'المنتجات والمخزون', 'fa-boxes-stacked', [
                ['inventory.operation.index', 'حالة تشغيل المحل', 'fa-toggle-on', ['inventory.close_setup', 'sales.view_cost'], ['inventory.operation.*']],
                ['inventory.index', 'إدارة المخزون والمنتجات', 'fa-boxes-stacked', ['inventory.view'], ['inventory.index', 'inventory.products.*', 'inventory.update', 'catalog.swatches.*']],
                ['catalog.attributes.index', 'التصنيفات والألوان والمقاسات', 'fa-tags', ['inventory.manage_catalog'], ['catalog.attributes.*']],
                ['inventory.opening-stock.index', 'البضاعة الافتتاحية', 'fa-box-open', ['inventory.correct_setup', 'sales.view_cost'], ['inventory.opening-stock.*']],
                ['inventory.review.index', 'مراجعة وجاهزية المخزون', 'fa-clipboard-check', ['inventory.valuation_review', 'sales.view_cost'], ['inventory.review.*']],
            ]],
            ['purchases', 'المشتريات والموردون', 'fa-truck', [
                ['purchases.invoices.index', 'فواتير الشراء', 'fa-file-invoice', ['purchases.view', 'sales.view_cost'], ['purchases.*']],
                ['suppliers.index', 'الموردون', 'fa-truck', ['purchases.view', 'sales.view_cost'], ['suppliers.*']],
            ]],
            ['finance', 'المالية والمحاسبة', 'fa-wallet', [
                ['finance.chart.index', 'شجرة الحسابات', 'fa-sitemap', ['finance.view', 'sales.view_cost'], ['finance.chart.*']],
                ['finance.inventory-reconciliation', 'مطابقة قيمة المخزون والدفتر', 'fa-scale-balanced', ['finance.view', 'sales.view_cost', 'inventory.view'], ['finance.inventory-reconciliation']],
                ['finance.expenses.index', 'المصروفات', 'fa-file-invoice-dollar', ['finance.view'], ['finance.expenses.*', 'finance.expense-payments.*']],
                ['finance.treasury.index', 'الصناديق والتحويلات', 'fa-money-bill-transfer', ['finance.view'], ['finance.treasury.*', 'finance.counts.*']],
                ['finance.accounts.index', 'الحسابات المالية', 'fa-wallet', ['sales.manage_accounts'], ['finance.accounts.*']],
                ['finance.transition.index', 'مراجعة الانتقال المالي', 'fa-scale-balanced', ['finance.transition_review', 'sales.view_cost'], ['finance.transition.*', 'finance.suppliers.*']],
            ]],
            ['store', 'إدارة المتجر', 'fa-store', [
                ['branches.index', 'الفروع', 'fa-store', ['branches.view'], ['branches.*']],
                ['access.users.index', 'الموظفون', 'fa-user-cog', ['access.users.manage'], ['access.users.*']],
                ['access.roles.index', 'الأدوار والصلاحيات', 'fa-user-shield', ['access.roles.manage'], ['access.roles.*']],
            ]],
            ['reports', 'التقارير', 'fa-chart-line', [
                ['sales.reports', 'تقارير المبيعات', 'fa-chart-line', ['sales.reports', 'sales.view_cost', 'sales.view'], ['sales.reports']],
            ]],
            ['account', 'الحساب', 'fa-user-circle', [
                ['profile.show', 'ملفي الشخصي وكلمة المرور', 'fa-user-circle', [], ['profile.*']],
            ]],
        ];
        $groups = [];
        foreach ($definitions as [$key, $label, $icon, $items]) {
            $links = [];
            foreach ($items as [$route, $title, $itemIcon, $permissions, $matches]) {
                if (collect($permissions)->contains(fn (string $ability): bool => ! $actor->can($ability))) {
                    continue;
                }
                $active = $request->routeIs(...$matches);
                if ($route === 'sales.invoices.index' && $request->routeIs('sales.invoices.edit')) {
                    $active = false;
                }
                $links[] = ['route' => $route, 'label' => $title, 'icon' => $itemIcon, 'active' => $active];
            }
            if ($links !== [] || $key === 'account') {
                $groups[] = ['key' => $key, 'label' => $label, 'icon' => $icon, 'links' => $links, 'active' => collect($links)->contains('active', true)];
            }
        }

        return $groups;
    }
}
