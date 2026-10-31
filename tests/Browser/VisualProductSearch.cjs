const assert = require('node:assert/strict');
const {spawn} = require('node:child_process');
const fs = require('node:fs/promises');
const http = require('node:http');
const path = require('node:path');

async function main() {
    const workspace = path.resolve(__dirname, '../..');
    const fixture = path.join(workspace, 'storage/app/reports/visual-search-preview');
    const reports = path.join(workspace, 'storage/app/reports');
    const testing = path.join(workspace, 'storage/framework/testing');
    const profile = path.join(testing, 'visual-chrome-'+Date.now());
    const products = JSON.parse(await fs.readFile(path.join(fixture, 'products.json'), 'utf8'));
    let origin, searches = 0, visualRequests = 0, visualMode = 'normal';
    const requests = [];

    const visual = JSON.parse(await fs.readFile(path.join(fixture,'visual.json'),'utf8'));
    const server = http.createServer(async(request,response)=>{
        try {
            const url=new URL(request.url,origin);
            requests.push({path:url.pathname,method:request.method});
            if(url.pathname==='/search/products/visual' && request.method==='POST'){
                visualRequests++;
                const chunks=[];let size=0;
                for await(const chunk of request){size+=chunk.length;if(size>4*1024*1024+4096)throw new Error('Unexpected upload size');chunks.push(chunk);}
                const body=Buffer.concat(chunks);
                assert.match(request.headers['content-type'],/^multipart\/form-data/);
                assert.equal(body.includes(Buffer.from('filename="visual-search.jpg"')),true);
                assert.equal(body.includes(Buffer.from('Content-Type: image/jpeg')),true);
                response.setHeader('Content-Type','application/json');
                if(visualMode==='busy'){response.writeHead(503);response.end(JSON.stringify({message:'Busy'}));return;}
                const send=()=>response.end(JSON.stringify(visual).replaceAll('http://localhost:8000',origin));
                if(visualMode==='delayed')setTimeout(send,400);else send();
                return;
            }
            if(url.pathname==='/search/products'){
                searches++;
                const data={...products,data:products.data.filter(item=>!url.searchParams.get('q') || item.sku===url.searchParams.get('q'))};
                response.setHeader('Content-Type','application/json');response.end(JSON.stringify(data).replaceAll('http://localhost:8000',origin));return;
            }
            const assetRoot=path.join(workspace,'public/assets');
            const photoRoot=path.join(workspace,'storage/app/public');
            const garmentRoot=path.join(workspace,'storage/framework/cache/visual-search/clothing-fixtures');
            const name=url.pathname==='/search'?path.join(fixture,'search.html'):url.pathname.startsWith('/visual-fixtures/')?path.resolve(garmentRoot,'.'+url.pathname.slice('/visual-fixtures'.length)):url.pathname.startsWith('/storage/')?path.resolve(photoRoot,'.'+url.pathname.slice('/storage'.length)):path.resolve(workspace,'public','.'+url.pathname);
            if(name!==path.join(fixture,'search.html') && !name.startsWith(assetRoot+path.sep) && !name.startsWith(photoRoot+path.sep) && !name.startsWith(garmentRoot+path.sep)){response.writeHead(404);response.end();return;}
            const binary=/\.(webp|png|jpg|jpeg)$/.test(name);
            const type=name.endsWith('.css')?'text/css':name.endsWith('.js')?'text/javascript':name.endsWith('.webp')?'image/webp':name.endsWith('.png')?'image/png':binary?'image/jpeg':'text/html';
            response.setHeader('Content-Type',type);
            response.end(binary?await fs.readFile(name):(await fs.readFile(name,'utf8')).replaceAll('http://localhost:8000',origin));
        }catch(error){response.writeHead(404);response.end();}
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
            while (Date.now()-start<12000) {if(await evaluate(expression))return;await new Promise(resolve=>setTimeout(resolve,60));}
            const diagnostic=await evaluate('({status:document.querySelector("[data-visual-status]")?.textContent})');
            throw new Error('UI condition timed out: '+expression+' '+JSON.stringify({diagnostic,requests:requests.slice(-12)}));
        }

        await send('Page.navigate',{url:origin+'/search?type=products'});
        await until('document.readyState==="complete" && !!document.getElementById("visual-product-search")');
        const result={viewport:390,api_replayed_from_real_model_integration:true,physical_camera_tested:false};
        assert.equal(await evaluate('document.documentElement.scrollWidth>innerWidth'),false);
        assert.equal(await evaluate('!!document.querySelector("[href*=visual-index]")'),false);
        await evaluate('document.querySelector("[data-scan-mode=visual]").click()');
        await until('document.getElementById("visual-product-search").open');
        assert.equal(await evaluate('document.getElementById("product-scanner").open'),false);
        result.separate_visual_dialog=true;
        const choosePhoto=async()=>evaluate(`(async()=>{const blob=await(await fetch('/visual-fixtures/query.png')).blob();const files=new DataTransfer();files.items.add(new File([blob],'garment.png',{type:'image/png'}));const input=document.querySelector('[data-visual-file]');input.files=files.files;input.dispatchEvent(new Event('change',{bubbles:true}));})()`);
        await choosePhoto();
        await until('!document.querySelector("[data-visual-preview]").hidden && !document.querySelector("[data-visual-submit]").disabled');
        assert.equal(visualRequests,0);result.preview_requires_confirmation=true;
        await evaluate('document.querySelector("[data-visual-submit]").click()');
        await until('document.querySelectorAll(".visual-product-result").length===5 && !document.getElementById("visual-product-search").hasAttribute("aria-busy")');
        assert.equal(visualRequests,1);
        assert.equal(await evaluate('document.getElementById("visual-product-search").scrollWidth>document.getElementById("visual-product-search").clientWidth'),false);
        result.five_distinct_products_rendered=true;
        assert.equal(await evaluate('document.querySelector("[data-visual-results]").textContent.includes("متوسط التكلفة")'),false);
        const screenshot=await send('Page.captureScreenshot',{format:'png'});
        await fs.writeFile(path.join(reports,'visual-search-mobile-2026-10-28.png'),Buffer.from(screenshot.data,'base64'));
        const expectedSku=visual.data[0].variants[0].sku;
        await evaluate('window.__choices=0;document.querySelector("[data-product-query]").addEventListener("product-search:sku",()=>window.__choices++);document.querySelector(".visual-product-result button").click()');
        await until('!document.getElementById("visual-product-search").open && document.querySelectorAll(".product-search-result").length===1');
        assert.equal(await evaluate('document.querySelector("[data-product-query]").value'),expectedSku);
        assert.equal(await evaluate('window.__choices'),1);
        assert.equal(await evaluate('document.querySelector("[data-visual-preview]").getAttribute("src")'),null);
        result.variant_selection_reuses_existing_search=true;
        result.preview_cleared_after_close=true;
        await evaluate('document.querySelector("[data-scan-mode=visual]").click();Object.defineProperty(navigator,"mediaDevices",{configurable:true,value:{getUserMedia:async()=>{throw new DOMException("Denied","NotAllowedError")}}});document.querySelector("[data-visual-camera]").click()');
        await until('document.querySelector("[data-visual-status]").textContent.includes("رُفض")');
        result.permission_denial_handled=true;
        await evaluate(`window.__stopped=0;HTMLMediaElement.prototype.play=async()=>{};window.__makeStream=()=>{const canvas=document.createElement('canvas');canvas.width=10;canvas.height=10;window.__streamCanvas=canvas;const stream=canvas.captureStream(1);for(const track of stream.getTracks()){const stop=track.stop.bind(track);track.stop=()=>{window.__stopped++;stop()}}return stream};navigator.mediaDevices.getUserMedia=async()=>window.__makeStream();document.querySelector('[data-visual-camera]').click()`);
        await until('!!document.querySelector("[data-visual-video]").srcObject');
        await evaluate('document.querySelector("[data-visual-close]").click()');
        await until('window.__stopped>0 && !document.querySelector("[data-visual-video]").srcObject');
        result.close_stops_camera=true;
        await evaluate('document.querySelector("[data-scan-mode=visual]").click();navigator.mediaDevices.getUserMedia=()=>new Promise(resolve=>window.__lateCamera=resolve);document.querySelector("[data-visual-camera]").click()');
        await until('typeof window.__lateCamera==="function"');
        const stops=await evaluate('window.__stopped');
        await evaluate('document.querySelector("[data-visual-close]").click();window.__lateCamera(window.__makeStream())');
        await until('window.__stopped>'+stops);
        result.late_camera_permission_is_stopped=true;
        visualMode='busy';
        await evaluate('document.querySelector("[data-scan-mode=visual]").click()');
        await choosePhoto();
        await until('!document.querySelector("[data-visual-submit]").disabled');
        await evaluate('document.querySelector("[data-visual-submit]").click()');
        await until('document.querySelector("[data-visual-status]").textContent.includes("مشغولة") && !document.querySelector("[data-visual-submit]").disabled');
        assert.equal(await evaluate('document.querySelectorAll(".visual-product-result").length'),0);
        result.runtime_busy_message=true;
        visualMode='delayed';
        await evaluate('document.querySelector("[data-visual-submit]").click()');
        await until('document.getElementById("visual-product-search").hasAttribute("aria-busy")');
        await evaluate('Object.defineProperty(document,"hidden",{configurable:true,value:true});document.dispatchEvent(new Event("visibilitychange"));Object.defineProperty(document,"hidden",{configurable:true,value:false});document.dispatchEvent(new Event("visibilitychange"))');
        assert.equal(await evaluate('document.querySelector("[data-visual-submit]").disabled'),false);
        await new Promise(resolve=>setTimeout(resolve,500));
        assert.equal(await evaluate('document.querySelectorAll(".visual-product-result").length'),0);
        result.background_cancel_keeps_retry_enabled=true;
        await evaluate('document.querySelector("[data-visual-submit]").click()');
        await until('document.getElementById("visual-product-search").hasAttribute("aria-busy")');
        await evaluate('document.querySelector("[data-visual-close]").click()');
        await new Promise(resolve=>setTimeout(resolve,600));
        assert.equal(await evaluate('document.getElementById("visual-product-search").open'),false);
        assert.equal(await evaluate('document.querySelectorAll(".visual-product-result").length'),0);
        result.close_cancels_pending_results=true;
        await evaluate('document.querySelector("[data-scan-mode=barcode]").click()');
        await until('document.getElementById("product-scanner").open');
        assert.equal(await evaluate('document.getElementById("visual-product-search").open'),false);
        await evaluate('document.querySelector("[data-scanner-close]").click();document.querySelector("[data-scan-mode=ocr]").click()');
        await until('document.getElementById("product-scanner").open && !document.querySelector("[data-ocr-review]").hidden');
        await evaluate('document.querySelector("[data-scanner-close]").click()');
        result.barcode_and_ocr_controls_preserved=true;
        result.financial_requests=requests.filter(item=>item.method==='POST' && item.path!=='/search/products/visual').length;
        assert.equal(result.financial_requests,0);
        result.client_model_downloads=requests.filter(item=>item.path.includes('/vendor/search/') || item.path.endsWith('.onnx')).length;
        assert.equal(result.client_model_downloads,0);
        result.javascript_errors=errors;assert.deepEqual(errors,[]);
        await fs.writeFile(path.join(reports,'visual-search-browser-2026-10-28.json'),JSON.stringify(result,null,2));
        console.log(JSON.stringify(result));
        await send('Browser.close').catch(()=>{});
    } finally {
        socket?.close();
        if(browser.exitCode===null){await new Promise(resolve=>{browser.once('exit',resolve);setTimeout(resolve,1500)});}
        if(browser.exitCode===null)browser.kill();
        await new Promise(resolve=>server.close(resolve));
        if(path.resolve(profile).startsWith(path.resolve(testing)+path.sep) && path.basename(profile).startsWith('visual-chrome-')) await fs.rm(profile,{recursive:true,force:true,maxRetries:6,retryDelay:200}).catch(()=>{});
    }
}
main().catch(error=>{console.error(error.stack);process.exitCode=1;});


