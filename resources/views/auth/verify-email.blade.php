@extends('layouts.account')
@section('title', 'تأكيد البريد الإلكتروني')
@section('content')
<p class="login-description">تأكيد بريدك يثبت أنك تستطيع الوصول إليه. لا نعتبر البريد موثقًا بمجرد كتابة عنوانه.</p>
@if(auth()->user()->hasVerifiedEmail())
<div class="message success">بريدك الإلكتروني مؤكد بالفعل.</div>
@else
<p class="email-protection">بريد الحساب: <bdi>{{ auth()->user()->email ?? 'لم يُضف بعد؛ أضفه من ملفك الشخصي' }}</bdi><br>يمكنك الدخول وإعداد الفرع والعمل ضمن صلاحياتك قبل التأكيد. لن نرسل كلمة مرورك بالبريد.</p>
@if(auth()->user()->email)
<form method="POST" action="{{ route('verification.send') }}">@csrf<button type="submit" class="login-submit">إعادة إرسال رابط التحقق</button></form>
@endif
@endif
@endsection
