(() => {
'use strict';
const find = (selector, root = document) => root.querySelector(selector);
const all = (selector, root = document) => [...root.querySelectorAll(selector)];
const toast = text => { const node = find('[data-store-toast]'); if (!node) return; node.textContent = text; node.hidden = false; clearTimeout(toast.timer); toast.timer = setTimeout(() => { node.hidden = true; }, 4000); };
const closeDialog = dialog => { dialog.close(); if (!find('dialog[open]')) document.body.classList.remove('dialog-open'); };
const showDialog = dialog => { if (!dialog?.showModal) return; dialog.showModal(); document.body.classList.add('dialog-open'); };
all('[data-close-dialog]').forEach(button => button.addEventListener('click', () => closeDialog(button.closest('dialog'))));
all('dialog').forEach(dialog => {
    dialog.addEventListener('close', () => { if (!find('dialog[open]')) document.body.classList.remove('dialog-open'); });
    dialog.addEventListener('click', event => { if (event.target !== dialog) return; const r = dialog.getBoundingClientRect(); if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) closeDialog(dialog); });
});
find('[data-open-menu]')?.addEventListener('click', () => showDialog(find('[data-menu-dialog]')));
find('[data-close-menu]')?.addEventListener('click', () => closeDialog(find('[data-menu-dialog]')));
document.addEventListener('click', event => all('.shop-search[open],.shop-dropdown[open]').forEach(details => { if (!details.contains(event.target)) details.open = false; }));
document.addEventListener('keydown', event => { if (event.key === 'Escape') all('.shop-search[open],.shop-dropdown[open]').forEach(details => { details.open = false; }); });
const cartKey = 'appstore-public-cart-v1';
let cart = [];
try { const stored = JSON.parse(localStorage.getItem(cartKey) || '[]'); if (Array.isArray(stored)) cart = stored; } catch {}
cart = cart.filter(item => item && Number.isInteger(item.id) && item.id > 0 && Number.isInteger(item.quantity) && item.quantity > 0 && item.quantity <= 100).slice(0, 50);
const storage = {
    get(key) { try { return sessionStorage.getItem(key); } catch { return null; } },
    set(key, value) { try { sessionStorage.setItem(key, value); } catch {} },
    remove(key) { try { sessionStorage.removeItem(key); } catch {} },
};
const uuid = () => {
    if (crypto.randomUUID) return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16)); bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
    const hex = [...bytes].map(byte => byte.toString(16).padStart(2, '0')).join('');
    return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
};
const element = (tag, text, className) => { const node = document.createElement(tag); if (text !== undefined) node.textContent = text; if (className) node.className = className; return node; };
function saveCart() {
    try { localStorage.setItem(cartKey, JSON.stringify(cart)); } catch { toast('تعذر حفظ الحقيبة على هذا الجهاز.'); }
    all('[data-cart-count]').forEach(node => { node.textContent = String(cart.reduce((count, item) => count + item.quantity, 0)); });
}
let scheduleQuote = () => {};
function renderCart(target) {
    if (!target) return;
    target.replaceChildren();
    if (!cart.length) {
        const empty = element('div', undefined, 'shop-cart-empty');
        const link = element('a', 'اكتشف التشكيلة', 'shop-text-link'); link.href = '/shop';
        empty.append(element('span', '✧'), element('h3', 'حقيبتك تنتظر اختياراتك'), element('p', 'اختر ما تحب من الألوان والمقاسات وأضفه هنا.'), link); target.append(empty); return;
    }
    cart.forEach(item => {
        const row = element('div', undefined, 'shop-cart-item');
        if (typeof item.image === 'string' && item.image) {
            try { const url = new URL(item.image, location.origin); if (url.origin === location.origin) { const image = element('img'); image.src = url.href; image.alt = ''; image.width = 64; image.height = 80; row.append(image); } } catch {}
        }
        const details = element('div'); if (!row.childElementCount) row.style.gridTemplateColumns = '1fr';
        details.append(element('strong', typeof item.name === 'string' ? item.name : 'قطعة مختارة'));
        const quantity = element('input'); quantity.type = 'number'; quantity.min = '1'; quantity.max = '100'; quantity.value = String(item.quantity); quantity.setAttribute('aria-label', 'الكمية');
        quantity.addEventListener('change', () => { const value = Number(quantity.value); if (!Number.isInteger(value) || value < 1 || value > 100) { quantity.value = String(item.quantity); return; } item.quantity = value; changedCart(); });
        const remove = element('button', 'إزالة'); remove.type = 'button'; remove.addEventListener('click', () => { cart = cart.filter(row => row !== item); changedCart(); });
        details.append(element('small', 'السعر والإجمالي يراجعان عند إتمام الطلب'), quantity, remove); row.append(details); target.append(row);
    });
}
function changedCart() { saveCart(); renderCart(find('[data-cart-drawer-items]')); renderCart(find('[data-checkout-cart]')); scheduleQuote(); }
all('[data-cart-open]').forEach(button => button.addEventListener('click', () => { renderCart(find('[data-cart-drawer-items]')); showDialog(find('[data-cart-dialog]')); }));
saveCart(); renderCart(find('[data-cart-drawer-items]'));
const product = find('[data-add-product]');
if (product) {
    const select = product.elements.variant;
    const add = find('button[type=submit]', product);
    let options = [];
    try { options = JSON.parse(find('[data-variant-options]', product)?.textContent || '[]'); } catch {}
    if (options.length) {
        product.classList.add('is-enhanced');
        let selectedSize = null;
        const updateSelection = () => {
            const color = find('[name=product_color]:checked', product);
            const colorId = Number(color?.value);
            all('[data-color-photo]').forEach(photo => { photo.hidden = Number(photo.dataset.colorPhoto) !== colorId; });
            all('[data-gallery-color]').forEach(button => button.setAttribute('aria-pressed', String(Number(button.dataset.galleryColor) === colorId)));
            find('[data-color-name]').textContent = color?.dataset.colorLabel || '';
            all('[data-product-size]', product).forEach(button => {
                const available = options.some(option => option.color_id === colorId && option.size_id === Number(button.dataset.productSize) && option.available);
                button.disabled = !available;
                button.setAttribute('aria-pressed', String(available && Number(button.dataset.productSize) === selectedSize));
            });
            const selected = options.find(option => option.color_id === colorId && option.size_id === selectedSize && option.available);
            select.value = selected ? String(selected.id) : ''; add.disabled = !selected;
            find('[data-variant-status]').textContent = selected ? 'التركيبة المختارة متوفرة. تأكيد الحجز بعد مراجعة الطلب.' : 'اختر المقاس المتوفر للون المحدد.';
            const display = selected || options.find(option => option.color_id === colorId);
            if (display) {
                find('[data-product-price]').textContent = display.price;
                const base = find('[data-product-base]'); base.hidden = display.base === display.price; base.textContent = display.base + ' ₪';
            }
            find('[data-product-sku]').textContent = selected ? ' · ' + selected.sku : '';
        };
        all('[name=product_color]', product).forEach(input => input.addEventListener('change', () => { selectedSize = null; updateSelection(); }));
        all('[data-product-size]', product).forEach(button => button.addEventListener('click', () => { selectedSize = Number(button.dataset.productSize); updateSelection(); }));
        all('[data-gallery-color]').forEach(button => button.addEventListener('click', () => { const radio = all('[name=product_color]', product).find(input => input.value === button.dataset.galleryColor); if (radio) { radio.checked = true; radio.dispatchEvent(new Event('change', { bubbles: true })); } }));
        updateSelection();
    } else { add.disabled = true; }
    all('[data-quantity-change]', product).forEach(button => button.addEventListener('click', () => { product.elements.quantity.value = String(Math.min(100, Math.max(1, Number(product.elements.quantity.value) + Number(button.dataset.quantityChange)))); }));
    product.addEventListener('submit', event => {
        event.preventDefault(); const id = Number(select.value), quantity = Number(product.elements.quantity.value);
        if (!Number.isInteger(id) || id < 1 || !Number.isInteger(quantity) || quantity < 1 || quantity > 100) return;
        const existing = cart.find(item => item.id === id);
        if (existing) existing.quantity = Math.min(100, existing.quantity + quantity);
        else if (cart.length < 50) cart.push({ id, quantity, name: product.dataset.productName + ' — ' + (options.find(option => option.id === id)?.label || select.selectedOptions[0].textContent), image: product.dataset.productImage || '' });
        else { toast('الحقيبة تحتوي الحد الأقصى من الأصناف.'); return; }
        changedCart(); toast('أضيفت القطعة إلى حقيبتك.'); showDialog(find('[data-cart-dialog]'));
    });
}
const checkout = find('[data-store-checkout]');
if (!checkout) return;
const form = find('[data-checkout-form]', checkout), error = find('[data-checkout-error]', checkout), submit = find('button[type=submit]', form), ready = checkout.dataset.ready === '1';
let quoteController, quoteTimer, quoteReady = false, quoteRevision = null, busy = false;
let clientUuid = storage.get('appstore-checkout-uuid') || uuid();
const csrf = () => find('meta[name=csrf-token]')?.content;
const rows = () => cart.map(item => ({ product_variant_id: item.id, quantity: item.quantity }));
async function request(url, body, signal) {
    const response = await fetch(url, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() }, body: JSON.stringify(body), signal });
    let result; try { result = await response.json(); } catch { throw Error('تعذر الاتصال. سلتك محفوظة؛ أعد المحاولة بعد قليل.'); }
    if (!response.ok) { const failure = Error(result.errors ? Object.values(result.errors).flat().join(' ') : result.message || 'تعذر إتمام الطلب.'); failure.fields = result.errors; throw failure; }
    return result;
}
async function recover() {
    try {
        const response = await fetch(checkout.dataset.status + '/' + encodeURIComponent(clientUuid), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (response.status !== 200) return false;
        const result = await response.json(); error.replaceChildren();
        const message = element('p', 'طلبك وصل سابقًا. لا حاجة لإرساله مرة أخرى.');
        const link = element('a', 'عرض ' + result.reference); link.href = result.url;
        const reset = element('button', 'بدء طلب جديد بهذه الحقيبة'); reset.type = 'button';
        reset.addEventListener('click', () => { if (!confirm('الطلب السابق محفوظ. هل تريد إنشاء طلب إضافي مستقل؟')) return; clientUuid = uuid(); storage.remove('appstore-checkout-uuid'); error.replaceChildren(); submit.disabled = !ready || !quoteReady; });
        error.append(message, link, reset); submit.disabled = true; return true;
    } catch { return false; }
}
scheduleQuote = () => {
    quoteReady = false; quoteRevision = null; submit.disabled = true;
    clearTimeout(quoteTimer); quoteController?.abort();
    ['products', 'delivery', 'original_delivery', 'total'].forEach(key => { find('[data-quote-' + key + ']', checkout).textContent = '—'; });
    if (ready && cart.length && form.elements.delivery_zone_id.value) quoteTimer = setTimeout(quote, 250);
};
async function quote() {
    quoteController = new AbortController();
    try {
        const result = await request(checkout.dataset.quote, { delivery_zone_id: Number(form.elements.delivery_zone_id.value), rows: rows() }, quoteController.signal);
        ['products', 'delivery', 'original_delivery', 'total'].forEach(key => { find('[data-quote-' + key + ']', checkout).textContent = result[key] + ' ₪'; });
        quoteRevision = result.quote_revision; quoteReady = true; submit.disabled = busy; error.textContent = '';
    } catch (failure) { if (failure.name !== 'AbortError') error.textContent = failure.message; }
}
form.elements.delivery_zone_id.addEventListener('change', scheduleQuote);
form.addEventListener('submit', async event => {
    event.preventDefault();
    if (!ready || busy || !quoteReady || !cart.length) { error.textContent = 'راجع توفر خدمة الطلب والمنطقة والإجمالي قبل الإرسال.'; return; }
    busy = true; submit.disabled = true; error.textContent = ''; storage.set('appstore-checkout-uuid', clientUuid);
    try {
        const values = Object.fromEntries(new FormData(form));
        const result = await request(checkout.dataset.place, { ...values, client_uuid: clientUuid, delivery_zone_id: Number(values.delivery_zone_id), accepted: form.elements.accepted.checked, policy_version: checkout.dataset.policy, quote_revision: quoteRevision, rows: rows() });
        cart = []; saveCart(); storage.remove('appstore-checkout-uuid'); location.assign(result.url);
    } catch (failure) { error.textContent = failure.message; if (failure.fields?.quote_revision) scheduleQuote(); await recover(); }
    finally { busy = false; if (!find('a', error)) submit.disabled = !ready || !quoteReady; }
});
renderCart(find('[data-checkout-cart]', checkout)); scheduleQuote();
if (storage.get('appstore-checkout-uuid')) recover();
})();
