(() => {
    'use strict';
    const root = document.querySelector('[data-global-search]');
    if (root) {
        const input = root.querySelector('input'), form = root.querySelector('form');
        const menu = root.querySelector('.global-search-dropdown'), status = root.querySelector('[data-global-status]');
        const options = root.querySelector('[data-global-options]'), all = root.querySelector('[data-global-all]');
        let timer, controller, revision = 0, active = -1;
        const mobile = window.matchMedia('(max-width:768px)');
        function place() {
            const slot = document.getElementById(mobile.matches ? 'global-search-mobile' : 'global-search-desktop');
            if (slot) slot.append(root);
        }
        mobile.addEventListener('change', place); place();
        function close() {
            clearTimeout(timer); controller?.abort(); revision++; active = -1;
            menu.hidden = true; input.setAttribute('aria-expanded','false'); input.removeAttribute('aria-activedescendant');
        }
        function open() { menu.hidden = false; input.setAttribute('aria-expanded','true'); }
        async function suggest() {
            const query = input.value.trim(); controller?.abort(); const version = ++revision;
            options.replaceChildren(); active = -1; input.removeAttribute('aria-activedescendant');
            if (!query) { close(); return; }
            open(); status.textContent = 'جارٍ البحث…';
            const url = new URL(root.dataset.url); url.searchParams.set('q',query);
            const full = new URL(form.action); full.searchParams.set('q',query); all.href = full;
            const request = new AbortController(); controller = request;
            try {
                const response = await fetch(url,{signal:request.signal,headers:{Accept:'application/json'},cache:'no-store'});
                if (!response.ok || response.redirected) throw new Error(response.status === 429 ? 'طلبات كثيرة؛ انتظر لحظة ثم أعد المحاولة.' : 'تعذر البحث. تحقق من اتصالك أو تسجيل الدخول.');
                const result = await response.json();
                if (version !== revision || query !== input.value.trim()) return;
                let count = 0;
                result.groups.forEach(group => {
                    const heading = document.createElement('h3'); heading.textContent = group.label; options.append(heading);
                    group.entries.forEach(entry => {
                        const target = new URL(entry.url,location.href); if (target.origin !== location.origin) return;
                        const link = document.createElement('a'); link.href = target; link.id = 'global-option-'+version+'-'+count++;
                        link.setAttribute('role','option'); link.setAttribute('aria-selected','false');
                        const title = document.createElement('strong'), detail = document.createElement('small');
                        title.textContent = entry.title; detail.textContent = entry.detail; link.append(title,detail);
                        if (entry.product) {
                            if (entry.product.image_url) { const image = document.createElement('img'); image.src = entry.product.image_url; image.alt = entry.title; image.width = 42; image.height = 48; image.loading = 'lazy'; link.prepend(image); }
                            const quantity = document.createElement('small'); quantity.textContent = 'المتاح: '+entry.product.quantity+' — '+entry.product.branch; link.append(quantity);
                        }
                        options.append(link);
                    });
                });
                status.textContent = count ? 'اقتراحات ضمن صلاحياتك — Enter لعرض النتائج كاملة.' : 'لا توجد نتائج مطابقة.';
            } catch (error) { if (error.name !== 'AbortError' && version === revision) status.textContent = error.message; }
        }
        input.addEventListener('input',()=>{clearTimeout(timer);controller?.abort();revision++;options.replaceChildren();active=-1;input.removeAttribute('aria-activedescendant');if (!input.value.trim()) { close();return; } open();status.textContent='جارٍ البحث…';timer=setTimeout(suggest,300);});
        input.addEventListener('focus',()=>{if(input.value.trim()) {clearTimeout(timer);timer=setTimeout(suggest,300);}});
        input.addEventListener('keydown',event=>{
            if(event.key==='Escape'){event.preventDefault();close();return;}
            const links=[...options.querySelectorAll('[role=option]')];
            if((event.key==='ArrowDown'||event.key==='ArrowUp') && links.length && !menu.hidden){
                event.preventDefault();active=active<0?(event.key==='ArrowDown'?0:links.length-1):(active+(event.key==='ArrowDown'?1:-1)+links.length)%links.length;
                links.forEach((link,index)=>link.setAttribute('aria-selected',String(index===active)));
                input.setAttribute('aria-activedescendant',links[active].id);links[active].scrollIntoView({block:'nearest'});
            } else if(event.key==='Enter' && active>=0 && !menu.hidden){event.preventDefault();location.assign(links[active].href);}
        });
        document.addEventListener('pointerdown',event=>{if(!root.contains(event.target))close();});
        document.addEventListener('focusin',event=>{if(!root.contains(event.target))close();});
        form.addEventListener('submit',close);
    }
    let localTimer, pending, ticket=0;
    const notice=document.createElement('p');notice.className='module-search-status';notice.setAttribute('role','status');notice.hidden=true;document.body.append(notice);
    function formUrl(form) {
        const url=new URL(form.action||location.href);url.search=location.search;
        [...form.elements].forEach(field=>{if(field.name)url.searchParams.delete(field.name);});
        for(const [key,value] of new FormData(form)){if(String(value).trim()!=='')url.searchParams.append(key,String(value).trim());}
        url.searchParams.delete(form.dataset.pageKey || 'page');
        return url;
    }
    async function refresh(url,region,push=true,focusName=null) {
        clearTimeout(localTimer);pending?.abort();pending=new AbortController();const id=++ticket;
        region.setAttribute('aria-busy','true');notice.hidden=false;notice.textContent='جارٍ تحديث النتائج…';
        const focus=document.activeElement,caret=focus?.selectionStart;
        try {
            const response=await fetch(url,{signal:pending.signal,headers:{Accept:'text/html'},cache:'no-store'});
            if(!response.ok||response.redirected)throw new Error(response.status===422?'راجع البحث والفلاتر والتواريخ المدخلة.':'تعذر تحديث النتائج. تحقق من اتصالك أو جلسة الدخول.');
            const documentNext=new DOMParser().parseFromString(await response.text(),'text/html');
            const next=[...documentNext.querySelectorAll('[data-search-region]')].find(item=>item.dataset.searchRegion===region.dataset.searchRegion);
            if(!next)throw new Error('تعذر تحديث هذه القائمة. حدّث الصفحة.');
            if(id!==ticket)return;
            region.replaceWith(next);if(push)history.pushState(null,'',url);
            if(focusName){const field=next.querySelector('[name="'+CSS.escape(focusName)+'"]');if(field){field.focus({preventScroll:true});if(typeof caret==='number'&&field.type!=='number')field.setSelectionRange?.(caret,caret);}}
            notice.hidden=true;document.dispatchEvent(new Event('module-search:updated'));
        } catch(error){if(error.name!=='AbortError'&&id===ticket){notice.textContent=error.message;notice.hidden=false;}}
        finally{region.removeAttribute('aria-busy');}
    }
    document.addEventListener('submit',event=>{
        const form=event.target.closest('form[data-module-search]');if(!form)return;
        const region=form.closest('[data-search-region]');if(!region)return;
        event.preventDefault();refresh(formUrl(form),region);
    });
    document.addEventListener('input',event=>{
        const form=event.target.closest('form[data-module-search]');if(!form||!['q','invoices_q','payments_q','entry_q'].includes(event.target.name))return;
        const region=form.closest('[data-search-region]');if(!region)return;
        clearTimeout(localTimer);pending?.abort();ticket++;const name=event.target.name;
        localTimer=setTimeout(()=>refresh(formUrl(form),region,true,name),300);
    });
    document.addEventListener('change',event=>{
        const form=event.target.closest('form[data-module-search]');if(!form||event.target.matches('input[type=search],input[name=q]'))return;
        const region=form.closest('[data-search-region]');if(region)refresh(formUrl(form),region);
    });
    document.addEventListener('click',event=>{
        const clear=event.target.closest('[data-search-clear]');
        if(clear){const form=clear.closest('form');const field=form?.querySelector('[name=q],[name=invoices_q],[name=payments_q],[name=entry_q]');if(field){field.value='';form.requestSubmit();}return;}
        const link=event.target.closest('a[href]'),region=link?.closest('[data-search-region]');
        if(!link||!region||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey||event.button!==0)return;
        const url=new URL(link.href);
        const pageLink=url.pathname===location.pathname&&[...url.searchParams.keys()].some(key=>key==='page'||key.endsWith('_page'));
        if(pageLink){event.preventDefault();refresh(url,region);}
    });
    window.addEventListener('popstate',()=>{const region=document.querySelector('[data-search-region]');if(region)refresh(new URL(location.href),region,false);});
})();