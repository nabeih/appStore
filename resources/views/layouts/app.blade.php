<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'نظام إدارة المتجر')</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800;900&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="{{ asset('assets/admin/css/style.css') }}">

    <link rel="stylesheet" href="{{ asset('assets/admin/css/catalog/color-dot.css') }}">

    <link rel="stylesheet" href="{{ asset('assets/admin/css/pages/auth/session.css') }}">
    @stack('styles')
    <link rel="stylesheet" href="{{ asset('assets/admin/css/mobile-documents.css') }}">
</head>

<body class="laravel-admin">

    <div id="appScreen">


        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        @include('partials.sidebar')

        <main class="main-content">

            @include('partials.topbar')

            @yield('content')

        </main>
    </div>

    <div class="toast-container" id="toastContainer"></div>

    <script src="{{ asset('assets/admin/js/pages/auth/session.js') }}" defer></script>
    <script src="{{ asset('assets/admin/js/layout.js') }}" defer></script>
    @stack('scripts')
</body>

</html>