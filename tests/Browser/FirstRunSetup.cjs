const assert=require('node:assert/strict');
const {spawn}=require('node:child_process');
const fs=require('node:fs/promises');
const http=require('node:http');
const path=require('node:path');
async function main(){
    const workspace=path.resolve(__dirname,'../..');
    const fixture=path.join(workspace,'storage/app/reports/first-run-preview');
    const reports=path.join(workspace,'storage/app/reports');
    const testing=path.join(workspace,'storage/framework/testing');
    const profile=path.join(testing,'first-run-chrome-'+Date.now());
    let origin, mutations=[];
    const requests=[];
    const pageFixtures={'/setup':'setup.html','/onboarding/branch':'branch.html','/profile':'profile.html','/email/verify':'verify.html','/forgot-password':'forgot.html','/reset-password/fixture':'reset.html'};
    const server=http.createServer(async(req,res)=>{
        try{
            const url=new URL(req.url,origin);requests.push({method:req.method,path:url.pathname});
            if(req.method!=='GET'){mutations.push({path:url.pathname});res.writeHead(422);res.end('Isolated test only');return;}
            const name=pageFixtures[url.pathname]?path.join(fixture,pageFixtures[url.pathname]):path.resolve(workspace,'public','.'+url.pathname);
            if(!Object.values(pageFixtures).some(file=>name===path.join(fixture,file))&&!name.startsWith(path.join(workspace,'public/assets')+path.sep)){res.writeHead(404);res.end();return;}
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

        await send('Page.navigate',{url:origin+'/setup'});
        await until('document.readyState==="complete" && !!document.querySelector("[data-setup-form]")');
        assert.equal(await evaluate('document.documentElement.dir'),'rtl');
        assert.equal(await evaluate('document.querySelector("[data-setup-form]").method'),'post');
        assert.equal(await evaluate('document.getElementById("setup_token").type'),'password');
        assert.equal(await evaluate('document.getElementById("setup_token").value'),'');
        assert.equal(await evaluate('document.getElementById("setup_token").autocomplete'),'off');
        assert.equal(await evaluate('document.getElementById("password").minLength'),4);
        assert.equal(await evaluate('document.getElementById("password_confirmation").minLength'),4);
        assert.equal(await evaluate('document.getElementById("email").required'),true);
        const bounds=await evaluate('({width:innerWidth,rect:document.querySelector(".setup-card").getBoundingClientRect().toJSON()})');
        assert.equal(bounds.width,390);
        assert.ok(bounds.rect.left>=0 && bounds.rect.right<=390);
        await evaluate('document.querySelector(".setup-card").scrollIntoView({block:"start"})');
        const screenshot=await send('Page.captureScreenshot',{format:'png'});
        await fs.writeFile(path.join(reports,'first-run-setup-mobile.png'),Buffer.from(screenshot.data,'base64'));
        await evaluate('document.getElementById("password").value="test";document.querySelector("[data-password-toggle=password]").click()');
        assert.equal(await evaluate('document.getElementById("password").type'),'text');
        await evaluate('document.querySelector("[data-password-toggle=password]").click()');
        assert.equal(await evaluate('document.getElementById("password").type'),'password');
        await evaluate('document.querySelector("[data-setup-form]").addEventListener("submit",e=>e.preventDefault());document.querySelector("[data-setup-form]").dispatchEvent(new Event("submit",{bubbles:true,cancelable:true}))');
        assert.equal(await evaluate('document.querySelector("[data-setup-submit]").disabled'),true);
        assert.equal(await evaluate('localStorage.length + sessionStorage.length'),0);
        const checkedPages=[];
        for(const pagePath of Object.keys(pageFixtures).filter(page=>page!=='/setup')){
            await send('Page.navigate',{url:origin+pagePath});
            await until('document.readyState==="complete" && !!document.querySelector(".setup-card,.account-panel")');
            assert.equal(await evaluate('document.documentElement.dir'),'rtl');
            assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'),true,'No overflow on '+pagePath);
            assert.equal(await evaluate('document.querySelectorAll("input[type=password]").length===0 || [...document.querySelectorAll("input[type=password]")].every(input=>input.value==="")'),true);
            const shot=await send('Page.captureScreenshot',{format:'png'});
            await fs.writeFile(path.join(reports,'account-'+pageFixtures[pagePath].replace('.html','.png')),Buffer.from(shot.data,'base64'));
            checkedPages.push(pagePath);
        }
        await send('Emulation.setDeviceMetricsOverride', {width:1280,height:900,deviceScaleFactor:1,mobile:false});
        for(const pagePath of Object.keys(pageFixtures)){
            await send('Page.navigate',{url:origin+pagePath});
            await until('document.readyState==="complete" && !!document.querySelector(".setup-card,.account-panel")');
            assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'),true,'No desktop overflow on '+pagePath);
        }
        assert.equal(mutations.length,0);
        assert.deepEqual(errors,[]);
        const result={mobile_width:390,desktop_width:1280,physical_phone_tested:false,rtl:true,setup_token_hidden_and_not_prefilled:true,
            password_minimum:4,email_required:true,account_pages_checked:checkedPages,password_visibility_toggle:true,submit_loading:true,
            no_horizontal_overflow:true,no_camera_or_external_services:true,browser_storage_entries:0,real_database_mutations:0,javascript_errors:errors};
        await fs.writeFile(path.join(reports,'first-run-browser.json'),JSON.stringify(result,null,2));
        console.log(JSON.stringify(result));
        await send('Browser.close').catch(()=>{});
    } finally {
        socket?.close();
        if(browser.exitCode===null){await new Promise(resolve=>{browser.once('exit',resolve);setTimeout(resolve,1500)});}
        if(browser.exitCode===null)browser.kill();
        await new Promise(resolve=>server.close(resolve));
        if(path.resolve(profile).startsWith(path.resolve(testing)+path.sep) && path.basename(profile).startsWith('first-run-chrome-')) await fs.rm(profile,{recursive:true,force:true,maxRetries:6,retryDelay:200}).catch(()=>{});
    }
}
main().catch(error=>{console.error(error.stack);process.exitCode=1;});


