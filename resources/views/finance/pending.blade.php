@extends('layouts.app')
@section('title','التحديث المالي')
@section('content')
<section class="screen active"><div class="card" style="padding:20px"><h1 class="section-title">التحديث المالي مجهز للمراجعة</h1><p>لم تُطبق الجداول الجديدة على قاعدة البيانات الحالية. يلزم اعتماد نشر التحديث ومراجعة كشف الانتقال قبل تشغيل المصروفات والصناديق.</p><p>الأرصدة الحالية لا تمثل كل حركات المتجر ولا تثبت مطابقتها للنقد أو كشف البنك.</p><a href="{{ route('finance.accounts.index') }}">حسابات القبض الحالية</a></div></section>
@endsection
