(() => {
    'use strict';
    const bound = new WeakSet(), requests = new Set();
    const normalize = value => String(value).trim().replace(/[٠-٩۰-۹]/g, digit => '٠١٢٣٤٥٦٧٨٩'.includes(digit) ? String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)) : String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit))).replace(/\s+/g, ' ');
    const element = (tag, text, className = '') => { const node = document.createElement(tag); node.textContent = text; node.className = className; return node; };
    function link(text, target) {
        const url = new URL(target, location.href);
        if (url.origin !== location.origin) return element('span', text);
        const anchor = element('a', text); anchor.href = url.href; return anchor;
    }
    function init() {
        document.querySelectorAll('[data-product-search]').forEach(root => {
            if (bound.has(root)) return;
            bound.add(root);
            const form = root.querySelector('form'), input = root.querySelector('[data-product-query]');
            const branch = root.querySelector('[data-product-branch]'), status = root.querySelector('[data-product-status]');
            const list = root.querySelector('[data-product-results]'), more = root.querySelector('[data-product-more]');
            let controller, timer, version = 0, nextPage = 1, scanned = false;
            function render(item) {
                const card = element('article', '', 'product-search-result');
                if (item.image_url) { const image = document.createElement('img'); image.src = item.image_url; image.alt = item.name; image.loading = 'lazy'; image.decoding = 'async'; card.append(image); }
                const body = element('div', ''); body.append(element('strong', item.name), element('small', item.color+' / '+item.size+' — '+item.branch), element('small', 'SKU: '+item.sku), element('small', 'كود المنتج: '+item.code+' — المتاح: '+item.quantity));
                if (Object.hasOwn(item, 'average_cost')) body.append(element('small', 'متوسط التكلفة: '+(item.average_cost ?? 'غير معتمد')));
                if (item.setup_open) body.append(element('small', 'فترة الإعداد مفتوحة؛ اعتماد البيع ممنوع.'));
                const actions = element('footer', ''); actions.append(link('عرض المخزون', item.inventory_url));
                if (item.sale_url) actions.append(link('اختيار للكاشير', item.sale_url));
                if (item.barcode_supported) actions.append(link('ملصق SKU', item.label_url));
                else actions.append(element('span', 'SKU غير متوافق مع Code 128', 'product-search-error'));
                body.append(actions); card.append(body); list.append(card);
            }
            async function run(page = 1, exactScan = false) {
                clearTimeout(timer); controller?.abort();
                const current = ++version, request = new AbortController(); controller = request; requests.add(request);
                const term = normalize(input.value), selectedBranch = branch.value;
                if (page === 1) { list.replaceChildren(); scanned = exactScan; }
                more.hidden = true; status.textContent = 'جارٍ البحث…'; root.setAttribute('aria-busy', 'true');
                const url = new URL(root.dataset.url, location.href); url.search = new URLSearchParams({ q: term, branch_id: selectedBranch, page, ...(scanned ? {match:'exact'} : {}) });
                try {
                    const response = await fetch(url, { signal: request.signal, headers: { Accept:'application/json' }, cache:'no-store' });
                    if (!response.ok || response.redirected) throw new Error(response.status === 429 ? 'طلبات كثيرة؛ انتظر قليلًا ثم أعد المحاولة.' : response.status === 403 ? 'صلاحيات البحث أو الفرع غير متاحة.' : 'تعذر البحث. تحقق من الاتصال وتسجيل الدخول.');
                    const result = await response.json();
                    if (current !== version || !root.isConnected || term !== normalize(input.value) || branch.value !== selectedBranch) return;
                    let items = result.data;
                    const exact = items.filter(item => normalize(item.sku).toUpperCase() === term.toUpperCase());
                    if (scanned || (page === 1 && exact.length)) items = exact;
                    items.forEach(render);
                    nextPage = result.current_page + 1;
                    more.hidden = !result.next_page_url || (exact.length > 0 && !scanned);
                    status.textContent = items.length ? 'اختر التركيبة والإجراء المناسب. الكمية تُراجع مجددًا عند اعتماد البيع.' : 'لا توجد تركيبة مطابقة ضمن الفروع المسموحة.';
                } catch (error) { if (error.name !== 'AbortError' && current === version) status.textContent = error.message; }
                finally { requests.delete(request); if (current === version) root.removeAttribute('aria-busy'); }
            }
            form.addEventListener('submit', event => { event.preventDefault(); run(); });
            input.addEventListener('input', () => { clearTimeout(timer); controller?.abort(); version++; timer = setTimeout(() => run(), 300); });
            input.addEventListener('product-search:sku', () => run(1, true));
            branch.addEventListener('change', () => run());
            more.addEventListener('click', () => run(nextPage, scanned));
            if (input.value.trim()) run();
        });
    }
    init(); document.addEventListener('module-search:updated', init);
    window.addEventListener('pagehide', () => requests.forEach(request => request.abort()));
    const dialog = document.getElementById('product-scanner');
    if (!dialog) return;
    const recognition = window.LocalSkuRecognition;
    const video = dialog.querySelector('video'), value = dialog.querySelector('[data-scanner-value]');
    const status = dialog.querySelector('[data-scanner-status]'), start = dialog.querySelector('[data-scanner-start]');
    const capture = dialog.querySelector('[data-scanner-capture]'), file = dialog.querySelector('[data-scanner-file]');
    const rawText = dialog.querySelector('[data-ocr-text]'), choices = dialog.querySelector('[data-ocr-choices]');
    let target, mode, stream, epoch = 0, frameTimer, ocrTask, matching;
    function stopCamera() {
        clearTimeout(frameTimer); stream?.getTracks().forEach(track => track.stop());
        stream = null; video.srcObject = null; video.hidden = true; capture.hidden = true;
    }
    function stop() { epoch++; stopCamera(); ocrTask?.cancel(); ocrTask = null; matching?.abort(); matching = null; }
    function message(error) {
        return error?.name === 'NotAllowedError' ? 'رُفض إذن الكاميرا. اختر صورة الملصق أو أدخل SKU يدويًا.' : error?.name === 'NotFoundError' ? 'لا توجد كاميرا متاحة. اختر صورة أو استخدم الإدخال اليدوي.' : 'تعذرت العملية. تحقق من HTTPS أو localhost والملفات المحلية، أو أدخل الكود يدويًا.';
    }
    function branchId() {
        return target?.closest('[data-product-search]')?.querySelector('[data-product-branch]')?.value || document.getElementById('cashier-branch')?.value || '';
    }
    async function matchCodes(current = epoch) {
        const originalText = rawText.value;
        const lines = originalText.split(/[\r\n]+/).map(normalize);
        const tokens = normalize(originalText).split(/\s+/).map(token => token.replace(/^[,:;]+|[,:;]+$/g,''));
        const codes = [...new Set([...lines,...tokens].filter(code => code && code.length <= 100))].slice(0,12);
        if (!codes.length) {status.textContent='راجع النص أو أدخل SKU يدويًا.';return;}
        value.value='';choices.replaceChildren();
        matching?.abort(); const request = new AbortController(); matching=request;
        status.textContent = 'جارٍ مطابقة النص مع الأكواد المسموحة…';
        try {
            const response = await fetch(dialog.dataset.codesUrl,{method:'POST',signal:request.signal,cache:'no-store',
                headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},
                body:JSON.stringify({codes,branch_id:branchId() || null})});
            if(!response.ok || response.redirected)throw new Error('تعذرت مطابقة الأكواد. راجع تسجيل الدخول أو أدخل SKU يدويًا.');
            const result=await response.json();
            if(current !== epoch || !dialog.open || originalText !== rawText.value)return;
            const matches=[...new Map(result.data.map(item=>[item.sku,item])).values()];
            choices.replaceChildren();
            for(const item of matches){
                const button=element('button',item.sku+' — '+item.name+' / '+item.color+' / '+item.size,'btn btn-outline btn-sm');button.type='button';
                button.addEventListener('click',()=>{value.value=item.sku;status.textContent='تم اختيار الكود. راجعه ثم اضغط بحث بالكود.';});choices.append(button);
            }
            if(matches.length===1){if(!value.value)value.value=matches[0].sku;status.textContent='تمت مطابقة SKU واحد. راجعه ثم اضغط بحث بالكود.';}
            else if(matches.length>1){value.value='';status.textContent='القراءة ملتبسة: اختر أحد الأكواد المطابقة أو صححه يدويًا. لم يُختر أي صنف تلقائيًا.';}
            else{status.textContent='لم يُعثر على SKU مطابق. صحح النص أو اكتب الكود يدويًا؛ لا نستبدل O بـ0 أو نخمن الكود.';}
        }catch(error){if(error.name!=='AbortError' && current===epoch)status.textContent='تعذرت مطابقة الأكواد. تحقق من الاتصال والصلاحيات أو أدخل SKU يدويًا.';}
    }
    async function readOcr(canvas) {
        stop(); const current=epoch; value.value='';rawText.value='';choices.replaceChildren();
        status.textContent='جارٍ قراءة الملصق محليًا…';dialog.setAttribute('aria-busy','true');
        const task=recognition.ocr(canvas,dialog.dataset.ocrBase,progress=>{if(current===epoch)status.textContent='جارٍ قراءة الملصق محليًا… '+Math.round(progress*100)+'٪';});
        ocrTask=task;
        try{
            const text=await task.promise;
            if(current!==epoch || !dialog.open)return;
            rawText.value=text.trim().slice(0,1200);
            if(!rawText.value){status.textContent='لم يظهر نص واضح. التقط الملصق بإضاءة أفضل أو أدخل الكود يدويًا.';return;}
            await matchCodes(current);
        }catch(error){if(error.name!=='AbortError' && current===epoch)status.textContent='تعذرت قراءة النص محليًا. جرّب صورة أوضح أو أدخل SKU يدويًا.';}
        finally{canvas.width=0;canvas.height=0;if(current===epoch){ocrTask=null;dialog.removeAttribute('aria-busy');}}
    }
    function found(sku) {
        if(!sku || sku.length>100){status.textContent='لم يُقرأ SKU صالح. أعد المحاولة أو أدخله يدويًا.';return;}
        stop();value.value=sku;status.textContent='تمت قراءة Code 128. راجع SKU ثم اضغط بحث بالكود.';
    }
    document.addEventListener('click',event=>{
        const button=event.target.closest('[data-scan-mode]');if(!button)return;
        stop();target=document.querySelector(button.closest('[data-search-target]').dataset.searchTarget);mode=button.dataset.scanMode;
        dialog.querySelector('[data-scanner-title]').textContent=mode==='barcode'?'مسح باركود SKU':mode==='ocr'?'قراءة SKU المكتوب':'البحث بصورة الملابس';
        value.value='';value.disabled=mode==='visual';file.value='';rawText.value='';choices.replaceChildren();dialog.removeAttribute('aria-busy');
        dialog.querySelector('[data-scanner-accept]').disabled=mode==='visual';
        dialog.querySelector('[data-scanner-local-tools]').hidden=mode==='visual';
        dialog.querySelector('[data-ocr-review]').hidden=mode!=='ocr';start.hidden=mode==='visual';
        status.textContent=mode==='barcode'?'شغّل الكاميرا أو اختر صورة الباركود. المسح محلي ويستخدم Code 128 فقط.':mode==='ocr'?'التقط الملصق أو اختر صورته لقراءة SKU محليًا. راجع النص والكود قبل البحث.':'البحث البصري غير مفعّل؛ لا يوجد مزود AI محلي معتمد. لم تُرسل أي صورة لخدمة خارجية.';
        dialog.showModal();
    });
    start.addEventListener('click',async()=>{
        if(mode==='visual')return;stop();const current=epoch;
        if(!window.isSecureContext || !navigator.mediaDevices?.getUserMedia){status.textContent='الكاميرا تحتاج HTTPS أو localhost ومتصفحًا يدعمها. يمكنك اختيار صورة الملصق أو إدخال الكود.';return;}
        try{
            const reader=mode==='barcode'?await recognition.barcodeReader(dialog.dataset.zxingSrc):null;
            if(current!==epoch || !dialog.open)return;
            const opened=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}},audio:false});
            if(current!==epoch || !dialog.open){opened.getTracks().forEach(track=>track.stop());return;}
            stream=opened;video.srcObject=opened;video.hidden=false;await video.play();
            if(current!==epoch || !dialog.open)return;
            if(mode==='ocr'){capture.hidden=false;status.textContent='قرّب الملصق واجعل النص واضحًا ثم اضغط التقاط الملصق.';return;}
            status.textContent=reader.kind==='native'?'وجّه الكاميرا إلى الباركود.':'وجّه الكاميرا إلى الباركود — المسح المحلي البديل جاهز.';
            async function scan(){
                if(current!==epoch || !dialog.open)return;
                try{const code=await reader.read(video);if(current!==epoch || !dialog.open)return;if(code){found(code);return;}}
                catch(error){if(current===epoch)status.textContent='لم يُقرأ باركود واضح بعد. أعد توجيه الكاميرا أو اختر صورة.';}
                if(current===epoch)frameTimer=setTimeout(scan,reader.kind==='native'?180:300);
            }scan();
        }catch(error){if(current===epoch){stop();status.textContent=message(error);}}
    });
    capture.addEventListener('click',()=>{
        if(mode!=='ocr' || !video.srcObject)return;
        try{readOcr(recognition.canvasFrom(video));}catch(error){status.textContent='الصورة غير جاهزة. انتظر لحظة أو اختر صورة للملصق.';}
    });
    file.addEventListener('change',async()=>{
        const selected=file.files?.[0];if(!selected || mode==='visual')return;
        stop();const current=epoch;let canvas;
        try{
            canvas=await recognition.imageCanvas(selected);file.value='';
            if(current!==epoch || !dialog.open){canvas.width=0;return;}
            if(mode==='ocr'){await readOcr(canvas);return;}
            const reader=await recognition.barcodeReader(dialog.dataset.zxingSrc);
            if(current!==epoch || !dialog.open)return;
            const code=await reader.read(canvas);
            if(current===epoch && dialog.open)found(code);
        }catch(error){if(current===epoch)status.textContent=error.message.startsWith('اختر')||error.message.startsWith('الصورة')?error.message:'تعذرت قراءة الصورة. استخدم صورة أوضح أو أدخل SKU يدويًا.';}
        finally{if(canvas){canvas.width=0;canvas.height=0;}file.value='';}
    });
    dialog.querySelector('[data-ocr-match]').addEventListener('click',()=>matchCodes());
    dialog.querySelector('[data-scanner-close]').addEventListener('click',()=>dialog.close());
    dialog.querySelector('[data-scanner-accept]').addEventListener('click',()=>{
        const sku=normalize(value.value);if(!sku || !target || mode==='visual'){status.textContent='أدخل SKU صحيحًا أولًا.';return;}
        stop();dialog.close();target.value=sku;target.dispatchEvent(new CustomEvent('product-search:sku',{bubbles:true}));target.focus();
    });
    dialog.addEventListener('close',stop);dialog.addEventListener('cancel',stop);window.addEventListener('pagehide',stop);
    document.addEventListener('visibilitychange',()=>{if(document.hidden)stop();});
})();
