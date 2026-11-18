@extends('layouts.app')
@section('title','مساعد المتجر — خطوة بخطوة')
@section('content')
<section class="screen active store-help-page"><h1>مساعد المتجر</h1><p>اختر ما تريد عمله. ستظهر إرشادات وروابط تناسب صلاحيات حسابك، دون تغيير أي بيانات.</p>
<div class="store-help-grid">@foreach($topics as $key=>$topic)<article><h2>{{ $topic['title'] }}</h2><p>{{ $topic['description'] }}</p><button type="button" class="btn btn-outline" data-assistant-topic="{{ $key }}">اشرح الخطوات</button></article>@endforeach</div></section>
@endsection
