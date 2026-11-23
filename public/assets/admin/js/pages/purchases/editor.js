(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('purchase-config').textContent);
    const state = config.state;
    function filterPaymentAccounts() {
        const branch = document.getElementById('purchase-branch').value;
        const select = document.getElementById('payment-account');
        [...select.options].forEach(option => {
            const permitted = !option.value || option.dataset.branch === branch;
            option.hidden = !permitted; option.disabled = !permitted;
        });
        if (select.selectedOptions[0]?.disabled) select.value = '';
    }
    document.getElementById('purchase-branch').addEventListener('change', filterPaymentAccounts);
    filterPaymentAccounts();
    const form = document.getElementById('purchase-form');
    const rowsElement = document.getElementById('purchase-rows');
    const errors = document.getElementById('purchase-errors');
    const supplierSearch = document.getElementById('supplier-search');
    const productSearch = document.getElementById('product-search');
    const supplierResults = document.getElementById('supplier-results');
    const productResults = document.getElementById('product-results');
    const supplierDialog = document.getElementById('supplier-dialog');
    const productDialog = document.getElementById('product-dialog');
    const productForm = document.getElementById('quick-product-form');
    let dirty = false;
    let saving = false;
    let previewTimer;
    let previewRequest;
    let previewVersion = 0;
    let productId = null;
    let optionsRequest;
    let productCatalog = null;
    const variantsDialog = document.getElementById('purchase-variants-dialog');
    const variantsForm = document.getElementById('purchase-variants-form');
    let variantRequest;
    let selectedProduct;
    let quickVariants = [];
    const combinations = new Map();
    let attributeType;
    const quickImages = new Map();
    let quickExistingImages = new Map();
    const rowUploads = new Map();
    const clearQuickImages = () => {
        quickImages.forEach(image => URL.revokeObjectURL(image.preview));
        quickImages.clear();
    };
    const syncSaveButton = () => {
        document.getElementById('save-purchase').disabled = saving || [...rowUploads.values()].some(upload => upload.busy);
    };
    function renderQuickImages() {
        const colors = [...document.getElementById('new-product-colors').selectedOptions];
        document.getElementById('new-product-images-section').hidden = colors.length === 0;
        ColorImageFields.render(document.getElementById('new-product-images'), colors.map(option => ({
            key: option.value, name: document.getElementById('new-product-name').value || 'منتج جديد', color: option.textContent,
            url: quickExistingImages.get(option.value), ...quickImages.get(option.value),
        })), {
            onFile(group, file) {
                try {
                    ColorImageFields.validate(file, productCatalog.limits.max_image_bytes);
                    const previous = quickImages.get(group.key);
                    if (previous) URL.revokeObjectURL(previous.preview);
                    quickImages.set(group.key, { file, preview: URL.createObjectURL(file) });
                    renderQuickImages();
                } catch (error) {
                    const message = productDialog.querySelector('[data-dialog-errors]'); message.textContent = error.message; message.hidden = false;
                }
            },
            onClear(group) {
                URL.revokeObjectURL(quickImages.get(group.key).preview); quickImages.delete(group.key); renderQuickImages();
            },
        });
    }
    function renderRowImages() {
        const groups = new Map();
        state.rows.forEach(row => {
            if (!row.product_id || !row.color_id) return;
            const key = `${row.product_id}:${row.color_id}`;
            if (!groups.has(key)) groups.set(key, { key, name: row.name, color: row.color, url: row.image_url, swatchManageUrl: row.swatch_manage_url, swatchUrl: row.swatch_url, hex: row.hex, uploadUrl: row.image_upload_url, colorId: row.color_id, productId: row.product_id });
            if (row.image_url) groups.get(key).url = row.image_url;
        });
        rowUploads.forEach((upload, key) => {
            if (!groups.has(key) && !upload.busy) { URL.revokeObjectURL(upload.preview); rowUploads.delete(key); }
        });
        document.getElementById('purchase-color-images-section').hidden = groups.size === 0;
        ColorImageFields.render(document.getElementById('purchase-color-images'), [...groups.values()].map(group => ({ ...group, ...rowUploads.get(group.key) })), {
            async onFile(group, file) {
                if (rowUploads.get(group.key)?.busy) return;
                try { ColorImageFields.validate(file); } catch (error) { showError(error.message); return; }
                const previous = rowUploads.get(group.key);
                if (previous?.preview) URL.revokeObjectURL(previous.preview);
                const upload = { file, preview: URL.createObjectURL(file), busy: true };
                rowUploads.set(group.key, upload); renderRowImages(); syncSaveButton();
                const data = new FormData(); data.append('images[0][color_id]', group.colorId); data.append('images[0][image]', file);
                try {
                    const result = await request(group.uploadUrl, { method: 'POST', body: data });
                    const image = result.data.find(image => String(image.color_id) === String(group.colorId));
                    state.rows.forEach(row => { if (String(row.product_id) === String(group.productId) && String(row.color_id) === String(group.colorId)) row.image_url = image?.url || null; });
                    rowUploads.delete(group.key); URL.revokeObjectURL(upload.preview);
                } catch (error) { upload.error = error.message; upload.busy = false; }
                finally { renderRowImages(); syncSaveButton(); }
            },
            onClear(group) {
                const upload = rowUploads.get(group.key);
                if (upload?.preview) URL.revokeObjectURL(upload.preview);
                rowUploads.delete(group.key); renderRowImages();
            },
        });
    }
    let searchTimers = {};
    const searches = {};
    const element = (tag, className, text) => {
        const item = document.createElement(tag);
        if (className) item.className = className;
        if (text !== undefined) item.textContent = text;
        return item;
    };
    const showError = message => {
        errors.textContent = message;
        errors.hidden = false;
        errors.focus();
    };
    const request = async (url, options = {}) => {
        const response = await fetch(url, { ...options, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': config.csrf, ...options.headers } });
        if (response.status === 413) throw new Error('حجم الصور يتجاوز حد الخادم. قلل عدد الصور أو حجمها.');
        if (response.status === 401 || response.status === 419) throw new Error('انتهت الجلسة. افتح تسجيل الدخول في نافذة أخرى ثم أعد المحاولة.');
        const data = await response.json();
        if (!response.ok && data.guidance) window.dispatchEvent(new CustomEvent('appstore:guidance', { detail: data.guidance }));
        if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join('\n') || data.message || 'تعذر تنفيذ الطلب.');
        return data;
    };
    const payload = () => ({ client_uuid: state.client_uuid, version: state.version, supplier_id: state.supplier_id,
        branch_id: document.getElementById('purchase-branch').value, invoice_date: document.getElementById('purchase-date').value,
        supplier_invoice_number: document.getElementById('supplier-invoice-number').value, notes: document.getElementById('purchase-notes').value,
        payment_details: document.getElementById('payment-account').value ? {account_id:Number(document.getElementById('payment-account').value),confirmed:document.getElementById('payment-confirmed').checked} : null,
        initial_payment: document.getElementById('initial-payment').value, payment_method: document.getElementById('payment-method').value,
        rows: state.rows.map(row => ({ product_variant_id: row.product_variant_id, quantity: row.quantity, unit_cost: row.unit_cost })) });
    const jsonOptions = (body, method = 'POST') => ({ method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    const invalidatePreview = () => {
        previewVersion++;
        clearTimeout(previewTimer);
        previewRequest?.abort();
        document.getElementById('preview-total').textContent = '—';
        document.getElementById('preview-remaining').textContent = '—';
        document.getElementById('preview-quantity').textContent = '—';
        document.getElementById('preview-paid').textContent = '—';
        document.getElementById('preview-products').textContent = new Set(state.rows.map(row => String(row.product_id))).size;
        rowsElement.querySelectorAll('[data-line-total]').forEach(cell => cell.textContent = '—');
        document.getElementById('preview-status').textContent = state.rows.length ? 'جارٍ مراجعة الحساب...' : 'أضف الأصناف لحساب الإجمالي.';
        if (state.rows.length && state.supplier_id) previewTimer = setTimeout(preview, 400);
        else if (!state.supplier_id) document.getElementById('preview-status').textContent = 'اختر المورد لحساب الفاتورة.';
    };
    async function preview() {
        const version = previewVersion;
        previewRequest = new AbortController();
        try {
            const data = await request(config.previewUrl, { validationScope: form, ...jsonOptions(payload()), signal: previewRequest.signal });
            if (version !== previewVersion) return;
            document.getElementById('preview-total').textContent = data.total;
            document.getElementById('preview-remaining').textContent = data.remaining;
            document.getElementById('preview-quantity').textContent = data.quantity;
            document.getElementById('preview-paid').textContent = data.paid;
            rowsElement.querySelectorAll('[data-line-total]').forEach(cell => cell.textContent = data.lines[Number(cell.dataset.lineIndex)]);
            document.getElementById('preview-status').textContent = 'تمت مراجعة الحساب.';
        } catch (error) {
            if (error.name !== 'AbortError' && version === previewVersion) document.getElementById('preview-status').textContent = error.message;
        }
    }
    function renderRows() {
        rowsElement.replaceChildren();
        if (!state.rows.length) {
            const row = element('tr');
            const cell = element('td', 'trade-empty', 'ابحث عن صنف لإضافته، أو أنشئ منتجًا جديدًا.');
            cell.colSpan = 6;
            row.append(cell);
            rowsElement.append(row);
        }
        const groups = new Map();
        state.rows.forEach((row, index) => {
            const key = String(row.product_id);
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push({ row, index });
        });
        groups.forEach(entries => {
            const group = element('tr', 'purchase-product-group');
            const heading = element('td'); heading.colSpan = 6;
            const title = element('strong', '', entries[0].row.name);
            const choose = element('button', 'btn btn-outline btn-sm', 'الألوان والمقاسات / إضافة تركيبات'); choose.type = 'button';
            choose.addEventListener('click', () => openVariants(entries[0].row));
            heading.append(title, element('small', '', `${entries.length} تركيبة في الفاتورة`), choose); group.append(heading); rowsElement.append(group);
            entries.forEach(({ row, index }) => {
            const tr = element('tr');
            tr.dataset.variantId = row.product_variant_id;
            const name = element('td', '', row.name);
            name.append(element('small', '', row.sku));
            const choice = element('td', '', `${row.color} / ${row.size}`);
            const quantityCell = element('td');
            quantityCell.dataset.label = 'الكمية';
            const quantity = element('input');
            quantity.type = 'number'; quantity.min = '1'; quantity.max = '1000000'; quantity.step = '1'; quantity.required = true;
            quantity.value = row.quantity; quantity.dataset.validationField = `rows.${index}.quantity`;
            quantity.setAttribute('aria-label', `كمية ${row.name} ${row.color} ${row.size}`);
            quantity.addEventListener('input', () => { row.quantity = quantity.value; dirty = true; invalidatePreview(); });
            quantityCell.append(quantity);
            const costCell = element('td');
            costCell.dataset.label = 'تكلفة الوحدة ₪';
            const cost = element('input');
            cost.type = 'text'; cost.inputMode = 'decimal'; cost.required = true; cost.value = row.unit_cost;
            cost.dataset.validationField = `rows.${index}.unit_cost`;
            cost.setAttribute('aria-label', `تكلفة ${row.name} ${row.color} ${row.size}`);
            cost.addEventListener('input', () => { row.unit_cost = cost.value; dirty = true; invalidatePreview(); });
            costCell.append(cost);
            const total = element('td', '', '—'); total.dataset.lineTotal = ''; total.dataset.lineIndex = index; total.dataset.label = 'المجموع ₪';
            const action = element('td');
            const remove = element('button', 'line-remove', '×'); remove.type = 'button';
            remove.setAttribute('aria-label', `حذف ${row.name} من المسودة`);
            remove.addEventListener('click', () => { state.rows.splice(index, 1); dirty = true; renderRows(); invalidatePreview(); });
            action.append(remove); tr.append(name, choice, quantityCell, costCell, total, action); rowsElement.append(tr);
            });
        });
        renderRowImages();
    }
    const addRows = rows => {
        const known = new Set(state.rows.map(row => String(row.product_variant_id)));
        const additional = rows.filter(row => {
            const key = String(row.product_variant_id);
            if (known.has(key)) return false;
            known.add(key); return true;
        });
        if (state.rows.length + additional.length > 300) throw new Error('الحد الأقصى 300 صنف في الفاتورة.');
        additional.forEach(row => state.rows.push({ ...row, quantity: row.quantity ?? 1, unit_cost: row.unit_cost || '0.00' }));
        const notice = document.getElementById('purchase-line-notice'); notice.hidden = false;
        notice.textContent = `أضيفت ${additional.length} تركيبة. ${rows.length > additional.length ? 'التركيبات الموجودة في الفاتورة لم تتكرر؛ عدّل كميتها في الجدول.' : 'راجع كمية وتكلفة كل سطر قبل الحفظ.'}`;
        dirty = true;
        renderRows();
        invalidatePreview();
    };
    const selectSupplier = supplier => {
        state.supplier_id = supplier.id;
        supplierSearch.value = supplier.name;
        document.getElementById('supplier-selection').textContent = `المورد المختار: ${supplier.name}`;
        supplierResults.hidden = true;
        dirty = true;
        invalidatePreview();
    };
    const runSearch = (kind, input, results, url, render) => {
        clearTimeout(searchTimers[kind]);
        searches[kind]?.abort();
        results.hidden = true;
        const query = input.value.trim();
        if (!query) return;
        searchTimers[kind] = setTimeout(async () => {
            const controller = new AbortController(); searches[kind] = controller;
            const params = new URLSearchParams({ q: query });
            if (kind === 'products') params.set('branch_id', document.getElementById('purchase-branch').value);
            try {
                const data = await request(`${url}?${params}`, { signal: controller.signal });
                if (input.value.trim() !== query) return;
                results.replaceChildren();
                if (!data.data.length && !data.catalog_products?.length) results.append(element('p', 'trade-muted', 'لا توجد نتائج مسموحة. جرّب الكود أو SKU وتأكد من تفعيل المنتج ولونه ومقاسه.'));
                                    data.data.forEach(item => render(item, results));
                    if (kind === 'products' && config.canManageCatalog) (data.catalog_products || []).forEach(product => {
                        const button = element('button', '', `${product.name} — اختيار الألوان والمقاسات`); button.type = 'button';
                        button.addEventListener('click', () => openProduct(product)); results.append(button);
                    });
                results.hidden = false;
            } catch (error) {
                if (error.name !== 'AbortError') { results.replaceChildren(element('p', 'trade-muted', error.message)); results.hidden = false; }
            }
        }, 250);
    };
    supplierSearch.addEventListener('input', () => {
        state.supplier_id = null; dirty = true;
        document.getElementById('supplier-selection').textContent = 'اختر المورد من نتائج البحث.';
        invalidatePreview();
        runSearch('suppliers', supplierSearch, supplierResults, config.suppliersUrl, (supplier, container) => {
            const button = element('button', '', supplier.name); button.type = 'button';
            button.append(element('small', '', supplier.phone1 || ''));
            button.addEventListener('click', () => selectSupplier(supplier)); container.append(button);
        });
    });
    productSearch.addEventListener('input', () => {
        const shown = new Set();
        runSearch('products', productSearch, productResults, config.productsUrl, (row, container) => {
        if (shown.has(row.product_id)) return;
        shown.add(row.product_id);
        const wrapper = element('div', 'trade-result-row');
        const select = element('button', '', row.name); select.type = 'button';
        select.append(element('small', '', `${row.code} — عرض الألوان والمقاسات واختيار الكميات`));
        select.addEventListener('click', () => openVariants(row)); wrapper.append(select); container.append(wrapper);
        });
    });
    for (const [input, results] of [[productSearch, productResults], [supplierSearch, supplierResults]]) {
        input.addEventListener('keydown', event => {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            const choices = results.querySelectorAll('.trade-result-row > button:first-child, :scope > button');
            if (!results.hidden && choices.length === 1) choices[0].click();
        });
    }
    document.addEventListener('click', event => {
        if (!supplierSearch.parentElement.contains(event.target)) supplierResults.hidden = true;
        if (!productSearch.parentElement.contains(event.target)) productResults.hidden = true;
    });
    document.querySelectorAll('[data-close-dialog]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
    document.getElementById('add-supplier').addEventListener('click', () => supplierDialog.showModal());
    document.getElementById('quick-supplier-form').addEventListener('submit', async event => {
        event.preventDefault(); const quickForm = event.currentTarget; const button = quickForm.querySelector('[type="submit"]');
        if (button.disabled) return;
        button.disabled = true;
        const message = supplierDialog.querySelector('[data-dialog-errors]'); message.hidden = true;
        try {
            const data = await request(quickForm.action, { method: 'POST', body: new FormData(quickForm) });
            selectSupplier(data.data); quickForm.reset(); supplierDialog.close();
        } catch (error) { message.textContent = error.message; message.hidden = false; }
        finally { button.disabled = false; }
    });
    async function fetchVariants(existing, signal) {
        let url = config.variantsUrl.replace('__PRODUCT__', encodeURIComponent(existing.product_id)) + '?branch_id=' + encodeURIComponent(document.getElementById('purchase-branch').value);
        const result = { product: null, data: [] };
        while (url) {
            const page = await request(url, { signal });
            result.product = page.product; result.data.push(...page.data); url = page.next_page_url;
        }
        return result;
    }
    async function openVariants(existing) {
        variantRequest?.abort(); variantRequest = new AbortController();
        const current = variantRequest;
        const body = document.getElementById('purchase-variants-rows'); body.replaceChildren();
        const message = document.getElementById('purchase-variants-error'); message.hidden = true;
        document.getElementById('purchase-variants-title').textContent = existing.name;
        document.getElementById('purchase-variants-code').textContent = 'جارٍ تحميل جميع التركيبات المسموحة...';
        document.getElementById('purchase-add-variants').disabled = true;
        const extend = document.getElementById('purchase-extend-product'); if (extend) extend.disabled = true;
        productResults.hidden = true; variantsDialog.showModal();
        try {
            const data = await fetchVariants(existing, current.signal);
            if (current.signal.aborted) return;
            selectedProduct = { ...data.product, ...data.data[0], product_id: data.product.product_id, name: data.product.name };
            document.getElementById('purchase-variants-code').textContent = `${data.product.code} — ${data.data.length} تركيبة متاحة ضمن صلاحياتك`;
            data.data.forEach(variant => {
                const row = element('tr'); row.dataset.variantId = variant.product_variant_id;
                const choice = element('td'); const checkbox = element('input'); checkbox.type = 'checkbox';
                const included = state.rows.some(line => String(line.product_variant_id) === String(variant.product_variant_id));
                checkbox.disabled = included; checkbox.setAttribute('aria-label', `اختيار ${variant.color} ${variant.size}`);
                choice.append(checkbox);
                if (included) choice.append(element('small', '', 'موجود في الفاتورة'));
                const quantity = element('input'); quantity.type = 'number'; quantity.min = 1; quantity.max = 1000000; quantity.step = 1; quantity.value = 1;
                quantity.required = true; quantity.disabled = true; quantity.setAttribute('aria-label', `كمية ${variant.color} ${variant.size}`);
                const cost = element('input'); cost.type = 'text'; cost.inputMode = 'decimal'; cost.value = variant.unit_cost; cost.required = true; cost.disabled = true;
                cost.pattern = '\\d{1,12}(?:\\.\\d{1,2})?'; cost.dataset.validationField = `rows.${index}.unit_cost`;
            cost.setAttribute('aria-label', `تكلفة ${variant.color} ${variant.size}`);
                const quantityCell = element('td'); quantityCell.append(quantity); const costCell = element('td'); costCell.append(cost);
                checkbox.addEventListener('change', () => { quantity.disabled = cost.disabled = !checkbox.checked; });
                row.purchaseVariant = variant;
                row.append(choice, element('td', '', `${variant.color || 'عام'} / ${variant.size || 'عام'}`), element('td', '', variant.sku), quantityCell, costCell);
                body.append(row);
            });
            if (!data.data.length) { const row = element('tr'); const cell = element('td', 'trade-empty', 'لا توجد تركيبات فعالة مسموحة لهذا المنتج.'); cell.colSpan = 5; row.append(cell); body.append(row); }
            document.getElementById('purchase-add-variants').disabled = !data.data.length;
            if (extend) extend.disabled = false;
        } catch (error) { if (error.name !== 'AbortError') { message.textContent = error.message; message.hidden = false; } }
    }
    variantsForm.addEventListener('submit', event => {
        event.preventDefault();
        const selected = [...document.querySelectorAll('#purchase-variants-rows tr')].filter(row => row.querySelector('input[type="checkbox"]:checked'));
        const message = document.getElementById('purchase-variants-error');
        if (!selected.length) { message.textContent = 'اختر تركيبة واحدة على الأقل؛ عدّل الأسطر الموجودة من جدول الفاتورة.'; message.hidden = false; return; }
        try {
            addRows(selected.map(row => ({ ...row.purchaseVariant, quantity: row.querySelector('input[type="number"]').value, unit_cost: row.querySelector('input[inputmode="decimal"]').value })));
            variantsDialog.close(); productSearch.value = '';
        } catch (error) { message.textContent = error.message; message.hidden = false; }
    });
    document.getElementById('purchase-extend-product')?.addEventListener('click', () => { variantsDialog.close(); openProduct(selectedProduct); });
    variantsDialog.addEventListener('close', () => variantRequest?.abort());
    document.getElementById('purchase-branch').addEventListener('change', () => {
        variantRequest?.abort(); optionsRequest?.abort(); searches.products?.abort(); clearTimeout(searchTimers.products);
        variantsDialog.close(); productDialog.close(); productResults.hidden = true; productSearch.value = '';
    });
    function renderCombinations() {
        const body = document.getElementById('new-product-combinations'); body.replaceChildren();
        const colors = [...document.getElementById('new-product-colors').selectedOptions];
        const sizes = [...document.getElementById('new-product-sizes').selectedOptions];
        colors.forEach(color => sizes.forEach(size => {
            const key = `${color.value}:${size.value}`;
            const existing = quickVariants.find(variant => String(variant.color_id) === color.value && String(variant.size_id) === size.value);
            if (!combinations.has(key)) combinations.set(key, { quantity: '1', unit_cost: existing?.unit_cost || '0.00' });
            const values = combinations.get(key); const row = element('tr');
            row.append(element('td', '', `${color.textContent} / ${size.textContent}`), element('td', '', existing ? 'تركيبة موجودة' : 'تركيبة جديدة'));
            for (const [field, label] of [['quantity', 'الكمية'], ['unit_cost', 'تكلفة الوحدة']]) {
                const cell = element('td'); const input = element('input'); input.required = true; input.value = values[field];
                input.type = field === 'quantity' ? 'number' : 'text'; input.inputMode = field === 'quantity' ? 'numeric' : 'decimal';
                if (field === 'quantity') { input.min = 1; input.max = 1000000; input.step = 1; } else input.pattern = '\\d{1,12}(?:\\.\\d{1,2})?';
                input.setAttribute('aria-label', `${label} ${color.textContent} ${size.textContent}`);
                input.addEventListener('input', () => { values[field] = input.value; }); cell.append(input); row.append(cell);
            }
            body.append(row);
        }));
        if (!colors.length || !sizes.length) { const row = element('tr'); const cell = element('td', 'trade-empty', 'اختر الألوان والمقاسات لعرض جدول التركيبات.'); cell.colSpan = 4; row.append(cell); body.append(row); }
    }
    document.querySelectorAll('[data-add-attribute]').forEach(button => button.addEventListener('click', () => {
        attributeType = button.dataset.addAttribute;
        document.getElementById('purchase-attribute-editor').hidden = false;
        document.getElementById('purchase-attribute-title').textContent = attributeType === 'color' ? 'تعريف لون جديد' : 'تعريف مقاس جديد';
        document.getElementById('purchase-attribute-hex-label').hidden = attributeType !== 'color';
        document.getElementById('purchase-attribute-name').value = document.getElementById('purchase-attribute-code').value = '';
        document.getElementById('purchase-attribute-error').hidden = true; document.getElementById('purchase-attribute-name').focus();
    }));
    document.getElementById('purchase-attribute-cancel').addEventListener('click', () => { document.getElementById('purchase-attribute-editor').hidden = true; });
    document.getElementById('purchase-attribute-save').addEventListener('click', async event => {
        const button = event.currentTarget; button.disabled = true;
        const message = document.getElementById('purchase-attribute-error'); message.hidden = true;
        const type = attributeType;
        try {
            const saved = await request(type === 'color' ? config.colorStoreUrl : config.sizeStoreUrl, { validationScope: document.getElementById('purchase-attribute-editor'), ...jsonOptions({
                name: document.getElementById('purchase-attribute-name').value.trim(), code: document.getElementById('purchase-attribute-code').value.trim().toUpperCase(),
                ...(type === 'color' ? { hex_code: document.getElementById('purchase-attribute-hex').value } : {}),
            }) });
            const catalog = await request(config.optionsUrl); productCatalog = catalog;
            for (const key of ['colors', 'sizes']) {
                const select = document.getElementById('new-product-' + key);
                const selected = new Set([...select.selectedOptions].map(option => option.value));
                if (key === (type === 'color' ? 'colors' : 'sizes')) selected.add(String(saved.id));
                select.replaceChildren(...catalog[key].map(item => new Option(item.name, item.id, false, selected.has(String(item.id)))));
            }
            document.getElementById('purchase-attribute-editor').hidden = true; renderQuickImages(); renderCombinations();
        } catch (error) { message.textContent = error.message; message.hidden = false; }
        finally { button.disabled = false; }
    });
    async function openProduct(existing = null) {
        optionsRequest?.abort();
        optionsRequest = new AbortController();
        const currentRequest = optionsRequest;
        clearQuickImages(); quickExistingImages = new Map();
        quickVariants = []; combinations.clear();
        productForm.reset();
        document.getElementById('purchase-attribute-editor').hidden = true;
        document.getElementById('new-product-combinations').replaceChildren();
        document.getElementById('new-product-images-section').hidden = true;
        productId = existing?.product_id || null;
        productDialog.querySelector('[data-dialog-errors]').hidden = true;
        document.getElementById('product-dialog-title').textContent = existing ? `خيارات إضافية: ${existing.name}` : 'منتج جديد';
        const name = document.getElementById('new-product-name'); const code = document.getElementById('new-product-code'); const category = document.getElementById('new-product-category');
        name.value = existing?.name || ''; code.value = existing?.code || ''; name.disabled = code.disabled = category.disabled = !!existing;
        document.getElementById('new-product-price').value = existing?.selling_price || '0.00';
        const button = productForm.querySelector('[type="submit"]'); button.disabled = true;
        productDialog.showModal();
        try {
            const [data, imageData, variants] = await Promise.all([
                request(config.optionsUrl, { signal: currentRequest.signal }),
                existing?.images_url ? request(existing.images_url, { signal: currentRequest.signal }) : Promise.resolve({ data: [] }),
                existing ? fetchVariants(existing, currentRequest.signal) : Promise.resolve({ data: [] }),
            ]);
            if (currentRequest.signal.aborted) return;
            productCatalog = data;
            quickVariants = variants.data;
            quickExistingImages = new Map(imageData.data.map(image => [String(image.color_id), image.url]));
            for (const [key, target] of [['categories', category], ['colors', document.getElementById('new-product-colors')], ['sizes', document.getElementById('new-product-sizes')]]) {
                target.replaceChildren();
                if (key === 'categories') target.append(new Option('اختر التصنيف', ''));
                data[key].forEach(item => target.append(new Option(item.name, item.id)));
            }
            if (existing) category.value = existing.category_id;
            renderQuickImages(); renderCombinations();
            button.disabled = false;
        } catch (error) { if (error.name !== 'AbortError') { const message = productDialog.querySelector('[data-dialog-errors]'); message.textContent = error.message; message.hidden = false; } }
    }
    document.getElementById('new-product-colors').addEventListener('change', renderQuickImages);
    document.getElementById('new-product-colors').addEventListener('change', renderCombinations);
    document.getElementById('new-product-sizes').addEventListener('change', renderCombinations);
    document.getElementById('new-product-name').addEventListener('input', renderQuickImages);
    document.getElementById('add-product')?.addEventListener('click', () => openProduct());
    productForm.addEventListener('submit', async event => {
        event.preventDefault(); const button = productForm.querySelector('[type="submit"]'); if (button.disabled) return;
        button.disabled = true; const message = productDialog.querySelector('[data-dialog-errors]'); message.hidden = true;
        const data = { product_id: productId, name: document.getElementById('new-product-name').value, code: document.getElementById('new-product-code').value || null,
            category_id: document.getElementById('new-product-category').value, colors: [...document.getElementById('new-product-colors').selectedOptions].map(option => Number(option.value)),
            sizes: [...document.getElementById('new-product-sizes').selectedOptions].map(option => Number(option.value)), selling_price: document.getElementById('new-product-price').value };
        const minimum = document.getElementById('new-product-minimum')?.value.trim(); if (minimum) data.minimum_selling_price = minimum;
        try {
            const multipart = new FormData();
            for (const [key, value] of Object.entries(data)) {
                if (Array.isArray(value)) value.forEach(item => multipart.append(`${key}[]`, item));
                else if (value !== null && value !== undefined) multipart.append(key, value);
            }
            const selected = data.colors.map(color => quickImages.get(String(color))).filter(Boolean);
            if (selected.length > productCatalog.limits.max_images) throw new Error('عدد الصور يتجاوز الحد المسموح للرفع في عملية واحدة.');
            if (selected.reduce((total, image) => total + image.file.size, 4096) > productCatalog.limits.max_request_bytes) throw new Error('حجم الصور يتجاوز حد الخادم. قلل عددها أو حجمها.');
            let imageIndex = 0;
            data.colors.forEach(color => {
                const image = quickImages.get(String(color));
                if (!image || quickExistingImages.has(String(color))) return;
                multipart.append(`images[${imageIndex}][color_id]`, color); multipart.append(`images[${imageIndex}][image]`, image.file); imageIndex++;
            });
            const result = await request(config.productStoreUrl, { validationScope: productForm, method: 'POST', body: multipart });
            clearQuickImages();
            addRows(result.data.map(variant => ({ ...variant, ...combinations.get(`${variant.color_id}:${variant.size_id}`) }))); productDialog.close(); productResults.hidden = true;
        } catch (error) { message.textContent = error.message; message.hidden = false; }
        finally { button.disabled = false; }
    });
    form.addEventListener('input', event => {
        if (!rowsElement.contains(event.target) && event.target !== supplierSearch && event.target !== productSearch) { dirty = true; invalidatePreview(); }
    });
    form.addEventListener('change', () => { dirty = true; invalidatePreview(); });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (saving) return;
        if ([...rowUploads.values()].some(upload => upload.busy)) { showError('انتظر اكتمال رفع صور الألوان قبل حفظ الفاتورة.'); return; }
        if (!state.supplier_id) { showError('اختر المورد من نتائج البحث أولًا.'); return; }
        if (!state.rows.length) { showError('أضف صنفًا واحدًا على الأقل.'); return; }
        saving = true; document.getElementById('save-purchase').disabled = true; errors.hidden = true;
        try {
            const data = await request(form.action, { ...jsonOptions(payload(), config.saveMethod), validationScope: form });
            dirty = false; window.location.assign(data.url);
        } catch (error) { showError(error.message); saving = false; document.getElementById('save-purchase').disabled = false; }
    });
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    renderRows();
    invalidatePreview();
})();
