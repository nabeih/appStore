const assert=require('node:assert/strict');
const {spawn}=require('node:child_process');
const fs=require('node:fs/promises');
const http=require('node:http');
const path=require('node:path');
async function main(){
    const workspace=path.resolve(__dirname,'../..');
    const fixture=path.join(workspace,'storage/app/reports/product-details-preview');
    const reports=path.join(workspace,'storage/app/reports');
    const testing=path.join(workspace,'storage/framework/testing');
    const profile=path.join(testing,'details-chrome-'+Date.now());
    let origin, mutations=[];
    const requests=[];
    const server=http.createServer(async(req,res)=>{
        try{
            const url=new URL(req.url,origin);requests.push({method:req.method,path:url.pathname});
            if(url.pathname.startsWith('/api/products/') && req.method!=='GET'){
                let body='';for await(const chunk of req)body+=chunk;
                mutations.push({method:req.method,body});
                res.writeHead(422,{'Content-Type':'application/json'});res.end(JSON.stringify({message:'اختبار خطأ آمن'}));return;
            }
            if(url.pathname.startsWith('/storage/')){
                res.writeHead(200,{'Content-Type':'image/png'});
                res.end(Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScLbtAAAAABJRU5ErkJggg==','base64'));return;
            }
            const name=url.pathname==='/inventory'?path.join(fixture,'list.html'):/^\/inventory\/products\/\d+$/.test(url.pathname)?path.join(fixture,'show.html'):path.resolve(workspace,'public','.'+url.pathname);
            const allowed=name===path.join(fixture,'list.html')||name===path.join(fixture,'show.html')||name.startsWith(path.join(workspace,'public/assets')+path.sep);
            if(!allowed){res.writeHead(404);res.end();return;}
            res.setHeader('Content-Type',name.endsWith('.js')?'text/javascript':name.endsWith('.css')?'text/css':'text/html');
            res.end((await fs.readFile(name,'utf8')).replaceAll('localhost:8000',new URL(origin).host));
        }catch(error){res.writeHead(404);res.end();}
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
            const diagnostic=await evaluate('({status:document.querySelector("[data-scanner-status]")?.textContent,ocrError:window.__ocrError,ocrPhase:window.__ocrPhase,workerMessages:window.__workerMessages})');
            throw new Error('UI condition timed out: '+expression+' '+JSON.stringify({diagnostic,requests:requests.slice(-12)}));
        }

        await send('Page.navigate',{url:origin+'/inventory'});
        await until('document.readyState==="complete" && !!document.querySelector(".inventory-product-link")');
        const result={mobile_width:390,physical_phone_tested:false};
        const initial=await evaluate('location.href');
        await evaluate('document.querySelector("[data-product-details]").click()');
        await until('document.getElementById("product-details-dialog").open');
        assert.equal(await evaluate('location.href'),initial);
        assert.equal(await evaluate('document.querySelector("[data-product-variant-fields]").hidden'),false);
        await evaluate('document.querySelector("[data-close-product-details]").click();document.querySelector("[data-inventory-action=delete]").click()');
        await until('document.getElementById("inventorySetupDialog").open');
        assert.equal(await evaluate('location.href'),initial);
        assert.equal(mutations.length,0);
        await evaluate('document.querySelector("[data-setup-close]").click()');
        result.row_actions_do_not_navigate=true;
        await send('Emulation.setDeviceMetricsOverride',{width:1280,height:900,deviceScaleFactor:1,mobile:false});
        await evaluate('document.querySelector(".inventory-product-link").scrollIntoView({block:"center",inline:"center"})');
        const targetsBefore=(await send('Target.getTargets')).targetInfos.filter(t=>t.type==='page').map(t=>t.targetId);
        const position=await evaluate('(()=>{const r=document.querySelector(".inventory-product-link").getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2}})()');
        await send('Input.dispatchMouseEvent',{type:'mousePressed',button:'middle',buttons:4,clickCount:1,...position});
        await send('Input.dispatchMouseEvent',{type:'mouseReleased',button:'middle',buttons:0,clickCount:1,...position});
        await new Promise(resolve=>setTimeout(resolve,400));
        const opened=(await send('Target.getTargets')).targetInfos.find(t=>t.type==='page'&&!targetsBefore.includes(t.targetId));
        assert.ok(opened,'A real middle click must open a new tab');
        assert.equal(await evaluate('location.href'),initial);
        await send('Target.closeTarget',{targetId:opened.targetId});
        result.middle_click_opens_new_tab=true;
        await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
        await evaluate('document.querySelector(".inventory-product-link").click()');
        await until('document.readyState==="complete" && !!document.querySelector("[data-product-show]")');
        assert.equal(await evaluate('document.documentElement.scrollWidth>innerWidth'),false);
        assert.equal(await evaluate('document.querySelectorAll(".product-show-gallery figure").length'),2);
        assert.equal(await evaluate('document.querySelectorAll(".product-show-variant").length'),3);
        assert.equal(await evaluate('!!document.querySelector(".product-show-no-image")'),true);
        result.colors_variants_and_image_fallback=true;
        const screenshot=await send('Page.captureScreenshot',{format:'png'});
        await fs.writeFile(path.join(reports,'inventory-product-details-mobile.png'),Buffer.from(screenshot.data,'base64'));
        await evaluate('document.querySelector(".product-show-actions [data-product-details]").click()');
        await until('document.getElementById("product-details-dialog").open');
        assert.equal(await evaluate('document.querySelector("[data-product-variant-fields]").hidden'),true);
        await evaluate('document.querySelector("#product-details-form [name=name]").value="اسم معدل";document.querySelector("#product-details-form [type=submit]").click()');
        await until('!document.getElementById("product-details-feedback").hidden');
        assert.equal(mutations.length,1);
        assert.equal(mutations[0].body.includes('name="variants['),false);
        assert.equal(mutations[0].body.includes('name="name"'),true);
        result.header_edit_uses_existing_metadata_endpoint_without_sku_fields=true;
        await evaluate('document.querySelector("[data-close-product-details]").click();window.__confirmed=0;window.confirm=()=>false;document.querySelector("[data-product-delete]").click()');
        assert.equal(mutations.length,1);
        await evaluate('window.confirm=()=>{window.__confirmed++;return true};document.querySelector("[data-product-delete]").click()');
        await until('!document.querySelector("[data-product-show-feedback]").hidden');
        assert.equal(mutations[1].method,'DELETE');
        assert.equal(await evaluate('window.__confirmed'),1);
        result.delete_requires_confirmation_and_uses_existing_api=true;
        assert.deepEqual(errors,[]);result.javascript_errors=errors;
        await fs.writeFile(path.join(reports,'inventory-product-details-browser.json'),JSON.stringify(result,null,2));
        console.log(JSON.stringify(result));
        await send('Browser.close').catch(()=>{});
    } finally {
        socket?.close();
        if(browser.exitCode===null){await new Promise(resolve=>{browser.once('exit',resolve);setTimeout(resolve,1500)});}
        if(browser.exitCode===null)browser.kill();
        await new Promise(resolve=>server.close(resolve));
        if(path.resolve(profile).startsWith(path.resolve(testing)+path.sep) && path.basename(profile).startsWith('details-chrome-')) await fs.rm(profile,{recursive:true,force:true,maxRetries:6,retryDelay:200}).catch(()=>{});
    }
}
main().catch(error=>{console.error(error.stack);process.exitCode=1;});


