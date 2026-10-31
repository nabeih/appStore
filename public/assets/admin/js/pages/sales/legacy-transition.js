(() => {
    'use strict';
    const panel = document.querySelector('[data-legacy-transition]');
    if (!panel) return;
    const form = panel.querySelector('[data-transition-form]');
    const feedback = panel.querySelector('[data-transition-feedback]');
    const result = panel.querySelector('[data-transition-result]');
    const post = panel.querySelector('[data-transition-post]');
    let reviewed = null, eligible = false, busy = false, generation = 0;
    const data = () => {
        const values = Object.fromEntries(new FormData(form));
        const [kind, id] = (values.source || '').split(':');
        delete values.source;
        return {...values, source_kind:kind, source_id:id, funding_confirmed:form.elements.funding_confirmed.checked, confirmed:form.elements.confirmed.checked};
    };
    const request = async (url, values) => {
        const response = await fetch(url, {method:'POST', credentials:'same-origin', headers:{
            'Accept':'application/json', 'Content-Type':'application/json',
            'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content || ''
        }, body:JSON.stringify(values)});
        const body = await response.json();
        if (!response.ok) throw new Error(Object.values(body.errors || {}).flat().join(' — ') || body.message || 'تعذر إتمام الطلب.');
        return body;
    };
    const changed = event => {
        if (event.target.name === 'confirmed' && reviewed) {
            reviewed.confirmed = form.elements.confirmed.checked;
            post.disabled = !eligible || !reviewed.confirmed || panel.dataset.enabled !== '1';
            return;
        }
        generation++; reviewed = null; eligible = false; post.disabled = true;
    };
    form.addEventListener('input', changed);
    form.addEventListener('change', changed);
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        busy = true; reviewed = null; eligible = false; post.disabled = true;
        feedback.textContent = 'جارٍ فحص المستند والأدلة...';
        try {
            const version = generation, values = data(), plan = await request(panel.dataset.previewUrl, values);
            if (version !== generation) {
                feedback.textContent = 'تغيرت المدخلات أثناء الفحص؛ أعد المعاينة.';
                return;
            }
            result.dataset.previewStatus = plan.status;
            result.textContent = [
                'التاريخ: ' + plan.posting_date,
                plan.amount_text === null ? 'المبلغ لم يثبت بعد' : 'المبلغ: ' + plan.amount_text + ' ₪',
                plan.effect_text,
                plan.journal_id ? 'القيد الموجود #' + plan.journal_id + ' — لا يكرر' : '',
                ...(plan.issues || [])
            ].filter(Boolean).join('\n');
            feedback.textContent = plan.status === 'rejected' ? 'رفضت المعاينة؛ عالج الموانع الموضحة.' : plan.status === 'already_posted' ? 'يوجد قيد مطابق؛ لن ينشأ قيد جديد.' : 'المستند مؤهل وفق الأدلة المدخلة؛ الاعتماد قرار مستقل.';
            reviewed = {...values, revision:plan.revision}; eligible = plan.status === 'eligible';
            post.disabled = !eligible || panel.dataset.enabled !== '1' || !values.confirmed;
        } catch (error) { feedback.textContent = error.message; }
        finally { busy = false; }
    });
    post.addEventListener('click', async () => {
        if (busy || !reviewed || post.disabled) return;
        if (!window.confirm('اعتماد الأثر المالي المعروض؟ لن تضاف كمية أو دفعة أخرى.')) return;
        busy = true; post.disabled = true;
        try {
            const response = await request(panel.dataset.postUrl, reviewed);
            feedback.textContent = response.message + ' القيد #' + response.journal_id;
            reviewed = null;
        } catch (error) { feedback.textContent = error.message; reviewed = null; }
        finally { busy = false; }
    });
})();
