@extends('store.public.layout')
@section('meta')
<script type="application/ld+json">{!! json_encode($structured,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) !!}</script>
@endsection
@section('content')
<nav class="live-breadcrumb" aria-label="مسار التنقل"><a href="{{ route('store.home') }}">الرئيسية</a><span>/</span>@if($entry->section)<a href="{{ route('store.section',$entry->section->slug) }}">{{ $entry->section->name }}</a><span>/</span>@endif<span>{{ $entry->product->name }}</span></nav>
<div class="live-product-details">
<section class="shop-product-gallery" aria-label="صور المنتج حسب اللون">
<div class="shop-main-photos">@forelse($entry->product->variants->unique('color_id') as $variant)
@php($image=$entry->product->images->firstWhere('color_id',$variant->color_id))
<figure data-color-photo="{{ $variant->color_id }}" @if(!$loop->first) hidden @endif>@if($image)<a href="{{ $image->optimized_url }}" target="_blank" rel="noopener"><img src="{{ $image->optimized_url }}" alt="{{ $entry->product->name }} — {{ $variant->color->name }}" width="1200" height="1200"></a>@else<div class="live-placeholder">@include('store.public.icon',['name'=>'hanger'])<span>لا توجد صورة لهذا اللون بعد</span></div>@endif<figcaption>{{ $variant->color->name }}</figcaption></figure>
@empty<div class="live-placeholder">@include('store.public.icon',['name'=>'hanger'])<span>الصور تتوفر قريبًا</span></div>@endforelse</div>
<div class="shop-photo-thumbs">@foreach($entry->product->variants->unique('color_id') as $variant)
@php($image=$entry->product->images->firstWhere('color_id',$variant->color_id))
<button type="button" data-gallery-color="{{ $variant->color_id }}" aria-label="عرض اللون {{ $variant->color->name }}" aria-pressed="{{ $loop->first?'true':'false' }}">@if($image)<img src="{{ $image->thumbnail_url }}" alt="{{ $variant->color->name }}" width="80" height="80">@else@include('store.public.icon',['name'=>'hanger'])@endif</button>@endforeach</div>
</section>
<section class="live-details-text"><p class="shop-eyebrow">{{ $entry->section?->name??'من تشكيلتنا' }}</p><h1>{{ $entry->product->name }}</h1>
@if($variantOptions->isNotEmpty())<p class="shop-product-price"><span data-product-price>{{ $variantOptions->first()['price'] }}</span> <small>₪</small><del data-product-base hidden></del></p>@endif
@if($entry->description??$entry->product->description)<p class="live-description">{{ $entry->description??$entry->product->description }}</p>@endif
<form data-add-product data-product-name="{{ $entry->product->name }}" data-product-image="{{ $entry->product->images->first()?->thumbnail_url }}">
<script type="application/json" data-variant-options>{!! json_encode($variantOptions,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) !!}</script>
<label class="shop-native-variant">اللون والمقاس<select name="variant" required><option value="">اختر اللون والمقاس</option>@foreach($entry->product->variants as $variant)@php($option=$variantOptions->firstWhere('id',$variant->id))<option value="{{ $variant->id }}" @disabled(!$option['available'])>{{ $variant->color->name }} / {{ $variant->size->name }} — {{ $option['price'] }} ₪</option>@endforeach</select></label>
<fieldset class="shop-color-options"><legend>اللون <span data-color-name></span></legend>@foreach($entry->product->variants->unique('color_id') as $variant)
@php($swatch=$entry->product->swatches->firstWhere('color_id',$variant->color_id))
<label class="shop-color-choice" title="{{ $variant->color->name }}"><input type="radio" name="product_color" value="{{ $variant->color_id }}" data-color-label="{{ $variant->color->name }}" @checked($loop->first)><span class="live-color" @if($variant->color->display_hex) style="--swatch:{{ $variant->color->display_hex }}" @endif>@if($swatch)<img src="{{ $swatch->url }}" alt="" width="32" height="32">@endif</span><span class="sr-only">{{ $variant->color->name }}</span></label>@endforeach</fieldset>
<fieldset class="shop-size-options"><legend>المقاس</legend>@foreach($entry->product->variants->unique('size_id') as $variant)<button type="button" data-product-size="{{ $variant->size_id }}" aria-pressed="false">{{ $variant->size->name }}</button>@endforeach</fieldset>
<p class="shop-variant-status" data-variant-status role="status">اختر اللون والمقاس المناسبين لك.</p>
<div class="shop-add-row"><label class="shop-quantity">الكمية<span><button type="button" data-quantity-change="-1" aria-label="تقليل الكمية">−</button><input name="quantity" type="number" value="1" min="1" max="100" required inputmode="numeric"><button type="button" data-quantity-change="1" aria-label="زيادة الكمية">+</button></span></label><button class="live-button" type="submit">إضافة إلى الحقيبة @include('store.public.icon',['name'=>'bag'])</button></div>
<noscript>اختر التركيبة من القائمة. يلزم JavaScript لإدارة حقيبة التسوق.</noscript>
</form>
@if(!$checkoutState['ready'])<p class="shop-service-note">@include('store.public.icon',['name'=>'info']){{ $checkoutState['message'] }}</p>@else<p class="shop-purchase-note">@include('store.public.icon',['name'=>'check']) الدفع عند الاستلام نقدًا أو بتحويل مباشر للمحل.</p>@endif
<details class="shop-product-info" open><summary>تفاصيل القطعة</summary><p>{{ $entry->product->code }} <span data-product-sku></span></p><p>اختيار اللون والمقاس من الخيارات المتوفرة. الصورة نفسها لجميع مقاسات اللون.</p></details>
<details class="shop-product-info"><summary>الطلب والتوصيل</summary><p>تظهر رسوم منطقتك قبل إرسال الطلب. نراجع التوفر قبل التأكيد والحجز.</p><p class="live-policy">{{ $settings->policy }}</p></details>
</section></div>
@endsection