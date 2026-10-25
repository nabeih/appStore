(() => {
    'use strict';
    const config = document.getElementById('attribute-config');
    if (!config) return;
    const settings = JSON.parse(config.textContent);
    const el = id => document.getElementById(id);
    const drafts = new Map(), editors = new WeakMap();
    let saving = false, navigation, navigationId = 0, searchTimer, feedbackTimer;
    const normalize = name => name.trim().toLowerCase().replace(/[أإآ]/g, 'ا').replace(/ى/g, 'ي').replace(/[\s\-_\u0640\u064B-\u065F]/g, '');
    const suggestions = new Map();
    settings.palette.forEach(item => [item.name, ...item.aliases].forEach(alias => suggestions.set(normalize(alias), item.hex)));
    const validHex = value => /^#[0-9a-f]{6}$/i.test(value) ? value.toUpperCase() : /^[0-9a-f]{6}$/i.test(value) ? `#${value.toUpperCase()}` : null;

    function bindEditor(root) {
        if (editors.has(root)) return editors.get(root);
        const name = root.querySelector('[data-color-name]'), hex = root.querySelector('[data-color-hex]');
        const picker = root.querySelector('[data-color-picker]'), preview = root.querySelector('[data-color-preview]');
        const message = root.querySelector('[data-color-message]');
        const paletteSearch = root.querySelector('[data-palette-search]'), paletteFamily = root.querySelector('[data-palette-family]');
        const presets = [...root.querySelectorAll('[data-color-preset]')];
        const searchablePresets = presets.map(button => ({ button, search: normalize(button.dataset.colorSearch) }));
        function filterPalette() {
            const query = normalize(paletteSearch.value), family = paletteFamily.value;
            let visible = 0;
            searchablePresets.forEach(({ button, search }) => {
                button.hidden = !!((family && button.dataset.colorFamily !== family) || (query && !search.includes(query)));
                if (!button.hidden) visible++;
            });
            root.querySelector('[data-palette-count]').textContent = `${visible} درجة متاحة`;
            root.querySelector('[data-palette-empty]').hidden = visible !== 0;
        }
        paletteSearch.addEventListener('input', filterPalette);
        paletteSearch.addEventListener('keydown', event => { if (event.key === 'Enter') event.preventDefault(); });
        paletteFamily.addEventListener('change', filterPalette);
        let manual = false;
        function paint(value, write = false) {
            const color = validHex(value);
            preview.style.backgroundColor = color || '';
            preview.classList.toggle('catalog-color-unknown', !color);
            preview.title = color || 'اختر درجة اللون';
            picker.value = color || '#808080';
            if (color && write) hex.value = color;
            presets.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.colorPreset === color)));
            return color;
        }
        function suggest(force = false) {
            const color = suggestions.get(normalize(name.value));
            if (color) {
                manual = false; hex.value = color; paint(color);
                message.textContent = 'درجة مقترحة من الاسم. يمكنك تعديلها باستخدام منتقي اللون.';
            } else {
                if (!force) { hex.value = ''; paint(''); }
                message.textContent = 'لهذا الاسم اختر الدرجة من المنتقي أو من الألوان الجاهزة.';
            }
        }
        name.addEventListener('input', () => { if (!manual) suggest(); });
        picker.addEventListener('input', () => { manual = true; hex.value = picker.value.toUpperCase(); paint(hex.value); message.textContent = 'تم اختيار درجة خاصة لهذا اللون.'; });
        hex.addEventListener('input', () => { manual = true; const color = paint(hex.value); message.textContent = color ? 'معاينة الدرجة المختارة.' : 'أدخل رمزًا من ست خانات، مثل #2563EB.'; });
        hex.addEventListener('blur', () => { paint(hex.value, true); });
        root.querySelector('[data-color-suggest]').addEventListener('click', () => suggest(true));
        presets.forEach(button => button.addEventListener('click', () => {
            if (!name.readOnly && (root.dataset.presetNames === 'true' || !name.value.trim())) name.value = button.dataset.colorLabel;
            manual = true; hex.value = button.dataset.colorPreset; paint(hex.value);
            message.textContent = 'يمكنك تعديل الدرجة المختارة من منتقي اللون.';
        }));
        const controller = { reset() {
            manual = Boolean(validHex(hex.value)); paint(hex.value, true);
            paletteSearch.value = ''; paletteFamily.value = ''; filterPalette();
            root.querySelector('.attribute-palette').scrollTop = 0;
            message.textContent = manual ? 'معاينة الدرجة المختارة.' : 'اكتب اسم لون شائع أو اختر درجة بنفسك.';
        } };
        editors.set(root, controller); controller.reset();
        return controller;
    }
    function initializeEditors() { document.querySelectorAll('[data-color-editor]').forEach(bindEditor); }
    function captureDrafts() {
        document.querySelectorAll('[data-attribute-form]').forEach(form => {
            const values = {};
            form.querySelectorAll('[name]').forEach(input => { if (!input.name.startsWith('_')) values[input.name] = input.value; });
            drafts.set(form.dataset.attributeForm, values);
        });
    }
    function restoreDrafts() {
        document.querySelectorAll('[data-attribute-form]').forEach(form => {
            const draft = drafts.get(form.dataset.attributeForm);
            if (draft) Object.entries(draft).forEach(([name, value]) => { const input = form.elements.namedItem(name); if (input) input.value = value; });
        });
    }
    function notify(message, bad = false) {
        clearTimeout(feedbackTimer);
        const box = el('attribute-feedback'); box.textContent = message; box.hidden = false;
        box.classList.toggle('attribute-bad-feedback', bad);
        feedbackTimer = setTimeout(() => { box.hidden = true; }, 6500);
    }
    async function navigate(url, { push = true, searchFocus = false, skipCapture = false } = {}) {
        clearTimeout(searchTimer);
        navigation?.abort(); navigation = new AbortController();
        const id = ++navigationId;
        if (!skipCapture) captureDrafts();
        el('attribute-loading').hidden = false;
        try {
            const response = await fetch(url, { signal: navigation.signal, headers: { Accept: 'text/html' }, cache: 'no-store' });
            if (!response.ok) throw new Error('تعذر تحميل القائمة. حاول مجددًا.');
            const next = new DOMParser().parseFromString(await response.text(), 'text/html').getElementById('catalog-attributes');
            if (!next) throw new Error('تعذر تحديث القائمة. حدّث الصفحة.');
            if (id !== navigationId) return;
            el('catalog-attributes').replaceWith(next); restoreDrafts(); initializeEditors();
            if (push) history.pushState(null, '', url);
            if (searchFocus) { const field = el('attribute-search'); field.focus(); field.setSelectionRange(field.value.length, field.value.length); }
        } finally { if (id === navigationId) el('attribute-loading').hidden = true; }
    }
    document.addEventListener('click', event => {
        const link = event.target.closest('a[data-attribute-nav]');
        if (link && !event.ctrlKey && !event.metaKey && !event.shiftKey && event.button === 0) {
            event.preventDefault(); if (saving) return;
            navigate(link.href).catch(error => { if (error.name !== 'AbortError') notify(error.message, true); });
        }
        const button = event.target.closest('[data-edit-color], [data-delete-color]');
        if (!button || saving || button.disabled) return;
        if (button.hasAttribute('data-delete-color')) {
            const color = JSON.parse(button.dataset.deleteColor), form = el('attribute-delete-color-form');
            form.action = color.url;
            form.querySelector('[data-delete-color-name]').textContent = color.name;
            form.querySelector('[data-form-errors]').hidden = true;
            el('attribute-delete-color-dialog').showModal();
            return;
        }
        const color = JSON.parse(button.dataset.editColor), form = el('attribute-color-form');
        form.action = color.url;
        form.querySelector('[data-color-name]').value = color.name;
        form.querySelector('[data-color-hex]').value = color.hex || '';
        form.querySelector('[data-form-errors]').hidden = true;
        form.querySelectorAll('[aria-invalid]').forEach(input => input.removeAttribute('aria-invalid'));
        bindEditor(form.querySelector('[data-color-editor]')).reset();
        el('attribute-color-dialog').showModal();
    });
    function searchUrl(form) {
        const url = new URL(settings.indexUrl); url.search = new URLSearchParams(new FormData(form)).toString(); return url;
    }
    document.addEventListener('input', event => {
        if (event.target.id !== 'attribute-search' || saving) return;
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => navigate(searchUrl(el('attribute-search-form')), { searchFocus: true }).catch(error => { if (error.name !== 'AbortError') notify(error.message, true); }), 300);
    });
    window.addEventListener('popstate', () => navigate(location.href, { push: false }).catch(error => { if (error.name !== 'AbortError') notify(error.message, true); }));
    document.addEventListener('submit', async event => {
        const form = event.target;
        if (form.id === 'attribute-search-form') {
            event.preventDefault(); if (saving) return;
            navigate(searchUrl(form), { searchFocus: true }).catch(error => { if (error.name !== 'AbortError') notify(error.message, true); });
            return;
        }
        if (!form.matches('[data-attribute-form], #attribute-color-form, #attribute-delete-color-form')) return;
        event.preventDefault(); if (saving) return;
        clearTimeout(searchTimer); navigation?.abort();
        const payload = new FormData(form);
        const box = form.querySelector('[data-form-errors]'); box.hidden = true;
        form.querySelectorAll('[aria-invalid]').forEach(input => input.removeAttribute('aria-invalid'));
        saving = true; document.querySelectorAll('.attribute-form fieldset, .attribute-dialog fieldset').forEach(fieldset => { fieldset.disabled = true; });
        document.querySelectorAll('.attribute-form button[type=submit], .attribute-dialog button').forEach(button => { button.disabled = true; });
        try {
            const response = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': settings.csrf }, body: payload });
            const result = await response.json().catch(() => ({}));
            if (!response.ok) {
                Object.keys(result.errors || {}).forEach(name => form.elements.namedItem(name)?.setAttribute('aria-invalid', 'true'));
                throw new Error(response.status === 419 ? 'انتهت صلاحية الصفحة. حدّث الصفحة ثم أعد المحاولة.' : Object.values(result.errors || {}).flat().join('\n') || result.message || 'تعذر حفظ البيانات.');
            }
            const deleting = form.id === 'attribute-delete-color-form';
            const updating = form.id === 'attribute-color-form' || deleting;
            if (updating) form.closest('dialog').close();
            captureDrafts(); if (!updating) drafts.delete(result.tab);
            const url = updating ? new URL(location.href) : new URL(settings.indexUrl);
            url.searchParams.set('tab', result.tab);
            if (deleting && document.querySelectorAll('[data-attribute-id]').length === 1 && Number(url.searchParams.get('page')) > 1) {
                url.searchParams.set('page', Number(url.searchParams.get('page')) - 1);
            }
            try { await navigate(url, { skipCapture: true }); notify(result.message); }
            catch (error) { if (error.name !== 'AbortError') notify('تم الحفظ، لكن تعذر تحديث القائمة. حدّث الصفحة لعرض النتيجة.', true); }
        } catch (error) {
            box.textContent = error instanceof TypeError ? 'تعذر الاتصال بالخادم. حاول مجددًا.' : error.message;
            box.hidden = false; box.focus();
        } finally {
            saving = false;
            document.querySelectorAll('.attribute-form fieldset, .attribute-dialog fieldset').forEach(fieldset => { fieldset.disabled = false; });
            document.querySelectorAll('.attribute-form button[type=submit], .attribute-dialog button').forEach(button => { button.disabled = false; });
        }
    });
    for (const [id, selector] of [['attribute-color-dialog', '[data-close-color]'], ['attribute-delete-color-dialog', '[data-close-delete-color]']]) {
        const dialog = el(id);
        dialog.querySelectorAll(selector).forEach(button => button.addEventListener('click', () => { if (!saving) dialog.close(); }));
        dialog.addEventListener('cancel', event => { if (saving) event.preventDefault(); });
    }
    window.addEventListener('beforeunload', event => { if (saving) { event.preventDefault(); event.returnValue = ''; } });
    initializeEditors();
})();