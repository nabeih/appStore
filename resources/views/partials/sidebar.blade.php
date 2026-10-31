<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <i class="fas fa-tshirt"></i><span>متجر الأناقة</span>
    </div>
    <div class="sidebar-user">
        <div class="user-avatar" id="userAvatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</div>
        <div class="sidebar-user-info">
            <h6 id="userName">{{ auth()->user()->name }}</h6>
            <small id="userRole">{{ auth()->user()->username }}</small>
        </div>
    </div>
    <nav class="sidebar-nav" id="sidebarNav">

        <div class="nav-title">الرئيسية</div>

        <a href="{{ route('dashboard.index') }}"
            class="nav-item {{ request()->routeIs('dashboard.*') ? 'active' : '' }}"
            style="text-decoration:none;color:inherit;">
            <i class="fas fa-chart-pie"></i>
            <span>لوحة التحكم</span>
        </a>

        <div class="nav-title">العمليات</div>
        @can('finance.view')<a href="{{ route('finance.expenses.index') }}" class="nav-item"><i class="fas fa-file-invoice-dollar"></i><span>المصروفات</span></a><a href="{{ route('finance.treasury.index') }}" class="nav-item"><i class="fas fa-money-bill-transfer"></i><span>الصناديق والتحويلات</span></a>@endcan
        @can('finance.transition_review')<a href="{{ route('finance.transition.index') }}" class="nav-item"><i class="fas fa-scale-balanced"></i><span>مراجعة الانتقال المالي</span></a>@endcan
        @can('sales.sell')<a href="{{ route('sales.cashier') }}" class="nav-item {{ request()->routeIs('sales.cashier') ? 'active' : '' }}" style="text-decoration:none;color:inherit;"><i class="fas fa-cash-register"></i><span>الكاشير</span></a>@endcan
        @can('sales.view')<a href="{{ route('sales.invoices.index') }}" class="nav-item {{ request()->routeIs('sales.invoices.*','sales.returns.*','sales.exchanges.*') ? 'active' : '' }}" style="text-decoration:none;color:inherit;"><i class="fas fa-receipt"></i><span>سجل المبيعات</span></a>@endcan
        @can('sales.customers')<a href="{{ route('customers.index') }}" class="nav-item {{ request()->routeIs('customers.*') ? 'active' : '' }}" style="text-decoration:none;color:inherit;"><i class="fas fa-users"></i><span>العملاء والتحصيل</span></a>@endcan
        @can('sales.reports')<a href="{{ route('sales.reports') }}" class="nav-item {{ request()->routeIs('sales.reports') ? 'active' : '' }}" style="text-decoration:none;color:inherit;"><i class="fas fa-chart-line"></i><span>تقارير المبيعات</span></a>@endcan
        @can('sales.manage_accounts')<a href="{{ route('finance.accounts.index') }}" class="nav-item {{ request()->routeIs('finance.accounts.*') ? 'active' : '' }}" style="text-decoration:none;color:inherit;"><i class="fas fa-wallet"></i><span>حسابات القبض</span></a>@endcan
        @can('inventory.valuation_review')<a href="{{ route('inventory.review.index') }}" class="nav-item {{ request()->routeIs('inventory.review.*') ? 'active' : '' }}" style="text-decoration:none;color:inherit;"><i class="fas fa-clipboard-check"></i><span>جاهزية المخزون</span></a>@endcan

        @can('inventory.view')
        <a href="{{ route('inventory.index') }}"
            class="nav-item {{ request()->routeIs('inventory.index', 'inventory.update') ? 'active' : '' }}"
            style="text-decoration:none;color:inherit;">
            <i class="fas fa-dolly-flatbed"></i>
            <span>إدارة المخزون</span>
        </a>
        @endcan

        @can('inventory.correct_setup')
        <a href="{{ route('inventory.opening-stock.index') }}" class="nav-item {{ request()->routeIs('inventory.opening-stock.*') ? 'active' : '' }}" style="text-decoration:none;color:inherit;">
            <i class="fas fa-box-open"></i><span>البضاعة الافتتاحية</span>
        </a>
        @endcan
        @can('inventory.manage_catalog')
        <a href="{{ route('catalog.attributes.index') }}" class="nav-item {{ request()->routeIs('catalog.attributes.*') ? 'active' : '' }}" style="text-decoration:none;color:inherit;">
            <i class="fas fa-tags"></i><span>التصنيفات والسمات</span>
        </a>
        @endcan
        @can('purchases.view')
        @can('sales.view_cost')
        <a href="{{ route('suppliers.index') }}" class="nav-item {{ request()->routeIs('suppliers.*') ? 'active' : '' }}" style="text-decoration:none;color:inherit;"><i class="fas fa-truck"></i><span>الموردون</span></a>
        <a href="{{ route('purchases.invoices.index') }}" class="nav-item {{ request()->routeIs('purchases.*') ? 'active' : '' }}" style="text-decoration:none;color:inherit;"><i class="fas fa-file-invoice"></i><span>فواتير الشراء</span></a>
        @endcan
        @endcan
        @can('access.roles.manage')<a href="{{ route('access.roles.index') }}" class="nav-item"><i class="fas fa-user-shield"></i><span>الأدوار والصلاحيات</span></a>@endcan
        @can('access.users.manage')<a href="{{ route('access.users.index') }}" class="nav-item"><i class="fas fa-user-cog"></i><span>إدارة الموظفين</span></a>@endcan
    </nav>

</aside>