(() => {
    'use strict';
    function update(id, property, minimum, maximum, suffix = '') {
        const field = document.getElementById(id);
        const apply = () => {
            const number = Number(field.value);
            if (Number.isFinite(number) && number >= minimum && number <= maximum && (property !== '--label-columns' || Number.isInteger(number))) document.documentElement.style.setProperty(property, number + suffix);
        };
        field.addEventListener('input', apply);
        apply();
    }
    update('label-width', '--label-width', 30, 300, 'mm');
    update('label-height', '--label-height', 25, 100, 'mm');
    update('label-columns', '--label-columns', 1, 5);
    document.getElementById('print-product-labels').addEventListener('click', () => window.print());
})();
