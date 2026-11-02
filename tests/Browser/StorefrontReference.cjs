const fs = require('node:fs/promises');
const path = require('node:path');
const { openChrome } = require('./ChromeSession.cjs');

(async () => {
    const chrome = await openChrome('storefront-reference');
    const result = { reference: 'https://minimoon-store.com/', observations: [] };
    try {
        await chrome.viewport(1440);
        await chrome.navigate('http://127.0.0.1:8000/shop');
        await chrome.screenshot('storefront-before-desktop');
        result.before = { text: await chrome.evaluate('document.body.innerText'), network: chrome.network.filter(row => row.path === '/shop') };
        await chrome.viewport(390); await chrome.screenshot('storefront-before-mobile');
        for (const width of [1440, 390]) {
            await chrome.viewport(width); await chrome.navigate(result.reference);
            await chrome.screenshot('minimoon-reference-home-' + width);
            result.observations.push(await chrome.evaluate('({width:innerWidth,title:document.title,headers:[...document.querySelectorAll("h1,h2")].slice(0,12).map(x=>x.textContent.trim()),background:getComputedStyle(document.body).backgroundColor,font:getComputedStyle(document.body).fontFamily,links:[...document.querySelectorAll("a[href]")].filter(x=>x.getBoundingClientRect().width>0).slice(0,20).map(x=>({text:x.textContent.trim().slice(0,70),path:new URL(x.href).pathname})),images:document.images.length})'));
        }
        const collection = await chrome.evaluate('[...document.querySelectorAll("a")].map(x=>x.href).find(x=>x.includes("/collections/")&&!x.includes("/all"))');
        if (collection) {
            await chrome.viewport(1440); await chrome.navigate(collection);
            await chrome.screenshot('minimoon-reference-collection-desktop');
            let product = await chrome.evaluate('[...document.querySelectorAll("a")].map(x=>x.href).find(x=>x.includes("/products/"))');
            for (let depth = 0; !product && depth < 3; depth++) {
                const next = await chrome.evaluate('[...document.querySelectorAll("a")].find(x=>x.querySelector("img")&&x.href.includes("/collections/")&&x.href!==location.href)?.href');
                if (!next) break;
                await chrome.navigate(next);
                product = await chrome.evaluate('[...document.querySelectorAll("a")].map(x=>x.href).find(x=>x.includes("/products/"))');
            }
            if (product) {
                await chrome.navigate(product); await chrome.screenshot('minimoon-reference-product-desktop');
                await chrome.viewport(390); await chrome.screenshot('minimoon-reference-product-mobile');
                result.product = await chrome.evaluate('({title:document.title,buttons:[...document.querySelectorAll("button,select")].filter(x=>x.getBoundingClientRect().width>0).map(x=>x.textContent.trim().slice(0,90)).slice(0,20)})');
            }
        }
        await chrome.navigate('https://minimoon-store.com/cart'); await chrome.screenshot('minimoon-reference-cart-mobile');
        await chrome.navigate('https://minimoon-store.com/checkout'); await chrome.screenshot('minimoon-reference-checkout-mobile');
        result.referenceErrors = chrome.errors;
        result.referenceNetwork = chrome.network.filter(row => row.host.includes('minimoon')).map(({host,path,status,type}) => ({host,path,status,type}));
        await fs.writeFile(path.resolve(__dirname, '../../storage/app/reports/storefront-reference.json'), JSON.stringify(result, null, 2));
        console.log(JSON.stringify({ before: result.before.network, observed: result.observations.length, productInspected: !!result.product }));
    } finally { chrome.close(); }
})().catch(error => { console.error(error.message); process.exitCode = 1; });
