@extends('layouts.account')
@section('title', 'نسيت كلمة المرور؟')
@section('content')
<p class="login-description">أدخل البريد الإلكتروني المرتبط بحسابك، وسنرسل لك رابطًا آمنًا لإعادة تعيين كلمة المرور.</p>
<form method="POST" action="{{ route('password.email') }}">
    @csrf
    <label for="email">البريد الإلكتروني</label><input id="email" type="email" name="email" value="{{ old('email') }}" required maxlength="255" dir="ltr" autocomplete="email">
    <button class="login-submit" type="submit">إرسال رابط الاسترداد</button>
</form>
<p class="setup-note">لن نرسل كلمة مرورك إلى البريد الإلكتروني، بل رابطًا مؤقتًا لإنشاء كلمة مرور جديدة.</p>
@endsection
