<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="same-origin">
    <title>تسجيل الدخول | متجر الأناقة</title>
    <link rel="stylesheet" href="{{ asset('assets/admin/css/pages/auth/login.css') }}">
    <script src="{{ asset('assets/admin/js/pages/auth/login.js') }}" defer></script>
</head>
<body>
    <main class="login-card">
        <div class="brand-mark" aria-hidden="true">أ</div>
        <p class="brand-name">متجر الأناقة</p>
        <h1>أهلًا بعودتك</h1>
        <p class="login-description">سجّل الدخول لإدارة متجرك ومتابعة أعمالك.</p>

        @if(session('status'))
            <div class="message success" role="status">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="message error" role="alert" id="login-errors">
                @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('login.store') }}" id="login-form">
            @csrf
            <label for="username">اسم المستخدم</label>
            <input id="username" name="username" type="text" value="{{ old('username') }}" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="50" required autofocus dir="ltr" @if($errors->has('username')) aria-invalid="true" aria-describedby="login-errors" @endif>

            <label for="password">كلمة المرور</label>
            <div class="password-field">
                <input id="password" name="password" type="password" autocomplete="current-password" maxlength="72" required dir="ltr" @if($errors->has('password')) aria-invalid="true" aria-describedby="login-errors" @endif>
                <button class="password-toggle" id="password-toggle" type="button" aria-controls="password" aria-pressed="false" hidden>إظهار</button>
            </div>
            <button type="submit" class="login-submit" id="login-submit">تسجيل الدخول</button>
        </form>
        <p class="login-footer">لوحة إدارة المتجر</p>
    </main>
</body>
</html>
