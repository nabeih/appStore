@extends('layouts.app')
@section('title', 'التصنيفات والسمات')
@push('styles')<link rel="stylesheet" href="{{ asset('assets/admin/css/pages/catalog/attributes.css') }}">@endpush
@section('content')
<div id="catalog-attributes" class="screen active" data-active-tab="{{ $tab }}">
    <h1 class="section-title"><i class="fas fa-tags text-primary"></i> التصنيفات والسمات</h1>
    <p class="section-subtitle">نظّم أصنافك وألوانها ومقاساتها من مكان واحد.</p>
    @if(session('success'))<p class="attribute-success" role="status">{{ session('success') }}</p>@endif
    @if($errors->any())<div class="attribute-errors" role="alert">{{ $errors->first() }}</div>@endif
    <nav class="attribute-tabs" aria-label="أقسام التصنيفات والسمات">
        @foreach(['categories' => ['التصنيفات', 'fa-tags'], 'colors' => ['الألوان', 'fa-palette'], 'sizes' => ['المقاسات', 'fa-ruler']] as $key => [$label, $icon])
            <a href="{{ route('catalog.attributes.index', ['tab' => $key]) }}" data-attribute-nav class="attribute-tab {{ $tab === $key ? 'active' : '' }}" @if($tab === $key) aria-current="page" @endif><i class="fas {{ $icon }}"></i><span>{{ $label }}</span><span class="attribute-count">{{ $counts[$key] }}</span></a>
        @endforeach
    </nav>
    <div class="attribute-layout">
        <aside class="card attribute-form-card">
            @include('catalog.attributes.partials.'.match($tab) { 'colors' => 'color', 'sizes' => 'size', default => 'category' }.'-form')
        </aside>
        <section class="card attribute-list-card" aria-label="القائمة الحالية">
            <header class="attribute-list-header"><h2>{{ match($tab) { 'colors' => 'الألوان المتاحة', 'sizes' => 'المقاسات المتاحة', default => 'التصنيفات المتاحة' } }}</h2>
                <form method="get" action="{{ route('catalog.attributes.index') }}" id="attribute-search-form"><input type="hidden" name="tab" value="{{ $tab }}"><input name="q" id="attribute-search" type="search" maxlength="100" value="{{ $search }}" placeholder="بحث بالاسم{{ $tab !== 'categories' ? ' أو الرمز' : '' }}..." aria-label="بحث في القائمة"><button class="btn btn-outline btn-sm" type="submit">بحث</button></form>
            </header>
            <div class="table-responsive"><table class="attribute-table {{ $tab === 'colors' ? 'attribute-color-table' : '' }}"><thead><tr>
                <th>{{ $tab === 'colors' ? 'اللون' : 'الاسم' }}</th>
                @if($tab === 'categories')<th>التصنيف الرئيسي</th><th>المنتجات</th>@elseif($tab === 'colors')<th>الدرجة</th><th>الرمز</th><th>الأصناف</th><th>الإجراءات</th>@else<th>الرمز</th><th>الترتيب</th><th>الأصناف</th>@endif
            </tr></thead><tbody>
                @forelse($entries as $entry)
                    <tr data-attribute-id="{{ $entry->id }}">
                        <td data-label="اللون"><div class="attribute-name">@if($tab === 'colors')<x-catalog.color-dot :hex="$entry->display_hex" :label="$entry->name" />@endif<strong>{{ $entry->name }}</strong></div>@unless($entry->is_active)<small class="attribute-muted">غير فعال</small>@endunless @if($tab === 'categories' && $entry->description)<small class="attribute-description">{{ $entry->description }}</small>@endif</td>
                        @if($tab === 'categories')
                            <td>{{ $entry->parent?->name ?? 'تصنيف رئيسي' }}</td><td>{{ $entry->products_count }}</td>
                        @elseif($tab === 'colors')
                            <td data-label="الدرجة"><span dir="ltr">{{ $entry->display_hex ?? 'لم تحدد' }}</span>@if(!$entry->hex_code && $entry->display_hex)<small class="attribute-muted">مقترح من الاسم</small>@endif</td><td data-label="الرمز"><span dir="ltr">{{ $entry->code }}</span></td><td data-label="الأصناف">{{ $entry->variants_count }}</td>
                            <td class="attribute-color-actions-cell"><div class="attribute-color-actions">
                                <button type="button" class="btn btn-outline btn-sm" data-edit-color="{{ json_encode(['name' => $entry->name, 'hex' => $entry->display_hex, 'url' => route('catalog.attributes.colors.update', $entry)]) }}" aria-label="تعديل {{ $entry->name }}">تعديل</button>
                                <button type="button" class="btn btn-outline btn-sm attribute-delete-button" data-delete-color="{{ json_encode(['name' => $entry->name, 'url' => route('catalog.attributes.colors.destroy', $entry)]) }}" @disabled($entry->variants_count > 0 || $entry->product_images_count > 0) aria-label="حذف {{ $entry->name }}" @if($entry->variants_count > 0 || $entry->product_images_count > 0) aria-describedby="color-delete-reason-{{ $entry->id }}" @endif>حذف</button>
                            </div>@if($entry->variants_count > 0 || $entry->product_images_count > 0)<small class="attribute-muted" id="color-delete-reason-{{ $entry->id }}">مستخدم في أصناف أو صور؛ لا يُحذف.</small>@endif</td>
                        @else
                            <td dir="ltr">{{ $entry->code }}</td><td>{{ $entry->sort_order }}</td><td>{{ $entry->variants_count }}</td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ match($tab) { 'colors' => 5, 'sizes' => 4, default => 3 } }}" class="attribute-empty">{{ $search !== '' ? 'لا توجد نتائج مطابقة للبحث.' : 'القائمة فارغة حاليًا. أضف أول عنصر من النموذج.' }}</td></tr>
                @endforelse
            </tbody></table></div>
            @include('catalog.attributes.partials.pagination')
        </section>
    </div>
