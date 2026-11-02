<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title') | إدارة المتجر</title>
    <link rel="stylesheet" href="{{ asset('assets/admin/css/pages/auth/login.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/admin/css/pages/auth/setup.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/admin/css/pages/auth/account.css') }}">
</head>
<body>
<main class="login-card setup-card">
    <div class="brand-mark" aria-hidden="true">أ</div>
    <h1>@yield('title')</h1>
    @if(session('status'))<div class="message success" role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="message error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @yield('content')
    <nav class="account-links" aria-label="روابط الحساب">
    @auth
        <a href="{{ route('dashboard.index') }}">لوحة الإدارة</a>
        <a href="{{ route('profile.show') }}">ملفي الشخصي</a>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="account-link-button">تسجيل الخروج</button></form>
    @else
        <a href="{{ route('login') }}">تسجيل الدخول</a>
    @endauth
    </nav>
</main>
</body>
</html>
