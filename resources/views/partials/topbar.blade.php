<header class="app-topbar">
    <div class="topbar-right">
        <div class="topbar-store-info">
            <span class="topbar-store-name"><i class="fas fa-store text-primary"></i> متجر الأناقة</span>
            <span class="topbar-date">{{ now()->format('Y/m/d') }}</span>
        </div>
    </div>
    <div id="global-search-desktop">@include('search.partials.header')</div>
    <div class="topbar-left">
        @include('store.admin.alert-bell')
        <a href="{{ route('profile.show') }}" class="topbar-user-pill" title="ملفي الشخصي">
            <div class="user-avatar-sm">{{ mb_substr(auth()->user()->name, 0, 1) }}</div>
            <span class="user-name-sm">{{ auth()->user()->name }} · ملفي الشخصي</span>
        </a>
        <form method="POST" action="{{ route('logout') }}" class="auth-logout-form">
            @csrf
            <button type="submit" class="auth-logout-button"><i class="fas fa-sign-out-alt" aria-hidden="true"></i> تسجيل الخروج</button>
        </form>
    </div>
</header>
<header class="auth-mobile-header">
    <button type="button" class="auth-menu-button" id="auth-menu-toggle" aria-controls="sidebar" aria-expanded="false" aria-label="فتح القائمة"><i class="fas fa-bars" aria-hidden="true"></i></button>
    @include('store.admin.alert-bell')
    <a class="auth-mobile-name" href="{{ route('profile.show') }}">ملفي الشخصي</a>
    <form method="POST" action="{{ route('logout') }}" class="auth-logout-form">
        @csrf
        <button type="submit" class="auth-logout-button">خروج</button>
    </form>
</header>
<div id="global-search-mobile" class="global-search-mobile-slot"></div>
