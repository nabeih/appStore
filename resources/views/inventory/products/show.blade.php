@extends('layouts.app')
@section('title', 'تفاصيل المنتج — '.$product->name)
@include('sales.partials.assets')
@push('styles')<link rel="stylesheet" href="{{ asset('assets/admin/css/pages/inventory/product-show.css') }}">@endpush
@section('content')
<div class="screen active inventory-product-page" data-product-show data-back-url="{{ $backUrl }}">
    <nav aria-label="مسار التنقل"><a href="{{ $backUrl }}">المخزون</a><span aria-hidden="true"> / </span><span>تفاصيل المنتج</span></nav>
    <header class="product-show-header">
        <div><h1 class="section-title">{{ $product->name }}</h1><p class="sale-muted">الكود: <bdi>{{ $product->code }}</bdi> · {{ $product->category?->name ?? 'بلا تصنيف' }}</p>
            <span class="badge">{{ $product->trashed() ? 'مؤرشف' : ($product->is_active ? 'منتج فعال' : 'منتج معطّل') }}</span>
        </div>
        <div class="product-show-actions">
            <a class="btn btn-outline" href="{{ $backUrl }}">الرجوع لقائمة المخزون</a>
            @if($canManage)
                <button type="button" class="btn btn-primary" data-product-details="{{ json_encode(['name'=>$product->name,'description'=>$product->description,'variant_id'=>null,'url'=>url('/api/products/'.$product->id)]) }}">تعديل المنتج</button>
                <button type="button" class="btn btn-danger" data-product-delete data-url="{{ url('/api/products/'.$product->id) }}">حذف / تعطيل المنتج</button>
            @endif
        </div>
    </header>
    <p data-product-show-feedback role="alert" hidden></p>
    @if($canManage)<p class="sale-muted">التعديل يفتح نافذة البيانات الحالية. المنتج المرتبط بمستندات يُعطّل عند الحذف مع حفظ مستنداته ومخزونه، وغير المرتبط يُؤرشف.</p>@endif
    <section class="card"><h2>وصف المنتج</h2><p class="product-show-description">{{ filled($product->description) ? $product->description : 'لا يوجد وصف لهذا المنتج.' }}</p></section>
    <section class="card"><h2>الصور والألوان</h2>
        <div class="product-show-gallery">
        @forelse($product->variants->groupBy('color_id') as $colorId=>$variants)
            @php($color = $variants->first()->color)
            @php($image = $product->images->firstWhere('color_id', $colorId))
            <figure>
                @if($image)
                    <a href="{{ $image->optimized_url }}" target="_blank" rel="noopener"><img src="{{ $image->thumbnail_url }}" alt="{{ $product->name }} — {{ $color->name }}" width="160" height="160" loading="lazy"></a>
                @else
                    <div class="product-show-no-image" role="img" aria-label="لا توجد صورة للون {{ $color->name }}">لا توجد صورة لهذا اللون</div>
                @endif
                <figcaption><x-catalog.color-dot :swatch-url="$product->swatches->firstWhere('color_id', $colorId)?->url" :hex="$color->display_hex" :label="$color->name" /> {{ $color->name }}</figcaption>
            </figure>
        @empty<p>لا توجد ألوان أو صور مرتبطة بتركيبات متاحة للعرض.</p>@endforelse
        </div>
        <p class="sale-muted">صورة واحدة لكل لون، مشتركة بين مقاساته. اضغط الصورة لعرض النسخة المحسّنة.</p>
    </section>
    <section class="card"><h2>الألوان والمقاسات والأرصدة المسموحة</h2>
        <div class="product-show-variants">
        @forelse($product->variants as $variant)
            <article class="product-show-variant">
                <header><h3>{{ $variant->color->name }} / {{ $variant->size->name }}</h3>
                    @if(!$variant->is_active)<span class="badge">تركيبة غير فعالة</span>@endif
                </header>
                <p>SKU: <bdi>{{ $variant->sku }}</bdi></p>
                <p>سعر البيع: <strong>{{ number_format($variant->selling_price, 2) }} ₪</strong></p>
                @can('sales.view_cost')@if($variant->minimum_selling_price !== null)<p>الحد الأدنى لسعر البيع: {{ number_format($variant->minimum_selling_price, 2) }} ₪</p>@endif @endcan
                <div class="product-show-stocks">
                @forelse($variant->inventories as $stock)
                    <div class="product-show-stock"><strong>{{ $stock->branch->name }}</strong><span>الكمية المتاحة: {{ $stock->quantity }}</span>
                        @can('sales.view_cost')<span>متوسط التكلفة: {{ $stock->value_minor === null ? 'غير معتمد' : number_format($stock->average_cost, 2).' ₪' }}</span>@endcan
                    </div>
                @empty<p class="sale-muted">لا يوجد رصيد مخزون مسجل لهذه التركيبة.</p>@endforelse
                </div>
                @if($canManage)
                    <button type="button" class="btn btn-outline btn-sm" data-product-details="{{ json_encode(['name'=>$product->name,'description'=>$product->description,'price'=>$variant->selling_price,'color_id'=>$variant->color_id,'size_id'=>$variant->size_id,'variant_id'=>$variant->id,'swatch_manage_url'=>route('catalog.swatches.index',$product),'url'=>url('/api/products/'.$product->id)]) }}">تعديل سعر وصورة هذه التركيبة</button>
                @endif
            </article>
        @empty<p>لا توجد تركيبات ألوان ومقاسات متاحة لهذا المنتج ضمن نطاقك.</p>@endforelse
        </div>
    </section>
</div>
@if($canManage)@include('inventory.setup.partials.product-details')@endif
@endsection
@push('scripts')<script src="{{ asset('assets/admin/js/pages/inventory/product-show.js') }}" defer></script>@endpush
