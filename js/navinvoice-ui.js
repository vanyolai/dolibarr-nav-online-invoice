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

    function hasObjectValues(object) {
        if (!object || typeof object !== 'object') {
            return false;
        }
        return Object.keys(object).some(function (key) {
            return Array.isArray(object[key]) ? object[key].length > 0 : !!object[key];
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

    function appendKeyValueTable(parent, rows) {
        if (!rows.length) {
            return;
        }
        var wrap = element('div', null, 'div-table-responsive');
        var table = element('table', null, 'noborder centpercent');
        rows.forEach(function (row) {
            var tr = element('tr', null, 'oddeven');
            var label = element('td', row[0]);
            label.style.width = '260px';
            label.style.verticalAlign = 'top';
            var value = element('td', row[1]);
            value.style.whiteSpace = 'normal';
            value.style.overflowWrap = 'anywhere';
            tr.appendChild(label);
            tr.appendChild(value);
            table.appendChild(tr);
        });
        wrap.appendChild(table);
        parent.appendChild(wrap);
    }

    function appendAdditionalTable(parent, data, labels) {
        if (!Array.isArray(data) || !data.length) {
            return;
        }
        parent.appendChild(element('div', labels.additional, 'bold marginbottomonly'));
        var wrap = element('div', null, 'div-table-responsive');
        var table = element('table', null, 'noborder centpercent');
        var head = element('tr', null, 'liste_titre');
        [labels.name, labels.description, labels.value].forEach(function (label) {
            head.appendChild(element('td', label));
        });
        table.appendChild(head);
        data.forEach(function (item) {
            var tr = element('tr', null, 'oddeven');
            tr.appendChild(element('td', item.name || '—'));
            tr.appendChild(element('td', item.description || '—'));
            tr.appendChild(element('td', item.value || '—'));
            table.appendChild(tr);
        });
        wrap.appendChild(table);
        parent.appendChild(wrap);
    }

    function appendMetadataContent(parent, data, labels) {
        var productCodes = Array.isArray(data.product_codes) ? data.product_codes : [];
        if (productCodes.length) {
            parent.appendChild(element('div', labels.product_codes, 'bold marginbottomonly'));
            appendKeyValueTable(parent, productCodes.map(function (code) {
                return [code.category || '—', code.value || '—'];
            }));
        }

        var conventional = data.conventional || {};
        if (hasObjectValues(conventional)) {
            parent.appendChild(element('div', labels.conventional, 'bold marginbottomonly margintoponly'));
            var conventionalRows = [];
            Object.keys(conventional).forEach(function (key) {
                var values = Array.isArray(conventional[key]) ? conventional[key] : [];
                if (values.length) {
                    conventionalRows.push([
                        (labels.conventional_labels && labels.conventional_labels[key]) || key,
                        values.join(' · ')
                    ]);
                }
            });
            appendKeyValueTable(parent, conventionalRows);
        }

        appendAdditionalTable(parent, data.additional_data || [], labels);

        var barcodeCandidates = Array.isArray(data.barcode_candidates) ? data.barcode_candidates : [];
        if (barcodeCandidates.length) {
            var barcodeTitle = element('div', labels.barcode_candidates, 'bold margintoponly');
            barcodeTitle.title = labels.barcode_help || '';
            parent.appendChild(barcodeTitle);
            appendKeyValueTable(parent, barcodeCandidates.map(function (candidate) {
                return [candidate.value || '—', (candidate.sources || []).join(' · ')];
            }));
            var help = element('div', labels.barcode_help, 'opacitymedium small marginbottomonly');
            parent.appendChild(help);
        }
    }

    function metadataHasContent(metadata) {
        var invoice = metadata && metadata.invoice ? metadata.invoice : {};
        if (hasObjectValues(invoice.conventional || {}) || (invoice.additional_data || []).length) {
            return true;
        }
        return (metadata && Array.isArray(metadata.lines) ? metadata.lines : []).some(function (line) {
            return (line.product_codes || []).length
                || hasObjectValues(line.conventional || {})
                || (line.additional_data || []).length
                || (line.barcode_candidates || []).length;
        });
    }

    function renderProcessingMetadata(payload) {
        if (!payload || !payload.labels || !payload.metadata || !metadataHasContent(payload.metadata)) {
            return;
        }
        if (document.querySelector('.navinvoice-processing-metadata')) {
            return;
        }

        var labels = payload.labels;
        var metadata = payload.metadata;
        var section = element('div', null, 'navinvoice-processing-metadata');
        section.style.marginTop = '18px';
        section.style.width = '100%';
        section.style.maxWidth = '100%';
        section.style.minWidth = '0';

        section.appendChild(element('div', labels.title, 'titre'));
        section.appendChild(element('div', labels.help, 'opacitymedium small marginbottomonly'));

        var invoice = metadata.invoice || {};
        if (hasObjectValues(invoice.conventional || {}) || (invoice.additional_data || []).length) {
            var invoiceDetails = element('details');
            invoiceDetails.style.marginBottom = '8px';
            invoiceDetails.appendChild(element('summary', labels.invoice, 'bold'));
            var invoiceBody = element('div');
            invoiceBody.style.padding = '8px 0';
            appendMetadataContent(invoiceBody, invoice, labels);
            invoiceDetails.appendChild(invoiceBody);
            section.appendChild(invoiceDetails);
        }

        (metadata.lines || []).forEach(function (line) {
            var hasData = (line.product_codes || []).length
                || hasObjectValues(line.conventional || {})
                || (line.additional_data || []).length
                || (line.barcode_candidates || []).length;
            if (!hasData) {
                return;
            }

            var details = element('details');
            details.style.marginBottom = '8px';
            var lineTitle = String(labels.line || 'Line %s').replace('%s', line.number || '—');
            if (line.description) {
                lineTitle += ' — ' + line.description;
            }
            details.appendChild(element('summary', lineTitle, 'bold'));
            var body = element('div');
            body.style.padding = '8px 0';
            appendMetadataContent(body, line, labels);
            details.appendChild(body);
            section.appendChild(details);
        });

        insertBeforeTechnicalXml(section);
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

    function loadProcessingMetadata() {
        var id = detailId();
        if (!id) {
            return;
        }

        var url = new URL('metadata.php', window.location.href);
        url.search = '?id=' + encodeURIComponent(id);
        fetch(url.toString(), {credentials: 'same-origin', headers: {'Accept': 'application/json'}, cache: 'no-store'})
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('metadata request failed');
                }
                return response.json();
            })
            .then(renderProcessingMetadata)
            .catch(function () {
                // Metadata is diagnostic/enrichment UI. Never break invoice detail rendering.
            });
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
        loadProductRelations();
        loadProcessingMetadata();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
