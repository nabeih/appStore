(() => {
    'use strict';
    document.querySelectorAll('[data-print]').forEach(button => button.addEventListener('click', () => window.print()));
    document.querySelectorAll('[data-open-dialog]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.openDialog).showModal()));
    document.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
    document.querySelectorAll('[data-financial-form]').forEach(form => {
        let pending = false;
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (pending) return;
            pending = true;
            const button = form.querySelector('[type="submit"]');
            button.disabled = true;
            const errors = document.querySelector('[data-document-errors]');
            errors.hidden = true;
            try {
                const response = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json' }, body: new FormData(form) });
                const data = await response.json();
                if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join('\n') || data.message || 'تعذر تنفيذ العملية.');
                window.location.assign(data.url);
            } catch (error) {
                form.closest('dialog')?.close();
                errors.textContent = error.message || 'تعذر الاتصال. يمكنك إعادة المحاولة بأمان.';
                errors.hidden = false;
                errors.focus();
                pending = false;
                button.disabled = false;
            }
        });
    });
})();
