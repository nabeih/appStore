@extends('layouts.app')
@push('styles')<link rel="stylesheet" href="{{ asset('assets/admin/css/pages/account-profile.css') }}">@endpush
@section('title', 'ملفي الشخصي')
@section('content')
<section class="account-panel"><h1>ملفي الشخصي</h1>
@if(session('status'))<div class="message success" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="message error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
<div class="account-summary">
    <p><strong>الدور:</strong> {{ $user->role?->display_name ?? 'غير محدد' }}</p>
    <p><strong>الفرع:</strong> {{ $user->branch?->name ?? 'غير مرتبط بفرع' }}</p>
    <p><strong>البريد:</strong> {{ $user->hasVerifiedEmail() ? 'مؤكد' : 'غير مؤكد' }}</p>
</div>
@if(!$user->hasVerifiedEmail())
    <div class="email-protection">يمكنك استخدام الحساب وإعداد المتجر قبل التأكيد. تأكيد البريد يثبت ملكيتك له ويحمي إمكانية الاسترداد.</div>
    @if($user->email)
    <form method="POST" action="{{ route('verification.send') }}">@csrf<button class="account-secondary" type="submit">إرسال رابط تأكيد البريد</button></form>
    @endif
@endif
<form method="POST" action="{{ route('profile.update') }}">
    @csrf @method('PUT')
    <fieldset class="setup-section"><legend>بيانات الحساب</legend>
        <label for="name">الاسم</label><input id="name" name="name" value="{{ old('name', $user->name) }}" required minlength="2" maxlength="255" autocomplete="name">
        <label for="username">اسم المستخدم</label><input id="username" name="username" value="{{ old('username', $user->username) }}" required minlength="3" maxlength="50" autocomplete="username" dir="auto">
        <label for="email">البريد الإلكتروني</label><input id="email" name="email" type="email" value="{{ old('email', $user->email) }}" required maxlength="255" autocomplete="email" dir="ltr">
        <label for="email_password">كلمة المرور الحالية عند تغيير البريد</label><input id="email_password" name="current_password" type="password" autocomplete="current-password" dir="ltr">
        <p class="setup-help">تغيير البريد يلغي تأكيد البريد السابق وروابط استرداده. لا تتغير صلاحياتك أو فرعك.</p>
        <button type="submit" class="login-submit">حفظ بياناتي</button>
    </fieldset>
</form>
<form method="POST" action="{{ route('profile.password') }}">
    @csrf @method('PUT')
    <fieldset class="setup-section"><legend>تغيير كلمة المرور</legend>
        <label for="current_password">كلمة المرور الحالية</label><input id="current_password" name="current_password" type="password" required autocomplete="current-password" dir="ltr">
        <label for="password">كلمة المرور الجديدة</label><input id="password" name="password" type="password" required minlength="4" maxlength="72" autocomplete="new-password" dir="ltr">
        <label for="password_confirmation">تأكيد كلمة المرور</label><input id="password_confirmation" name="password_confirmation" type="password" required minlength="4" maxlength="72" autocomplete="new-password" dir="ltr">
        <p class="setup-help">من 4 أحرف على الأقل؛ ننصح بكلمة أطول. ستُنهى الجلسات الأخرى.</p>
        <button type="submit" class="login-submit">تغيير كلمة المرور</button>
    </fieldset>
</form>
</section>
@endsection
