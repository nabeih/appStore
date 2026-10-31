(function () {
    'use strict';
    var dialog = document.getElementById('product-details-dialog');
    var form = document.getElementById('product-details-form');
    if (!dialog || !form) { return; }
    var details = null;
    var feedback = document.getElementById('product-details-feedback');
    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-product-details]');
        if (button) {
            details = JSON.parse(button.dataset.productDetails);
            form.reset();
            feedback.hidden = true;
            form.elements.name.value = details.name;
            form.elements.description.value = details.description || '';
            var variantFields = form.querySelector('[data-product-variant-fields]');
            variantFields.hidden = !details.variant_id;
            form.querySelector('[data-product-editor-hint]').textContent = details.variant_id
                ? 'يتغير الاسم والوصف للمنتج، وسعر البيع للمقاس واللون المحددين. الصورة مشتركة لكل مقاسات اللون نفسه. الفواتير السابقة تحتفظ ببياناتها الأصلية.'
                : 'تعديل الاسم والوصف للمنتج بكل ألوانه ومقاساته. لا تتغير كميات المخزون أو SKU من هذه النافذة.';
            dialog.querySelector('[data-product-variant-warning]').hidden = !details.variant_id;
            variantFields.querySelectorAll('input').forEach(function (input) { input.disabled = !details.variant_id; });
            form.elements.selling_price.required = !!details.variant_id;
            form.elements.selling_price.value = details.price || '';
            document.getElementById('product-fabric-swatch').onclick = function () { window.FabricSwatches.open(details.swatch_manage_url, details.color_id); };
            dialog.showModal();
        }
        if (event.target.closest('[data-close-product-details]')) { dialog.close(); }
    });
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (!details) { return; }
        var data = new FormData();
        data.set('_method', 'PUT');
        data.set('name', form.elements.name.value);
        data.set('description', form.elements.description.value);
        if (details.variant_id) {
            data.set('variants[0][id]', details.variant_id);
            data.set('variants[0][color_id]', details.color_id);
            data.set('variants[0][size_id]', details.size_id);
            data.set('variants[0][selling_price]', form.elements.selling_price.value);
        }
        if (form.elements.image.files[0]) {
            data.set('image', form.elements.image.files[0]);
            data.set('image_color_id', details.color_id);
        }
        form.querySelector('fieldset').disabled = true;
        feedback.hidden = true;
        try {
            var response = await fetch(details.url, {
                method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: data
            });
            var result = await response.json();
            if (!response.ok) {
                throw new Error(result.errors ? Object.values(result.errors).flat().join(' ') : result.message || 'تعذر الحفظ.');
            }
            window.location.reload();
        } catch (error) {
            feedback.textContent = error.message;
            feedback.hidden = false;
            form.querySelector('fieldset').disabled = false;
        }
    });
}());
