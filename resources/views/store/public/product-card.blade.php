@php($variant=$entry->product->variants->first())
@php($image=$entry->product->images->firstWhere('color_id',$variant?->color_id))
@php($price=$variant?app(\App\Actions\Store\StorePricing::class)->product($variant,$offers):null)
<article class="live-product-card">
<a class="live-product-photo" href="{{ route('store.product',$entry->slug) }}">
@if($image)<img src="{{ $image->optimized_url }}" srcset="{{ $image->thumbnail_url }} 160w, {{ $image->optimized_url }} 1200w" sizes="(max-width:600px) 46vw, 25vw" alt="{{ $entry->product->name }}" width="600" height="750" loading="lazy" decoding="async">@else<div class="live-placeholder">@include('store.public.icon',['name'=>'hanger'])<span>صورة القطعة قريبًا</span></div>@endif
@if($entry->badge)<span class="live-product-badge">{{ $entry->badge }}</span>@endif
<span class="shop-card-action">اكتشف التفاصيل @include('store.public.icon',['name'=>'arrow'])</span>
</a>
<div class="live-product-info"><h2><a href="{{ route('store.product',$entry->slug) }}">{{ $entry->product->name }}</a></h2>
@if($price)<div class="live-product-price"><span>{{ \App\Support\Sales\Money::format($price['price']) }} <small>₪</small></span>@if($price['offer'])<del>{{ \App\Support\Sales\Money::format($price['base']) }} ₪</del>@endif</div>@else<p class="shop-muted">الخيارات تتوفر قريبًا</p>@endif
<div class="live-color-dots">@foreach($entry->product->variants->unique('color_id')->take(7) as $colorVariant)<span title="{{ $colorVariant->color->name }}" class="live-color" @if($colorVariant->color->display_hex) style="--swatch:{{ $colorVariant->color->display_hex }}" @endif>@if($entry->product->swatches->firstWhere('color_id',$colorVariant->color_id))<img src="{{ $entry->product->swatches->firstWhere('color_id',$colorVariant->color_id)->url }}" alt="{{ $colorVariant->color->name }}" loading="lazy" width="20" height="20">@endif</span>@endforeach</div></div>
</article>