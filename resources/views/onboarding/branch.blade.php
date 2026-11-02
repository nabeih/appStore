@extends('layouts.account')
@section('title', 'إعداد متجرك لأول مرة')
@section('content')
<p class="login-description">أضف فرعك الأول لبدء تجهيز المتجر. يمكنك إضافة بياناته الفعلية فقط.</p>
<div class="setup-note">إنشاء الفرع لا يفعّل البيع. تبقى فترة الإعداد الافتتاحي مفتوحة حتى مراجعة المخزون والأرصدة واعتمادها من صاحب الصلاحية.</div>
@if(!auth()->user()->hasVerifiedEmail())
<div class="email-protection">بريدك الإلكتروني لم يُؤكد بعد. يمكنك إعداد الفرع الآن، ثم <a href="{{ route('verification.notice') }}">تأكيد البريد لحماية الحساب</a>.</div>
@endif
<form method="POST" action="{{ route('onboarding.branch.store') }}">
    @csrf
    <label for="name">اسم الفرع الأول</label><input id="name" name="name" value="{{ old('name') }}" maxlength="100" required autocomplete="organization">
    <label for="address">العنوان <span class="setup-optional">(اختياري)</span></label><input id="address" name="address" value="{{ old('address') }}" maxlength="255" autocomplete="street-address">
    <label for="phone">رقم الهاتف <span class="setup-optional">(اختياري)</span></label><input id="phone" name="phone" type="tel" value="{{ old('phone') }}" maxlength="50" autocomplete="tel" dir="ltr">
    <button type="submit" class="login-submit">حفظ الفرع والمتابعة</button>
</form>
@endsection
