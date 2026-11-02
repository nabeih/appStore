const assert=require('node:assert/strict');

const {spawn}=require('node:child_process');

const fs=require('node:fs/promises');

const http=require('node:http');

const path=require('node:path');

async function main(){

    const workspace=path.resolve(__dirname,'../..');

    const fixture=path.join(workspace,'storage/app/reports/branches-preview');

    const reports=path.join(workspace,'storage/app/reports');

    const testing=path.join(workspace,'storage/framework/testing');

    const profile=path.join(testing,'branches-chrome-'+Date.now());

    let origin, mutations=[];

    const requests=[];

    const pageFixtures={'/branches':'index.html','/branches/create':'create.html','/branches/1/edit':'edit.html','/profile':'profile.html'};

    const server=http.createServer(async(req,res)=>{

        try{

            const url=new URL(req.url,origin);requests.push({method:req.method,path:url.pathname});

            if(req.method!=='GET'){mutations.push({path:url.pathname});res.writeHead(422);res.end('Isolated test only');return;}

            const pieces=url.pathname.split('/'); const pageFile=pageFixtures[url.pathname]||(pieces.length===4 && pieces[1]==='branches' && Number.isInteger(Number(pieces[2])) && pieces[3]==='edit'?'edit.html':null);

            const name=pageFile?path.join(fixture,pageFile):path.resolve(workspace,'public','.'+url.pathname);

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



        await send('Emulation.setTouchEmulationEnabled',{enabled:true});

        await send('Page.navigate',{url:origin+'/branches'});

        await until('document.readyState==="complete" && !!document.querySelector("[data-nav-group=store]")');

        assert.equal(await evaluate('document.documentElement.dir'),'rtl');

        assert.equal(await evaluate('document.querySelector("[data-nav-group=store]").open'),true);

        assert.equal(await evaluate('document.querySelector("[data-nav-group=store] summary").getAttribute("aria-expanded")'),'true');

        assert.equal(await evaluate('document.querySelectorAll("#sidebar a[aria-current=page]").length'),1);

        assert.equal(await evaluate('document.querySelector("#sidebar").inert'),true);

        async function touch(selector) {

            const point=await evaluate('(()=>{const r=document.querySelector('+JSON.stringify(selector)+').getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2}})()');

            await send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:point.x,y:point.y}]});

            await send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});

        }

        await touch('#auth-menu-toggle');

        await until('document.querySelector("#sidebar").classList.contains("auth-open") && !document.querySelector("#sidebar").inert');

        await touch('[data-nav-group=inventory] summary');

        await until('document.querySelector("[data-nav-group=inventory]").open && document.querySelector("[data-nav-group=inventory] summary").getAttribute("aria-expanded")==="true"');

        await evaluate('document.querySelector("[data-nav-group=inventory] summary").focus()');

        await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13,text:'\r'});

        await send('Input.dispatchKeyEvent',{type:'keyUp',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});

        await until('!document.querySelector("[data-nav-group=inventory]").open');

        assert.equal(await evaluate('document.querySelector("#sidebar").scrollHeight >= document.querySelector("#sidebar").clientHeight'),true);

        await evaluate('document.querySelector("#sidebar").scrollTop=0');

        const mobileShot=await send('Page.captureScreenshot',{format:'png'});

        await fs.writeFile(path.join(reports,'branches-sidebar-mobile.png'),Buffer.from(mobileShot.data,'base64'));

        await evaluate('document.querySelector("[data-sidebar-close]").click()');

        await until('!document.querySelector("#sidebar").classList.contains("auth-open")');

        assert.equal(await evaluate('document.activeElement.id'),'auth-menu-toggle');

        await evaluate('document.querySelector(".b-heading a.b-button").click()');

        await until('document.readyState==="complete" && !!document.querySelector("[data-branch-save]")');

        assert.equal(await evaluate('location.pathname'),'/branches/create');

        assert.equal(await evaluate('document.querySelector("[data-nav-group=store]").open'),true);

        assert.equal(await evaluate('document.querySelectorAll("[data-branch-save] input:not([type=hidden])").length'),3);

        const checked=[];

        for(const width of [390,1280]){

            await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width===390});

            for(const page of Object.keys(pageFixtures)){

                await send('Page.navigate',{url:origin+page});

                await until('document.readyState==="complete" && !!document.querySelector("#sidebar")');

                assert.equal(await evaluate('document.documentElement.scrollWidth <= innerWidth'),true,'No overflow '+width+' '+page);

                assert.equal(await evaluate('document.querySelectorAll("#sidebar a[aria-current=page]").length'),1);

                if(width===1280)assert.equal(await evaluate('document.querySelector("#sidebar").inert'),false);

                checked.push({width,page});

            }

        }

        await send('Page.navigate',{url:origin+'/branches'});

        await until('document.readyState==="complete" && !!document.querySelector(".b-table")');

        const desktopShot=await send('Page.captureScreenshot',{format:'png'});

        await fs.writeFile(path.join(reports,'branches-sidebar-desktop.png'),Buffer.from(desktopShot.data,'base64'));

        const report={checked,mobile_touch:true,keyboard_toggle:true,active_group:true,focus_return:true,no_horizontal_overflow:true,physical_phone_tested:false,database_writes:mutations.length,javascript_errors:errors};

        assert.equal(mutations.length,0);

        assert.deepEqual(errors,[]);

        await fs.writeFile(path.join(reports,'branches-sidebar-browser.json'),JSON.stringify(report,null,2));

        console.log(JSON.stringify(report));

        await send('Browser.close').catch(()=>{});

    } finally {

        socket?.close();

        if(browser.exitCode===null){await new Promise(resolve=>{browser.once('exit',resolve);setTimeout(resolve,1500)});}

        if(browser.exitCode===null)browser.kill();

        await new Promise(resolve=>server.close(resolve));

        if(path.resolve(profile).startsWith(path.resolve(testing)+path.sep) && path.basename(profile).startsWith('branches-chrome-')) await fs.rm(profile,{recursive:true,force:true,maxRetries:6,retryDelay:200}).catch(()=>{});

    }

}

main().catch(error=>{console.error(error.stack);process.exitCode=1;});










