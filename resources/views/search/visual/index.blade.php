@extends('layouts.app')
@section('title', 'حالة فهرسة صور المنتجات')
@section('content')
<div class="screen active">
    <h1 class="section-title">فهرسة صور المنتجات</h1>
    <p>خدمة التحليل: {{ $health ? 'متصلة' : 'غير متصلة — لن يكتمل البحث أو الفهرسة حتى تشغيلها' }}.</p>
    <p>الإصدار: <bdi>{{ config('product_search.model_version') }}</bdi></p>
    <p>يلزم عامل واحد لقائمة <bdi>ai-indexing</bdi>. وجود المهام في القائمة لا يعني أن العامل يعمل.</p>
    <div class="product-search-results">
        @foreach(['ready'=>'مفهرسة', 'pending'=>'بانتظار العامل', 'processing'=>'قيد التحليل', 'failed'=>'فشلت'] as $state=>$label)
        <article class="card"><strong>{{ $label }}</strong><span>{{ $counts[$state] ?? 0 }}</span></article>
        @endforeach
        <article class="card"><strong>لم تُجدول بعد</strong><span>{{ $missing }}</span></article>
    </div>
    @if(session('success'))<p role="status">{{ session('success') }}</p>@endif
    <div class="product-search-results">
    @foreach($rows as $row)
        <article class="card">
            <strong>{{ $row->image->product->name }} / {{ $row->image->color->name }}</strong>
            <p>{{ ['pending'=>'بانتظار العامل','processing'=>'قيد التحليل','ready'=>'مفهرسة','failed'=>'فشلت'][$row->status] ?? 'غير متاح' }}</p>
            @if($row->last_error)<p>{{ $row->last_error }}</p>@endif
            @if(in_array($row->status, ['failed','pending','processing'], true))
            <form method="post" action="{{ route('search.visual-index.retry', $row->id) }}">@csrf<button class="btn btn-outline" type="submit">إعادة الجدولة</button></form>
            @endif
        </article>
    @endforeach
    </div>
    <x-pagination :paginator="$rows" />
</div>
@endsection