</div>
<dialog id="attribute-color-dialog" class="attribute-dialog" aria-labelledby="attribute-dialog-title">
    <form method="post" id="attribute-color-form"><header><h2 id="attribute-dialog-title">تعديل اللون</h2><button type="button" class="btn btn-outline btn-sm" data-close-color aria-label="إغلاق">✕</button></header>
        @csrf @method('PUT')
        <div class="attribute-dialog-body"><div class="attribute-errors" data-form-errors role="alert" tabindex="-1" hidden></div><fieldset><x-catalog.color-editor id="edit-color" :palette="$palette" :hex-required="false" :preset-names="false" /><p class="attribute-muted">يمكن تعديل الاسم والدرجة. يبقى رمز اللون وأكواد الأصناف كما هي، وتحتفظ المستندات السابقة بأسمائها المحفوظة.</p></fieldset></div>
        <footer><button type="button" class="btn btn-outline" data-close-color>إلغاء</button><button type="submit" class="btn btn-primary">حفظ التعديل</button></footer>
    </form>
</dialog>
<dialog id="attribute-delete-color-dialog" class="attribute-dialog attribute-confirm-dialog" aria-labelledby="attribute-delete-color-title">
    <form method="post" id="attribute-delete-color-form">
        @csrf @method('DELETE')
        <header><h2 id="attribute-delete-color-title">حذف اللون</h2><button type="button" class="btn btn-outline btn-sm" data-close-delete-color aria-label="إغلاق">✕</button></header>
        <div class="attribute-dialog-body"><div class="attribute-errors" data-form-errors role="alert" tabindex="-1" hidden></div><p>حذف اللون «<strong data-delete-color-name></strong>» من القائمة؟</p><p class="attribute-muted">يمكن حذف الألوان غير المستخدمة فقط.</p></div>
        <footer><button type="button" class="btn btn-outline" data-close-delete-color>إلغاء</button><button type="submit" class="btn attribute-confirm-delete">حذف اللون</button></footer>
    </form>
</dialog>
<div id="attribute-feedback" role="status" hidden></div>
<div id="attribute-loading" role="status" hidden>جاري تحديث القائمة...</div>
@endsection
@push('scripts')
    <script type="application/json" id="attribute-config">{!! json_encode(['indexUrl' => route('catalog.attributes.index'), 'palette' => $palette, 'csrf' => csrf_token()], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
    <script src="{{ asset('assets/admin/js/pages/catalog/attributes.js') }}" defer></script>
@endpush