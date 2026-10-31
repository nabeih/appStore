const assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const fs = require('node:fs/promises');
const http = require('node:http');
const path = require('node:path');

async function main() {
    const workspace = path.resolve(__dirname, '../..');
    const fixture = path.join(workspace, 'storage/app/reports/product-search-preview');
    const reports = path.join(workspace, 'storage/app/reports');
    const testing = path.join(workspace, 'storage/framework/testing');
    const profile = path.join(testing, 'search-chrome-'+Date.now());
    const products = JSON.parse(await fs.readFile(path.join(fixture, 'products.json'), 'utf8'));
    const sku = products.data[0].sku;
    let origin, searches = 0;
    const requests = [];
    const server = http.createServer(async (request, response) => {
        try {
            const url = new URL(request.url, origin);
            requests.push({path:url.pathname,method:request.method});
            if(url.pathname === '/search/products/codes'){
                let body='';for await(const chunk of request)body+=chunk;
                const payload=JSON.parse(body);
                assert.equal(Object.hasOwn(payload,'image'),false);
                assert.equal(body.includes('data:image'),false);
                const codes=(payload.codes || []).map(code=>String(code).toUpperCase());
                response.setHeader('Content-Type','application/json');
                response.end(JSON.stringify({data:products.data.filter(item=>codes.includes(item.sku.toUpperCase()))}).replaceAll('http://localhost:8000',origin));return;
            }
            if (url.pathname === '/search/products') {
                searches++;
                response.setHeader('Content-Type', 'application/json');
                response.end(JSON.stringify(products).replaceAll('http://localhost:8000', origin));
                return;
            }
            const name = url.pathname === '/search' ? path.join(fixture, 'search.html') : url.pathname === '/search/products/labels' ? path.join(fixture, 'labels.html') : path.resolve(workspace, 'public', '.'+url.pathname);
            if (!name.startsWith(path.join(workspace, 'public/assets')+path.sep) && name !== path.join(fixture, 'search.html') && name !== path.join(fixture, 'labels.html')) {
                response.writeHead(404); response.end(); return;
            }
            const binary = name.endsWith('.gz') || name.endsWith('.traineddata');
            const type = name.endsWith('.css') ? 'text/css' : name.endsWith('.js') ? 'text/javascript' : binary ? 'application/octet-stream' : 'text/html';
            response.setHeader('Content-Type', type+'; charset=utf-8');
            response.end(binary ? await fs.readFile(name) : (await fs.readFile(name, 'utf8')).replaceAll('http://localhost:8000', origin));
        } catch (error) { response.writeHead(404); response.end(); }
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = 'http://127.0.0.1:'+server.address().port;
    await fs.mkdir(profile, {recursive:true});
    const browser = spawn('C:/Program Files/Google/Chrome/Application/chrome.exe', ['--headless=new', '--remote-debugging-port=0', '--user-data-dir='+profile, '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1', '--disable-background-networking', '--disable-component-update', '--disable-sync', '--no-first-run', '--no-default-browser-check', '--disable-extensions', 'about:blank'], {windowsHide:true, stdio:['ignore','ignore','pipe']});
    let socket;
    try {
        const endpoint = await new Promise((resolve,reject) => {
            const timeout = setTimeout(() => reject(new Error('Chrome startup timed out')), 15000);
            browser.stderr.on('data', data => { const match = String(data).match(/DevTools listening on (ws:\/\/[^\s]+)/); if (match) {clearTimeout(timeout);resolve(match[1]);} });
            browser.once('error', reject);
        });
        const port = new URL(endpoint).port;
        const pages = await (await fetch('http://127.0.0.1:'+port+'/json/list')).json();
        socket = new WebSocket(pages.find(page => page.type === 'page').webSocketDebuggerUrl);
        await new Promise((resolve,reject) => {socket.addEventListener('open',resolve,{once:true});socket.addEventListener('error',reject,{once:true});});
        let id = 0;
        const pending = new Map(), errors = [], externalRequests = [];
        function send(method,params={}) { return new Promise((resolve,reject) => {const key=++id;pending.set(key,{resolve,reject});socket.send(JSON.stringify({id:key,method,params}));}); }
        socket.addEventListener('message', event => {
            const data = JSON.parse(String(event.data));
            if (data.id) {const operation=pending.get(data.id);if(operation){pending.delete(data.id);data.error?operation.reject(new Error(data.error.message)):operation.resolve(data.result);} }
            if (data.method === 'Runtime.exceptionThrown') errors.push(data.params.exceptionDetails.text);
            if (data.method === 'Fetch.requestPaused') {
                const allowed = data.params.request.url.startsWith(origin+'/');
                if(!allowed)externalRequests.push(data.params.request.url);
                send(allowed?'Fetch.continueRequest':'Fetch.failRequest', {requestId:data.params.requestId,...(allowed?{}:{errorReason:'BlockedByClient'})}).catch(()=>{});
            }
        });
        await send('Runtime.enable'); await send('Page.enable');
        await send('Fetch.enable', {patterns:[{urlPattern:'http*'}]});
        await send('Emulation.setDeviceMetricsOverride', {width:390,height:844,deviceScaleFactor:1,mobile:true});
        async function evaluate(expression) {
            const result = await send('Runtime.evaluate', {expression,awaitPromise:true,returnByValue:true});
            if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
            return result.result.value;
        }
        async function until(expression) {
            const start = Date.now();
            while (Date.now()-start<45000) {if(await evaluate(expression))return;await new Promise(resolve=>setTimeout(resolve,60));}
            const diagnostic=await evaluate('({status:document.querySelector("[data-scanner-status]")?.textContent,ocrError:window.__ocrError,ocrPhase:window.__ocrPhase,workerMessages:window.__workerMessages})');
            throw new Error('UI condition timed out: '+expression+' '+JSON.stringify({diagnostic,requests:requests.slice(-12)}));
        }
        await send('Page.navigate',{url:origin+'/search?type=products'});
        await until('document.readyState === "complete" && !!document.querySelector("[data-product-search]")');
        const result = {viewport:390, external_requests_blocked:true};
        result.native_code128_available = await evaluate('(async () => !!window.BarcodeDetector && (await BarcodeDetector.getSupportedFormats()).includes("code_128"))()');
        result.horizontal_overflow = await evaluate('document.documentElement.scrollWidth > innerWidth');
        assert.equal(result.horizontal_overflow,false);
        const before = searches;
        await evaluate(`(() => {const input=document.querySelector('[data-product-query]'); input.value='';for(const char of ${JSON.stringify(sku)}){input.value+=char;input.dispatchEvent(new Event('input',{bubbles:true}));}input.closest('form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));})()`);
        await until('document.querySelectorAll(".product-search-result").length > 0');
        await new Promise(resolve=>setTimeout(resolve,500));
        assert.equal(searches-before,1); result.wedge_request_deduplicated=true;
        assert.equal(await evaluate('getComputedStyle(document.querySelector("[data-product-more]")).display'), 'none');
        assert.equal(await evaluate(`document.querySelector('[data-product-results]').textContent.includes(${JSON.stringify(sku)})`),true);
        assert.equal(requests.some(request=>request.path.includes('/vendor/search/')),false);result.recognition_lazy_loading=true;
        const externalBeforeRecognition=externalRequests.length;
        await evaluate(`document.querySelector('[data-scan-mode="barcode"]').click();window.__realDetector=window.BarcodeDetector;Object.defineProperty(window,'BarcodeDetector',{configurable:true,writable:true,value:undefined});Object.defineProperty(navigator,'mediaDevices',{configurable:true,value:{getUserMedia:async()=>{throw new DOMException('Denied','NotAllowedError')}}});document.querySelector('[data-scanner-start]').click();`);
        await until('document.querySelector("[data-scanner-status]").textContent.includes("رُفض")'); result.permission_denied_message=true;
        assert.equal(await evaluate('!!window.ZXingBrowser'),true);result.zxing_local_fallback_loaded=true;
        result.code128_decoded_value=await evaluate(`(async()=>{const html=await(await fetch('/search/products/labels')).text();const documentLabel=new DOMParser().parseFromString(html,'text/html');const svg=documentLabel.querySelector('.product-label svg');svg.setAttribute('width','1800');svg.setAttribute('height','300');const blob=new Blob([svg.outerHTML],{type:'image/svg+xml'});const url=URL.createObjectURL(blob);try{const img=new Image();img.src=url;await img.decode();const canvas=document.createElement('canvas');canvas.width=1800;canvas.height=300;canvas.getContext('2d').drawImage(img,0,0,1800,300);const reader=await LocalSkuRecognition.barcodeReader(document.getElementById('product-scanner').dataset.zxingSrc);const code=await reader.read(canvas);canvas.width=0;return code;}finally{URL.revokeObjectURL(url)}})()`);
        assert.equal(result.code128_decoded_value,sku);result.code128_roundtrip_real_decoder=true;
        await evaluate(`window.__stopped=0;window.__testSku=${JSON.stringify(sku)};HTMLMediaElement.prototype.play=async()=>{};window.BarcodeDetector=class{static async getSupportedFormats(){return ['code_128'];}async detect(){return [{rawValue:window.__testSku}]}};navigator.mediaDevices.getUserMedia=async()=>{const c=document.createElement('canvas');c.width=100;c.height=100;window.__canvas=c;const stream=c.captureStream(1);for(const track of stream.getTracks()){const stop=track.stop.bind(track);track.stop=()=>{window.__stopped++;stop()}}return stream};document.querySelector('[data-scanner-start]').click();`);
        await until('document.querySelector("[data-scanner-value]").value === window.__testSku && window.__stopped > 0');
        result.success_stops_camera_tracks=true;
        await evaluate(`document.querySelector('[data-scanner-accept]').click()`);
        await until('!document.getElementById("product-scanner").open && document.querySelectorAll(".product-search-result").length > 0');
        result.confirmed_sku_search=true;
        await evaluate(`document.querySelector('[data-scan-mode="barcode"]').click();window.BarcodeDetector=class{static async getSupportedFormats(){return ['code_128'];}async detect(){return []}};document.querySelector('[data-scanner-start]').click()`);
        await until('!!document.querySelector("[data-scanner-video]").srcObject');
        const stops = await evaluate('window.__stopped');
        await evaluate('document.querySelector("[data-scanner-close]").click()');
        await until('window.__stopped > '+stops+' && !document.querySelector("[data-scanner-video]").srcObject');
        result.close_stops_camera_tracks=true;
        await evaluate('document.querySelector("[data-scan-mode=ocr]").click()');
        assert.equal(requests.some(request=>request.path.endsWith('/ocr/tesseract.min.js')),false);
        const ocrStart=Date.now();
        await evaluate(`window.__workerMessages=[];const OriginalWorker=window.Worker;window.Worker=class extends OriginalWorker{constructor(...args){super(...args);this.addEventListener('message',event=>window.__workerMessages.push({action:event.data.action,status:event.data.status,data:typeof event.data.data==='string'?event.data.data:event.data.data?.status}));this.addEventListener('error',event=>window.__workerMessages.push({error:event.message}));}postMessage(message,...args){window.__workerMessages.push({send:message.action,options:message.payload?.options,langs:typeof message.payload?.langs==='string'?message.payload.langs:message.payload?.langs?.map(item=>item.code)});return super.postMessage(message,...args)}};`);
        await evaluate(`window.__ocrError=null;const originalOcr=LocalSkuRecognition.ocr;LocalSkuRecognition.ocr=(...args)=>{const progress=args[2];args[2]=(p,s)=>{window.__ocrPhase=s;progress?.(p,s)};const task=originalOcr(...args);task.promise.catch(error=>{window.__ocrError=String(error)});return task;};`);
        await evaluate(`(async()=>{const canvas=document.createElement('canvas');canvas.width=2000;canvas.height=350;const ctx=canvas.getContext('2d');ctx.fillStyle='white';ctx.fillRect(0,0,2000,350);ctx.fillStyle='black';ctx.font='bold 120px Arial';ctx.fillText(${JSON.stringify(sku)},70,220);const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/png'));const transfer=new DataTransfer();transfer.items.add(new File([blob],'clear-label.png',{type:'image/png'}));const input=document.querySelector('[data-scanner-file]');input.files=transfer.files;input.dispatchEvent(new Event('change',{bubbles:true}));canvas.width=0;})()`);
        await until('document.querySelector("[data-ocr-text]").value.length > 0 && !document.getElementById("product-scanner").hasAttribute("aria-busy")');
        result.ocr_elapsed_ms=Date.now()-ocrStart;
        result.ocr_text=await evaluate('document.querySelector("[data-ocr-text]").value.trim()');
        assert.equal(result.ocr_text,sku);
        assert.equal(await evaluate('document.querySelector("[data-scanner-value]").value'),sku);
        result.ocr_real_local_worker=true;
        const secondSku=products.data[1].sku;
        await evaluate(`document.querySelector('[data-ocr-text]').value=${JSON.stringify(sku+'\n'+products.data[1].sku)};document.querySelector('[data-ocr-match]').click()`);
        await until('document.querySelectorAll("[data-ocr-choices] button").length === 2');
        assert.equal(await evaluate('document.querySelector("[data-scanner-value]").value'),'');result.ambiguous_ocr_requires_selection=true;
        await evaluate('document.querySelectorAll("[data-ocr-choices] button")[1].click()');
        assert.equal(await evaluate('document.querySelector("[data-scanner-value]").value'),secondSku);
        await evaluate('document.querySelector("[data-ocr-text]").value="NO-SUCH-SKU";document.querySelector("[data-ocr-match]").click()');
        await until('document.querySelector("[data-scanner-status]").textContent.includes("لم يُعثر")');
        assert.equal(await evaluate('document.querySelector("[data-scanner-value]").value'),'');result.ocr_manual_correction=true;
        result.ocr_cancel_during_initialization=await evaluate(`(async()=>{const original=Tesseract.createWorker;let resolveWorker,terminated=0;Tesseract.createWorker=()=>new Promise(resolve=>{resolveWorker=resolve});const canvas=document.createElement('canvas');canvas.width=100;canvas.height=50;const task=LocalSkuRecognition.ocr(canvas,document.getElementById('product-scanner').dataset.ocrBase);const completion=task.promise.then(()=>false,error=>error.name==='AbortError');await new Promise(resolve=>setTimeout(resolve,20));task.cancel();resolveWorker({terminate:async()=>{terminated++}});const cancelled=await completion;await new Promise(resolve=>setTimeout(resolve,20));Tesseract.createWorker=original;canvas.width=0;return cancelled && terminated>0;})()`);
        assert.equal(result.ocr_cancel_during_initialization,true);
        await evaluate('document.querySelector("[data-scanner-close]").click()');
        const screen = await send('Page.captureScreenshot',{format:'png'});
        await fs.writeFile(path.join(reports,'product-search-mobile-2026-10-27.png'),Buffer.from(screen.data,'base64'));
        await send('Page.navigate',{url:origin+'/search/products/labels'});
        await until('document.readyState === "complete" && !!document.querySelector(".product-label svg")');
        result.label_text_matches_sku=await evaluate(`document.querySelector('.product-label-sku').textContent === ${JSON.stringify(sku)}`);
        assert.equal(result.label_text_matches_sku,true);
        await evaluate(`document.getElementById('label-width').value='90';document.getElementById('label-width').dispatchEvent(new Event('input'));window.__prints=0;window.print=()=>{window.__prints++};document.getElementById('print-product-labels').click();`);
        assert.equal(await evaluate('getComputedStyle(document.documentElement).getPropertyValue("--label-width")'),'90mm');
        assert.equal(await evaluate('window.__prints'),1);result.print_controls=true;
        result.javascript_errors=errors; assert.deepEqual(errors,[]);
        result.camera_tests_use_synthetic_streams=true;
        result.image_upload_requests=requests.filter(request=>request.method==='POST' && request.path!=='/search/products/codes').length;
        assert.equal(result.image_upload_requests,0);
        result.physical_camera_and_printer_tested=false;
        result.external_recognition_page_requests=externalRequests.slice(externalBeforeRecognition);
        assert.deepEqual(result.external_recognition_page_requests,[]);
        await fs.writeFile(path.join(reports,'product-search-browser-2026-10-27.json'),JSON.stringify(result,null,2));
        console.log(JSON.stringify(result));
        await send('Browser.close').catch(()=>{});
    } finally {
        socket?.close();
        if(browser.exitCode===null){await new Promise(resolve=>{browser.once('exit',resolve);setTimeout(resolve,1500)});}
        if(browser.exitCode===null)browser.kill();
        await new Promise(resolve=>server.close(resolve));
        if(path.resolve(profile).startsWith(path.resolve(testing)+path.sep) && path.basename(profile).startsWith('search-chrome-')) await fs.rm(profile,{recursive:true,force:true,maxRetries:6,retryDelay:200}).catch(()=>{});
    }
}
main().catch(error=>{console.error(error.stack);process.exitCode=1;});

