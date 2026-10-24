(() => {
    'use strict';
    const form = document.getElementById('login-form');
    const password = document.getElementById('password');
    const toggle = document.getElementById('password-toggle');
    const submit = document.getElementById('login-submit');
    toggle.hidden = false;
    toggle.addEventListener('click', () => {
        const visible = password.type === 'password';
        password.type = visible ? 'text' : 'password';
        toggle.textContent = visible ? 'إخفاء' : 'إظهار';
        toggle.setAttribute('aria-pressed', String(visible));
    });
    form.addEventListener('submit', () => {
        submit.disabled = true;
        submit.textContent = 'جارٍ تسجيل الدخول…';
    });
    window.addEventListener('pageshow', () => {
        submit.disabled = false;
        submit.textContent = 'تسجيل الدخول';
        password.type = 'password';
        toggle.textContent = 'إظهار';
        toggle.setAttribute('aria-pressed', 'false');
    });
})();
