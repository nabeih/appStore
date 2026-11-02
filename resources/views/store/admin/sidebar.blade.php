<aside class="sidebar" id="sidebar" aria-label="إدارة الموقع">
<div class="sidebar-header"><i class="fas fa-globe"></i><span>إدارة الموقع</span></div>
<div class="sidebar-user"><div class="user-avatar">{{ mb_substr(auth()->user()->name,0,1) }}</div><div><h6>{{ auth()->user()->name }}</h6><small>لوحة المتجر الإلكتروني</small></div><button type="button" class="sidebar-close" data-sidebar-close aria-label="إغلاق">×</button></div>
<nav class="sidebar-nav">
<a class="nav-item" href="{{ route('dashboard.index') }}">← العودة إلى لوحة المحل</a>
@can('store.orders.view')<a class="nav-item {{ request()->routeIs('store.admin.orders.*') ? 'active' : '' }}" href="{{ route('store.admin.orders.index') }}">طلبات الموقع <span class="store-count-badge" data-store-order-count hidden>0</span></a>@endcan
@can('store.manage')@foreach(\App\Actions\Store\ManageStoreContent::TITLES as $kind=>$label)<a class="nav-item {{ request()->route('type') === $kind ? 'active' : '' }}" href="{{ route('store.admin.content.index',$kind) }}">{{ $label }}</a>@endforeach @endcan
<a class="nav-item" href="{{ route('store.home') }}" target="_blank" rel="noopener">عرض الموقع ↗</a>
</nav></aside>