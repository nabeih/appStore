(() => {
'use strict';
const roots=[...document.querySelectorAll('[data-app-alerts]')];if(!roots.length)return;
let timer,activeRequest,delay=30000;
const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content;
const setCount=(nodes,n)=>nodes.forEach(x=>{x.textContent=n>99?'99+':String(n);x.hidden=!n;});
async function read(url){const response=await fetch(url,{method:'POST',credentials:'same-origin',headers:{Accept:'application/json','X-CSRF-TOKEN':csrf()}});if(!response.ok)throw Error('تعذر تعليم الإشعار.');}
async function refresh(){
 if(document.hidden)return;
 activeRequest?.abort();activeRequest=new AbortController();
 try{
 const response=await fetch(roots[0].dataset.url,{credentials:'same-origin',headers:{Accept:'application/json'},signal:activeRequest.signal});
 if(!response.ok)throw Error('تعذر تحديث الإشعارات.');const result=await response.json();
 setCount(document.querySelectorAll('[data-alert-count]'),result.unread);setCount(document.querySelectorAll('[data-store-order-count]'),result.new_orders);
 roots.forEach(root=>{const list=root.querySelector('[data-alert-list]');list.replaceChildren();root.querySelector('[data-alert-status]').textContent=result.enabled?(result.data.length?'':'لا توجد إشعارات.'):'الإشعارات تنتظر اعتماد تحديث قاعدة البيانات.';
 result.data.forEach(item=>{const row=document.createElement('article');row.className='app-alert-entry'+(item.read?'':' is-unread');const link=document.createElement('a');link.href=item.url;link.textContent=item.title;link.addEventListener('click',async event=>{if(event.ctrlKey||event.metaKey||event.shiftKey||event.button!==0)return;event.preventDefault();try{await read(root.dataset.readAll.replace('/read','/'+item.id+'/read'));}catch{}location.assign(item.url);});const text=document.createElement('p');text.textContent=item.message;const date=document.createElement('small');date.textContent=new Date(item.created_at).toLocaleString('ar');row.append(link,text,date);list.append(row);});});delay=30000;
 }catch(error){if(error.name!=='AbortError')delay=Math.min(delay*2,300000);}finally{clearTimeout(timer);if(!document.hidden)timer=setTimeout(refresh,delay);}
}
roots.forEach(root=>{const button=root.querySelector('[data-alert-toggle]'),menu=root.querySelector('[data-alert-menu]');button.addEventListener('click',()=>{menu.hidden=!menu.hidden;button.setAttribute('aria-expanded',String(!menu.hidden));});root.querySelector('[data-alert-read-all]').addEventListener('click',async()=>{try{await read(root.dataset.readAll);refresh();}catch(error){root.querySelector('[data-alert-status]').textContent=error.message;}});});
document.addEventListener('click',event=>roots.forEach(root=>{if(!root.contains(event.target)){root.querySelector('[data-alert-menu]').hidden=true;root.querySelector('[data-alert-toggle]').setAttribute('aria-expanded','false');}}));
document.addEventListener('visibilitychange',()=>{clearTimeout(timer);if(document.hidden)activeRequest?.abort();else refresh();});window.addEventListener('pagehide',()=>{clearTimeout(timer);activeRequest?.abort();});refresh();
})();
