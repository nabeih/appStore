@extends('layouts.app')
@section('title', 'إدارة المخزون')
@section('content')
    <div id="screen-inventory" class="screen active">
        <header style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:18px;">
            <div><h1 class="section-title"><i class="fas fa-boxes text-primary"></i> إدارة المخزون</h1><p class="section-subtitle">الكميات الحالية وتكلفة الأصناف حسب الفرع.</p></div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;"><a class="btn btn-primary" href="{{ route('purchases.invoices.create') }}">فاتورة شراء جديدة</a><a class="btn btn-outline" href="{{ route('purchases.invoices.index') }}">سجل فواتير الشراء</a></div>
        </header>
        <div id="purchSubtab-stock" style="display:block;">

            <div class="card">

                <div class="card-header">

                    <h3>
                        <i class="fas fa-boxes text-primary"></i>
                        قائمة المنتجات والكميات الحالية
                    </h3>

                    {{-- البحث الأصلي: حقل واحد فقط --}}
                    <div class="search-box">
                        <i class="fas fa-search"></i>

                        <input type="search" id="inventorySearch" placeholder="بحث بالاسم أو الكود..." autocomplete="off">
                    </div>
                    <button type="button" class="btn btn-primary" onclick="openQuickOpeningStockModal()"
                        style="background:linear-gradient(135deg,#4f46e5,#4338ca);font-weight:700;">
                        <i class="fas fa-boxes"></i> إدخال بضاعة أول المدة
                    </button>
                </div>

                {{-- فلترة الكميات --}}
                <div class="d-flex flex-wrap gap-2 mb-3" style="padding:0 16px;align-items:center;">

                    <select id="inventoryQuantityFilter" class="form-select" style="max-width:200px;">
                        <option value="all">كل المنتجات</option>
                        <option value="available">متوفر بالمخزون</option>
                        <option value="empty">الكمية صفر</option>
                    </select>

                    <span id="inventoryVisibleCount" style="font-size:0.85rem;color:var(--gray);">
                    </span>

                </div>

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
                                <th>متوسط التكلفة</th>
                                <th>سعر البيع</th>
                                <th>الكمية المتاحة</th>@if($setupChangesEnabled)<th>إجراءات</th>@endif
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
                                        @if($colorImage)
                                            <img src="{{ asset('storage/'.$colorImage->image_path) }}" alt="{{ $inventory->productVariant?->product?->name }}" width="40" height="40" loading="lazy" style="object-fit:cover;border-radius:6px;margin-inline-end:6px;">
                                        @endif
                                        {{ $inventory->productVariant?->product?->name ?? '—' }}
                                    </td>

                                    <td>
                                        {{ $inventory->productVariant?->product?->category?->name ?? '—' }}
                                    </td>

                                    <td>
                                        {{ $inventory->productVariant?->size?->name ?? '—' }}
                                    </td>

                                    <td>
                                        <x-catalog.color-dot :hex="$inventory->productVariant?->color?->display_hex" :label="$inventory->productVariant?->color?->name ?? ''" />
                                        {{ $inventory->productVariant?->color?->name ?? '—' }}
                                    </td>

                                    <td>
                                        {{ number_format($inventory->average_cost, 2) }}
                                    </td>

                                    <td>
                                        {{ number_format($inventory->productVariant?->selling_price ?? 0, 2) }}
                                    </td>

                                    <td>
                                        {{ $inventory->quantity }}
                                    </td>

                                    @if($setupChangesEnabled)
                                        @include('inventory.setup.partials.actions')
                                    @endif
                                </tr>

                            @empty

                                <tr>
                                    <td colspan="{{ $setupChangesEnabled ? 10 : 9 }}" class="text-center" style="padding:24px;">
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
    @include('inventory.opening-stock.partials.form')
    @if($setupChangesEnabled)
        @include('inventory.setup.partials.dialog')
    @endif
@endsection
@push('scripts')
    <script src="{{ asset('assets/admin/js/pages/inventory.js') }}"></script>
@endpush
