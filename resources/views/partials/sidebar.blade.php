<aside class="sidebar" id="sidebar" aria-label="القائمة الرئيسية">
    <div class="sidebar-header"><i class="fas fa-tshirt" aria-hidden="true"></i><span>متجر الأناقة</span></div>
    <div class="sidebar-user">
        <div class="user-avatar" id="userAvatar">{{ mb_substr(auth()->user()->name, 0, 1) }}</div>
        <div class="sidebar-user-info"><h6 id="userName">{{ auth()->user()->name }}</h6><small id="userRole">{{ auth()->user()->username }}</small></div>
        <button type="button" class="sidebar-close" data-sidebar-close aria-label="إغلاق القائمة">×</button>
    </div>
    <nav class="sidebar-nav" id="sidebarNav" aria-label="وحدات المتجر">
        <a href="{{ route('dashboard.index') }}" class="nav-item nav-dashboard {{ request()->routeIs('dashboard.*') ? 'active' : '' }}" @if(request()->routeIs('dashboard.*')) aria-current="page" @endif>
            <i class="fas fa-chart-pie" aria-hidden="true"></i><span>لوحة التحكم</span>
        </a>
        @can('store.orders.view')<a class="nav-item {{ request()->routeIs('store.orders.*') ? 'active' : '' }}" href="{{ route('store.orders.index') }}"><i class="fas fa-bag-shopping" aria-hidden="true"></i><span>طلبات الموقع</span><span class="store-count-badge" data-store-order-count hidden>0</span></a>@endcan
        @if(auth()->user()->can('store.manage') && auth()->user()->can('sales.all_branches'))<a class="nav-item" href="{{ route('store.admin.home') }}"><i class="fas fa-globe" aria-hidden="true"></i><span>إدارة الموقع</span></a>@endif
        @foreach(app(\App\Support\Navigation\SidebarNavigation::class)->groups(auth()->user(), request()) as $group)
        <details class="nav-group {{ $group['active'] ? 'is-current' : '' }}" data-nav-group="{{ $group['key'] }}" @if($group['active']) open @endif>
            <summary class="nav-group-toggle" aria-expanded="{{ $group['active'] ? 'true' : 'false' }}" aria-controls="nav-group-{{ $group['key'] }}">
                <i class="fas {{ $group['icon'] }}" aria-hidden="true"></i><span>{{ $group['label'] }}</span><span class="nav-group-arrow" aria-hidden="true">⌄</span>
            </summary>
            <div class="nav-group-links" id="nav-group-{{ $group['key'] }}">
            @foreach($group['links'] as $link)
                <a href="{{ route($link['route']) }}" class="nav-item nav-child {{ $link['active'] ? 'active' : '' }}" @if($link['active']) aria-current="page" @endif>
                    <i class="fas {{ $link['icon'] }}" aria-hidden="true"></i><span>{{ $link['label'] }}</span>
                </a>
            @endforeach
            @if($group['key'] === 'account')
                <form method="POST" action="{{ route('logout') }}" class="nav-logout-form">@csrf<button class="nav-item nav-child nav-logout" type="submit"><i class="fas fa-sign-out-alt" aria-hidden="true"></i><span>تسجيل الخروج</span></button></form>
            @endif
            </div>
        </details>
        @endforeach
    </nav>
</aside>
