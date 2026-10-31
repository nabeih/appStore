@extends('layouts.app')
@section('title', 'إدارة المخزون')
@include('sales.partials.assets')
@push('styles')<link rel="stylesheet" href="{{ asset('assets/admin/css/pages/inventory/product-show.css') }}">@endpush
@section('content')
    <div id="screen-inventory" class="screen active">
        <header style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:18px;">
            <div><h1 class="section-title"><i class="fas fa-boxes text-primary"></i> إدارة المخزون</h1><p class="section-subtitle">الكميات الحالية للأصناف حسب الفرع.</p></div>
            @can('purchases.view')<div style="display:flex;gap:10px;flex-wrap:wrap;">@can('purchases.manage')<a class="btn btn-primary" href="{{ route('purchases.invoices.create') }}">فاتورة شراء جديدة</a>@endcan<a class="btn btn-outline" href="{{ route('purchases.invoices.index') }}">سجل فواتير الشراء</a></div>@endcan
        </header>
        @include('search.partials.product-search', ['inputId' => 'inventory-product-query'])
        <div id="purchSubtab-stock" data-search-region="inventory" style="display:block;">

            <div class="card">

                <div class="card-header">

                    <h3>
                        <i class="fas fa-boxes text-primary"></i>
                        قائمة المنتجات والكميات الحالية
                    </h3>

                    @can('inventory.correct_setup')<button type="button" class="btn btn-primary" onclick="openQuickOpeningStockModal()"
                        style="background:linear-gradient(135deg,#4f46e5,#4338ca);font-weight:700;">
                        <i class="fas fa-boxes"></i> إدخال بضاعة أول المدة
                    </button>@endcan
                </div>

                <div style="padding:0 16px">@include('search.partials.filters',['searchKind'=>'inventory','placeholder'=>'اسم المنتج أو كوده أو SKU'])<p class="module-search-help">نتائج البحث في جميع السجلات: {{ $inventories->total() }}</p></div>
                {{-- جدول المخزون الحقيقي --}}
                <div class="table-responsive">

                    <table>

                        <thead>
                            <tr>
                                <th>الكود</th>
                                <th>الفرع</th>
                                <th>المنتج</th>
                                <th>التصنيف</th>
                                <th>المقاس</th>
                                <th>اللون</th>
                                @can('sales.view_cost')<th>متوسط التكلفة</th>@endcan
                                <th>سعر البيع</th>
                                <th>الكمية المتاحة</th><th>إجراءات</th>
                            </tr>
                        </thead>

                        <tbody id="inventoryTableBody">

                            @forelse ($inventories as $inventory)

                                <tr data-inventory-row data-quantity="{{ $inventory->quantity }}">

                                    <td>
                                        {{ $inventory->productVariant?->product?->code ?? '—' }}
                                    </td>

                                    <td>{{ $inventory->branch?->name ?? '—' }}</td>

                                    <td>
                                        @php($colorImage = $inventory->productVariant?->product?->images->firstWhere('color_id', $inventory->productVariant?->color_id))
                                        <a class="inventory-product-link" href="{{ route('inventory.products.show', ['product' => $inventory->productVariant->product_id, 'list' => array_intersect_key($filters, array_flip(['q','page','branch_id','category_id','color_id','size_id','active','quantity','match','sort']))]) }}">
                                        @if($colorImage)
                                            <img src="{{ $colorImage->thumbnail_url }}" alt="{{ $inventory->productVariant?->product?->name }}" width="40" height="40" loading="lazy" style="object-fit:cover;border-radius:6px;margin-inline-end:6px;">
                                        @endif
                                        {{ $inventory->productVariant?->product?->name ?? '—' }}
                                        </a>
                                    </td>

                                    <td>
                                        {{ $inventory->productVariant?->product?->category?->name ?? '—' }}
                                    </td>

                                    <td>
                                        {{ $inventory->productVariant?->size?->name ?? '—' }}
                                    </td>

                                    <td>
                                        <span data-fabric-product="{{ $inventory->productVariant->product_id }}" data-fabric-color="{{ $inventory->productVariant->color_id }}"><x-catalog.color-dot :swatch-url="$inventory->productVariant?->product?->swatches->firstWhere('color_id', $inventory->productVariant?->color_id)?->url" :hex="$inventory->productVariant?->color?->display_hex" :label="$inventory->productVariant?->color?->name ?? ''" /></span>
                                        {{ $inventory->productVariant?->color?->name ?? '—' }}
                                    </td>

                                    @can('sales.view_cost')<td>
                                        {{ number_format($inventory->average_cost, 2) }}
                                    </td>@endcan

                                    <td>
                                        {{ number_format($inventory->productVariant?->selling_price ?? 0, 2) }}
                                    </td>

                                    <td>
                                        {{ $inventory->quantity }}
                                    </td>

                                    @include('inventory.setup.partials.actions')
                                </tr>

                            @empty

                                <tr>
                                    <td colspan="10" class="text-center" style="padding:24px;">
                                        لا توجد بيانات مخزون حاليًا
                                    </td>
                                </tr>

                            @endforelse

                        </tbody>

                    </table>

                </div>

                {{-- Pagination --}}
                <div style="padding:16px;">
                    <x-pagination :paginator="$inventories" />
                </div>

            </div>

        </div>


    </div>
    @can('inventory.correct_setup')@include('inventory.opening-stock.partials.form')@endcan
    @can('inventory.manage_catalog')@include('inventory.setup.partials.product-details')@endcan
    @if($setupChangesEnabled && auth()->user()->can('inventory.correct_setup') && auth()->user()->can('sales.view_cost'))
        @include('inventory.setup.partials.dialog')
    @endif
@endsection
@push('scripts')
    <script src="{{ asset('assets/admin/js/pages/inventory.js') }}"></script>
@endpush
