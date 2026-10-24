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

        <a href="{{ route('inventory.index') }}"
            class="nav-item {{ request()->routeIs('inventory.index', 'inventory.update') ? 'active' : '' }}"
            style="text-decoration:none;color:inherit;">
            <i class="fas fa-dolly-flatbed"></i>
            <span>إدارة المخزون</span>
        </a>

        <a href="{{ route('inventory.opening-stock.index') }}" class="nav-item {{ request()->routeIs('inventory.opening-stock.*') ? 'active' : '' }}" style="text-decoration:none;color:inherit;">
            <i class="fas fa-box-open"></i><span>البضاعة الافتتاحية</span>
        </a>
        <a href="{{ route('catalog.attributes.index') }}" class="nav-item {{ request()->routeIs('catalog.attributes.*') ? 'active' : '' }}" style="text-decoration:none;color:inherit;">
            <i class="fas fa-tags"></i><span>التصنيفات والسمات</span>
        </a>
        <a href="{{ route('suppliers.index') }}" class="nav-item {{ request()->routeIs('suppliers.*') ? 'active' : '' }}" style="text-decoration:none;color:inherit;"><i class="fas fa-truck"></i><span>الموردون</span></a>
        <a href="{{ route('purchases.invoices.index') }}" class="nav-item {{ request()->routeIs('purchases.*') ? 'active' : '' }}" style="text-decoration:none;color:inherit;"><i class="fas fa-file-invoice"></i><span>فواتير الشراء</span></a>
    </nav>

</aside>