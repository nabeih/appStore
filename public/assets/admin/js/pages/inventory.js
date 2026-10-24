
/**
 * Inventory Page
 * Laravel Blade + MySQL
 *
 * Responsibilities:
 * - Purchase/inventory tab navigation
 * - Inventory table search (minimum 3 characters)
 * - Quantity filtering
 * - Visible results counter
 * - Purchase payment fields visibility
 *
 * Inventory data is rendered by Laravel Blade.
 * This file never changes stock quantities.
 */

(() => {
    'use strict';

    const MIN_SEARCH_LENGTH = 3;

    const TAB_NAMES = ['invoice', 'stock', 'history'];

    /**
     * Switch between the three page tabs.
     */
    function switchPurchSubtab(tabName) {
        if (!TAB_NAMES.includes(tabName)) {
            return;
        }

        TAB_NAMES.forEach(name => {
            const panel = document.getElementById(
                `purchSubtab-${name}`
            );

            const button = document.getElementById(
                `tabBtn-purch-${name}`
            );

            const isActive = name === tabName;

            if (panel) {
                panel.style.display = isActive ? 'block' : 'none';
            }

            if (button) {
                button.classList.toggle('active', isActive);
                button.setAttribute(
                    'aria-selected',
                    String(isActive)
                );
            }
        });
    }

    // Compatibility with original HTML onclick handlers.
    window.switchPurchSubtab = switchPurchSubtab;

    /**
     * Initialize tab buttons.
     */
    function initializeTabs() {
        document
            .querySelectorAll('[data-purch-tab]')
            .forEach(button => {
                button.addEventListener('click', () => {
                    const tabName = button.dataset.purchTab;

                    switchPurchSubtab(tabName);
                });
            });

        // Default page tab: current inventory.
        switchPurchSubtab('stock');
    }

    /**
     * Initialize inventory search and quantity filter.
     *
     * Search is performed only on the current
     * paginated page rendered by Laravel.
     */
    function initializeInventoryFilters() {
        function applyFilters() {
            const tableBody = document.getElementById('inventoryTableBody');
            if (!tableBody) return;
            const query = (document.getElementById('inventorySearch')?.value || '').trim().toLocaleLowerCase();
            const quantityFilter = document.getElementById('inventoryQuantityFilter')?.value || 'all';
            const rows = Array.from(tableBody.querySelectorAll('tr[data-inventory-row]'));
            let noResults = document.getElementById('inventoryNoSearchResults');
            if (!noResults && rows.length) {
                noResults = document.createElement('tr');
                noResults.id = 'inventoryNoSearchResults';
                const cell = document.createElement('td');
                cell.colSpan = tableBody.closest('table').querySelectorAll('thead th').length;
                cell.className = 'text-center'; cell.style.padding = '24px';
                cell.textContent = 'لا توجد منتجات مطابقة للبحث أو الفلتر.';
                noResults.appendChild(cell); tableBody.appendChild(noResults);
            }
            let visible = 0;
            rows.forEach(row => {
                const text = Array.from(row.cells).filter(cell => !cell.classList.contains('inventory-setup-actions')).map(cell => cell.textContent).join(' ').replace(/\s+/g, ' ').toLocaleLowerCase();
                const quantity = Number(row.dataset.quantity || 0);
                const matches = (query.length < MIN_SEARCH_LENGTH || text.includes(query))
                    && (quantityFilter !== 'available' || quantity > 0)
                    && (quantityFilter !== 'empty' || quantity === 0);
                row.style.display = matches ? '' : 'none';
                if (matches) visible++;
            });
            if (noResults) noResults.style.display = rows.length && !visible ? '' : 'none';
            const counter = document.getElementById('inventoryVisibleCount');
            if (counter) counter.textContent = `النتائج الظاهرة: ${visible}`;
        }
        document.addEventListener('input', event => { if (event.target.id === 'inventorySearch') applyFilters(); });
        document.addEventListener('change', event => { if (event.target.id === 'inventoryQuantityFilter') applyFilters(); });
        document.addEventListener('inventory:refreshed', applyFilters);
        applyFilters();
    }
    /**
     * Show or hide bank transfer fields
     * based on the selected payment method.
     *
     * This does not submit or save an invoice.
     */
    function initializePurchasePaymentFields() {
        const paymentMethod = document.getElementById(
            'purchMethod'
        );

        const bankDetailsBox = document.getElementById(
            'purchBankDetailsBox'
        );

        if (!paymentMethod || !bankDetailsBox) {
            return;
        }

        function updateBankDetailsVisibility() {
            const method = paymentMethod.value;

            bankDetailsBox.style.display =
                method === 'bank' || method === 'partial'
                    ? 'block'
                    : 'none';
        }

        paymentMethod.addEventListener(
            'change',
            updateBankDetailsVisibility
        );

        updateBankDetailsVisibility();
    }

    /**
     * Clear purchase product search.
     *
     * Purchase search itself will be connected
     * to the backend in the purchases phase.
     */
    function initializePurchaseSearch() {
        const searchInput = document.getElementById(
            'purchSearch'
        );

        const clearButton = document.getElementById(
            'clearPurchSearchBtn'
        );

        if (!searchInput || !clearButton) {
            return;
        }

        clearButton.addEventListener('click', () => {
            searchInput.value = '';

            searchInput.dispatchEvent(
                new Event('input', {
                    bubbles: true
                })
            );

            searchInput.focus();
        });
    }

    /**
     * Page initialization.
     */
    function initializeInventoryPage() {
        const page = document.getElementById(
            'screen-inventory'
        );

        if (!page) {
            return;
        }

        initializeTabs();
        initializeInventoryFilters();
        initializePurchasePaymentFields();
        initializePurchaseSearch();
    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            initializeInventoryPage
        );
    } else {
        initializeInventoryPage();
    }
})();
