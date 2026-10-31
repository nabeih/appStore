@extends('layouts.app')
@section('title','نتائج البحث')
@section('content')
<div class="screen active search-page" data-search-region="global-results">
@include('search.partials.product-search', ['inputId' => 'product-query'])
<h1 class="section-title">{{ $results['types'][$filters['type'] ?? ''] ?? 'البحث في التطبيق' }}</h1>
<form method="get" action="{{ route('search.index') }}" class="module-search-form" data-module-search>
<label>البحث<input name="q" type="search" value="{{ $results['q'] }}" maxlength="100" placeholder="اسم، كود، SKU أو رقم مستند"></label>
<label>المجموعة<select name="type"><option value="">كل المجموعات المسموحة</option>@foreach($results['types'] as $type=>$label)<option value="{{ $type }}" @selected(($filters['type'] ?? '')===$type)>{{ $label }}</option>@endforeach</select></label>
<label>المطابقة<select name="match">@foreach(['contains'=>'جزء من النص','prefix'=>'بداية النص','exact'=>'مطابقة كاملة'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['match'] ?? 'contains')===$value)>{{ $label }}</option>@endforeach</select></label>
@if(!empty($filters['type']) && in_array($filters['type'],['sales','purchases','openings','returns','exchanges','receipts','supplier_payments'],true))
<label>من تاريخ<input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label><label>إلى تاريخ<input type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
@if($filters['type'] !== 'supplier_payments')<label>الحالة<select name="status"><option value="">الكل</option>@foreach(['draft'=>'مسودة','approved'=>'معتمد','cancelled'=>'ملغى','reversed'=>'معكوس'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['status'] ?? '')===$value)>{{ $label }}</option>@endforeach</select></label>@endif
@if(in_array($filters['type'],['receipts','supplier_payments'],true))<label>المبلغ من<input name="min_amount" inputmode="decimal" value="{{ $filters['min_amount'] ?? '' }}"></label><label>المبلغ إلى<input name="max_amount" inputmode="decimal" value="{{ $filters['max_amount'] ?? '' }}"></label>@endif
@endif
@if(!empty($filters['type']) && in_array($filters['type'],['products','sales','purchases','openings','returns','exchanges','receipts','supplier_payments','accounts'],true))
<label>الفرع<select name="branch_id"><option value="">الفروع المسموحة</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(($filters['branch_id'] ?? '')==$branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
@endif
@if(($filters['type'] ?? '')==='receipts')<label>نوع السند<select name="kind"><option value="">الكل</option>@foreach(['receipt'=>'قبض','refund'=>'رد أموال','credit'=>'استخدام رصيد دائن'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['kind'] ?? '')===$value)>{{ $label }}</option>@endforeach</select></label>@endif
@if(($filters['type'] ?? '')==='accounts')<label>نوع الحساب<select name="account_type"><option value="">الكل</option>@foreach(['cash'=>'درج نقدي','bank'=>'بنك','wallet'=>'محفظة'] as $value=>$label)<option value="{{ $value }}" @selected(($filters['account_type'] ?? '')===$value)>{{ $label }}</option>@endforeach</select></label>@endif
<button type="submit" class="btn btn-primary">بحث</button><button type="button" class="btn btn-outline" data-search-clear>مسح البحث</button></form>
<p class="module-search-help">اكتب حرفين للبحث الجزئي. الحرف الواحد يطابق الاسم أو الكود كاملًا.</p>
@forelse($results['groups'] as $group)<section class="card global-result-group"><h2>{{ $group['label'] }}</h2><ul class="global-result-list">
@forelse($group['entries'] as $entry)<li><a href="{{ $entry['url'] }}" class="product-search-result-link">@if(!empty($entry['product']['image_url']))<img src="{{ $entry['product']['image_url'] }}" alt="{{ $entry['title'] }}" width="42" height="48" loading="lazy">@endif<span><strong>{{ $entry['title'] }}</strong><small>{{ $entry['detail'] }}</small>@if(isset($entry['product']))<small>الكود: {{ $entry['product']['code'] }} — المتاح: {{ $entry['product']['quantity'] }}</small>@endif</span></a>@if(isset($entry['product']))<div class="product-search-tools">@if($entry['product']['sale_url'])<a class="btn btn-outline btn-sm" href="{{ $entry['product']['sale_url'] }}">اختيار للكاشير</a>@endif @if($entry['product']['barcode_supported'])<a class="btn btn-outline btn-sm" href="{{ $entry['product']['label_url'] }}">طباعة الملصق</a>@else<small>SKU غير متوافق مع Code 128؛ لم يتغير.</small>@endif</div>@endif</li>@empty<li class="module-search-help">لا توجد نتائج مطابقة.</li>@endforelse
</ul>@if($group['url'])<a class="btn btn-outline btn-sm" href="{{ $group['url'] }}">عرض نتائج المجموعة كاملة</a>@else<x-pagination :paginator="$group['entries']" />@endif</section>
@empty<p class="card search-empty">{{ $results['q'] === '' ? 'اكتب عبارة للبحث، أو اختر مجموعة مسموحة لعرض سجلها.' : 'لا توجد نتائج مطابقة ضمن الصلاحيات والفلاتر الحالية.' }}</p>@endforelse</div>
@endsection