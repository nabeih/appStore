@extends('layouts.account')
@section('title', 'إنشاء كلمة مرور جديدة')
@section('content')
<form method="POST" action="{{ route('password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <label for="email">البريد الإلكتروني</label><input id="email" type="email" name="email" value="{{ $email }}" required maxlength="255" autocomplete="email" dir="ltr">
    <label for="password">كلمة المرور الجديدة</label><input id="password" name="password" type="password" required minlength="4" maxlength="72" autocomplete="new-password" dir="ltr">
    <label for="password_confirmation">تأكيد كلمة المرور</label><input id="password_confirmation" name="password_confirmation" type="password" required minlength="4" maxlength="72" autocomplete="new-password" dir="ltr">
    <p class="setup-help">الحد الأدنى 4 أحرف. ننصح بكلمة أطول وفريدة.</p>
    <button type="submit" class="login-submit">حفظ كلمة المرور</button>
</form>
@endsection
