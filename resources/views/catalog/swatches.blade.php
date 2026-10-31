@extends('layouts.app')
@section('title', 'عينات قماش المنتج')
@include('sales.partials.assets')
@section('content')
<div class="sale-page">
    <header class="sale-header"><h1>عينات قماش — {{ $product->name }}</h1><a class="btn btn-outline" href="{{ route('inventory.index') }}">المخزون</a></header>
    <p class="sale-notice">كل عينة تخص المنتج واللون وتستخدمها جميع المقاسات. لا يتغير المخزون أو الفواتير عند تعديلها. اضغط إدارة العينة لرفع صورة مستقلة أو قص مربع من صورة اللون.</p>
    <section class="sale-panel fabric-page-colors">
        @forelse($appearance['colors'] as $color)
        <article class="fabric-page-color">
            <h2>{{ $color['name'] }}</h2>
            <span data-fabric-product="{{ $product->id }}" data-fabric-color="{{ $color['color_id'] }}"><x-catalog.color-dot :hex="$color['hex']" :label="$color['name']" :swatch-url="$color['swatch']['url'] ?? null" /></span>
            <button type="button" class="fabric-manage-button" data-open-fabric-swatches="{{ $appearance['manage_url'] }}" data-color-id="{{ $color['color_id'] }}">إدارة عينة القماش</button>
        </article>
        @empty<p>لا توجد ألوان فعالة مرتبطة بهذا المنتج.</p>@endforelse
    </section>
</div>
@endsection
