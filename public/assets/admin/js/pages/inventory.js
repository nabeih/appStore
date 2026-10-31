(() => {
    'use strict';
    window.switchPurchSubtab = tab => {
        ['invoice','stock','history'].forEach(name => {
            const panel=document.getElementById('purchSubtab-'+name);
            if(panel) panel.style.display=name===tab?'block':'none';
        });
    };
})();