(() => {
    'use strict';
    const dialog = document.getElementById('visual-product-search');
    if (!dialog) return;
    const find = selector => dialog.querySelector(selector);
    const video = find('[data-visual-video]'), preview = find('[data-visual-preview]');
    const camera = find('[data-visual-camera]'), capture = find('[data-visual-capture]');
    const file = find('[data-visual-file]'), submit = find('[data-visual-submit]');
    const status = find('[data-visual-status]'), results = find('[data-visual-results]');
    const branch = find('[data-visual-branch]');
    const inputPanel = find('[data-visual-input]'), newPhoto = find('[data-visual-new]');
    let target, stream, selectedImage, previewUrl, request, timer, epoch = 0;

    function stopCamera() {
        stream?.getTracks().forEach(track => track.stop());
        stream = null;
        video.srcObject = null;
        video.hidden = true;
        capture.hidden = true;
    }

    function clearImage() {
        if (previewUrl) URL.revokeObjectURL(previewUrl);
        previewUrl = null;
        selectedImage = null;
        preview.removeAttribute('src');
        preview.hidden = true;
        submit.disabled = true;
    }

    function stop() {
        const wasSearching = !!request;
        epoch++;
        stopCamera();
        request?.abort();
        request = null;
        clearTimeout(timer);
        dialog.removeAttribute('aria-busy');
        submit.disabled = !selectedImage;
        if (wasSearching && dialog.open) status.textContent = 'توقف البحث. يمكنك تأكيد الصورة للمحاولة مجددًا.';
    }

    function sourceBranch() {
        return target?.closest('[data-product-search]')?.querySelector('[data-product-branch]')
            || (target?.id === 'cashier-search' ? document.getElementById('cashier-branch') : null);
    }

    document.addEventListener('click', event => {
        const button = event.target.closest('[data-scan-mode="visual"]');
        if (!button || button.disabled) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        stop();
        clearImage();
        results.replaceChildren();
        inputPanel.hidden = false;
        newPhoto.hidden = true;
        target = document.querySelector(button.closest('[data-search-target]')?.dataset.searchTarget || '#visual-no-target');
        const selectedBranch = sourceBranch()?.value;
        if (selectedBranch && [...branch.options].some(option => option.value === selectedBranch)) branch.value = selectedBranch;
        branch.disabled = target?.id === 'cashier-search';
        status.textContent = 'شغّل الكاميرا أو اختر صورة، ثم راجعها قبل تأكيد البحث.';
        dialog.showModal();
    }, true);

    camera.addEventListener('click', async () => {
        stop();
        clearImage();
        results.replaceChildren();
        const current = epoch;
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
            status.textContent = 'الكاميرا تحتاج HTTPS أو localhost. يمكنك اختيار صورة من المعرض.';
            return;
        }
        try {
            const opened = await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}}, audio:false});
            if (current !== epoch || !dialog.open) {
                opened.getTracks().forEach(track => track.stop());
                return;
            }
            stream = opened;
            video.srcObject = opened;
            video.hidden = false;
            await video.play();
            if (current !== epoch || !dialog.open) return;
            capture.hidden = false;
            status.textContent = 'اجعل القطعة واضحة وكاملة داخل الصورة ثم التقطها.';
        } catch (error) {
            if (current !== epoch) return;
            stopCamera();
            status.textContent = error.name === 'NotAllowedError'
                ? 'رُفض إذن الكاميرا. اختر صورة من المعرض.'
                : 'تعذر تشغيل الكاميرا. اختر صورة من المعرض أو أعد المحاولة.';
        }
    });

    async function prepare(source, width, height, current) {
        if (!width || !height || width * height > 16_000_000) throw new Error('الصورة كبيرة جدًا أو غير جاهزة. الحد 16 مليون بكسل.');
        const scale = Math.min(1, 1200 / Math.max(width, height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(width * scale));
        canvas.height = Math.max(1, Math.round(height * scale));
        try {
            const context = canvas.getContext('2d');
            context.fillStyle = '#fff';
            context.fillRect(0, 0, canvas.width, canvas.height);
            context.drawImage(source, 0, 0, canvas.width, canvas.height);
            const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.85));
            if (!blob || blob.size > 4 * 1024 * 1024) throw new Error('تعذر تجهيز الصورة ضمن حد 4 ميجابايت.');
            if (current !== epoch || !dialog.open) return;
            clearImage();
            results.replaceChildren();
            selectedImage = blob;
            previewUrl = URL.createObjectURL(blob);
            preview.src = previewUrl;
            preview.hidden = false;
            submit.disabled = false;
            status.textContent = 'راجع المعاينة. لم تُرسل الصورة بعد؛ اضغط تأكيد الصورة والبحث لإرسالها إلى التطبيق.';
        } finally {
            canvas.width = 0;
            canvas.height = 0;
        }
    }

    capture.addEventListener('click', async () => {
        const current = epoch;
        try {
            await prepare(video, video.videoWidth, video.videoHeight, current);
            if (current === epoch) stopCamera();
        } catch (error) {
            if (current === epoch) status.textContent = error.message;
        }
    });

    file.addEventListener('change', async () => {
        const image = file.files?.[0];
        file.value = '';
        if (!image) return;
        stop();
        clearImage();
        results.replaceChildren();
        const current = epoch;
        if (!['image/jpeg','image/png','image/webp'].includes(image.type) || image.size > 12 * 1024 * 1024) {
            status.textContent = 'اختر صورة JPG أو PNG أو WebP بحجم لا يزيد عن 12 ميجابايت.';
            return;
        }
        let bitmap;
        try {
            bitmap = await createImageBitmap(image);
            await prepare(bitmap, bitmap.width, bitmap.height, current);
        } catch (error) {
            if (current === epoch) status.textContent = error.message || 'تعذر قراءة الصورة.';
        } finally {
            bitmap?.close();
        }
    });

    const element = (tag, text, className = '') => {
        const node = document.createElement(tag);
        node.textContent = text;
        node.className = className;
        return node;
    };

    function showProducts(products) {
        results.replaceChildren();
        products.forEach((product, index) => {
            const variants = product.variants || [];
            if (!variants.length) return;
            const card = element('article', '', 'card visual-product-result');
            const thumbnail = document.createElement('img');
            thumbnail.alt = variants[0].name;
            thumbnail.loading = 'lazy';
            thumbnail.width = 100;
            thumbnail.height = 100;
            thumbnail.style.objectFit = 'contain';
            if (variants[0].image_url) thumbnail.src = variants[0].image_url;
            const heading = element('strong', (index + 1) + '. ' + variants[0].name);
            const select = document.createElement('select');
            select.setAttribute('aria-label', 'اختر اللون والمقاس');
            variants.forEach((variant, variantIndex) => select.add(new Option(
                variant.color + ' / ' + variant.size + ' — المتاح ' + variant.quantity + ' — ' + variant.branch,
                String(variantIndex)
            )));
            const details = element('p', '');
            const choose = element('button', 'اختيار التركيبة', 'btn btn-outline');
            choose.type = 'button';
            function update() {
                const variant = variants[Number(select.value)];
                details.textContent = 'SKU: ' + variant.sku + (variant.setup_open ? ' — فترة الإعداد مفتوحة؛ اعتماد البيع ممنوع.' : '');
                choose.disabled = !target || (target.id === 'cashier-search' && !variant.sale_url);
                if (variant.image_url) thumbnail.src = variant.image_url;
            }
            choose.addEventListener('click', () => {
                const variant = variants[Number(select.value)];
                if (!target || (target.id === 'cashier-search' && !variant.sale_url)) return;
                const source = sourceBranch();
                if (target.id === 'cashier-search' && source?.value !== String(variant.branch_id)) {
                    status.textContent = 'تغير فرع الكاشير؛ أعد البحث في الفرع الحالي.';
                    return;
                }
                if (source && source.value !== String(variant.branch_id)) {
                    source.value = String(variant.branch_id);
                    source.dispatchEvent(new Event('change', {bubbles:true}));
                }
                target.value = variant.sku;
                target.dispatchEvent(new CustomEvent('product-search:sku', {bubbles:true}));
                dialog.close();
                target.focus();
            });
            select.addEventListener('change', update);
            update();
            card.append(thumbnail, heading, element('p', 'ترتيب بالتشابه البصري؛ راجع المنتج واللون والمقاس.'), select, details, choose);
            results.append(card);
        });
    }

    submit.addEventListener('click', async () => {
        if (!selectedImage || !branch.value || request) return;
        stopCamera();
        const current = epoch;
        const controller = new AbortController();
        request = controller;
        const payload = new FormData();
        payload.append('image', selectedImage, 'visual-search.jpg');
        payload.append('branch_id', branch.value);
        results.replaceChildren();
        submit.disabled = true;
        dialog.setAttribute('aria-busy', 'true');
        status.textContent = 'جارٍ البحث محليًا عن أقرب المنتجات…';
        timer = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(dialog.dataset.url, {
                method:'POST', body:payload, signal:controller.signal, cache:'no-store',
                headers:{Accept:'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}
            });
            const body = await response.json();
            if (current !== epoch || !dialog.open) return;
            if (!response.ok) throw new Error(response.status === 503 ? 'خدمة البحث مشغولة أو غير متصلة. أعد المحاولة بعد قليل.' : body.message || 'تعذر البحث بالصورة.');
            showProducts(Array.isArray(body.data) ? body.data : []);
            inputPanel.hidden = !!body.data?.length;
            newPhoto.hidden = !body.data?.length;
            status.textContent = body.data?.length ? 'هذه أقرب المنتجات المفهرسة. اختر اللون والمقاس المناسبين.' : 'لا توجد منتجات مفهرسة ضمن الفرع والصلاحيات الحالية.';
        } catch (error) {
            if (current === epoch && dialog.open) status.textContent = error.name === 'AbortError' ? 'انتهت مهلة البحث. أعد المحاولة بعد قليل.' : error.message;
        } finally {
            if (current === epoch) {
                clearTimeout(timer);
                request = null;
                submit.disabled = !selectedImage;
                dialog.removeAttribute('aria-busy');
            }
        }
    });

    branch.addEventListener('change', () => {stop();results.replaceChildren();inputPanel.hidden=false;newPhoto.hidden=true;submit.disabled=!selectedImage;status.textContent = 'تغير الفرع؛ أكّد البحث مجددًا للصورة المختارة.';});
    newPhoto.addEventListener('click', () => {stop();clearImage();results.replaceChildren();inputPanel.hidden=false;newPhoto.hidden=true;status.textContent='شغّل الكاميرا أو اختر صورة جديدة.';});
    find('[data-visual-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {stop();clearImage();results.replaceChildren();});
    dialog.addEventListener('cancel', stop);
    window.addEventListener('pagehide', () => {stop();clearImage();});
    document.addEventListener('visibilitychange', () => {if (document.hidden) stop();});
})();
