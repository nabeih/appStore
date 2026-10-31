(() => {
    'use strict';
    const configElement = document.getElementById('opening-stock-config');
    if (!configElement) return;
    const config = JSON.parse(configElement.textContent);
    const el = id => document.getElementById(id);
    const dialog = el('quickOpeningStockModal');
    const body = el('quickOpeningStockTableBody');
    const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
    const newUuid = () => {
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    };
    let catalog = null, rows = [], preview = null, busy = false, dirty = false;
    let clientUuid = newUuid(), searchTimer, searchController;
    const products = new Map();
    const colorFiles = new Map();
    const resolvedImages = new Map();
    let previousImageKeys = new Map(), currentImageKeys = new Map();
    let imageTimer, imageRequest;
    const identityStamp = row => JSON.stringify([row.name.trim().toLowerCase(), row.code.trim().toUpperCase(), String(row.category_id), String(row.color_id)]);
    const completeImageRows = () => rows.filter(row => row.name.trim() && row.category_id && row.color_id);
    const forgetImage = key => {
        const selected = colorFiles.get(key);
        if (selected) URL.revokeObjectURL(selected.preview);
        colorFiles.delete(key);
    };
    const clearImages = () => { [...colorFiles.keys()].forEach(forgetImage); resolvedImages.clear(); };
    function renderColorImages() {
        const validRows = completeImageRows();
        const codesByName = new Map();
        validRows.forEach(row => {
            const name = JSON.stringify([String(row.category_id), row.name.trim().toLowerCase()]);
            if (!codesByName.has(name)) codesByName.set(name, new Set());
            if (row.code.trim()) codesByName.get(name).add(row.code.trim().toUpperCase());
        });
        const groups = new Map();
        const nextKeys = new Map();
        validRows.forEach(row => {
            const name = JSON.stringify([String(row.category_id), row.name.trim().toLowerCase()]);
            const aliases = [...codesByName.get(name)];
            const code = row.code.trim().toUpperCase() || (aliases.length === 1 ? aliases[0] : '');
            const known = products.get(code);
            const current = resolvedImages.get(identityStamp(row));
            const productId = current?.product_id || known?.id;
            const identity = productId ? `id:${productId}` : code ? `code:${code}` : `name:${name}`;
            const key = `${identity}:color:${row.color_id}`;
            const url = current?.image_url || known?.images?.find(image => String(image.color_id) === String(row.color_id))?.url || null;
            nextKeys.set(row.id, key);
            if (!groups.has(key)) groups.set(key, { key, productId, colorId: row.color_id, swatchManageUrl: current?.swatch_manage_url || known?.swatch_manage_url, swatchUrl: current?.swatch_url || known?.swatches?.find(sample => String(sample.color_id) === String(row.color_id))?.url, hex: catalog.colors.find(color => String(color.id) === String(row.color_id))?.hex, name: row.name, color: catalog.colors.find(color => String(color.id) === String(row.color_id))?.name || '', url, sizes: new Set(), sources: new Set() });
            const group = groups.get(key);
            group.url ||= url;
            group.sizes.add(String(row.size_id));
            group.sources.add(previousImageKeys.get(row.id));
        });
        groups.forEach(group => {
            if (!colorFiles.has(group.key)) {
                const source = [...group.sources].find(key => key && !groups.has(key) && colorFiles.has(key));
                if (source) { colorFiles.set(group.key, colorFiles.get(source)); colorFiles.delete(source); }
            }
            if (group.url) forgetImage(group.key);
        });
        [...colorFiles.keys()].filter(key => !groups.has(key)).forEach(forgetImage);
        currentImageKeys = nextKeys;
        previousImageKeys = new Map(nextKeys);
        el('osColorImagesSection').hidden = groups.size === 0;
        ColorImageFields.render(el('osColorImages'), [...groups.values()].map(group => ({ ...group, ...colorFiles.get(group.key), note: `مشتركة بين ${group.sizes.size} مقاس — اختيارية` })), {
            onFile(group, file) {
                try {
                    ColorImageFields.validate(file, catalog.limits.max_image_bytes);
                    forgetImage(group.key);
                    colorFiles.set(group.key, { file, preview: URL.createObjectURL(file) });
                    invalidate(); renderColorImages(); errors([]);
                } catch (error) { report(error); }
            },
            onClear(group) { forgetImage(group.key); invalidate(); renderColorImages(); },
        });
    }
    function refreshColorImages() {
        if (!catalog) return;
        renderColorImages();
        clearTimeout(imageTimer);
        imageRequest?.abort();
        const pending = [...new Map(completeImageRows().map(row => [identityStamp(row), { ...row }])).values()]
            .filter(row => !resolvedImages.has(identityStamp(row)));
        if (!pending.length) return;
        imageTimer = setTimeout(async () => {
            const controller = new AbortController(); imageRequest = controller;
            try {
                const result = await json(config.imageResolveUrl, { method: 'POST', signal: controller.signal, headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ rows: pending.map(row => ({ name: row.name.trim(), code: row.code.trim().toUpperCase(), category_id: row.category_id, color_id: row.color_id })) }) });
                if (imageRequest !== controller) return;
                pending.forEach((row, index) => resolvedImages.set(identityStamp(row), result.data[index]));
                renderColorImages();
            } catch (error) { if (error.name !== 'AbortError') report(error); }
        }, 300);
    }
    const blank = data => ({ id: newUuid(), name: '', code: '', category_id: '', color_id: '', size_id: '', qty: '', cost: '', price: '', ...data });
    const hasData = row => ['name', 'code', 'qty', 'cost', 'price'].some(key => String(row[key]).trim() !== '');
    const options = (items, selected = '', multiple = false) => `${multiple ? '' : '<option value="">اختر...</option>'}${items.map(item => `<option value="${item.id}" ${String(selected) === String(item.id) ? 'selected' : ''}>${escape(item.name)}</option>`).join('')}`;

    function errors(messages, fields = {}) {
        const box = el('openingStockErrors');
        box.textContent = messages.join('\n');
        box.hidden = messages.length === 0;
        body.querySelectorAll('tr').forEach(row => row.classList.remove('os-row-error'));
        const submitted = rows.filter(hasData);
        Object.keys(fields).forEach(key => {
            const match = key.match(/^rows\.(\d+)/);
            if (match && submitted[Number(match[1])]) body.querySelector(`[data-row="${submitted[Number(match[1])].id}"]`)?.classList.add('os-row-error');
        });
        if (messages.length) box.focus();
    }
    function invalidate() {
        preview = null;
        dirty = true;
        el('osPreview').hidden = true;
        el('osSave').disabled = true;
        el('osStatus').textContent = 'راجع الرصيد بعد الانتهاء من التعديلات.';
    }
    function setBusy(value, message = '') {
        busy = value;
        el('osFields').disabled = value;
        el('osReview').disabled = value;
        el('osSave').disabled = value || !preview;
        el('osClose').disabled = value;
        el('osSaveDraft').disabled = value;
        el('osCancel').disabled = value;
        el('osStatus').textContent = message;
    }
    async function json(url, init = {}) {
        const response = await fetch(url, { ...init, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf, ...init.headers } });
        const result = await response.json().catch(() => ({}));
        if (!response.ok) {
            if (response.status === 413) throw new Error('حجم الطلب يتجاوز حد الخادم. قلل حجم الصور أو عددها ثم أعد المراجعة.');
            const message = response.status === 419 ? 'انتهت صلاحية الصفحة. افتح سجل المستندات في تبويب آخر للتحقق من الحفظ قبل تحديث هذه الصفحة.' : (result.message || 'تعذر إكمال الطلب. حاول مرة أخرى.');
            throw Object.assign(new Error(message), { fields: result.errors || {} });
        }
        return result;
    }
    function report(error) {
        const fields = error.fields || {};
        errors(Object.keys(fields).length ? Object.values(fields).flat() : [error instanceof TypeError ? 'تعذر الاتصال بالخادم. احتفظ بالصفحة وأعد المحاولة؛ إعادة نفس الحفظ لا تضاعف المخزون.' : error.message], fields);
    }
    async function loadCatalog() {
        catalog = await json(config.optionsUrl);
        ['matrixCategory', 'matrixColors', 'matrixSizes'].forEach((id, index) => {
            const select = el(id);
            const selected = Array.from(select.selectedOptions).map(option => option.value);
            select.innerHTML = options(catalog[['categories', 'colors', 'sizes'][index]], '', index > 0);
            Array.from(select.options).forEach(option => { option.selected = selected.includes(option.value); });
        });
    }
    function render() {
        body.innerHTML = rows.map((row, index) => `<tr data-row="${row.id}">
            <td>${index + 1}</td>
            <td data-label="الكود"><input aria-label="كود المنتج السطر ${index + 1}" data-id="${row.id}" data-field="code" dir="ltr" maxlength="50" value="${escape(row.code)}" placeholder="تلقائي"></td>
            <td data-label="اسم المنتج"><input aria-label="اسم المنتج السطر ${index + 1}" data-id="${row.id}" data-field="name" maxlength="200" value="${escape(row.name)}"></td>
            ${[['category_id', 'categories', 'التصنيف'], ['color_id', 'colors', 'اللون'], ['size_id', 'sizes', 'المقاس']].map(([field, list, label]) => `<td data-label="${label}"><select aria-label="${label} السطر ${index + 1}" data-id="${row.id}" data-field="${field}">${options(catalog[list], row[field])}</select></td>`).join('')}
            ${[['qty', 'الكمية'], ['cost', 'تكلفة الوحدة'], ['price', 'سعر البيع']].map(([field, label]) => `<td data-label="${label}"><input type="number" aria-label="${label} السطر ${index + 1}" min="${field === 'qty' ? 1 : 0}" max="${field === 'qty' ? 1000000 : 9999999.99}" step="${field === 'qty' ? 1 : '0.01'}" data-id="${row.id}" data-field="${field}" value="${escape(row[field])}"></td>`).join('')}
            <td><button type="button" class="btn btn-outline btn-sm" data-remove="${row.id}" aria-label="حذف السطر ${index + 1}">✕</button></td>
        </tr>`).join('');
        el('quickStockRowCount').textContent = `${rows.filter(hasData).length} سطر من أصل ${rows.length} — الحد الأقصى 300`;
        refreshColorImages();
    }
    function fillExistingPrice(row) {
        const product = products.get(row.code.trim().toUpperCase());
        const variant = product?.variants.find(item => String(item.color_id) === String(row.color_id) && String(item.size_id) === String(row.size_id));
        if (variant) row.price = variant.selling_price;
    }
    window.openQuickOpeningStockModal = async () => {
        if (!dialog.open) dialog.showModal();
        setBusy(true, 'جاري تحميل القوائم...');
        try {
            await loadCatalog();
            if (!rows.length) rows = [blank()];
            render();
            const missing = ['categories', 'colors', 'sizes'].some(key => !catalog[key].length);
            errors(!el('openingStockBranch').value ? ['أضف فرعًا فعالًا قبل إدخال البضاعة.'] : missing ? ['بعض القوائم فارغة. يمكنك إضافة التصنيفات والألوان والمقاسات من الإضافة السريعة أدناه.'] : []);
        } catch (error) { report(error); }
        finally { setBusy(false); }
    };
    const close = () => { if (!busy) dialog.close(); };
    el('osClose').addEventListener('click', close);
    el('osCancel').addEventListener('click', close);
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    body.addEventListener('input', event => {
        const row = rows.find(item => item.id === event.target.dataset.id);
        const field = event.target.dataset.field;
        if (!row || !field) return;
        row[field] = field === 'code' ? event.target.value.trim().toUpperCase() : event.target.value;
        invalidate();
        if (['name', 'code', 'category_id', 'color_id'].includes(field)) refreshColorImages();
        if (['code', 'color_id', 'size_id'].includes(field)) {
            fillExistingPrice(row);
            body.querySelector(`[data-id="${row.id}"][data-field="price"]`).value = row.price;
        }
        el('quickStockRowCount').textContent = `${rows.filter(hasData).length} سطر — الحد الأقصى 300`;
    });
    body.addEventListener('click', event => {
        const remove = event.target.closest('[data-remove]');
        if (!remove) return;
        rows = rows.filter(row => row.id !== remove.dataset.remove);
        if (!rows.length) rows = [blank()];
        invalidate(); render();
    });
    ['openingStockBranch', 'openingStockDate', 'openingStockNotes'].forEach(id => el(id).addEventListener('input', invalidate));
    el('osAddRow').addEventListener('click', () => {
        if (rows.length >= 300) return errors(['الحد الأقصى 300 سطر للمستند الواحد.']);
        rows.push(blank()); invalidate(); render();
    });
    el('osGenerate').addEventListener('click', () => {
        const name = el('matrixProductName').value.trim(), code = el('matrixProductCode').value.trim().toUpperCase();
        const category = el('matrixCategory').value;
        const colors = Array.from(el('matrixColors').selectedOptions).map(option => option.value);
        const sizes = Array.from(el('matrixSizes').selectedOptions).map(option => option.value);
        if (!name || !category || !colors.length || !sizes.length) return errors(['أدخل اسم المنتج وتصنيفه واختر لونًا ومقاسًا على الأقل.']);
        const generated = [];
        colors.forEach(color => sizes.forEach(size => {
            if (rows.some(row => row.name.trim() === name && row.code === code && String(row.category_id) === category && String(row.color_id) === color && String(row.size_id) === size)) return;
            const row = blank({ name, code, category_id: category, color_id: color, size_id: size, qty: el('matrixQty').value, cost: el('matrixCost').value, price: el('matrixPrice').value });
            fillExistingPrice(row); generated.push(row);
        }));
        if (!generated.length) return errors(['التركيبات المختارة موجودة في الجدول بالفعل.']);
        const filled = rows.filter(hasData);
        if (filled.length + generated.length > 300) return errors(['عدد التركيبات يتجاوز 300 سطر. قلل الألوان أو المقاسات.']);
        rows = [...filled, ...generated]; errors([]); invalidate(); render();
    });
    el('osProductSearch').addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchController?.abort();
        const query = el('osProductSearch').value.trim();
        el('osProductResults').replaceChildren();
        if (query.length < 2) return;
        searchTimer = setTimeout(async () => {
            searchController = new AbortController();
            try {
                const result = await json(`${config.productsUrl}?q=${encodeURIComponent(query)}`, { signal: searchController.signal });
                el('osProductResults').replaceChildren();
                if (!result.data.length) el('osProductResults').textContent = 'لا توجد نتائج. يمكنك إدخال منتج جديد أدناه.';
                result.data.forEach(product => {
                    products.set(product.code.toUpperCase(), product);
                    const button = document.createElement('button'); button.type = 'button';
                    button.textContent = `${product.code} — ${product.name}`;
                    button.addEventListener('click', () => {
                        el('matrixProductName').value = product.name;
                        el('matrixProductCode').value = product.code;
                        el('matrixCategory').value = product.category_id;
                        ['matrixColors', 'matrixSizes'].forEach((id, index) => {
                            const ids = product.variants.map(item => String(item[index === 0 ? 'color_id' : 'size_id']));
                            Array.from(el(id).options).forEach(option => { option.selected = ids.includes(option.value); });
                        });
                        el('osProductResults').replaceChildren();
                        el('osProductSearch').value = `${product.code} — ${product.name}`;
                    });
                    el('osProductResults').appendChild(button);
                });
            } catch (error) { if (error.name !== 'AbortError') report(error); }
        }, 250);
    });
    el('osOptionType').addEventListener('change', () => { el('osOptionCodeLabel').hidden = el('osOptionType').value === 'category'; });
    el('osAddOption').addEventListener('click', async () => {
        const payload = { type: el('osOptionType').value, name: el('osOptionName').value.trim(), code: el('osOptionType').value === 'category' ? null : el('osOptionCode').value.trim() };
        setBusy(true, 'جاري إضافة الخيار...');
        try {
            await json(config.optionsStoreUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
            await loadCatalog(); render(); invalidate(); errors([]);
            el('osOptionName').value = ''; el('osOptionCode').value = '';
        } catch (error) { report(error); }
        finally { setBusy(false); }
    });

    function parseCsv(text) {
        text = text.replace(/^\uFEFF/, '');
        const first = text.split(/\r?\n/, 1)[0];
        const delimiter = (first.match(/;/g) || []).length > (first.match(/,/g) || []).length ? ';' : ',';
        const records = []; let record = [], field = '', quoted = false;
        for (let i = 0; i < text.length; i++) {
            const char = text[i];
            if (char === '"') {
                if (quoted && text[i + 1] === '"') { field += '"'; i++; }
                else quoted = !quoted;
            } else if (char === delimiter && !quoted) { record.push(field.trim()); field = ''; }
            else if ((char === '\n' || char === '\r') && !quoted) {
                if (char === '\r' && text[i + 1] === '\n') i++;
                record.push(field.trim()); if (record.some(Boolean)) records.push(record);
                field = ''; record = [];
            } else field += char;
        }
        if (quoted) throw new Error('صيغة CSV غير صحيحة: علامات اقتباس غير مغلقة.');
        record.push(field.trim()); if (record.some(Boolean)) records.push(record);
        return records;
    }
    el('osDownload').addEventListener('click', () => {
        const text = '\uFEFFكود المنتج,اسم المنتج,التصنيف,اللون,المقاس,الكمية,تكلفة الوحدة,سعر البيع\r\nSHIRT-01,قميص قطني,ملابس رجالية,أسود,L,10,20.50,35.00\r\n';
        const url = URL.createObjectURL(new Blob([text], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a'); link.href = url; link.download = 'opening-stock.csv'; link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
    el('openingStockCsvInput').addEventListener('change', async event => {
        const file = event.target.files[0]; if (!file) return;
        try {
            if (file.size > 1024 * 1024) throw new Error('حجم CSV يجب ألا يزيد عن 1 ميجابايت.');
            const records = parseCsv(await file.text());
            const headers = records.shift() || [];
            const aliases = { code: ['كود المنتج'], name: ['اسم المنتج'], category_id: ['التصنيف'], color_id: ['اللون'], size_id: ['المقاس'], qty: ['الكمية'], cost: ['تكلفة الوحدة', 'سعر التكلفة'], price: ['سعر البيع'] };
            const indexes = Object.fromEntries(Object.entries(aliases).map(([key, names]) => [key, headers.findIndex(header => names.includes(header.trim()))]));
            if (Object.entries(indexes).some(([key, index]) => key !== 'code' && index < 0)) throw new Error('عناوين CSV غير مكتملة. استخدم النموذج المتاح للتحميل.');
            if (!records.length || records.length > 300) throw new Error('الملف يجب أن يحتوي بين سطر واحد و300 سطر.');
            const parsed = records.map((record, index) => {
                const row = blank(Object.fromEntries(Object.entries(indexes).map(([key, column]) => [key, column < 0 ? '' : record[column] || ''])));
                row.code = row.code.toUpperCase();
                for (const [field, list] of [['category_id', 'categories'], ['color_id', 'colors'], ['size_id', 'sizes']]) {
                    const matches = catalog[list].filter(item => item.name === row[field]);
                    const match = matches.length === 1 ? matches[0] : catalog[list].find(item => String(item.id) === row[field]);
                    if (!match) throw new Error(`السطر ${index + 2}: القيمة «${row[field]}» غير موجودة أو غير محددة في القوائم. أضفها أولًا، أو استخدم رقمها عند تشابه الأسماء.`);
                    row[field] = String(match.id);
                }
                return row;
            });
            if (rows.some(hasData) && !confirm('سيستبدل الاستيراد أسطر الجدول الحالية. متابعة؟')) return;
            clearImages(); rows = parsed; invalidate(); render(); errors([]);
        } catch (error) { report(error); }
        finally { event.target.value = ''; }
    });
    function payload() {
        const filled = rows.filter(hasData);
        if (!filled.length) throw new Error('أدخل صنفًا واحدًا على الأقل.');
        const form = new FormData();
        const data = { client_uuid: clientUuid, branch_id: el('openingStockBranch').value, opening_date: el('openingStockDate').value, notes: el('openingStockNotes').value.trim(), rows: [], image_rows: [] };
        let imageBytes = 0;
        const attached = new Set();
        renderColorImages();
        filled.forEach((row, index) => {
            data.rows.push(Object.fromEntries(['name', 'code', 'category_id', 'color_id', 'size_id', 'qty', 'cost', 'price'].map(key => [key, String(row[key]).trim()])));
            const key = currentImageKeys.get(row.id);
            const image = colorFiles.get(key);
            if (image && !attached.has(key)) {
                form.append(`images[${index}]`, image.file); data.image_rows.push(index); imageBytes += image.file.size; attached.add(key);
            }
        });
        const serialized = JSON.stringify(data);
        if (data.image_rows.length > catalog.limits.max_images) throw new Error(`الخادم يسمح برفع ${catalog.limits.max_images} صورة في الطلب الواحد. قسم الإدخال إلى مستندات أصغر.`);
        if (imageBytes + new Blob([serialized]).size + data.image_rows.length * 1024 + 4096 > catalog.limits.max_request_bytes) throw new Error('حجم الطلب يتجاوز حد الخادم. قلل عدد الصور أو حجمها.');
        form.append('payload', serialized);
        return form;
    }
    el('osReview').addEventListener('click', async () => {
        setBusy(true, 'جاري التحقق ومراجعة الرصيد...');
        preview = null; el('osPreview').hidden = true; errors([]);
        try {
            preview = await json(config.previewUrl, { method: 'POST', body: payload() });
            el('osPreviewTotals').innerHTML = `<span>${preview.line_count} صنف</span><span>${preview.total_quantity} قطعة</span><span>قيمة البضاعة: ${escape(preview.total_cost)} ₪</span>`;
            el('osPreviewRows').innerHTML = preview.rows.map(row => `<tr><td class="record-primary" data-label="المنتج">${escape(row.name)}<br><small>${escape(row.code || 'كود تلقائي عند الحفظ')}</small></td><td data-label="اللون / المقاس">${escape(row.color)} / ${escape(row.size)}</td><td data-label="الكمية">${row.quantity}</td><td data-label="تكلفة الوحدة ₪">${escape(row.unit_cost)}</td><td class="record-amount" data-label="قيمة البضاعة ₪">${escape(row.line_total)}</td></tr>`).join('');
            el('osPreview').hidden = false; el('osPreview').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        } catch (error) { report(error); }
        finally { setBusy(false, preview ? 'المراجعة مكتملة. يمكنك الاعتماد الآن.' : 'صحح البيانات ثم أعد المراجعة.'); }
    });
    el('osSave').addEventListener('click', async () => {
        if (!preview || busy) return;
        setBusy(true, 'جاري اعتماد المستند...'); errors([]);
        try {
            const result = await json(config.saveUrl, { method: 'POST', body: payload() });
            dirty = false; window.location.assign(result.url);
        } catch (error) { report(error); setBusy(false, 'يمكن إعادة المحاولة بنفس المستند دون تكرار الرصيد.'); }
    });
    el('osSaveDraft').addEventListener('click', async () => {
        if (busy) return;
        setBusy(true, 'جاري حفظ المسودة دون ترحيل المخزون...'); errors([]);
        try {
            const result = await json(config.draftUrl, { method: 'POST', body: payload() });
            dirty = false; window.location.assign(result.url);
        } catch (error) { report(error); setBusy(false, 'لم تحفظ المسودة؛ صحح البيانات ثم أعد المحاولة.'); }
    });
})();