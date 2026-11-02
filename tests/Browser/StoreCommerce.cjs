const assert=require('node:assert/strict');
const fs=require('node:fs/promises');
const path=require('node:path');
const {spawn}=require('node:child_process');

async function main(){
 const root=path.resolve(__dirname,'../..'),reports=path.join(root,'storage/app/reports');
 const context=JSON.parse(await fs.readFile(path.join(root,'storage/app/private/store-preview/credentials.json'),'utf8'));
 assert.equal(context.database,'appstore_store_install_20261101_tests');assert.equal(context.base_url,'http://127.0.0.1:8012');
 const origin=context.base_url,profile=path.join(root,'storage/framework/testing/store-browser-'+Date.now());
 await fs.mkdir(profile,{recursive:true});
 const browser=spawn('C:/Program Files/Google/Chrome/Application/chrome.exe',['--headless=new','--remote-debugging-port=0','--user-data-dir='+profile,
 '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1','--disable-background-networking','--disable-component-update','--disable-sync','--no-first-run','--no-default-browser-check','--disable-extensions','about:blank'],
 {windowsHide:true,stdio:['ignore','ignore','pipe']});
 let socket;
 try{
 const endpoint=await new Promise((resolve,reject)=>{const timeout=setTimeout(()=>reject(Error('Chrome startup timeout')),15000);browser.stderr.on('data',data=>{const m=String(data).match(/DevTools listening on (ws:\/\/[^\s]+)/);if(m){clearTimeout(timeout);resolve(m[1]);}});browser.once('error',reject);});
 const port=new URL(endpoint).port,pages=await(await fetch('http://127.0.0.1:'+port+'/json/list')).json();
 socket=new WebSocket(pages.find(p=>p.type==='page').webSocketDebuggerUrl);await new Promise((resolve,reject)=>{socket.addEventListener('open',resolve,{once:true});socket.addEventListener('error',reject,{once:true});});
 let id=0;const pending=new Map(),errors=[],requests=[],checked=[];let guestPayload;
 function send(method,params={}){return new Promise((resolve,reject)=>{const key=++id;pending.set(key,{resolve,reject});socket.send(JSON.stringify({id:key,method,params}));});}
 socket.addEventListener('message',event=>{const data=JSON.parse(String(event.data));if(data.id){const item=pending.get(data.id);if(item){pending.delete(data.id);data.error?item.reject(Error(data.error.message)):item.resolve(data.result);}return;}
 if(data.method==='Runtime.exceptionThrown')errors.push(data.params.exceptionDetails.text);
 if(data.method==='Network.requestWillBeSent'&&data.params.request.url.startsWith(origin+'/')){const req=data.params.request;requests.push({method:req.method,path:new URL(req.url).pathname});if(new URL(req.url).pathname==='/shop/orders'&&req.postData)guestPayload=JSON.parse(req.postData);}
 if(data.method==='Fetch.requestPaused'){const allowed=data.params.request.url.startsWith(origin+'/');send(allowed?'Fetch.continueRequest':'Fetch.failRequest',{requestId:data.params.requestId,...(allowed?{}:{errorReason:'BlockedByClient'})}).catch(()=>{});}
 });
 await send('Runtime.enable');await send('Page.enable');await send('Network.enable');await send('Fetch.enable',{patterns:[{urlPattern:'http*'}]});
 async function evaluate(expression){const r=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.exception?.description||r.exceptionDetails.text);return r.result.value;}
 async function until(expression){const start=Date.now();while(Date.now()-start<25000){if(await evaluate(expression))return;await new Promise(r=>setTimeout(r,80));}throw Error('Condition timeout: '+expression+' '+JSON.stringify(await evaluate('({path:location.pathname,title:document.title,text:document.body?.innerText.slice(0,500)})')));}
 async function navigate(url,selector){await send('Page.navigate',{url:origin+url});await until('document.readyState==="complete" && !!document.querySelector('+JSON.stringify(selector)+')');}
 async function shot(label,width){await send('Emulation.setDeviceMetricsOverride',{width,height:900,deviceScaleFactor:1,mobile:width<600});await new Promise(r=>setTimeout(r,160));
 const info=await evaluate('({width:innerWidth,scroll:document.documentElement.scrollWidth,rtl:document.documentElement.dir})');assert.equal(info.rtl,'rtl');assert.ok(info.scroll<=info.width+1,label+' horizontal overflow '+JSON.stringify(info));
 const image=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});await fs.writeFile(path.join(reports,label+'-'+width+'.png'),Buffer.from(image.data,'base64'));checked.push({page:label,width,overflow:false});}
 await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
 await navigate('/shop','.live-product-card');assert.ok((await evaluate('document.body.innerText')).includes('معاينة'));assert.equal(await evaluate('document.querySelectorAll(".live-product-card").length'),3);
 await shot('store-home',390);await shot('store-home',1280);
 await navigate('/shop/products/preview-1','[data-add-product]');await shot('store-product',390);await shot('store-product',1280);
 await evaluate('document.querySelector("[data-add-product] select").selectedIndex=1;document.querySelector("[data-add-product]").requestSubmit()');
 assert.equal(await evaluate('JSON.parse(localStorage.getItem("appstore-public-cart-v1"))[0].quantity'),1);
 await navigate('/shop/checkout','[data-checkout-form]');await evaluate('document.querySelector("select[name=delivery_zone_id]").value="1";document.querySelector("select[name=delivery_zone_id]").dispatchEvent(new Event("change",{bubbles:true}))');
 await until('document.querySelector("[data-quote-total]").textContent.includes("95.00")');await shot('store-checkout',390);
 await evaluate('document.querySelector("[name=customer_name]").value="عميل متصفح تجريبي";document.querySelector("[name=phone]").value="0599000123";document.querySelector("[name=address]").value="عنوان متصفح تجريبي معزول";document.querySelector("[name=accepted]").checked=true;document.querySelector("[data-checkout-form]").requestSubmit()');
 await until('location.pathname.includes("/receipt")');const receiptPath=await evaluate('location.pathname');await until('document.readyState==="complete"&&!!document.querySelector(".live-order-success")');await shot('store-receipt',390);
 assert.ok(guestPayload,'Guest request captured in memory');
 const repeated=await evaluate('fetch('+JSON.stringify(origin+'/shop/orders')+',{method:"POST",headers:{"Content-Type":"application/json",Accept:"application/json","X-CSRF-TOKEN":document.querySelector("meta[name=csrf-token]").content},body:JSON.stringify('+JSON.stringify(guestPayload)+')}).then(async r=>({status:r.status,body:await r.json()}))');
 assert.equal(repeated.status,200);assert.equal(new URL(repeated.body.url).pathname,receiptPath);
 await navigate('/login','#login-form');await evaluate('document.querySelector("[name=username]").value='+JSON.stringify(context.username)+';document.querySelector("[name=password]").value='+JSON.stringify(context.password)+';document.querySelector("#login-form").requestSubmit()');
 await until('location.pathname==="/dashboard"&&document.readyState==="complete"');await shot('store-dashboard',1280);await shot('store-dashboard',390);
 for(const [route,label] of [['/site-admin/content/products','store-admin-products'],['/site-admin/content/zones/1/edit','store-admin-zone'],['/site-admin/orders/'+receiptPath.split('/')[3],'store-admin-order']]){
 await navigate(route,'.commerce-page');await shot(label,390);await shot(label,1280);
 }
 await navigate('/inventory/products/1/minimum','.content-form, .commerce-form');
 await evaluate('document.querySelector("[name=apply_all]").checked=true;document.querySelector("[name=minimum]").value="80.00";document.querySelector("form.commerce-form").requestSubmit()');
 await until('location.pathname==="/inventory/products/1"&&document.readyState==="complete"');
 await navigate('/sales/cashier','#cashier-products');await until('document.querySelectorAll(".cashier-product").length>0');await evaluate('[...document.querySelectorAll(".cashier-product")].find(x=>x.textContent.includes("قميص")).click()');await until('document.querySelector("#cashier-variant-dialog").open');
 await evaluate('document.querySelector("#cashier-variant-sizes button").click();document.querySelector("#cashier-add-variant").click()');
 await until('document.querySelector("#cashier-preview-status").textContent.includes("الحد الأدنى")');assert.equal(await evaluate('document.querySelector("#cashier-complete").disabled'),true);
 await shot('store-cashier-minimum',390);
 await evaluate('document.querySelector("#cashier-minimum-confirm").click()');await until('!document.querySelector("#cashier-complete").disabled');
 await evaluate('document.querySelector("#cashier-form").requestSubmit()');await until('location.pathname.startsWith("/sales/")&&!location.pathname.includes("cashier")&&document.readyState==="complete"');
 await until('document.body.innerText.includes("تجاوزات الحد الأدنى")');
 await until('Number(document.querySelector("[data-alert-count]")?.textContent)>=2');
 await evaluate('[...document.querySelectorAll("[data-alert-toggle]")].find(x=>x.getBoundingClientRect().width>0).click()');await shot('store-alerts',390);
 await evaluate('fetch("/notifications/read",{method:"POST",headers:{Accept:"application/json","X-CSRF-TOKEN":document.querySelector("meta[name=csrf-token]").content}}).then(r=>{if(!r.ok)throw Error("mark read failed");return r.json()})');
 assert.deepEqual(errors,[]);
 const result={database:context.database,transport:'live HTTP Chrome',checked,guest_order_replay_same_receipt:true,below_minimum_ui_confirmation:true,web_notifications_visible:true,js_exceptions:errors,mutating_paths:requests.filter(r=>r.method!=='GET'),physical_iphone_tested:false,external_assets_blocked:true};
 await fs.writeFile(path.join(reports,'store-commerce-browser.json'),JSON.stringify(result,null,2));console.log(JSON.stringify({checked:checked.length,guest_order_replay:true,minimum_confirmation:true,js_exceptions:errors.length}));
 }finally{socket?.close();browser.kill();}
}
main().catch(error=>{console.error(error.message);process.exitCode=1;});
