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

        // Validate before reformatting. If the NAV payload is not valid XML,
        // keep the decoded source readable instead of changing its contents.
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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', enhanceTechnicalXml);
    } else {
        enhanceTechnicalXml();
    }
})();
