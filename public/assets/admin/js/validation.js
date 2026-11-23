(() => {
    'use strict';
    if (window.FormValidation) return;
    let sequence = 0, interaction = null;
    const requests = new WeakMap();
    const controls = 'input,select,textarea,[data-validation-field]';
    const normalize = name => String(name || '').replace(/\[([^\]]*)\]/g, '.$1').replace(/\.$/, '.*');
    const readable = value => {
        const text = String(value || '');
        return /[\u0600-\u06ff]/.test(text) && !/SQLSTATE|\bSQL\b|(?:App|Illuminate)\\|\bstack trace\b/i.test(text)
            ? text : 'راجع القيمة المدخلة وفق التعليمات بجانب الحقل، ثم حاول مجددًا.';
    };
    function fields(scope, key) {
        return [...scope.querySelectorAll(controls)].filter(input => {
            if (input.type === 'hidden') return false;
            const name = normalize(input.dataset.validationField || input.name);
            return name === key || name === key + '.*' || (name.endsWith('.*') && key.startsWith(name.slice(0, -1)));
        });
    }
    function clear(scope) {
        scope.querySelectorAll('[data-validation-message]').forEach(node => node.remove());
        scope.querySelectorAll('.validation-invalid').forEach(input => {
            input.classList.remove('validation-invalid'); input.removeAttribute('aria-invalid');
            const ids = (input.getAttribute('aria-describedby') || '').split(/\s+/).filter(id => !id.startsWith('validation-error-') && !id.startsWith('field-error-'));
            if (ids.length) input.setAttribute('aria-describedby', ids.join(' ')); else input.removeAttribute('aria-describedby');
        });
        scope.querySelectorAll('[data-validation-summary]').forEach(node => node.remove());
    }
    function focus(input, attempts = 0) {
        if (!input) return;
        for (let node = input.parentElement; node; node = node.parentElement) {
            if (node.matches('details')) node.open = true;
            if (node.matches('dialog') && !node.open) node.showModal();
            if (node.matches('.collapse:not(.show)')) { if (window.bootstrap?.Collapse) window.bootstrap.Collapse.getOrCreateInstance(node).show(); else node.classList.add('show'); }
            if (node.matches('.tab-pane:not(.active)') && node.id) {
                const tab = [...document.querySelectorAll('[data-bs-toggle="tab"]')].find(item => item.getAttribute('data-bs-target') === '#' + node.id || item.getAttribute('href') === '#' + node.id);
                if (tab && window.bootstrap?.Tab) window.bootstrap.Tab.getOrCreateInstance(tab).show();
                else if (tab) { tab.click(); node.classList.add('active', 'show'); tab.setAttribute('aria-selected', 'true'); }
            }
        }
        if (input.matches(':disabled')) { if (attempts < 20) setTimeout(() => focus(input, attempts + 1), 100); return; }
        input.focus(); input.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
    function show(scope, errors, options = {}) {
        if (!scope?.isConnected) return;
        clear(scope);
        const summary = document.createElement('div'); summary.className = 'validation-summary';
        summary.dataset.validationSummary = ''; summary.setAttribute('role', 'alert'); summary.tabIndex = -1;
        const title = document.createElement('strong'); title.textContent = 'لم يُحفظ الإدخال. راجع البيانات التالية:'; summary.append(title);
        const list = document.createElement('ul'); summary.append(list);
        let first = null;
        Object.entries(errors || {}).forEach(([key, messages]) => {
            const targets = fields(scope, key);
            const inputLabel = targets[0]?.getAttribute('aria-label') || [...(targets[0]?.labels?.[0]?.childNodes || [])].filter(node => node.nodeType === Node.TEXT_NODE).map(node => node.textContent).join(' ').trim();
            const text = [...new Set((Array.isArray(messages) ? messages : [messages]).map(readable))].join('\n').replaceAll('هذا الحقل', /[\u0600-\u06ff]/.test(inputLabel) ? inputLabel : 'هذا الحقل');
            targets.forEach(input => {
                const message = document.createElement('span'); message.id = 'validation-error-' + (++sequence);
                message.dataset.validationMessage = ''; message.className = 'validation-field-error'; message.textContent = text;
                input.classList.add('validation-invalid'); input.setAttribute('aria-invalid', 'true');
                input.setAttribute('aria-describedby', [input.getAttribute('aria-describedby'), message.id].filter(Boolean).join(' '));
                input.insertAdjacentElement('afterend', message);
                first ||= input;
            });
            const li = document.createElement('li');
            if (targets.length) {
                const button = document.createElement('button'); button.type = 'button'; button.textContent = text;
                button.addEventListener('click', () => focus(targets[0])); li.append(button);
            } else { li.textContent = text; }
            list.append(li);
        });
        if (!list.children.length) return;
        (scope.matches('dialog') ? scope.querySelector('.os-dialog-body') || scope : scope).prepend(summary);
        if (options.focus !== false) setTimeout(() => { if (first) focus(first); else summary.focus(); }, 0);
    }
    function context(event) {
        const target = event.target;
        if (!(target instanceof Element)) return;
        const scope = target.closest('form,[data-validation-scope],dialog');
        if (scope) interaction = { scope, time: Date.now() };
    }
    document.addEventListener('submit', event => {
        context(event);
        if (!(event.target instanceof HTMLFormElement)) return;
        try {
            sessionStorage.setItem('appstore.validation.form', JSON.stringify({ page: location.pathname, id: event.target.id, action: event.target.action, index: [...document.forms].indexOf(event.target), time: Date.now() }));
        } catch (_) { /* Validation remains available when browser storage is disabled. */ }
    }, true);
    document.addEventListener('click', context, true);
    document.addEventListener('change', context, true);
    document.addEventListener('invalid', event => {
        const input = event.target, scope = input.form || input.closest('[data-validation-scope]');
        if (!scope) return;
        event.preventDefault();
        scope._invalidFields ||= {};
        if (!input.name && !input.dataset.validationField) input.dataset.validationField = 'local.' + (++sequence);
        const key = normalize(input.dataset.validationField || input.name);
        scope._invalidFields[key] = [input.validity.valueMissing ? 'هذا الحقل مطلوب. أكمله قبل المتابعة.' : input.validity.typeMismatch ? 'صيغة القيمة غير صحيحة. راجع المثال أو التعليمات بجانب الحقل.' : 'راجع القيمة المدخلة والحدود الموضحة بجانب الحقل.'];
        clearTimeout(scope._invalidTimer);
        scope._invalidTimer = setTimeout(() => { show(scope, scope._invalidFields); scope._invalidFields = {}; }, 0);
    }, true);
    const fetchOriginal = window.fetch.bind(window);
    window.fetch = async (input, init = {}) => {
        const url = new URL(typeof input === 'string' || input instanceof URL ? input : input.url, location.href);
        const method = (init.method || input.method || 'GET').toUpperCase();
        const candidate = init.validationScope || (interaction && Date.now() - interaction.time < 3000 ? interaction.scope : null);
        const scope = candidate?.isConnected && url.origin === location.origin && !['GET', 'HEAD'].includes(method) ? candidate : null;
        let requestNumber = 0;
        if (scope) {
            if (!requests.has(scope)) requests.set(scope, new Map());
            requestNumber = (requests.get(scope).get(url.href) || 0) + 1;
            requests.get(scope).set(url.href, requestNumber);
        }
        let response;
        try { response = await fetchOriginal(input, init); }
        catch (error) {
            if (scope && error.name !== 'AbortError') throw new TypeError('تعذر الاتصال بالخادم. احتفظ بالمدخلات وتحقق من نتيجة العملية قبل إعادة إرسالها.', { cause: error });
            throw error;
        }
        if (scope && requests.get(scope).get(url.href) !== requestNumber) return response;
        if (scope && response.status === 422 && response.headers.get('content-type')?.includes('json')) {
            const data = await response.clone().json().catch(() => null);
            if (data) { scope.dataset.validationUrl = url.href; show(scope, data.errors && Object.keys(data.errors).length ? data.errors : { operation: [data.message] }); }
        } else if (scope && response.ok && scope.dataset.validationUrl === url.href) clear(scope);
        return response;
    };
    window.FormValidation = { show, clear, focus, normalize };
    function initialize() {
        document.querySelectorAll('[data-validation-errors]').forEach(node => {
            const errors = JSON.parse(node.textContent || '{}');
            if (!Object.keys(errors).length) return;
            const scopes = [...document.querySelectorAll('form')];
            let preferred = null;
            try {
                const previous = JSON.parse(sessionStorage.getItem('appstore.validation.form') || 'null');
                if (previous?.page === location.pathname && Date.now() - previous.time < 300000) {
                    preferred = previous.id ? document.getElementById(previous.id) : scopes[previous.index];
                    if (preferred?.action !== previous.action) preferred = null;
                }
                sessionStorage.removeItem('appstore.validation.form');
            } catch (_) { /* Do not store user-entered values as a fallback. */ }
            const matched = new Map();
            Object.entries(errors).forEach(([key, messages]) => {
                const candidates = scopes.filter(scope => fields(scope, key).length);
                const scope = preferred || candidates[0] || scopes.find(form => form.querySelector('input[name="_token"]')) || scopes[0];
                if (scope) { if (!matched.has(scope)) matched.set(scope, {}); matched.get(scope)[key] = messages; }
            });
            let first = true;
            matched.forEach((messages, scope) => { show(scope, messages, { focus: first }); first = false; });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize); else initialize();
})();
