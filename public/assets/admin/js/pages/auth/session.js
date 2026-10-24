(() => {
    'use strict';
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) window.location.reload();
    });
    const toggle = document.getElementById('auth-menu-toggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    const setOpen = (open) => {
        sidebar.classList.toggle('auth-open', open);
        overlay.classList.toggle('auth-open', open);
        toggle.setAttribute('aria-expanded', String(open));
    };
    toggle.addEventListener('click', () => setOpen(!sidebar.classList.contains('auth-open')));
    overlay.addEventListener('click', () => setOpen(false));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') setOpen(false);
    });
})();
