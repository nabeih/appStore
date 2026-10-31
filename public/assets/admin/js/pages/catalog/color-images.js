(() => {
    'use strict';
    const element = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    };
    window.ColorImageFields = {
        validate(file, limit = 4 * 1024 * 1024) {
            if (file.size > limit) throw new Error('حجم الصورة يتجاوز الحد المسموح (4 ميجابايت كحد أقصى).');
            if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) && !(file.type === '' && /\.(jpe?g|png|webp)$/i.test(file.name))) {
                throw new Error('اختر صورة بصيغة JPG أو PNG أو WebP.');
            }
        },
        render(container, groups, { onFile, onClear } = {}) {
            container.replaceChildren();
            groups.forEach(group => {
                const card = element('article', 'color-image-card');
                card.dataset.colorImageKey = group.key;
                const preview = element('div', 'color-image-preview');
                if (group.url || group.preview) {
                    const img = element('img'); img.src = group.url || group.preview; img.alt = `${group.name} — ${group.color}`; img.loading = 'lazy';
                    preview.append(img);
                } else {
                    preview.append(element('span', '', 'صورة'));
                }
                const details = element('div', 'color-image-details');
                details.append(element('strong', '', group.name), element('span', 'color-image-color', group.color));
                details.append(element('small', '', group.url ? 'صورة محفوظة لكل مقاسات هذا اللون' : group.note || 'صورة واحدة لكل المقاسات'));
                if (!group.url) {
                    const actions = element('div', 'color-image-actions');
                    const label = element('label', 'color-image-select', group.file ? 'اختيار صورة أخرى' : 'اختيار الصورة');
                    const input = element('input'); input.type = 'file'; input.accept = 'image/jpeg,image/png,image/webp'; input.disabled = !!group.busy;
                    input.setAttribute('aria-label', `صورة ${group.name} — ${group.color}`);
                    input.addEventListener('change', () => { const file = input.files[0]; input.value = ''; if (file) onFile?.(group, file); });
                    label.append(input); actions.append(label);
                    if (group.file && onClear) {
                        const clear = element('button', 'color-image-clear', 'إزالة'); clear.type = 'button'; clear.disabled = !!group.busy;
                        clear.addEventListener('click', () => onClear(group)); actions.append(clear);
                    }
                    details.append(actions);
                    if (group.file) details.append(element('small', 'color-image-filename', group.file.name));
                }
                if (group.busy) details.append(element('small', 'color-image-status', 'جارٍ رفع الصورة...'));
                if (group.error) { const error = element('small', 'color-image-error', group.error); error.setAttribute('role', 'alert'); details.append(error); }
                if (window.FabricSwatches?.canManage) {
                    if (group.swatchManageUrl && group.colorId) {
                        const sample = element('span', 'fabric-current');
                        if (group.productId) { sample.dataset.fabricProduct = group.productId; sample.dataset.fabricColor = group.colorId; }
                        const cached = FabricSwatches.cached(group.swatchManageUrl,group.colorId);
                        sample.append(FabricSwatches.indicator(cached ? cached.swatch?.url : group.swatchUrl, cached ? cached.hex : group.hex,group.color));
                        const manage = element('button','fabric-manage-button','عينة القماش');
                        manage.type = 'button'; manage.addEventListener('click',() => FabricSwatches.open(group.swatchManageUrl,group.colorId));
                        details.append(sample,manage);
                    } else {
                        details.append(element('small','','يمكن إضافة عينة القماش بعد حفظ المنتج؛ لا يلزم رفعها لكل مقاس.'));
                    }
                }
                card.append(preview, details); container.append(card);
            });
        },
    };
})();
