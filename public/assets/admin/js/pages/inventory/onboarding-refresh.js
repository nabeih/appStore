(() => {
    'use strict';
    window.addEventListener('pageshow', event => {
        if (event.persisted && document.querySelector('[data-owner-onboarding]')) window.location.reload();
    });
})();
