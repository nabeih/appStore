const assert=require('node:assert/strict');
const {spawn}=require('node:child_process');
const fs=require('node:fs/promises');
const http=require('node:http');
const path=require('node:path');
async function main(){
    const workspace=path.resolve(__dirname,'../..');
    const fixture=path.join(workspace,'storage/app/reports/go-live-preview');
    const reports=path.join(workspace,'storage/app/reports');
    const testing=path.join(workspace,'storage/framework/testing');
    const profile=path.join(testing,'transition-chrome-'+Date.now());
    let origin, mutations=[], previews=0;
    const requests=[];
    const server=http.createServer(async(req,res)=>{
        try {
            const url=new URL(req.url,origin);requests.push({method:req.method,path:url.pathname});
            if(req.method==='POST' && url.pathname.startsWith('/finance/transition/')){
                let raw='';for await(const chunk of req)raw+=chunk;
                const data=JSON.parse(raw);
                res.setHeader('Content-Type','application/json');
                if(url.pathname.endsWith('/preview')){
                    previews++;
                    res.end(JSON.stringify({status:'eligible',issues:[],posting_date:data.posting_date,journal_id:null,amount_text:'440.00',effect_text:'<img src=x onerror=window.__injected=true>',
                        lines:[{account:'<img src=x onerror=window.__injected=true>',amount:44000},{account:'opening_equity',amount:-44000}],revision:'a'.repeat(64)}));
                }else{
                    mutations.push(data);
                    res.writeHead(422);res.end(JSON.stringify({message:'Isolated simulated server rejection'}));
                }
                return;
            }
            const name=url.pathname==='/finance/transition'?path.join(fixture,'transition.html'):path.resolve(workspace,'public','.'+url.pathname);
            const allowed=name===path.join(fixture,'transition.html')||name.startsWith(path.join(workspace,'public/assets')+path.sep);
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

        await send('Page.navigate',{url:origin+'/finance/transition'});
        await until('document.readyState==="complete" && !!document.querySelector("[data-transition-form]")');
        assert.equal(await evaluate('document.querySelector("[data-transition-post]").disabled'),true);
        await evaluate('(function(){const f=document.querySelector("[data-transition-form]");f.elements.source.value=f.elements.source.options[1].value;f.elements.posting_date.value="2026-10-31";f.elements.evidence.value="Isolated explicit documentary evidence";f.elements.reason.value="Isolated approval reason";f.requestSubmit();})()');
        await until('document.querySelector("[data-transition-result]").dataset.previewStatus === "eligible"');
        assert.equal(previews,1);assert.equal(mutations.length,0);
        assert.equal(await evaluate('document.querySelector("[data-transition-post]").disabled'),true);
        assert.equal(await evaluate('document.querySelectorAll("[data-transition-result] img").length'),0);
        assert.equal(await evaluate('window.__injected===undefined'),true);
        await evaluate('document.querySelector("[name=confirmed]").click()');
        assert.equal(await evaluate('document.querySelector("[data-transition-post]").disabled'),false);
        await evaluate('window.confirm=()=>false;document.querySelector("[data-transition-post]").click()');
        assert.equal(mutations.length,0);
        await evaluate('const e=document.querySelector("[name=evidence]");e.value+=" changed";e.dispatchEvent(new Event("input",{bubbles:true}))');
        assert.equal(await evaluate('document.querySelector("[data-transition-post]").disabled'),true);
        await evaluate('document.querySelector("[data-transition-form]").requestSubmit()');
        await until('!document.querySelector("[data-transition-post]").disabled');
        assert.equal(previews,2);
        assert.equal(await evaluate('document.documentElement.scrollWidth>innerWidth'),false);
        const layout=await evaluate('({width:innerWidth,main:document.querySelector(".main-content").getBoundingClientRect().toJSON(),sale:document.querySelector(".sale-page").getBoundingClientRect().toJSON(),style:getComputedStyle(document.querySelector(".main-content")).marginRight})');
        assert.equal(layout.width,390);
        assert.ok(layout.main.left>=0 && layout.main.right<=390);
        assert.equal(await evaluate('scrollX'),0);
        await evaluate('document.querySelector("[data-legacy-transition]").scrollIntoView({block:"start"})');
        const screenshot=await send('Page.captureScreenshot',{format:'png'});
        await fs.writeFile(path.join(reports,'go-live-transition-mobile.png'),Buffer.from(screenshot.data,'base64'));
        await evaluate('window.confirm=()=>true;document.querySelector("[data-transition-post]").click()');
        await until('document.querySelector("[data-transition-feedback]").textContent.includes("Isolated simulated")');
        assert.equal(mutations.length,1);
        assert.equal(mutations[0].confirmed,true);
        assert.equal(mutations[0].revision,'a'.repeat(64));
        assert.equal(await evaluate('document.querySelector("[data-transition-post]").disabled'),true);
        assert.deepEqual(errors,[]);
        const result={mobile_width:390,physical_phone_tested:false,preview_does_not_post:true,
            approval_requires_review_and_confirmation:true,changed_inputs_invalidate_review:true,
            server_rejection_visible:true,result_uses_text_not_html:true,no_horizontal_overflow:true,
            real_database_mutations:0,javascript_errors:errors};
        await fs.writeFile(path.join(reports,'go-live-transition-browser.json'),JSON.stringify(result,null,2));
        console.log(JSON.stringify(result));
        await send('Browser.close').catch(()=>{});
    } finally {
        socket?.close();
        if(browser.exitCode===null){await new Promise(resolve=>{browser.once('exit',resolve);setTimeout(resolve,1500)});}
        if(browser.exitCode===null)browser.kill();
        await new Promise(resolve=>server.close(resolve));
        if(path.resolve(profile).startsWith(path.resolve(testing)+path.sep) && path.basename(profile).startsWith('transition-chrome-')) await fs.rm(profile,{recursive:true,force:true,maxRetries:6,retryDelay:200}).catch(()=>{});
    }
}
main().catch(error=>{console.error(error.stack);process.exitCode=1;});


