(() => {
    'use strict';
    const cache = new Map();
    const node = (tag, className, text) => {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== undefined) element.textContent = text;
        return element;
    };
    function indicator(url, hex, label) {
        const valid = /^#[0-9a-f]{6}$/i.test(hex || '');
        const element = node('span', 'fabric-swatch'+(valid ? '' : ' fabric-swatch-neutral'));
        if (valid) element.style.backgroundColor = hex;
        element.title = label || 'لون غير محدد';
        element.setAttribute('role', 'img'); element.setAttribute('aria-label', element.title);
        if (url) {
            const image = node('img'); image.src = url; image.alt = ''; image.loading = 'lazy'; image.decoding = 'async';
            image.dataset.fabricSwatch = '1'; element.append(image);
        }
        return element;
    }
    document.addEventListener('error', event => {
        if (event.target instanceof HTMLImageElement && event.target.hasAttribute('data-fabric-swatch')) event.target.hidden = true;
    }, true);
    const hideFailedImages = () => document.querySelectorAll('img[data-fabric-swatch]').forEach(image => {
        if (image.complete && !image.naturalWidth) image.hidden = true;
    });
    hideFailedImages();
    document.addEventListener('DOMContentLoaded', hideFailedImages, { once:true });
    let dialog, state, source, mode, crop, file, objectUrl, serial = 0, busy = false;
    const field = name => dialog.querySelector('[data-fabric="'+name+'"]');
    async function request(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin', ...options,
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, ...options.headers },
        });
        const data = await response.json();
        if (!response.ok) {
            const statuses = {
                401:'انتهت جلسة الدخول. سجّل الدخول ثم أعد فتح معاينة العينة.',
                403:'لا تملك صلاحية تعديل عينات القماش.',
                404:'لم تعد بيانات المنتج أو اللون متاحة. حدّث المعاينة.',
                419:'انتهت صلاحية جلسة الحماية. احفظ بياناتك ثم حدّث الصفحة.',
            };
            throw new Error(statuses[response.status] || (response.status >= 500 ? 'تعذر إكمال الطلب على الخادم. حدّث المعاينة قبل إعادة المحاولة.' : data.errors ? Object.values(data.errors).flat().join('\n') : data.message || 'تعذر إكمال الطلب.'));
        }
        return data;
    }
    function message(text, error = false) {
        field('message').textContent = error && /failed to fetch|networkerror|network request/i.test(text) ? 'تعذر الاتصال بالخادم. تحقق من الشبكة وأعد المحاولة.' : text; field('message').className = error ? 'fabric-error' : 'fabric-message';
    }
    function release() { if (objectUrl) URL.revokeObjectURL(objectUrl); objectUrl = null; }
    function lock(value) {
        busy = value;
        dialog.querySelectorAll('button,input').forEach(element => element.disabled = value);
        field('save').disabled = value || !source;
        field('delete').disabled = value || !state?.color?.swatch;
        field('from-product').disabled = value || !state?.color?.source_image;
    }
    function update(data) {
        const color = data.colors.find(item => String(item.color_id) === String(state.colorId));
        if (!color) throw new Error('هذا اللون لم يعد متاحًا للمنتج.');
        state.color = color;
        cache.set(state.url+':'+color.color_id, color);
        field('title').textContent = 'عينة قماش — '+data.name+' / '+color.name;
        field('current').replaceChildren(indicator(color.swatch?.url, color.hex, color.name), node('span', '', color.swatch ? 'العينة الحالية لجميع المقاسات' : color.hex ? 'البديل الحالي: '+color.hex : 'لا توجد عينة أو درجة HEX؛ سيظهر مؤشر محايد مع اسم اللون.'));
        lock(false);
    }
    function createDialog() {
        if (dialog) return;
        dialog = node('dialog', 'fabric-dialog');
        dialog.setAttribute('aria-label', 'إدارة عينة القماش');
        dialog.innerHTML = '<header><h2 data-fabric="title">عينة القماش</h2><button type="button" data-fabric="close">إغلاق</button></header>'
            +'<p data-fabric="message" role="status"></p><div data-fabric="current" class="fabric-current"></div>'
            +'<p>العينة مستقلة عن صورة المنتج ومشتركة بين مقاسات اللون نفسه.</p>'
            +'<div class="fabric-actions"><button type="button" data-fabric="upload">رفع صورة للقماش</button><button type="button" data-fabric="from-product">قص من صورة المنتج</button></div>'
            +'<input data-fabric="file" type="file" accept="image/jpeg,image/png,image/webp" hidden>'
            +'<section data-fabric="editor" hidden><p>حرّك المربع باللمس أو الماوس. يمكن استخدام الأسهم بعد التركيز على الصورة.</p>'
            +'<canvas data-fabric="canvas" tabindex="0" aria-label="تحديد موضع مربع قص القماش"></canvas>'
            +'<label>حجم المربع<input data-fabric="size" type="range" min="5" max="100" value="35"></label>'
            +'<div class="fabric-preview"><span>معاينة قبل الحفظ</span><canvas data-fabric="preview" width="96" height="96" aria-label="معاينة عينة القماش"></canvas></div></section>'
            +'<footer><button type="button" data-fabric="delete">حذف العينة</button><button type="button" data-fabric="save" disabled>حفظ العينة</button></footer>';
        document.body.append(dialog);
        field('close').addEventListener('click', () => { if (!busy) dialog.close(); });
        dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
        dialog.addEventListener('close', () => { release(); source = null; serial++; });
        field('upload').addEventListener('click', () => field('file').click());
        field('file').addEventListener('change', () => {
            const selected = field('file').files[0]; field('file').value = '';
            if (!selected) return;
            if (selected.size > 4 * 1024 * 1024 || (!['image/jpeg','image/png','image/webp'].includes(selected.type) && !(selected.type === '' && /\.(jpe?g|png|webp)$/i.test(selected.name)))) {
                message('اختر صورة JPG أو PNG أو WebP لا تتجاوز 4 ميجابايت.', true); return;
            }
            file = selected; mode = 'upload'; release(); objectUrl = URL.createObjectURL(file); loadImage(objectUrl);
        });
        field('from-product').addEventListener('click', () => {
            if (!state.color.source_image) return;
            mode = 'crop'; file = null; release(); loadImage(state.color.source_image.url);
        });
        field('size').addEventListener('input', () => {
            if (!source) return;
            const centerX = crop.x+crop.size/2, centerY = crop.y+crop.size/2;
            crop.size = Math.max(1, Math.floor(Math.min(source.naturalWidth,source.naturalHeight)*Number(field('size').value)/100));
            crop.x = Math.round(centerX-crop.size/2); crop.y = Math.round(centerY-crop.size/2); clamp(); draw();
        });
        const canvas = field('canvas');
        let dragging = false;
        const move = event => {
            if (!source || busy) return;
            const rect = canvas.getBoundingClientRect();
            crop.x = Math.round((event.clientX-rect.left)/rect.width*source.naturalWidth-crop.size/2);
            crop.y = Math.round((event.clientY-rect.top)/rect.height*source.naturalHeight-crop.size/2); clamp(); draw();
        };
        canvas.addEventListener('pointerdown', event => { if (busy) return; dragging = true; canvas.setPointerCapture(event.pointerId); move(event); });
        canvas.addEventListener('pointermove', event => { if (dragging) move(event); });
        canvas.addEventListener('pointerup', () => dragging = false);
        canvas.addEventListener('pointercancel', () => dragging = false);
        canvas.addEventListener('keydown', event => {
            if (!source || busy || !['ArrowUp','ArrowDown','ArrowLeft','ArrowRight'].includes(event.key)) return;
            event.preventDefault(); const step = Math.max(1,Math.round(crop.size/20));
            if (event.key === 'ArrowLeft') crop.x -= step;
            if (event.key === 'ArrowRight') crop.x += step;
            if (event.key === 'ArrowUp') crop.y -= step;
            if (event.key === 'ArrowDown') crop.y += step;
            clamp(); draw();
        });
        field('save').addEventListener('click', save);
        field('delete').addEventListener('click', remove);
    }
    function clamp() {
        crop.x = Math.max(0,Math.min(crop.x,source.naturalWidth-crop.size));
        crop.y = Math.max(0,Math.min(crop.y,source.naturalHeight-crop.size));
    }
    function draw() {
        const canvas = field('canvas'), ctx = canvas.getContext('2d'), scale = canvas.width/source.naturalWidth;
        ctx.clearRect(0,0,canvas.width,canvas.height); ctx.drawImage(source,0,0,canvas.width,canvas.height);
        ctx.strokeStyle = '#ffffff'; ctx.lineWidth = 4; ctx.strokeRect(crop.x*scale,crop.y*scale,crop.size*scale,crop.size*scale);
        ctx.strokeStyle = '#2563eb'; ctx.lineWidth = 2; ctx.strokeRect(crop.x*scale,crop.y*scale,crop.size*scale,crop.size*scale);
        const preview = field('preview'), target = preview.getContext('2d');
        target.clearRect(0,0,preview.width,preview.height);
        target.drawImage(source,crop.x,crop.y,crop.size,crop.size,0,0,preview.width,preview.height);
    }
    function loadImage(url) {
        const token = ++serial;
        source = null; field('save').disabled = true;
        const image = new Image();
        image.onload = () => {
            if (token !== serial || !dialog.open) return;
            source = image; field('size').value = '35';
            const scale = Math.min(1,320/image.naturalWidth,320/image.naturalHeight), canvas = field('canvas');
            canvas.width = Math.max(1,Math.floor(image.naturalWidth*scale)); canvas.height = Math.max(1,Math.floor(image.naturalHeight*scale));
            crop = { size: Math.max(1,Math.floor(Math.min(image.naturalWidth,image.naturalHeight)*0.35)), x: 0, y: 0 };
            crop.x = Math.floor((image.naturalWidth-crop.size)/2); crop.y = Math.floor((image.naturalHeight-crop.size)/2);
            field('editor').hidden = false; draw(); lock(false); message('راجع المربع ومعاينة العينة، ثم اضغط حفظ.');
        };
        image.onerror = () => { if (token === serial) message('تعذر تحميل الصورة للقص. أعد فتح المعاينة أو ارفع صورة أخرى.', true); };
        image.src = url;
    }
    function announce(data) {
        update(data);
        document.dispatchEvent(new CustomEvent('fabric-swatch-updated', { detail: { productId: state.productId, colorId: state.colorId, manageUrl: state.url, color: state.color } }));
    }
    async function save() {
        if (!source || busy) return;
        const body = new FormData();
        body.set('mode',mode); body.set('revision',state.color.swatch?.revision || '');
        body.set('x',crop.x); body.set('y',crop.y); body.set('size',crop.size);
        if (mode === 'upload') body.set('image',file);
        else { body.set('source_image_id',state.color.source_image.id); body.set('source_revision',state.color.source_image.revision); }
        lock(true);
        try {
            const data = await request(state.color.save_url,{ method:'POST',body });
            source = null; field('editor').hidden = true; release(); announce(data); message(data.message);
        } catch (error) { message(error.message,true); } finally { lock(false); }
    }
    async function remove() {
        if (busy || !state.color.swatch || !confirm('حذف عينة القماش لهذا المنتج واللون؟ ستعود جميع مقاساته إلى HEX أو المؤشر المحايد. صورة المنتج لن تُحذف.')) return;
        lock(true);
        try {
            const data = await request(state.color.save_url,{ method:'DELETE',headers:{'Content-Type':'application/json'},body:JSON.stringify({revision:state.color.swatch.revision}) });
            source = null; field('editor').hidden = true; announce(data); message(data.message);
        } catch (error) { message(error.message,true); } finally { lock(false); }
    }
    async function open(url,colorId) {
        if (busy || !api.canManage) return;
        createDialog(); release(); state = {url,colorId}; source = null;
        field('editor').hidden = true; field('current').replaceChildren(); message('جارٍ تحميل بيانات اللون...');
        if (!dialog.open) dialog.showModal();
        const token = ++serial; lock(true);
        try {
            const data = await request(url); if (token !== serial) return;
            state.productId = data.product_id; update(data); message('اختر رفع صورة قماش أو القص من صورة هذا اللون.');
        } catch (error) { message(error.message,true); } finally { if (token === serial) lock(false); }
    }
    const api = { open, indicator, canManage:document.querySelector('meta[name="can-manage-catalog"]')?.content === '1', cached:(url,id)=>cache.get(url+':'+id) };
    window.FabricSwatches = api;
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-open-fabric-swatches]');
        if (button) open(button.dataset.openFabricSwatches,button.dataset.colorId);
    });
    document.addEventListener('fabric-swatch-updated', event => {
        document.querySelectorAll('[data-fabric-product][data-fabric-color]').forEach(element => {
            if (String(element.dataset.fabricProduct) !== String(event.detail.productId) || String(element.dataset.fabricColor) !== String(event.detail.colorId)) return;
            element.replaceChildren(indicator(event.detail.color.swatch?.url,event.detail.color.hex,event.detail.color.name));
        });
    });
})();
