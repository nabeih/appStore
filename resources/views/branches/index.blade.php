@extends('layouts.app')
@section('title', 'إدارة الفروع')
@push('styles')<link rel="stylesheet" href="{{ asset('assets/admin/css/pages/branches.css') }}">@endpush
@push('scripts')<script src="{{ asset('assets/admin/js/pages/branches.js') }}" defer></script>@endpush
@section('content')
<section class="branches-page">
    <div class="b-heading"><div><p class="b-breadcrumb">إدارة المتجر / الفروع</p><h1>إدارة الفروع</h1><p>بيانات فروعك وحالتها، دون تغيير سياق عمل حسابك.</p></div>
    @can('branches.create')@can('sales.all_branches')<a class="b-button" href="{{ route('branches.create') }}">إضافة فرع جديد</a>@endcan
    @endcan</div>
    @include('branches.partials.messages')
    <form method="GET" action="{{ route('branches.index') }}" class="b-search">
        <label for="branch-search">البحث باسم الفرع</label><div><input id="branch-search" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="اكتب اسم الفرع"><button class="b-button" type="submit">بحث</button>@if(filled($filters['q'] ?? null))<a href="{{ route('branches.index') }}">مسح البحث</a>@endif</div>
    </form>
    <div class="b-table-scroll">
        <table class="b-table"><caption class="b-screen-reader">الفروع المسموح لحسابك بعرضها</caption>
            <thead><tr><th scope="col">اسم الفرع</th><th scope="col">العنوان</th><th scope="col">الهاتف</th><th scope="col">النوع</th><th scope="col">الحالة</th><th scope="col">تاريخ الإنشاء</th><th scope="col">الإجراءات</th></tr></thead>
            <tbody>@forelse($branches as $branch)
                <tr><td data-label="اسم الفرع"><strong>{{ $branch->name }}</strong></td><td data-label="العنوان">{{ $branch->address ?: '—' }}</td><td data-label="الهاتف"><bdi>{{ $branch->phone ?: '—' }}</bdi></td>
                <td data-label="النوع">{{ $branch->type === 'shop' ? 'متجر' : ($branch->type === 'warehouse' ? 'مستودع' : $branch->type) }}</td>
                <td data-label="الحالة"><span class="b-badge {{ $branch->is_active ? 'is-active' : '' }}">{{ $branch->is_active ? 'نشط' : 'غير نشط' }}</span></td>
                <td data-label="تاريخ الإنشاء">{{ $branch->created_at?->format('Y/m/d') ?? '—' }}</td>
                <td data-label="الإجراءات"><div class="b-actions">
                    @can('branches.update')<a class="b-secondary" href="{{ route('branches.edit', $branch) }}">تعديل</a>@endcan
                    @can('branches.manage_status')@include('branches.partials.status', ['branch' => $branch])@endcan
                    @cannot('branches.update')@cannot('branches.manage_status')<span>عرض فقط</span>@endcannot
                    @endcannot
                </div></td></tr>
            @empty<tr><td colspan="7" class="b-empty">{{ filled($filters['q'] ?? null) ? 'لا توجد فروع مطابقة للبحث.' : 'لا توجد فروع ضمن نطاق حسابك.' }}</td></tr>@endforelse</tbody>
        </table>
    </div>
    <div class="b-pagination">{{ $branches->links() }}</div>
    <p class="b-note">إضافة فرع لا تجعله جاهزًا للبيع؛ يلزم تجهيز المخزون والأرصدة واعتماد الإعداد الافتتاحي. لا يوجد حذف نهائي للفروع.</p>
</section>
@endsection
