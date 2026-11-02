<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $pageTitle }}</title>
<meta name="description" content="{{ $pageDescription }}">
<meta name="robots" content="{{ $robots }}">
<link rel="canonical" href="{{ $canonical }}">
<meta property="og:locale" content="ar">
<meta property="og:title" content="{{ $pageTitle }}">
<meta property="og:description" content="{{ $pageDescription }}">
<meta property="og:type" content="{{ $ogType ?? 'website' }}">
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:site_name" content="{{ $settings->name }}">
@if(!empty($ogImage))<meta property="og:image" content="{{ $ogImage }}">@endif
<link rel="stylesheet" href="{{ asset('assets/store/css/live-store.css') }}">
<link rel="preload" href="{{ asset('assets/store/fonts/Changa-variable.ttf') }}" as="font" type="font/ttf" crossorigin>
<script type="application/ld+json">{!! json_encode($siteStructured, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE) !!}</script>
@yield('meta')
</head>
<body class="live-store">
<a class="skip-link" href="#store-content">انتقل إلى المحتوى</a>
<header class="shop-header">
<div class="shop-header-inner">
<nav class="shop-desktop-nav" aria-label="التنقل الرئيسي">
<a href="{{ route('store.home') }}" @if(request()->routeIs('store.home')) aria-current="page" @endif>الصفحة الرئيسية</a>
@if($sections->isNotEmpty())
<details class="shop-dropdown"><summary>تسوّق الأقسام <span>⌄</span></summary><div>@foreach($sections as $section)<a href="{{ route('store.section',$section->slug) }}">{{ $section->name }}</a>@endforeach</div></details>
@endif
</nav>
<div class="shop-mobile-tools"><button type="button" class="shop-icon-button" data-open-menu aria-label="فتح القائمة">@include('store.public.icon',['name'=>'menu'])</button></div>
<a class="shop-brand" href="{{ route('store.home') }}" aria-label="{{ $settings->name }} — الرئيسية"><span>{{ $settings->name }}</span><small>تفاصيل على ذوقك</small></a>
<div class="shop-tools">
<details class="shop-search"><summary class="shop-icon-button" aria-label="البحث">@include('store.public.icon',['name'=>'search'])</summary><form action="{{ route('store.home') }}" role="search"><label class="sr-only" for="store-search">ابحث عن منتج</label><input type="search" id="store-search" name="q" maxlength="100" placeholder="عن ماذا تبحث؟" value="{{ request('q') }}"><button type="submit">بحث</button></form></details>
<button type="button" class="shop-icon-button shop-cart-button" data-cart-open aria-label="فتح سلة التسوق">@include('store.public.icon',['name'=>'bag'])<span data-cart-count>0</span></button>
</div>
</div>
</header>
<div class="shop-announcement">اختيارات تشبهك، وتفاصيل تحبّها <span aria-hidden="true">✦</span> اكتشف التشكيلة</div>
<dialog class="shop-menu-dialog" data-menu-dialog aria-label="القائمة الرئيسية"><header><strong>{{ $settings->name }}</strong><button class="shop-icon-button" type="button" data-close-dialog aria-label="إغلاق القائمة">@include('store.public.icon',['name'=>'close'])</button></header><nav><a href="{{ route('store.home') }}">الصفحة الرئيسية</a><details open><summary>تسوّق الأقسام</summary>@forelse($sections as $section)<a href="{{ route('store.section',$section->slug) }}">{{ $section->name }}</a>@empty<p>تشكيلتنا الجديدة قريبًا</p>@endforelse</details><a href="{{ route('store.checkout') }}">حقيبة التسوق</a><a href="#store-contact" data-close-menu>التواصل معنا</a></nav></dialog>
<main class="live-container" id="store-content">@yield('content')</main>
<footer class="shop-footer" id="store-contact">
<div class="shop-footer-grid">
<section><h2>فروعنا</h2>@forelse($publicBranches as $branch)<p class="shop-contact-line">@include('store.public.icon',['name'=>'location'])<span>{{ $branch->name }}@if($branch->address)<small>{{ $branch->address }}</small>@endif</span></p>@empty<p>سنشاركك تفاصيل الزيارة قريبًا.</p>@endforelse</section>
<section><h2>تواصل معنا</h2>@if($settings->phone)<a class="shop-contact-line" href="tel:{{ preg_replace('/[^+0-9]/','',$settings->phone) }}">@include('store.public.icon',['name'=>'phone'])<bdi>{{ $settings->phone }}</bdi></a>@else<p>معلومات التواصل تتوفر قريبًا.</p>@endif<a href="{{ route('store.checkout') }}">حقيبة التسوق</a><a href="{{ route('store.home') }}">جميع المنتجات</a></section>
<section class="shop-footer-brand"><strong>{{ $settings->name }}</strong><p>{{ $settings->seo_description ?: 'اختيارات للأيام الجميلة. اكتشف الألوان والمقاسات التي تناسبك.' }}</p><span class="shop-footer-flower" aria-hidden="true">✳</span></section>
</div>
<div class="shop-copyright">حقوق النشر © {{ date('Y') }} {{ $settings->name }}</div>
</footer>
<dialog class="shop-cart-dialog" data-cart-dialog aria-label="حقيبة التسوق">
<header><h2>حقيبة التسوق <span data-cart-count>0</span></h2><button type="button" class="shop-icon-button" data-close-dialog aria-label="إغلاق السلة">@include('store.public.icon',['name'=>'close'])</button></header>
<div data-cart-drawer-items class="shop-drawer-items"></div>
<footer><p>تظهر الأسعار الحالية ورسوم التوصيل قبل إرسال الطلب.</p><a class="live-button" href="{{ route('store.checkout') }}">عرض الحقيبة وإتمام الطلب @include('store.public.icon',['name'=>'arrow'])</a>@if(!$checkoutState['ready'])<small>{{ $checkoutState['message'] }}</small>@endif</footer>
</dialog>
<div class="live-toast" role="status" data-store-toast hidden></div>
<script src="{{ asset('assets/store/js/live-store.js') }}" defer></script>
@stack('scripts')
</body>
</html>