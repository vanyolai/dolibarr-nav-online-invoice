(function () {
    'use strict';

    function decodeEscapedWhitespace(value) {
        var current = String(value || '');
        for (var i = 0; i < 4; i++) {
            var next = current
                .replace(/\\r\\n/g, '\n')
                .replace(/\\n/g, '\n')
                .replace(/\\r/g, '\n')
                .replace(/\\t/g, '\t');
            if (next === current) {
                break;
            }
            current = next;
        }
        return current.replace(/^\uFEFF/, '');
    }

    function prettyXml(value) {
        var xml = decodeEscapedWhitespace(value).trim();
        if (!xml) {
            return xml;
        }

        try {
            var parsed = new DOMParser().parseFromString(xml, 'application/xml');
            if (parsed.getElementsByTagName('parsererror').length) {
                return xml;
            }
        } catch (e) {
            return xml;
        }

        var lines = xml.replace(/>\s*</g, '>\n<').split('\n');
        var depth = 0;
        var output = [];

        lines.forEach(function (line) {
            var trimmed = line.trim();
            if (!trimmed) {
                return;
            }

            if (/^<\//.test(trimmed)) {
                depth = Math.max(0, depth - 1);
            }

            output.push(new Array(depth + 1).join('  ') + trimmed);

            var isOpeningOnly = /^<[^!?/][^>]*>\s*$/.test(trimmed)
                && !/\/>\s*$/.test(trimmed)
                && !/<\/[^>]+>\s*$/.test(trimmed);
            if (isOpeningOnly) {
                depth++;
            }
        });

        return output.join('\n');
    }

    function enhanceTechnicalXml() {
        if (window.location.pathname.indexOf('/navinvoice/detail.php') === -1) {
            return;
        }

        document.querySelectorAll('details pre').forEach(function (pre) {
            var source = pre.textContent || '';
            if (source.indexOf('<') === -1 || (source.indexOf('InvoiceData') === -1 && source.indexOf('<?xml') === -1)) {
                return;
            }

            pre.textContent = prettyXml(source);

            var details = pre.closest('details');
            if (details) {
                details.style.display = 'block';
                details.style.width = '100%';
                details.style.maxWidth = '100%';
                details.style.minWidth = '0';
                details.style.overflow = 'hidden';
                details.style.boxSizing = 'border-box';
            }

            pre.style.display = 'block';
            pre.style.width = '100%';
            pre.style.maxWidth = '100%';
            pre.style.minWidth = '0';
            pre.style.maxHeight = '700px';
            pre.style.overflow = 'auto';
            pre.style.boxSizing = 'border-box';
            pre.style.whiteSpace = 'pre-wrap';
            pre.style.overflowWrap = 'anywhere';
            pre.style.wordBreak = 'break-word';
            pre.style.tabSize = '2';
        });
    }

    function removeRedundantInvoiceAction() {
        if (window.location.pathname.indexOf('/navinvoice/detail.php') === -1) {
            return;
        }

        document.querySelectorAll('.tabsAction a.butAction').forEach(function (link) {
            var href = link.getAttribute('href') || '';
            if (href.indexOf('/fourn/facture/card.php?facid=') !== -1
                || href.indexOf('/compta/facture/card.php?facid=') !== -1) {
                link.remove();
            }
        });

        document.querySelectorAll('.tabsAction').forEach(function (actions) {
            if (!actions.querySelector('a,button,input')) {
                actions.remove();
            }
        });
    }

    function element(tag, text, className) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }
        return node;
    }

    function insertBeforeTechnicalXml(section) {
        var technicalDetails = null;
        document.querySelectorAll('details').forEach(function (details) {
            if (!technicalDetails && details.querySelector('pre')) {
                technicalDetails = details;
            }
        });
        if (technicalDetails && technicalDetails.parentNode) {
            var anchor = technicalDetails.parentNode;
            if (anchor.parentNode) {
                anchor.parentNode.insertBefore(section, anchor);
                return true;
            }
        }
        var fiche = document.querySelector('.fichecenter');
        if (fiche) {
            fiche.appendChild(section);
            return true;
        }
        return false;
    }

    function productStatusLabel(labels, status) {
        return labels[status] || status || '—';
    }

    function renderProductRelations(payload) {
        if (!payload || !payload.labels || !Array.isArray(payload.lines) || !payload.lines.length) {
            return;
        }
        if (document.querySelector('.navinvoice-product-relations')) {
            return;
        }

        var labels = payload.labels;
        var section = element('div', null, 'navinvoice-product-relations');
        section.style.marginTop = '18px';
        section.style.width = '100%';
        section.style.maxWidth = '100%';
        section.style.minWidth = '0';

        section.appendChild(element('div', labels.title, 'titre'));
        section.appendChild(element('div', labels.help, 'opacitymedium small marginbottomonly'));

        var summary = payload.summary || {};
        var summaryParts = [
            (labels.summary_matched || 'Matched') + ': ' + (summary.matched || 0),
            (labels.summary_unmatched || 'No match') + ': ' + (summary.unmatched || 0),
            (labels.summary_review || 'Needs review') + ': ' + (summary.review || 0),
            (labels.summary_ambiguous || 'Ambiguous') + ': ' + (summary.ambiguous || 0)
        ];
        section.appendChild(element('div', (labels.summary || '') + ': ' + summaryParts.join(' · '), 'small marginbottomonly'));

        var wrap = element('div', null, 'div-table-responsive');
        var table = element('table', null, 'noborder centpercent');
        var head = element('tr', null, 'liste_titre');
        ['line', 'description', 'nav_reference', 'dolibarr_product', 'match_status'].forEach(function (key) {
            head.appendChild(element('td', labels[key] || key));
        });
        table.appendChild(head);

        payload.lines.forEach(function (line) {
            var tr = element('tr', null, 'oddeven');
            tr.appendChild(element('td', line.number || '—'));
            tr.appendChild(element('td', line.description || '—'));
            tr.appendChild(element('td', line.supplier_ref || '—'));

            var match = line.match || {};
            var productCell = element('td');
            if (match.product && match.product.id) {
                var productLink = element('a', (match.product.ref || ('#' + match.product.id)) + (match.product.label ? ' — ' + match.product.label : ''));
                productLink.href = '../../product/card.php?id=' + encodeURIComponent(match.product.id);
                productCell.appendChild(productLink);
            } else {
                productCell.appendChild(element('span', '—', 'opacitymedium'));
            }
            tr.appendChild(productCell);

            var status = String(match.status || 'none');
            var statusClass = status === 'matched' ? 'ok' : (status === 'none' || status === 'no_reference' || status === 'partner_required' ? 'opacitymedium' : 'warning');
            tr.appendChild(element('td', productStatusLabel(labels, status), statusClass));
            table.appendChild(tr);
        });

        wrap.appendChild(table);
        section.appendChild(wrap);
        insertBeforeTechnicalXml(section);
    }

    function detailId() {
        if (window.location.pathname.indexOf('/navinvoice/detail.php') === -1) {
            return '';
        }
        var params = new URLSearchParams(window.location.search);
        var id = params.get('id');
        return id && /^\d+$/.test(id) ? id : '';
    }

    function loadProductRelations() {
        var id = detailId();
        if (!id) {
            return;
        }

        var url = new URL('productmatches.php', window.location.href);
        url.search = '?id=' + encodeURIComponent(id);
        fetch(url.toString(), {credentials: 'same-origin', headers: {'Accept': 'application/json'}, cache: 'no-store'})
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('product relation request failed');
                }
                return response.json();
            })
            .then(renderProductRelations)
            .catch(function () {
                // Product matching is advisory. Never break the invoice detail page.
            });
    }

    function init() {
        enhanceTechnicalXml();
        removeRedundantInvoiceAction();
        loadProductRelations();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
