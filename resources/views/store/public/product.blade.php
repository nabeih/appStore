@extends('store.public.layout')
@section('title',$entry->seo_title??$entry->product->name)
@section('description',$entry->seo_description??$entry->description??$entry->product->description??'')
@section('canonical',route('store.product',$entry->slug))
@section('og_type','product')
@section('meta')
@if($entry->product->images->first())<meta property="og:image" content="{{ $entry->product->images->first()->optimized_url }}">@endif
<script type="application/ld+json">{!! json_encode($structured,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) !!}</script>
@endsection
@section('content')
<nav class="live-breadcrumb"><a href="{{ route('store.home') }}">التشكيلة</a> / {{ $entry->product->name }}</nav>
<div class="live-product-details"><section class="live-gallery">@forelse($entry->product->variants->unique('color_id') as $variant)
@php($image=$entry->product->images->firstWhere('color_id',$variant->color_id))
<figure>@if($image)<a href="{{ $image->optimized_url }}" target="_blank" rel="noopener"><img src="{{ $image->optimized_url }}" alt="{{ $entry->product->name }} — {{ $variant->color->name }}" width="1200" height="1200" @if(!$loop->first) loading="lazy" @endif></a>@else<div class="live-placeholder">لا توجد صورة لهذا اللون</div>@endif<figcaption>{{ $variant->color->name }}</figcaption></figure>
@empty<div class="live-placeholder">لا توجد ألوان متاحة حاليًا.</div>@endforelse</section>
<section class="live-details-text"><p class="live-eyebrow">{{ $entry->section?->name }}</p><h1>{{ $entry->product->name }}</h1><p class="live-description">{{ $entry->description??$entry->product->description }}</p>
<form data-add-product data-product-name="{{ $entry->product->name }}"><label>اللون / المقاس<select name="variant" required><option value="">اختر تركيبة اللون والمقاس</option>@foreach($entry->product->variants as $variant)
@php($price=app(\App\Actions\Store\StorePricing::class)->product($variant,$offers))
<option value="{{ $variant->id }}">{{ $variant->color->name }} / {{ $variant->size->name }} — {{ \App\Support\Sales\Money::format($price['price']) }} ₪</option>@endforeach</select></label>
<label>الكمية<input name="quantity" type="number" value="1" min="1" max="100" required inputmode="numeric"></label><button class="live-button">إضافة إلى حقيبة التسوق</button></form>
<p class="live-notice">تأكيد التوفر وحجز القطعة يتم بعد مراجعة الطلب. الدفع نقدًا لمزوّد التوصيل أو تحويلًا مباشرًا للمحل عند الاستلام.</p><a href="{{ route('store.checkout') }}" class="live-secondary">الذهاب لإتمام الطلب ←</a></section></div>
@endsection