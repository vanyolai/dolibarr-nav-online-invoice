(function () {
    'use strict';

    function isNavInvoiceDetailPage() {
        return /\/navinvoice\/detail\.php$/i.test(window.location.pathname || '');
    }

    function moduleBaseUrl() {
        var scripts = document.getElementsByTagName('script');
        for (var i = scripts.length - 1; i >= 0; i--) {
            var src = scripts[i].src || '';
            if (/\/navinvoice\/js\/navpurchase-button\.js(?:\?|$)/i.test(src)) {
                return src.replace(/\/js\/navpurchase-button\.js(?:\?.*)?$/i, '');
            }
        }
        var root = window.DOL_URL_ROOT || '';
        return root + '/custom/navinvoice';
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

        fetch(moduleBaseUrl() + '/purchasebutton.php?id=' + encodeURIComponent(id), {
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
