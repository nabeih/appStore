(() => {
    'use strict';
    document.querySelectorAll('[data-password-toggle]').forEach(button => {
        const input = document.getElementById(button.dataset.passwordToggle);
        if (!input) return;
        button.hidden = false;
        button.addEventListener('click', () => {
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            button.textContent = visible ? 'إخفاء' : 'إظهار';
            button.setAttribute('aria-pressed', visible ? 'true' : 'false');
        });
    });
    const form = document.querySelector('[data-setup-form]');
    const button = document.querySelector('[data-setup-submit]');
    if (!form || !button) return;
    form.addEventListener('submit', () => {
        button.disabled = true;
        button.textContent = 'جارٍ تجهيز حسابك...';
    });
    window.addEventListener('pageshow', () => {
        button.disabled = false;
        button.textContent = 'إنشاء حساب المالك وبدء الاستخدام';
    });
})();
