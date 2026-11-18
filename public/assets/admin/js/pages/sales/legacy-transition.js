(() => {
    'use strict';
    const panel = document.querySelector('[data-legacy-transition]');
    if (!panel) return;
    const enabled = panel.dataset.enabled === '1';
    const slots = { preview: 0, post: 0 };
    const request = async (kind, values) => {
        const wait = Math.max(0, slots[kind] - Date.now());
        slots[kind] = Date.now() + wait + (kind === 'post' ? 6200 : 2100);
        if (wait) await new Promise(resolve => setTimeout(resolve, wait));
        const response = await fetch(kind === 'post' ? panel.dataset.postUrl : panel.dataset.previewUrl, {
            method: 'POST', credentials: 'same-origin', headers: {
                'Accept': 'application/json', 'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            }, body: JSON.stringify(values),
        });
        const body = await response.json();
        if (!response.ok && body.guidance) window.dispatchEvent(new CustomEvent('appstore:guidance', { detail: body.guidance }));
        if (!response.ok) throw new Error(Object.values(body.errors || {}).flat().join(' — ') || body.message || 'تعذر إتمام الطلب؛ أعد المعاينة للتحقق من النتيجة.');
        return body;
    };
    const describe = plan => [
        'التاريخ: ' + plan.posting_date,
        plan.amount_text === null ? 'المبلغ لم يثبت بعد' : 'المبلغ: ' + plan.amount_text + ' ₪',
        plan.effect_text,
        plan.journal_id ? 'القيد الموجود #' + plan.journal_id + ' — لا يكرر' : '',
        ...plan.issues,
    ].filter(Boolean).join('\n');
    const freeze = form => {
        const fields = [...form.querySelectorAll('input,select,textarea,button')].map(field => [field, field.disabled]);
        fields.forEach(([field]) => { field.disabled = true; });
        return () => fields.forEach(([field, disabled]) => { field.disabled = disabled; });
    };
    const form = panel.querySelector('[data-transition-form]');
    const feedback = panel.querySelector('[data-transition-feedback]');
    const result = panel.querySelector('[data-transition-result]');
    const post = panel.querySelector('[data-transition-post]');
    const confirmed = form.elements.confirmed;
    let reviewed = null, eligible = false, busy = false;
    confirmed.disabled = true;
    const updatePost = () => { post.disabled = busy || !eligible || !reviewed || !confirmed.checked || !enabled; };
    const invalidate = () => { reviewed = null; eligible = false; confirmed.checked = false; confirmed.disabled = true; updatePost(); };
    const selectSource = () => {
        const option = form.elements.source.selectedOptions[0];
        const kind = form.elements.source.value.split(':')[0];
        const funding = panel.querySelector('[data-transition-funding]');
        funding.hidden = funding.disabled = kind !== 'payment';
        if (option?.dataset.branch) form.elements.branch_id.value = option.dataset.branch;
        if (option?.dataset.date) form.elements.posting_date.value = option.dataset.date;
        const summary = panel.querySelector('[data-opening-summary]');
        summary.hidden = kind !== 'opening';
        summary.textContent = kind === 'opening' ? `كمية المستند: ${option.dataset.quantity} قطعة — القيمة: ${option.dataset.amount} ₪. القيد مقابل الافتتاح، دون دفع أو إضافة بضاعة.` : '';
    };
    const changed = event => {
        if (event.target === confirmed) { updatePost(); return; }
        invalidate();
        if (event.target.name === 'source') selectSource();
        result.textContent = ''; delete result.dataset.previewStatus;
    };
    form.addEventListener('input', changed); form.addEventListener('change', changed); selectSource();
    form.addEventListener('submit', async event => {
        event.preventDefault(); if (busy || !form.reportValidity()) return;
        const values = Object.fromEntries(new FormData(form));
        const [kind, id] = values.source.split(':'); delete values.source;
        values.source_kind = kind; values.source_id = id; values.confirmed = false;
        if (kind === 'payment') values.funding_confirmed = form.elements.funding_confirmed.checked;
        invalidate(); busy = true; const release = freeze(form);
        feedback.textContent = 'جارٍ فحص المستند والأدلة...';
        try {
            const plan = await request('preview', values);
            result.dataset.previewStatus = plan.status; result.textContent = describe(plan);
            reviewed = { ...values, revision: plan.revision }; eligible = plan.status === 'eligible';
            feedback.textContent = eligible ? 'راجع المعاينة ثم فعّل الموافقة الصريحة للاعتماد.' : plan.status === 'already_posted' ? 'يوجد قيد مطابق؛ لن ينشأ قيد جديد.' : 'المستند غير جاهز؛ راجع الأسباب.';
        } catch (error) { feedback.textContent = error.message; }
        finally { release(); busy = false; confirmed.disabled = !eligible; updatePost(); }
    });
    post.addEventListener('click', async () => {
        if (busy || post.disabled || !reviewed) return;
        if (!window.confirm('اعتماد الأثر المالي المعروض؟ لن تضاف كمية أو دفعة أخرى.')) return;
        const values = { ...reviewed, confirmed: true }; busy = true; const release = freeze(form);
        try {
            const response = await request('post', values);
            feedback.textContent = response.message + ' القيد #' + response.journal_id;
        } catch (error) { feedback.textContent = error.message + ' أعد المعاينة قبل المحاولة مجددًا.'; }
        finally { release(); busy = false; invalidate(); }
    });
    const batch = document.querySelector('[data-opening-batch-form]');
    if (!batch) return;
    const batchPost = batch.querySelector('[data-opening-batch-post]');
    const batchFeedback = batch.querySelector('[data-opening-batch-feedback]');
    const consent = batch.elements.confirmed;
    let plans = [], batchBusy = false;
    const updateBatchPost = () => { batchPost.disabled = batchBusy || !enabled || !plans.length || !consent.checked; };
    const invalidateBatch = () => { plans = []; consent.checked = false; consent.disabled = true; updateBatchPost(); };
    const batchChanged = event => { if (event.target === consent) updateBatchPost(); else invalidateBatch(); };
    batch.addEventListener('input', batchChanged); batch.addEventListener('change', batchChanged);
    batch.addEventListener('submit', async event => {
        event.preventDefault(); if (batchBusy || !batch.reportValidity()) return;
        const rows = [...batch.querySelectorAll('[data-opening-row]')].filter(row => row.querySelector('[data-opening-select]').checked);
        if (!rows.length) { batchFeedback.textContent = 'حدد مستندًا جاهزًا للمعاينة أولًا.'; return; }
        const shared = Object.fromEntries(new FormData(batch));
        invalidateBatch(); batchBusy = true; const release = freeze(batch); const completed = [];
        try {
            for (const [index, row] of rows.entries()) {
                const output = row.querySelector('[data-opening-result]');
                batchFeedback.textContent = `جارٍ معاينة ${index + 1} من ${rows.length}؛ لا أثر مالي حتى الاعتماد.`;
                const values = { source_kind: 'opening', source_id: row.dataset.id, branch_id: row.dataset.branch,
                    posting_date: shared.posting_date || row.dataset.date, evidence: shared.evidence, reason: shared.reason, confirmed: false };
                try {
                    const plan = await request('preview', values);
                    output.textContent = describe(plan); output.style.whiteSpace = 'pre-line'; output.dataset.status = plan.status;
                    if (plan.status === 'eligible') plans.push({ row, values: { ...values, revision: plan.revision } });
                    if (plan.status === 'already_posted') completed.push(row);
                } catch (error) { output.textContent = error.message; output.dataset.status = 'failed'; }
            }
        } finally {
            release(); batchBusy = false;
            completed.forEach(row => { const input = row.querySelector('[data-opening-select]'); input.checked = false; input.disabled = true; });
            consent.disabled = plans.length === 0; updateBatchPost();
            batchFeedback.textContent = `${plans.length} مستند جاهز للاعتماد من ${rows.length}. راجع أثر كل مستند ثم وافق صراحة. المستندات غير الجاهزة لن تُعتمد.`;
        }
    });
    batchPost.addEventListener('click', async () => {
        if (batchBusy || batchPost.disabled) return;
        if (!window.confirm(`اعتماد ${plans.length} مستند ماليًا؟ كل مستند مستقل، دون إضافة بضاعة أو دفع.`)) return;
        const queue = [...plans]; const completed = []; let successful = 0;
        batchBusy = true; const release = freeze(batch);
        try {
            for (const [index, item] of queue.entries()) {
                batchFeedback.textContent = `جارٍ اعتماد ${index + 1} من ${queue.length}. اترك الصفحة مفتوحة؛ النتيجة مستقلة لكل مستند.`;
                const output = item.row.querySelector('[data-opening-result]');
                try {
                    const response = await request('post', { ...item.values, confirmed: true });
                    output.textContent += '\n' + response.message + ' القيد #' + response.journal_id;
                    output.dataset.status = 'posted'; completed.push(item.row); successful++;
                } catch (error) { output.textContent += '\nلم يتأكد الاعتماد: ' + error.message + ' أعد المعاينة قبل المحاولة مجددًا.'; output.dataset.status = 'failed'; }
            }
        } finally {
            release(); batchBusy = false; invalidateBatch();
            completed.forEach(row => { const input = row.querySelector('[data-opening-select]'); input.checked = false; input.disabled = true; });
            batchFeedback.textContent = `نجح ${successful} من ${queue.length}. راجع نتيجة كل مستند؛ أعد المعاينة لأي نتيجة غير مؤكدة دون إنشاء مستند جديد.`;
        }
    });
})();
