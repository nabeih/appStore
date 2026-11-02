(() => {
    'use strict';
    document.querySelectorAll('[data-branch-status]').forEach(form => {
        form.addEventListener('submit', event => {
            if (!window.confirm(form.dataset.branchAction + ' الفرع «' + form.dataset.branchLabel + '»؟ لن تُحذف بياناته.')) {
                event.preventDefault();
            }
        });
    });
    document.querySelectorAll('[data-branch-save]').forEach(form => {
        form.addEventListener('submit', () => {
            const button = form.querySelector('[type=submit]');
            button.disabled = true;
            button.textContent = 'جارٍ الحفظ…';
        });
    });
    window.addEventListener('pageshow', () => document.querySelectorAll('[data-branch-save] [type=submit]').forEach(button => {
        button.disabled = false;
        button.textContent = 'حفظ الفرع';
    }));
})();
