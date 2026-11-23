(() => {
    'use strict';
    const page = document.querySelector('[data-product-show]');
    const button = page?.querySelector('[data-product-delete]');
    if (!button) return;
    let busy = false;
    button.addEventListener('click', async () => {
        if (busy || !window.confirm('هل تريد حذف هذا المنتج؟ إذا كان مرتبطًا بمستندات فسيُعطّل مع بقاء مستنداته ومخزونه، وإلا سيُؤرشف.')) return;
        busy = true;
        button.disabled = true;
        const feedback = page.querySelector('[data-product-show-feedback]');
        feedback.hidden = true;
        try {
            const response = await fetch(button.dataset.url, {
                method:'DELETE', credentials:'same-origin',
                headers:{Accept:'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}
            });
            const result = await response.json();
            if (!response.ok) throw new Error(Object.values(result.errors || {}).flat().join(' ') || result.message || 'تعذر تنفيذ الأرشفة؛ لم يُحذف المنتج.');
            feedback.textContent = result.message; feedback.hidden = false;
            button.hidden = true;
            const back = document.createElement('a'); back.href = page.dataset.backUrl; back.textContent = 'العودة إلى المخزون'; back.className = 'btn btn-outline'; feedback.append(' ', back);
        } catch (error) {
            feedback.textContent = error instanceof TypeError ? 'تعذر الاتصال. حدّث الصفحة للتحقق من النتيجة قبل إعادة المحاولة.' : error.message;
            feedback.hidden = false;
            button.disabled = false;
            busy = false;
        }
    });
})();
