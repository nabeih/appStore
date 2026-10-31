(() => {
    'use strict';
    const form = document.getElementById('opening-draft-edit'); if (!form) return;
    form.addEventListener('submit',async event => {
        event.preventDefault(); if (form.dataset.busy) return;
        const data = { version: Number(form.dataset.version), rows: [...form.querySelectorAll('[data-draft-item]')].map(row => ({
            id: Number(row.dataset.draftItem), quantity: row.querySelector('[data-draft-quantity]').value, cost: row.querySelector('[data-draft-cost]').value,
        })) };
        form.dataset.busy = '1'; form.querySelector('fieldset').disabled = true; SalesUI.error(form,'');
        try { const result = await SalesUI.request(form.dataset.url,{ method: 'PUT',data }); location.assign(result.url); }
        catch (failure) { SalesUI.error(form,failure); delete form.dataset.busy; form.querySelector('fieldset').disabled = false; }
    });
})();
