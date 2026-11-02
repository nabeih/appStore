(() => {
    'use strict';
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    sidebar.querySelectorAll('[data-nav-group]').forEach(group => {
        const summary = group.querySelector('summary');
        const sync = () => summary.setAttribute('aria-expanded', String(group.open));
        group.addEventListener('toggle', sync);
        sync();
    });
    const opener = document.getElementById('auth-menu-toggle');
    const overlay = document.getElementById('sidebarOverlay');
    const syncMobile = () => {
        const open = matchMedia('(max-width: 767px)').matches && sidebar.classList.contains('auth-open');
        document.body.classList.toggle('sidebar-open', open);
        sidebar.inert = matchMedia('(max-width: 767px)').matches && !open;
    };
    new MutationObserver(syncMobile).observe(sidebar, { attributes: true, attributeFilter: ['class'] });
    window.addEventListener('resize', syncMobile);
    sidebar.querySelector('[data-sidebar-close]')?.addEventListener('click', () => {
        sidebar.classList.remove('auth-open');
        overlay?.classList.remove('auth-open');
        opener?.setAttribute('aria-expanded', 'false');
        opener?.focus();
    });
    sidebar.addEventListener('keydown', event => {
        if (event.key !== 'Tab' || !matchMedia('(max-width: 767px)').matches || !sidebar.classList.contains('auth-open')) return;
        const items = [...sidebar.querySelectorAll('a,button,summary')].filter(item => item.getClientRects().length && !item.disabled);
        const first = items[0], last = items[items.length - 1];
        if (event.shiftKey && document.activeElement === first) {event.preventDefault();last?.focus();}
        else if (!event.shiftKey && document.activeElement === last) {event.preventDefault();first?.focus();}
    });
    opener?.addEventListener('click', () => {
        syncMobile();
        if (sidebar.classList.contains('auth-open')) sidebar.querySelector('[data-sidebar-close]')?.focus();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && sidebar.contains(document.activeElement)) opener?.focus();
    });
    syncMobile();
})();
