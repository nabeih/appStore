(() => {
    'use strict';
    const configNode = document.getElementById('store-assistant-config');
    if (!configNode) return;
    const config = JSON.parse(configNode.textContent);
    const dialog = document.getElementById('store-assistant-dialog');
    const form = document.getElementById('store-assistant-form');
    const question = document.getElementById('store-assistant-question');
    const answer = document.getElementById('store-assistant-answer');
    const status = document.getElementById('store-assistant-status');
    let code = null, page = config.page, previousFocus, pendingRequest, requestId = 0;
    const node = (tag, text) => { const value = document.createElement(tag); value.textContent = text; return value; };
    const safeLink = (label, url) => {
        if (typeof url !== 'string' || !url.startsWith('/') || url.startsWith('//')) return null;
        const target = new URL(url, location.origin);
        if (target.origin !== location.origin) return null;
        const link = node('a', label); link.href = target.href; return link;
    };
    function open(problem = null, topic = config.page) {
        code = problem; page = topic; answer.replaceChildren(); status.textContent = '';
        if (!dialog.open) { previousFocus = document.activeElement; dialog.showModal(); }
        if (problem || topic) ask(''); else question.focus();
    }
    function attach(root, guidance = null) {
        if (!root || root.hidden || root.querySelector('[data-assistant-error-help]') || !root.textContent.trim()) return;
        const button = node('button', 'ساعدني أحل المشكلة'); button.type = 'button'; button.className = 'store-help-button'; button.dataset.assistantErrorHelp = '';
        button.addEventListener('click', () => open(guidance?.code || null)); root.append(button);
    }
    function notice(guidance) {
        if (!guidance?.code) return;
        const box = document.getElementById('store-guidance-notice'); box.replaceChildren(node('strong', guidance.title), node('p', guidance.message), node('p', guidance.next_step)); box.hidden = false;
        if (guidance.action_url) { const link = safeLink(guidance.action_label, guidance.action_url); if (link) box.append(link); }
        attach(box, guidance);
        const close = node('button', 'إخفاء'); close.type = 'button'; close.addEventListener('click', () => { box.hidden = true; }); box.append(close);
    }
    async function ask(text) {
        pendingRequest?.abort(); pendingRequest = new AbortController(); const currentRequest = ++requestId;
        form.querySelector('button').disabled = true; status.textContent = 'جارٍ مراجعة الإرشادات المسموحة…';
        try {
            const branchSelection = !config.document_kind && document.querySelector('#cashier-branch, #purchase-branch, .sale-filters select[name="branch_id"]');
            const branchId = branchSelection && /^\d+$/.test(branchSelection.value) ? Number(branchSelection.value) : config.branch_id;
            const response = await fetch(config.endpoint, { method: 'POST', credentials: 'same-origin', signal: pendingRequest.signal, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                body: JSON.stringify({ page, code, question: text, branch_id: branchId, document_kind: config.document_kind, document_id: config.document_id }) });
            const result = await response.json();
            if (currentRequest !== requestId) return;
            if (!response.ok) throw Error(result.message || 'تعذر عرض المساعدة الآن. احتفظ بصفحتك وحاول بعد قليل.');
            answer.replaceChildren(node('p', result.answer));
            const list = node('ol', ''); (result.steps || []).forEach(step => list.append(node('li', step))); answer.append(list);
            const links = [...(result.links || [])];
            if (result.guidance?.action_url) links.push({ label: result.guidance.action_label, url: result.guidance.action_url });
            const seen = new Set(); links.forEach(item => { if (seen.has(item.url)) return; seen.add(item.url); const link = safeLink(item.label, item.url); if (link) answer.append(link); });
            const branch = result.context?.branch; document.getElementById('store-assistant-context').textContent = branch ? `الفرع الحالي: ${branch.name}` : 'إرشادات عامة؛ اختر الفرع في الشاشة للحصول على حالته.';
            status.textContent = result.uncertain ? 'لا توجد إجابة مؤكدة لهذا السؤال بعد.' : '';
        } catch (error) { if (currentRequest === requestId && error.name !== 'AbortError') status.textContent = error.message; }
        finally { if (currentRequest === requestId) form.querySelector('button').disabled = false; }
    }
    document.getElementById('store-assistant-launch').addEventListener('click', () => open());
    dialog.querySelector('[data-assistant-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => previousFocus?.focus());
    dialog.querySelector('[data-assistant-current]').addEventListener('click', () => { code = null; page = config.page; ask(''); });
    form.addEventListener('submit', event => { event.preventDefault(); code = null; ask(question.value.trim()); });
    document.addEventListener('click', event => { const button = event.target.closest('[data-assistant-topic]'); if (button) open(null, button.dataset.assistantTopic); });
    window.addEventListener('appstore:guidance', event => notice(event.detail));
    window.StoreAssistant = { attach, open, notice };
    let scanPending = false;
    const scan = () => { scanPending = false; document.querySelectorAll('[data-sales-errors], [data-errors], .alert-danger, [role="alert"]').forEach(root => { if (!dialog.contains(root) && root.getClientRects().length) attach(root); }); };
    new MutationObserver(() => { if (!scanPending) { scanPending = true; requestAnimationFrame(scan); } }).observe(document.querySelector('main') || document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['hidden'] });
    scan();
    const initial = document.getElementById('store-assistant-initial-guidance'); if (initial) notice(JSON.parse(initial.textContent));
    if (config.initial_code) open(config.initial_code);
})();
