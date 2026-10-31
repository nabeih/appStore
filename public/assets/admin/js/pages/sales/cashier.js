(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('cashier-config').textContent), ui = SalesUI, state = config.state;
    const el = id => document.getElementById(id), form = el('cashier-form'), allowed = key => !!config.capabilities[key];
    let dirty = false, busy = false, products = [], nextPage, selectedProduct, selectedVariant, previousBranch = el('cashier-branch').value;
    let scanBusy = false;
    let catalogRequest, catalogTimer, customerRequest, customerTimer, previewRequest, previewTimer, previewVersion = 0, lastPreview;
    const accounts = () => config.accounts.filter(account => String(account.branch_id) === el('cashier-branch').value);
    function addPayment(initial = {}) {
        if (el('cashier-payments').children.length >= 10) return;
        const row = ui.paymentRow(accounts(), initial); row.addEventListener('removed', invalidate); el('cashier-payments').append(row);
    }
    function customer(person) {
        state.customer_id = person?.id || null; state.customer_name = person?.name || '';
        el('cashier-customer-search').value = person?.name || '';
        el('cashier-customer-selection').textContent = person ? 'العميل المختار: '+person.name : 'زبون نقدي — السداد الكامل مطلوب.';
        el('cashier-customer-results').hidden = true; invalidate();
    }
    function payload() {
        return { client_uuid: state.client_uuid, ...(state.version ? { version: state.version } : {}), branch_id: Number(el('cashier-branch').value), customer_id: state.customer_id,
            rows: state.rows.map(row => ({ product_variant_id: row.product_variant_id, quantity: row.quantity, unit_price: row.unit_price,
                discount: row.discount || { type: 'amount', value: '0' }, price_reason: row.price_reason || null })),
            discount: { type: el('cashier-discount-type').value, value: el('cashier-discount-value').value.trim() || '0' },
            reason: el('cashier-reason').value.trim() || null, notes: el('cashier-notes').value.trim() || null, payment_mode: el('cashier-payment-mode').value,
            payments: el('cashier-payment-mode').value === 'credit' ? [] : ui.payments(el('cashier-payments')) };
    }
    function invalidate() {
        dirty = true; lastPreview = null; previewVersion++; clearTimeout(previewTimer); previewRequest?.abort();
        ui.error(form,'');
        ['gross','discount','total','paid','due','change','cost','gross-profit'].forEach(key => { const node = el('cashier-'+key); if (node) node.textContent = '—'; });
        el('cashier-complete').disabled = true;
        el('cashier-preview-status').textContent = state.rows.length ? 'جارٍ مراجعة الحساب...' : 'أضف الأصناف لمراجعة الحساب.';
        if (state.rows.length && !busy) previewTimer = setTimeout(preview, 350);
    }
    async function preview() {
        const version = previewVersion, controller = new AbortController(); previewRequest = controller;
        try {
            const result = await ui.request(config.previewUrl, { method: 'POST', data: payload(), signal: controller.signal });
            if (version !== previewVersion) return; lastPreview = result;
            Object.entries(result).forEach(([key,value]) => { const node = el('cashier-'+key.replaceAll('_','-')); if (node && typeof value === 'string') node.textContent = value+' ₪'; });
            el('cashier-lines').querySelectorAll('[data-line-total]').forEach((node,index) => node.textContent = result.lines[index]+' ₪');
            el('cashier-preview-status').textContent = 'الحساب مراجع. الاعتماد يعيد فحص السعر والرصيد والمقبوض.'; el('cashier-complete').disabled = busy;
        } catch (failure) { if (failure.name !== 'AbortError' && version === previewVersion) { el('cashier-preview-status').textContent = failure.message; ui.error(form,failure); } }
    }
    function renderLines() {
        const container = el('cashier-lines'); container.replaceChildren();
        if (!state.rows.length) container.append(ui.element('p','sale-muted','السلة فارغة. اختر منتجًا ولونًا ومقاسًا.'));
        state.rows.forEach((row,index) => {
            const card = ui.element('article','cashier-line'), header = ui.element('header'), name = ui.element('strong','',row.name);
            name.append(ui.element('small','',row.color+' / '+row.size+' — '+row.sku));
            const remove = ui.element('button','','×'); remove.type = 'button'; remove.dataset.removeLine = ''; remove.setAttribute('aria-label','إزالة '+row.name);
            remove.addEventListener('click', () => { state.rows.splice(index,1); renderLines(); invalidate(); }); header.append(name,remove); card.append(header);
            const grid = ui.element('div','sale-grid');
            const field = (text,input) => { const label = ui.element('label','',text); label.append(input); grid.append(label); };
            const quantity = ui.element('input'); quantity.type = 'number'; quantity.min = '1'; quantity.max = '1000000'; quantity.step = '1'; quantity.value = row.quantity;
            quantity.addEventListener('input', () => { row.quantity = quantity.value; invalidate(); }); field('الكمية',quantity);
            const price = ui.element('input'); price.type = 'text'; price.inputMode = 'decimal'; price.value = row.unit_price; price.readOnly = !allowed('sales.override_price');
            price.addEventListener('input', () => { row.unit_price = price.value; invalidate(); }); field('سعر الوحدة ₪',price);
            const type = ui.element('select'); type.append(new Option('مبلغ على السطر ₪','amount'),new Option('نسبة على السطر %','percent')); type.value = row.discount?.type || 'amount';
            type.addEventListener('change', () => { row.discount = { ...row.discount,type: type.value }; invalidate(); }); field('نوع خصم السطر',type);
            const discount = ui.element('input'); discount.type = 'text'; discount.inputMode = 'decimal'; discount.value = row.discount?.value || '0';
            discount.addEventListener('input', () => { row.discount = { type: type.value,value: discount.value || '0' }; invalidate(); }); field('قيمة خصم السطر',discount);
            card.append(grid); const total = ui.element('div','cashier-line-total'), amount = ui.element('strong','','—'); amount.dataset.lineTotal = '';
            total.append(ui.element('span','','صافي السطر'),amount); card.append(total); container.append(card);
        });
    }
    function addVariant(product,variant) {
        const current = state.rows.find(row => row.product_variant_id === variant.product_variant_id);
        if (current) {
            const quantity = Number(current.quantity)+1;
            if (!Number.isInteger(quantity) || quantity > variant.available) { ui.error(form,'الكمية في السلة بلغت المتاح حاليًا.'); return; } current.quantity = quantity;
        } else {
            if (state.rows.length >= 300) { ui.error(form,'الحد الأقصى 300 صنف للفاتورة.'); return; }
            state.rows.push({ ...variant,name: product.name,quantity: 1,base_price: variant.unit_price,discount: { type: 'amount',value: '0' } });
        }
        el('cashier-variant-dialog').close(); ui.error(form,''); renderLines(); invalidate();
    }
    function renderSizes() {
        document.querySelectorAll('#cashier-color-swatches button').forEach(button => button.setAttribute('aria-pressed',String(button.dataset.colorId === el('cashier-variant-color').value)));
        selectedVariant = null; el('cashier-add-variant').disabled = true;
        const variants = selectedProduct.variants.filter(v => String(v.color_id) === el('cashier-variant-color').value), list = el('cashier-variant-sizes'); list.replaceChildren();
        const imageVariant = variants.find(v => v.optimized_image_url || v.image_url);
        const image = imageVariant?.optimized_image_url || imageVariant?.image_url; el('cashier-variant-image').hidden = !image;
        if (image) { el('cashier-variant-image').src = image; el('cashier-variant-image').alt = selectedProduct.name; }
        variants.forEach(variant => {
            const button = ui.element('button','',variant.size+' ('+variant.available+')'); button.type = 'button'; button.disabled = variant.available < 1; button.setAttribute('aria-pressed','false');
            button.addEventListener('click', () => {
                selectedVariant = variant; list.querySelectorAll('button').forEach(b => b.setAttribute('aria-pressed',String(b === button)));
                el('cashier-variant-info').textContent = variant.color+' / '+variant.size+' — المتاح '+variant.available+' — '+variant.unit_price+' ₪'; el('cashier-add-variant').disabled = false;
            }); list.append(button);
        });
        el('cashier-variant-info').textContent = 'اختيار اللون والمقاس مطلوب قبل الإضافة.';
    }
    function openProduct(product) {
        selectedProduct = product; el('cashier-variant-title').textContent = product.name;
        const select = el('cashier-variant-color'); select.replaceChildren(); const seen = new Set();
        product.variants.forEach(variant => { if (!seen.has(variant.color_id)) { seen.add(variant.color_id); select.append(new Option(variant.color,variant.color_id)); } });
        const samples = el('cashier-color-swatches'); samples.replaceChildren();
        const displayed = new Set();
        product.variants.forEach(variant => {
            if (displayed.has(variant.color_id)) return;
            displayed.add(variant.color_id);
            const button = ui.element('button',''); button.type = 'button'; button.dataset.colorId = String(variant.color_id);
            button.append(FabricSwatches.indicator(variant.swatch_url,variant.hex,variant.color),document.createTextNode(variant.color));
            button.addEventListener('click',() => { select.value = String(variant.color_id); renderSizes(); });
            samples.append(button);
        });
        renderSizes(); el('cashier-variant-dialog').showModal();
    }
    async function catalog(page = 1) {
        catalogRequest?.abort(); const controller = new AbortController(); catalogRequest = controller; el('cashier-catalog-status').textContent = 'جاري تحميل البضاعة...';
        try {
            const result = await ui.request(config.catalogUrl+'?'+new URLSearchParams({ branch_id: el('cashier-branch').value,q: el('cashier-search').value.trim(),page }),{ signal: controller.signal });
            if (catalogRequest !== controller) return; products = page === 1 ? result.data : [...products,...result.data]; nextPage = result.next_page;
            const readiness = result.readiness;
            el('cashier-readiness').hidden = !readiness?.blocked && !readiness?.setup_open;
            if (readiness?.blocked || readiness?.setup_open) {
                el('cashier-readiness-message').textContent = readiness.setup_open ? readiness.setup_message : readiness.blocked === readiness.total
                    ? 'البيع على الأصناف الحالية غير متاح: أرصدة المخزون في هذا الفرع تحتاج مراجعة واعتمادًا من المسؤول قبل البيع.'
                    : 'يوجد '+readiness.blocked+' رصيد مخزون يحتاج مراجعة المسؤول؛ الأصناف السليمة متاحة للبيع.';
                const reasons = [];
                if (readiness.quantity_mismatches) reasons.push('فروق كمية: '+readiness.quantity_mismatches);
                if (readiness.unapproved_values) reasons.push('قيم غير معتمدة: '+readiness.unapproved_values);
                if (readiness.unknown_movements) reasons.push('حركات ناقصة القيمة: '+readiness.unknown_movements);
                if (reasons.length) el('cashier-readiness-message').textContent += ' '+reasons.join('، ')+'.';
                const link = el('cashier-readiness-link'); link.hidden = !readiness.review_url;
                if (readiness.review_url) link.href = readiness.review_url;
            }
            const container = el('cashier-products'); container.replaceChildren();
            products.forEach(product => {
                const button = ui.element('button','cashier-product'); button.type = 'button'; const image = product.images[0]?.url;
                if (image) { const img = ui.element('img'); img.src = image; img.alt = product.name; img.loading = 'lazy'; img.decoding = 'async'; img.width = 160; img.height = 160; button.append(img); }
                else button.append(ui.element('span','cashier-product-placeholder','اختيار اللون والمقاس'));
                button.append(ui.element('strong','',product.name),ui.element('small','',product.code)); button.addEventListener('click',() => openProduct(product)); container.append(button);
            });
            el('cashier-more').hidden = !nextPage; el('cashier-catalog-status').textContent = products.length ? 'اختر المنتج ثم اللون والمقاس.' : 'لا توجد بضائع متاحة تطابق البحث في الفرع.';
            return true;
        } catch (failure) { if (failure.name !== 'AbortError') el('cashier-catalog-status').textContent = failure.message; }
    }
    el('cashier-search').addEventListener('input', () => { clearTimeout(catalogTimer); catalogRequest?.abort(); catalogTimer = setTimeout(() => catalog(),250); });
    async function scanSku(event) {
        if (event.type === 'keydown' && event.key !== 'Enter') return;
        event.preventDefault(); if (scanBusy) return;
        clearTimeout(catalogTimer); scanBusy = true;
        const normalizeCode = value => value.trim().replace(/[٠-٩۰-۹]/g, digit => '٠١٢٣٤٥٦٧٨٩'.includes(digit) ? String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)) : String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit))).toUpperCase();
        const code = normalizeCode(el('cashier-search').value), branch = el('cashier-branch').value;
        try {
            if (!await catalog()) return;
            if (normalizeCode(el('cashier-search').value) !== code || el('cashier-branch').value !== branch) return;
            const matches = products.flatMap(product => product.variants.filter(v => normalizeCode(v.sku) === code).map(variant => ({ product,variant })));
            if (matches.length === 1 && matches[0].variant.available > 0) { addVariant(matches[0].product,matches[0].variant); el('cashier-search').value = ''; }
        } finally { scanBusy = false; }
    }
    el('cashier-search').addEventListener('keydown', scanSku);
    el('cashier-search').addEventListener('product-search:sku', scanSku);
    el('cashier-more').addEventListener('click', () => { if (nextPage) catalog(nextPage); });
    el('cashier-variant-color').addEventListener('change',renderSizes); el('cashier-add-variant').addEventListener('click',() => { if (selectedVariant) addVariant(selectedProduct,selectedVariant); });
    el('cashier-clear').addEventListener('click',() => { if (state.rows.length && confirm('تفريغ أصناف السلة الحالية؟')) { state.rows = []; renderLines(); invalidate(); } });
    el('cashier-branch').addEventListener('change',() => {
        if (state.rows.length && !confirm('تغيير الفرع يفرّغ أصناف السلة الحالية. متابعة؟')) { el('cashier-branch').value = previousBranch; return; }
        previousBranch = el('cashier-branch').value; state.rows = []; el('cashier-payments').replaceChildren(); addPayment(); renderLines(); invalidate(); catalog();
    });
    el('cashier-add-payment').addEventListener('click',() => { addPayment(); invalidate(); });
    el('cashier-payment-mode').addEventListener('change',() => { el('cashier-payment-section').hidden = el('cashier-payment-mode').value === 'credit'; invalidate(); });
    form.addEventListener('input',event => { if (!['cashier-search','cashier-customer-search'].includes(event.target.id) && !event.target.closest('#cashier-lines')) invalidate(); });
    form.addEventListener('change',event => { if (!event.target.closest('#cashier-lines') && event.target.id !== 'cashier-branch') invalidate(); });
    el('cashier-customer-search').addEventListener('input',() => {
        state.customer_id = null; clearTimeout(customerTimer); customerRequest?.abort(); el('cashier-customer-results').hidden = true;
        el('cashier-customer-selection').textContent = 'اختر العميل من النتائج لربط المستحق به.'; invalidate();
        const q = el('cashier-customer-search').value.trim(); if (!q) return;
        customerTimer = setTimeout(async () => {
            const controller = new AbortController(); customerRequest = controller;
            try {
                const result = await ui.request(config.customersUrl+'?'+new URLSearchParams({ q }),{ signal: controller.signal });
                if (el('cashier-customer-search').value.trim() !== q) return;
                const container = el('cashier-customer-results'); container.replaceChildren();
                result.data.forEach(person => { const button = ui.element('button','',person.name); button.type = 'button'; button.append(ui.element('small','',person.phone || '')); button.addEventListener('click',() => customer(person)); container.append(button); });
                container.hidden = false;
            } catch (failure) { if (failure.name !== 'AbortError') ui.error(form,failure); }
        },250);
    });
    el('cashier-customer-search').addEventListener('keydown',event => { if (event.key === 'Enter') { event.preventDefault(); const choices = el('cashier-customer-results').querySelectorAll('button'); if (choices.length === 1) choices[0].click(); } });
    el('cashier-walkin').addEventListener('click',() => customer(null));
    el('cashier-new-customer').addEventListener('click',() => { ui.error(el('cashier-customer-form'),''); el('cashier-customer-dialog').showModal(); });
    el('cashier-customer-form').addEventListener('submit',async event => {
        event.preventDefault(); const quick = event.currentTarget, button = quick.querySelector('[type=submit]'); if (button.disabled) return; button.disabled = true;
        try { const result = await ui.request(config.customerStoreUrl,{ method: 'POST',data: Object.fromEntries(new FormData(quick)) }); customer(result.data); quick.reset(); el('cashier-customer-dialog').close(); }
        catch (failure) { ui.error(quick,failure); } finally { button.disabled = false; }
    });
    async function save(complete) {
        if (busy || !state.rows.length) return; busy = true; clearTimeout(previewTimer); previewRequest?.abort(); el('cashier-fields').disabled = true; ui.error(form,'');
        try { const result = await ui.request(complete ? config.completeUrl : config.saveUrl,{ method: complete ? 'POST' : config.saveMethod,data: payload() }); dirty = false; busy = false; location.assign(result.url); }
        catch (failure) { ui.error(form,failure); busy = false; el('cashier-fields').disabled = false; invalidate(); }
    }
    form.addEventListener('submit',event => { event.preventDefault(); if (lastPreview) save(true); }); el('cashier-save-draft').addEventListener('click',() => save(false));
    window.addEventListener('beforeunload',event => { if (dirty || busy) { event.preventDefault(); event.returnValue = ''; } });
    el('cashier-discount-type').value = state.discount?.type || 'amount'; el('cashier-discount-value').value = state.discount?.value || '0';
    el('cashier-reason').value = state.reason || ''; el('cashier-notes').value = state.notes || ''; el('cashier-payment-mode').value = state.payment_mode || 'paid';
    el('cashier-payment-section').hidden = el('cashier-payment-mode').value === 'credit';
    if (state.payments.length) state.payments.forEach(addPayment); else if (accounts().length) addPayment();
    if (state.customer_id) { el('cashier-customer-search').value = state.customer_name; el('cashier-customer-selection').textContent = 'العميل المختار: '+state.customer_name; }
    renderLines(); catalog(); if (state.rows.length) { invalidate(); dirty = false; }
})();
