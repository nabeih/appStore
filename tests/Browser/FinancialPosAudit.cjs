const assert=require('node:assert/strict');

const {spawn,execFileSync}=require('node:child_process');

const fs=require('node:fs/promises');

const http=require('node:http');

const path=require('node:path');

async function main(){

    const workspace=path.resolve(__dirname,'../..');

    const reports=path.join(workspace,'storage/app/reports');
    const context=JSON.parse(await fs.readFile(path.join(workspace,'storage/app/private/audits/financial-audit-browser.json'),'utf8'));
    assert.match(context.database,/^appstore_financial_audit_[a-f0-9]{16}$/);
    const testing=path.join(workspace,'storage/framework/testing');
    const profile=path.join(testing,'financial-audit-chrome-'+Date.now());
    const portProbe=http.createServer();
    await new Promise(resolve=>portProbe.listen(0,'127.0.0.1',resolve));
    const port=portProbe.address().port;
    await new Promise(resolve=>portProbe.close(resolve));
    let forward=Promise.resolve();
    const proxy=http.createServer(async(req,res)=>{
        const requested=new URL(req.url,'http://127.0.0.1').pathname;
        if(req.method==='GET'&&requested.startsWith('/assets/')){
            const assetRoot=path.join(workspace,'public/assets')+path.sep;
            const assetPath=path.resolve(workspace,'public','.'+requested);
            if(!assetPath.startsWith(assetRoot)){res.writeHead(404);res.end();return;}
            try{const content=await fs.readFile(assetPath);res.setHeader('Content-Type',assetPath.endsWith('.js')?'text/javascript':assetPath.endsWith('.css')?'text/css':'application/octet-stream');res.end(content);}catch{res.writeHead(404);res.end();}return;
        }
        if(requested==='/favicon.ico'){res.writeHead(204);res.end();return;}
        const chunks=[];for await(const chunk of req)chunks.push(chunk);
        const body=Buffer.concat(chunks);
        const next=forward.then(()=>new Promise(resolve=>{
            const upstream=http.request({hostname:'127.0.0.1',port,path:req.url,method:req.method,headers:{...req.headers,connection:'close'},agent:false},response=>{
                res.writeHead(response.statusCode,response.headers);response.pipe(res);response.once('end',resolve);response.once('close',resolve);response.once('error',resolve);
            });
            upstream.once('error',()=>{if(!res.headersSent)res.writeHead(503);if(!res.writableEnded)res.end('Isolated server starting');resolve();});
            upstream.setTimeout(10000,()=>upstream.destroy());
            upstream.end(body);
        }));
        forward=next.catch(()=>{});
    });
    await new Promise(resolve=>proxy.listen(0,'127.0.0.1',resolve));
    const origin='http://127.0.0.1:'+proxy.address().port;
    const requests=[],mutations=[],statuses=[],checked=[];
    const childEnv={...process.env,APP_ENV:'testing',DB_CONNECTION:'mysql',DB_HOST:'127.0.0.1',DB_DATABASE:context.database,DB_URL:'',
        APP_URL:origin,ASSET_URL:origin,APP_DEBUG:'false',DEBUGBAR_ENABLED:'false',CACHE_STORE:'array',SESSION_DRIVER:'database',SESSION_SECURE_COOKIE:'false',
        QUEUE_CONNECTION:'sync',MAIL_MAILER:'log',LOG_CHANNEL:'null',VISUAL_SEARCH_ENABLED:'false',VISUAL_SEARCH_INDEXING:'false'};
    const php='C:/laragon/bin/php/php-8.5/php.exe';
    const guard="require 'vendor/autoload.php';$a=new Illuminate\\Foundation\\Application(getcwd());(new Illuminate\\Foundation\\Bootstrap\\LoadEnvironmentVariables)->bootstrap($a);(new Illuminate\\Foundation\\Bootstrap\\LoadConfiguration)->bootstrap($a);$c=$a['config']->get('database.connections.mysql');if($a->configurationIsCached()||$a['config']->get('app.env')!=='testing'||$a['config']->get('database.default')!=='mysql'||$c['host']!=='127.0.0.1'||$c['database']!==getenv('DB_DATABASE')||!empty($c['url']))throw new LogicException('Isolated HTTP guard rejected');echo 'isolated';";
    assert.equal(execFileSync(php,['-r',guard],{cwd:workspace,env:childEnv,encoding:'utf8'}),'isolated');
    const phpServer=spawn(php,['-S','127.0.0.1:'+port,path.join(workspace,'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')],
        {cwd:path.join(workspace,'public'),env:childEnv,windowsHide:true,stdio:'ignore'});
    process.once('exit',()=>phpServer.kill());
    for(let i=0;i<80;i++){try{const r=await fetch(origin+'/login',{headers:{Connection:'close'}});await r.arrayBuffer();if(r.status===200)break;}catch{}await new Promise(resolve=>setTimeout(resolve,100));}
    await fs.mkdir(profile,{recursive:true});
    const browser=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless=new','--remote-debugging-port=0','--user-data-dir='+profile,
        '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1','--disable-background-networking','--disable-component-update','--disable-sync',
        '--no-first-run','--no-default-browser-check','--disable-extensions','about:blank'],{windowsHide:true,stdio:['ignore','ignore','pipe']});
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

            if(data.method==='Network.requestWillBeSent'&&data.params.request.url.startsWith(origin+'/')){
                const request=data.params.request; requests.push({method:request.method,path:new URL(request.url).pathname});
                if(!['GET','HEAD'].includes(request.method))mutations.push({method:request.method,path:new URL(request.url).pathname});
            }
            if(data.method==='Network.responseReceived'&&data.params.response.url.startsWith(origin+'/'))statuses.push({status:data.params.response.status,path:new URL(data.params.response.url).pathname});
            if (data.method === 'Runtime.exceptionThrown') errors.push(data.params.exceptionDetails.text);

            if (data.method === 'Fetch.requestPaused') {

                const allowed = data.params.request.url.startsWith(origin+'/');

                if(!allowed)externalRequests.push(data.params.request.url);

                send(allowed?'Fetch.continueRequest':'Fetch.failRequest', {requestId:data.params.requestId,...(allowed?{}:{errorReason:'BlockedByClient'})}).catch(()=>{});

            }

        });

        await send('Runtime.enable'); await send('Page.enable'); await send('Network.enable');

        await send('Fetch.enable', {patterns:[{urlPattern:'http*'}]});

        await send('Emulation.setDeviceMetricsOverride', {width:390,height:844,deviceScaleFactor:1,mobile:true});

        async function evaluate(expression) {

            const result = await send('Runtime.evaluate', {expression,awaitPromise:true,returnByValue:true});

            if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);

            return result.result.value;

        }

        async function until(expression) {

            const start = Date.now();

            while (Date.now()-start<25000) {if(await evaluate(expression))return;await new Promise(resolve=>setTimeout(resolve,60));}

            const diagnostic=await evaluate('({location:location.pathname,ready:document.readyState,title:document.title,text:document.body?.textContent.slice(0,180),formErrors:[...document.querySelectorAll("[data-sales-errors]")].map(n=>n.textContent),preview:document.querySelector("#cashier-preview-status")?.textContent,paymentAccount:document.querySelector("#cashier-payments select")?.value,status:document.querySelector("[data-scanner-status]")?.textContent,ocrError:window.__ocrError,ocrPhase:window.__ocrPhase,workerMessages:window.__workerMessages})');

            throw new Error('UI condition timed out: '+expression+' '+JSON.stringify({diagnostic,requests:requests.slice(-12)}));

        }

        await send('Page.navigate',{url:origin+'/login'});
        await until('document.readyState==="complete" && !!document.querySelector("#login-form")');
        await evaluate('document.querySelector("#username").value='+JSON.stringify(context.username)+';document.querySelector("#password").value='+JSON.stringify(context.password)+';document.querySelector("#login-form").requestSubmit()');
        await until('document.readyState==="complete" && location.pathname==="/dashboard"');
        if(process.env.FINANCIAL_REPORT_REVIEW==='1'){
            const reportPath='/finance/treasury?branch_id='+context.branch_id+'&from=2026-10-01&to=2026-10-31';
            for(const width of [390,1280]){
                await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width===390});
                await send('Page.navigate',{url:origin+reportPath});
                await until('document.readyState==="complete" && !!document.querySelector("[data-period-profitability]")');
                const overflow=await evaluate('document.documentElement.scrollWidth>innerWidth');
                assert.equal(overflow,false,'Treasury report must fit the viewport');
                const text=await evaluate('document.querySelector("[data-period-profitability]").textContent');
                assert.ok(text.includes('27.87%') && text.includes('6.56%'),'Approved margins must appear in the report');
                const screenshot=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});
                await fs.writeFile(path.join(reports,'financial-remediation-treasury-'+width+'.png'),Buffer.from(screenshot.data,'base64'));
                checked.push({width,path:reportPath,overflow,margins_visible:true});
            }
            const payload=await evaluate('fetch(location.href,{headers:{Accept:"application/json"}}).then(r=>r.json())');
            const exported=await evaluate('fetch(document.querySelector("[data-period-profitability] a").href).then(r=>r.json())');
            assert.equal(payload.period.cogs_minor,11000);
            assert.equal(payload.period.gross_margin_percent,'27.87');
            assert.equal(payload.period.operating_margin_percent,'6.56');
            assert.equal(payload.completeness.complete,true);
            assert.deepEqual(exported,payload);
            assert.deepEqual(errors,[]);
            assert.deepEqual(mutations.map(m=>m.path),['/login']);
            const result={database:context.database,actual_http:true,checked,authorized_json_and_export:true,javascript_errors:errors,
                business_mutations:[],physical_phone_tested:false,external_requests_blocked:true};
            await fs.writeFile(path.join(reports,'financial-remediation-live-browser.json'),JSON.stringify(result,null,2));
            console.log(JSON.stringify(result));
            await send('Browser.close').catch(()=>{});
            return;
        }
        const pagePaths=['/sales/cashier','/sales','/sales/'+context.sale_id,'/sales/'+context.sale_id+'/returns/create','/sales/'+context.sale_id+'/exchanges/create',
            '/inventory','/inventory/opening-stock','/inventory/readiness','/purchases','/purchases/create','/purchases/'+context.purchase_id,'/suppliers','/customers',
            '/finance/accounts','/finance/expenses','/finance/expenses/'+context.expense_id,'/finance/treasury?branch_id='+context.branch_id+'&from=2026-10-01&to=2026-10-31',
            '/sales/reports?branch_id='+context.branch_id+'&from=2026-10-01&to=2026-10-31','/finance/transition'];

        for(const width of [390,1280]){
            await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width===390});
            for(const page of pagePaths){
                await send('Page.navigate',{url:origin+page});
                await until('document.readyState==="complete" && location.pathname==='+JSON.stringify(new URL(page,origin).pathname)+' && !!document.querySelector("#sidebar")');
                const overflow=await evaluate('document.documentElement.scrollWidth>innerWidth');
                checked.push({width,path:page,overflow});
            }
            const screenshot=await send('Page.captureScreenshot',{format:'png'});
            await fs.writeFile(path.join(reports,'financial-audit-live-'+width+'.png'),Buffer.from(screenshot.data,'base64'));
        }
        await send('Page.navigate',{url:origin+'/finance/expenses'});
        await until('document.readyState==="complete" && !!document.querySelector("form[data-simple-form]")');
        const today=new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Hebron',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
        await evaluate('(()=>{const f=document.querySelector("form[data-simple-form]");f.elements.namedItem("title").value="Live browser audit expense";f.elements.namedItem("category").value="other";f.elements.namedItem("amount").value="0";["period_start","period_end","transaction_date"].forEach(k=>f.elements.namedItem(k).value='+JSON.stringify(today)+');f.requestSubmit()})()');
        await until('!!document.querySelector("[data-sales-errors]")?.textContent.trim()');
        const validationVisible=await evaluate('document.querySelector("[data-sales-errors]").textContent.trim().length>0');
        await evaluate('(()=>{const f=document.querySelector("form[data-simple-form]");f.elements.namedItem("amount").value="5";f.requestSubmit()})()');
        await until('document.readyState==="complete" && /^\\/finance\\/expenses\\/\\d+$/.test(location.pathname)');
        const createdExpensePath=await evaluate('location.pathname');
        await evaluate('document.querySelector("form[action$=\\"/approve\\"]").requestSubmit()');
        await until('document.readyState==="complete" && !!document.querySelector("form[action$=\\"/payments\\"]")');
        await evaluate('(()=>{const f=document.querySelector("form[action$=\\"/payments\\"]");f.elements.namedItem("account_id").value='+JSON.stringify(String(context.cash_id))+';f.elements.namedItem("amount").value="5";f.elements.namedItem("reason").value="Live browser payment audit";f.requestSubmit()})()');
        await until('document.readyState==="complete" && !document.querySelector("form[action$=\\"/payments\\"]")');
        await send('Page.navigate',{url:origin+'/sales/cashier'});
        await until('document.readyState==="complete" && document.querySelectorAll(".cashier-product").length>0');
        await evaluate('[...document.querySelectorAll(".cashier-product")].find(b=>b.querySelector("small")?.textContent==="A").click()');
        await until('document.querySelector("#cashier-variant-dialog").open');
        await evaluate('document.querySelector("#cashier-variant-sizes button").click();document.querySelector("#cashier-add-variant").click();const payment=document.querySelector("#cashier-payments select");payment.value='+JSON.stringify(String(context.cash_id))+';payment.dispatchEvent(new Event("change",{bubbles:true}))');
        await until('!document.querySelector("#cashier-complete").disabled');
        await evaluate('document.querySelector("#cashier-complete").click()');
        await until('document.readyState==="complete" && /^\\/sales\\/\\d+$/.test(location.pathname)');
        const createdSalePath=await evaluate('location.pathname');
        const result={database:context.database,actual_http:true,checked,validation_visible:validationVisible,createdExpensePath,createdSalePath,
            mutations,failed_responses:statuses.filter(r=>r.status>=400&&r.path!=='/favicon.ico'),javascript_errors:errors,physical_phone_tested:false,external_requests_blocked:true};
        await fs.writeFile(path.join(reports,'financial-audit-live-browser.json'),JSON.stringify(result,null,2));
        console.log(JSON.stringify({database:context.database,pages_checked:checked.length,overflow_pages:checked.filter(p=>p.overflow),
            validation_visible:validationVisible,createdExpensePath,createdSalePath,javascript_errors:errors}));
        assert.deepEqual(errors,[]);
        await send('Browser.close').catch(()=>{});

    } catch(error) {
        await fs.writeFile(path.join(reports,'financial-audit-live-progress.json'),JSON.stringify({message:error.message,checked,mutations,statuses,requests:requests.slice(-40)},null,2));
        throw error;
    } finally {

        socket?.close();

        if(browser.exitCode===null){await new Promise(resolve=>{browser.once('exit',resolve);setTimeout(resolve,1500)});}

        if(browser.exitCode===null)browser.kill();

        phpServer.kill();
        await new Promise(resolve=>proxy.close(resolve));

        if(path.resolve(profile).startsWith(path.resolve(testing)+path.sep) && path.basename(profile).startsWith('financial-audit-chrome-')) await fs.rm(profile,{recursive:true,force:true,maxRetries:6,retryDelay:200}).catch(()=>{});

    }

}

main().catch(error=>{console.error(error.stack);process.exitCode=1;});

