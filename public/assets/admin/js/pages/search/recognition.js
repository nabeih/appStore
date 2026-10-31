(() => {
    'use strict';
    const scripts = new Map();
    const abort = () => new DOMException('Cancelled', 'AbortError');
    function localUrl(value) {
        const url = new URL(value, location.href);
        if (url.origin !== location.origin) throw new Error('Recognition assets must be hosted locally.');
        return url.href;
    }
    function load(source) {
        const url = localUrl(source);
        if (!scripts.has(url)) scripts.set(url, new Promise((resolve,reject) => {
            const script = document.createElement('script'); script.src = url; script.async = true;
            script.onload = resolve;
            script.onerror = () => {scripts.delete(url);script.remove();reject(new Error('Local recognition library could not load.'));};
            document.head.append(script);
        }));
        return scripts.get(url);
    }
    function canvasFrom(source, maximum = 1600) {
        const width = source.videoWidth || source.naturalWidth || source.width;
        const height = source.videoHeight || source.naturalHeight || source.height;
        if (!width || !height) throw new Error('No image frame is available.');
        const ratio = Math.min(1,maximum/Math.max(width,height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1,Math.round(width*ratio)); canvas.height = Math.max(1,Math.round(height*ratio));
        const context = canvas.getContext('2d', {willReadFrequently:true});
        context.fillStyle = 'white'; context.fillRect(0,0,canvas.width,canvas.height); context.drawImage(source,0,0,canvas.width,canvas.height);
        return canvas;
    }
    async function imageCanvas(file) {
        if (!['image/jpeg','image/png','image/webp'].includes(file.type) || file.size > 8*1024*1024) throw new Error('اختر JPG أو PNG أو WebP بحجم لا يزيد عن 8 ميجابايت.');
        const bitmap = await createImageBitmap(file);
        try {
            if (bitmap.width*bitmap.height > 24_000_000) throw new Error('الصورة كبيرة جدًا. التقط الملصق وحده أو صغّر الصورة.');
            return canvasFrom(bitmap);
        } finally {bitmap.close();}
    }
    async function barcodeReader(script) {
        if (window.BarcodeDetector) {
            try {
                if ((await BarcodeDetector.getSupportedFormats()).includes('code_128')) {
                    const detector = new BarcodeDetector({formats:['code_128']});
                    return {kind:'native', async read(source){return (await detector.detect(source))[0]?.rawValue || null;}};
                }
            } catch (error) { /* Use the local decoder when native initialization fails. */ }
        }
        await load(script);
        const possibleFormatsHint = 2;
        const hints = new Map([[possibleFormatsHint,[window.ZXingBrowser.BarcodeFormat.CODE_128]]]);
        const reader = new window.ZXingBrowser.BrowserMultiFormatOneDReader(hints);
        return {kind:'zxing', async read(source) {
            const canvas = source instanceof HTMLCanvasElement ? source : canvasFrom(source,1280);
            try {return reader.decodeFromCanvas(canvas).getText();}
            catch (error) {return null;}
            finally {if(canvas !== source){canvas.width=0;canvas.height=0;}}
        }};
    }
    function ocr(canvas, base, progress = () => {}) {
        let cancelled = false, worker, rejectCancellation, timer;
        const cancellation = new Promise((resolve,reject) => {rejectCancellation=reject;});
        const origin = localUrl(base).replace(/\/$/,'');
        const work = (async () => {
            await load(origin+'/tesseract.min.js');
            if(cancelled) throw abort();
            const ready = window.Tesseract.createWorker('eng',1,{
                workerPath:origin+'/worker.min.js', corePath:origin+'/core', langPath:origin+'/lang',
                workerBlobURL:false, gzip:false, cacheMethod:'none',
                logger:message => {if(!cancelled)progress(message.progress || 0, message.status || '');},
                errorHandler:() => {cancelled=true;rejectCancellation(new Error('Local OCR failed.'));},
            });
            ready.then(instance => {worker=instance;if(cancelled)instance.terminate().catch(()=>{});},()=>{});
            worker = await ready;
            if(cancelled) throw abort();
            await worker.setParameters({tessedit_pageseg_mode:11, tessedit_char_whitelist:'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_. /'});
            if(cancelled) throw abort();
            const result = await worker.recognize(canvas);
            if(cancelled) throw abort();
            return result.data.text;
        })();
        timer=setTimeout(()=>{cancelled=true;rejectCancellation(new Error('Local OCR timed out.'));},45000);
        const promise=Promise.race([work,cancellation]).finally(async()=>{clearTimeout(timer);if(worker){try{await worker.terminate();}catch(error){}}});
        return {promise,cancel(){cancelled=true;rejectCancellation(abort());if(worker)Promise.resolve(worker.terminate()).catch(()=>{});}};
    }
    window.LocalSkuRecognition = {barcodeReader,ocr,canvasFrom,imageCanvas};
})();
