@extends('store.public.layout')
@section('content')
@if(!$currentSection && !request()->filled('q') && !request()->filled('color_id') && !request()->filled('size_id'))
@if($banners->whereNull('store_section_id')->isNotEmpty())
<section class="shop-banners" aria-label="مختارات المتجر">@foreach($banners->whereNull('store_section_id') as $banner)<article class="shop-banner">@if($banner->image_path)<img src="{{ Storage::disk('public')->url($banner->image_path) }}" alt="{{ $banner->title }}" width="1200" height="600" @if(!$loop->first) loading="lazy" @endif>@endif<div><h1>{{ $banner->title }}</h1>@if($banner->description)<p>{{ $banner->description }}</p>@endif<a href="#shop-catalog">اكتشف التشكيلة @include('store.public.icon',['name'=>'arrow'])</a></div></article>@endforeach</section>
@endif
@if($sections->isNotEmpty())
<section class="shop-collections" aria-label="تسوّق المجموعات">@foreach($sections as $section)
@php($coverBanner=$banners->firstWhere('store_section_id',$section->id))
@php($coverProduct=$entries->getCollection()->firstWhere('store_section_id',$section->id))
@php($coverUrl=$coverBanner?->image_path?Storage::disk('public')->url($coverBanner->image_path):$coverProduct?->product->images->first()?->optimized_url)
<a class="shop-collection" href="{{ route('store.section',$section->slug) }}"><div>@if($coverUrl)<img src="{{ $coverUrl }}" alt="{{ $section->name }}" width="600" height="650" @if(!$loop->first) loading="lazy" @endif>@else<span class="shop-collection-placeholder" aria-hidden="true">@include('store.public.icon',['name'=>'hanger'])</span>@endif</div><h2>{{ $section->name }}</h2></a>
@endforeach</section>
@endif
@endif
<section id="shop-catalog" class="shop-catalog">
<header class="shop-page-heading"><p class="shop-eyebrow">{{ $currentSection?'تسوّق المجموعة':'على ذوقك' }}</p><h1>{{ request()->filled('q')?'نتائج البحث عن «'.request('q').'»':($currentSection?->name??'تشكيلة تستحق الاكتشاف') }}</h1>@if($currentSection?->description)<p>{{ $currentSection->description }}</p>@endif</header>
<div class="shop-catalog-toolbar"><p>{{ $entries->total() }} منتج</p><details class="shop-filters"><summary>@include('store.public.icon',['name'=>'filter']) تصفية وترتيب</summary><form method="GET"><input type="hidden" name="q" value="{{ $filters['q']??'' }}"><label>اللون<select name="color_id"><option value="">كل الألوان</option>@foreach($colors as $color)<option value="{{ $color->id }}" @selected((int)($filters['color_id']??0)===$color->id)>{{ $color->name }}</option>@endforeach</select></label><label>المقاس<select name="size_id"><option value="">كل المقاسات</option>@foreach($sizes as $size)<option value="{{ $size->id }}" @selected((int)($filters['size_id']??0)===$size->id)>{{ $size->name }}</option>@endforeach</select></label><label>الترتيب<select name="sort"><option value="name">حسب الاسم</option><option value="newest" @selected(($filters['sort']??'')==='newest')>الأحدث</option></select></label><button class="live-button">تطبيق</button><a href="{{ $currentSection?route('store.section',$currentSection->slug):route('store.home') }}">مسح الفلاتر</a></form></details></div>
@if($entries->isNotEmpty())<div class="live-products">@foreach($entries as $entry)@include('store.public.product-card')@endforeach</div>
@else
<div class="shop-empty" data-store-empty><div class="shop-empty-art" aria-hidden="true"><span>✦</span>@include('store.public.icon',['name'=>'bag'])<span>✧</span></div><p class="shop-eyebrow">{{ array_filter($filters)?'لنجرّب اختيارًا آخر':'أشياء جميلة في الطريق' }}</p><h2>{{ array_filter($filters)?'لم نجد قطعًا تطابق اختيارك':'تشكيلتنا تتجدّد' }}</h2><p>{{ array_filter($filters)?'جرّب كلمة أخرى أو أزل الفلاتر لاكتشاف المزيد.':'نعمل على تجهيز قطعنا وألواننا. يسعدنا أن تعود لتكتشف الجديد قريبًا.' }}</p>@if(array_filter($filters))<a class="live-button" href="{{ route('store.home') }}">عرض التشكيلة @include('store.public.icon',['name'=>'arrow'])</a>@else<a class="shop-text-link" href="#store-contact">ابقَ على تواصل @include('store.public.icon',['name'=>'arrow'])</a>@endif</div>
@endif
<div class="live-pagination">{{ $entries->links() }}</div>
</section>
@if(!$checkoutState['ready'])<aside class="shop-service-note" role="status">@include('store.public.icon',['name'=>'info'])<p>{{ $checkoutState['message'] }}</p></aside>@endif
@endsection