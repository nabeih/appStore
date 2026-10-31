<header class="app-topbar">
    <div class="topbar-right">
        <div class="topbar-store-info">
            <span class="topbar-store-name"><i class="fas fa-store text-primary"></i> متجر الأناقة</span>
            <span class="topbar-date">{{ now()->format('Y/m/d') }}</span>
        </div>
    </div>
    <div id="global-search-desktop">@include('search.partials.header')</div>
    <div class="topbar-left">
        <div class="topbar-user-pill" title="الحساب الحالي">
            <div class="user-avatar-sm">{{ mb_substr(auth()->user()->name, 0, 1) }}</div>
            <span class="user-name-sm">{{ auth()->user()->name }}</span>
        </div>
        <form method="POST" action="{{ route('logout') }}" class="auth-logout-form">
            @csrf
            <button type="submit" class="auth-logout-button"><i class="fas fa-sign-out-alt" aria-hidden="true"></i> تسجيل الخروج</button>
        </form>
    </div>
</header>
<header class="auth-mobile-header">
    <button type="button" class="auth-menu-button" id="auth-menu-toggle" aria-controls="sidebar" aria-expanded="false" aria-label="فتح القائمة"><i class="fas fa-bars" aria-hidden="true"></i></button>
    <span class="auth-mobile-name">{{ auth()->user()->name }}</span>
    <form method="POST" action="{{ route('logout') }}" class="auth-logout-form">
        @csrf
        <button type="submit" class="auth-logout-button">خروج</button>
    </form>
</header>
<div id="global-search-mobile" class="global-search-mobile-slot"></div>
