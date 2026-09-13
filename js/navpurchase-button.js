(function () {
    'use strict';

    function isNavInvoiceDetailPage() {
        return /\/navinvoice\/detail\.php$/i.test(window.location.pathname || '');
    }

    function addWorkbenchButton(data) {
        if (!data || !data.show || !data.url) {
            return;
        }
        if (document.querySelector('[data-nav-purchase-workbench-button="1"]')) {
            return;
        }

        var actions = document.querySelector('.tabsAction');
        if (!actions) {
            return;
        }

        var link = document.createElement('a');
        link.className = 'butAction';
        link.href = data.url;
        link.textContent = data.label || 'Purchase workbench';
        link.setAttribute('data-nav-purchase-workbench-button', '1');
        actions.appendChild(link);
    }

    function init() {
        if (!isNavInvoiceDetailPage()) {
            return;
        }
        var params = new URLSearchParams(window.location.search || '');
        var id = parseInt(params.get('id') || '0', 10);
        if (!id) {
            return;
        }

        var base = window.DOL_URL_ROOT || '';
        fetch(base + '/custom/navinvoice/purchasebutton.php?id=' + encodeURIComponent(id), {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'}
        }).then(function (response) {
            if (!response.ok) {
                return null;
            }
            return response.json();
        }).then(addWorkbenchButton).catch(function () {
            // Optional enhancement only; keep the invoice detail page usable.
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
