(() => {
    'use strict';
    const viewport = window.visualViewport;
    if (!viewport) return;
    let frame;
    const update = () => {
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(() => {
            const mobile = window.matchMedia('(max-width: 767px), (max-width: 1023px) and (pointer: coarse)').matches;
            const bottom = Math.max(0, window.innerHeight - viewport.height - viewport.offsetTop);
            document.querySelectorAll('dialog.os-dialog[open], dialog.trade-dialog[open]').forEach(dialog => {
                if (mobile) {
                    dialog.style.setProperty('--dialog-height', `${Math.round(viewport.height)}px`);
                    dialog.style.setProperty('--dialog-top', `${Math.round(viewport.offsetTop)}px`);
                    dialog.style.setProperty('--dialog-bottom', `${Math.round(bottom)}px`);
                } else {
                    ['--dialog-height', '--dialog-top', '--dialog-bottom'].forEach(property => dialog.style.removeProperty(property));
                }
            });
            const typing = document.activeElement?.matches('input, textarea, select');
            document.body.classList.toggle('document-keyboard-open', mobile && typing && bottom > 120);
        });
    };
    viewport.addEventListener('resize', update);
    viewport.addEventListener('scroll', update);
    window.addEventListener('resize', update);
    document.addEventListener('focusin', update);
    document.addEventListener('focusout', update);
    new MutationObserver(update).observe(document.body, { subtree: true, attributes: true, attributeFilter: ['open'] });
    update();
})();
