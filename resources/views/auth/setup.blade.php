<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="referrer" content="no-referrer">
    <title>مرحبًا بك | إعداد المتجر لأول مرة</title>
    <link rel="stylesheet" href="{{ asset('assets/admin/css/pages/auth/login.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/admin/css/pages/auth/setup.css') }}">
    <link rel="stylesheet" href="{{ asset('assets/admin/css/pages/auth/account.css') }}">
    <script src="{{ asset('assets/admin/js/pages/auth/setup.js') }}" defer></script>
</head>
<body>
<main class="login-card setup-card">
    <div class="brand-mark" aria-hidden="true">أ</div>
    <p class="brand-name">إدارة متجرك تبدأ من هنا</p>
    <h1>أهلًا بك في متجرك الجديد</h1>
    <p class="login-description">جهّز حساب المالك بخطوتين بسيطتين. يتم هذا الإعداد مرة واحدة فقط.</p>
    <ol class="setup-steps" aria-label="خطوات الإعداد"><li><span>1</span> رمز التثبيت الآمن</li><li><span>2</span> حساب مالك المتجر</li></ol>
    @if($errors->any())
        <div class="message error" role="alert" id="setup-errors">
            <strong>راجع البيانات التالية</strong>
            @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif
    @if($prepared)
    <form method="POST" action="{{ route('setup.store') }}" data-setup-form>
        @csrf
        <fieldset class="setup-section">
            <legend>1. رمز الإعداد</legend>
            <p class="setup-help" id="token-help">أدخل الرمز الذي سلّمه لك مسؤول تثبيت التطبيق. لا ترسله لأحد ولا تضعه في رابط.</p>
            <label for="setup_token">رمز الإعداد لمرة واحدة</label>
            <input id="setup_token" name="setup_token" type="password" autocomplete="off" spellcheck="false" autocapitalize="none" minlength="64" maxlength="64" required dir="ltr" aria-describedby="token-help" @if($errors->has('setup_token')) aria-invalid="true" @endif>
        </fieldset>
        <fieldset class="setup-section">
            <legend>2. حساب المالك</legend>
            <p class="setup-help">هذا الحساب سيحصل على جميع صلاحيات الإدارة. أضف حسابات الموظفين لاحقًا من لوحة التحكم.</p>
            <div class="setup-grid">
                <div><label for="name">اسمك</label><input id="name" name="name" value="{{ old('name') }}" autocomplete="name" maxlength="255" minlength="2" required></div>
                <div><label for="username">اسم المستخدم</label><input id="username" name="username" value="{{ old('username') }}" autocomplete="username" autocapitalize="none" spellcheck="false" minlength="3" maxlength="50" required dir="auto"><small>حروف عربية أو إنجليزية وأرقام، دون مسافات.</small></div>
                <div class="setup-full"><label for="email">البريد الإلكتروني</label><input id="email" type="email" name="email" value="{{ old('email') }}" autocomplete="email" maxlength="255" required dir="ltr" aria-describedby="email-protection"><div class="email-protection" id="email-protection"><strong>البريد الإلكتروني ضروري لحماية حسابك واستعادته عند نسيان كلمة المرور. يرجى إدخال بريد إلكتروني حقيقي وفعّال، وتأكد أنك تستطيع الوصول إليه في أي وقت؛ لأننا سنرسل إليه رابطًا آمنًا لإعادة تعيين كلمة المرور عند الحاجة.</strong><p>لن نرسل كلمة مرورك إلى البريد الإلكتروني، بل رابطًا مؤقتًا لإنشاء كلمة مرور جديدة.</p></div></div>
                <div><label for="password">كلمة المرور</label><div class="password-field"><input id="password" name="password" type="password" autocomplete="new-password" minlength="4" maxlength="72" required dir="ltr"><button type="button" class="password-toggle" data-password-toggle="password" aria-controls="password" aria-pressed="false" hidden>إظهار</button></div></div>
                <div><label for="password_confirmation">تأكيد كلمة المرور</label><div class="password-field"><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="4" maxlength="72" required dir="ltr"><button type="button" class="password-toggle" data-password-toggle="password_confirmation" aria-controls="password_confirmation" aria-pressed="false" hidden>إظهار</button></div></div>
            </div>
            <p class="setup-help">الحد الأدنى 4 أحرف. ننصح بكلمة أطول وفريدة لحماية إدارة المتجر.</p>
        </fieldset>
        <p class="setup-note">سيُغلق الإعداد بعد إنشاء حسابك، وسيتم تسجيل دخولك تلقائيًا. لا تُضاف بضائع أو أرصدة مالية بهذه الخطوة.</p>
        <button class="login-submit" type="submit" data-setup-submit>إنشاء حساب المالك وبدء الاستخدام</button>
    </form>
    @elseif($rateLimited ?? false)
        <section class="message error" role="alert"><strong>محاولات إعداد كثيرة</strong><p>انتظر قليلًا ثم افتح شاشة الإعداد وحاول مجددًا. لم يتغير حساب أو رمز بسبب هذه المحاولات.</p></section>
    @else
        <section class="message" role="status"><strong>{{ $expiresAt?->isPast() ? 'انتهت صلاحية رمز الإعداد' : 'بانتظار رمز التثبيت' }}</strong><p>اطلب من مسؤول النشر تجهيز رمز آمن لهذا التثبيت. لم يُنشأ أي حساب بعد.</p></section>
    @endif
    <p class="login-footer">إعداد آمن لمرة واحدة • بيانات الدخول تخصك وحدك</p>
</main>
</body>
</html>
