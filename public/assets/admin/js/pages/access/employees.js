(() => {
    'use strict';
    const select = document.querySelector('[data-employee-role]');
    const source = document.getElementById('employee-role-definitions');
    if (!select || !source) return;
    const roles = JSON.parse(source.textContent);
    const status = document.querySelector('[data-employee-role-status]');
    const list = document.querySelector('[data-employee-role-summary]');
    function preview() {
        const role = roles[select.value];
        status.textContent = role ? role.name : 'اختر دورًا لعرض صلاحياته.';
        list.replaceChildren();
        (role?.permissions || []).forEach(label => {
            const item = document.createElement('li');
            item.textContent = label;
            list.append(item);
        });
    }
    select.addEventListener('change', preview);
    preview();
})();
