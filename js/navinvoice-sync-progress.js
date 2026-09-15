(function () {
    'use strict';

    function runKey() {
        var bytes = new Uint8Array(16);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
        } else {
            for (var i = 0; i < bytes.length; i++) {
                bytes[i] = Math.floor(Math.random() * 256);
            }
        }
        return Array.prototype.map.call(bytes, function (value) {
            return value.toString(16).padStart(2, '0');
        }).join('');
    }

    function findSyncForm() {
        var action = document.querySelector('form input[name="action"][value="sync"]');
        return action ? action.closest('form') : null;
    }

    function compactIndexLayout(form) {
        var table = form ? form.closest('table') : null;
        if (!table) {
            return;
        }

        var container = table.parentElement;
        if (container) {
            container.style.maxWidth = '940px';
        }

        table.querySelectorAll('tr:not(.liste_titre) td > div').forEach(function (cell) {
            if (cell.style && cell.style.justifyContent === 'space-between') {
                cell.style.justifyContent = 'flex-start';
                cell.style.gap = '12px';
            }
        });
    }

    function submitButton(form) {
        return form.querySelector('input[type="submit"], button[type="submit"]');
    }

    function buttonLabel(button) {
        if (!button) {
            return '';
        }
        return button.tagName === 'INPUT' ? String(button.value || '') : String(button.textContent || '').trim();
    }

    function setButtonLabel(button, value) {
        if (!button) {
            return;
        }
        if (button.tagName === 'INPUT') {
            button.value = value;
        } else {
            button.textContent = value;
        }
    }

    function progressPanel(form, initialText) {
        var existing = document.getElementById('navinvoice-sync-progress');
        if (existing) {
            existing.remove();
        }

        var panel = document.createElement('div');
        panel.id = 'navinvoice-sync-progress';
        panel.className = 'info';
        panel.style.marginTop = '10px';
        panel.style.maxWidth = '940px';
        panel.style.boxSizing = 'border-box';

        var text = document.createElement('div');
        text.className = 'navinvoice-sync-progress-text';
        text.textContent = initialText;
        panel.appendChild(text);

        var bar = document.createElement('progress');
        bar.className = 'navinvoice-sync-progress-bar';
        bar.style.width = '100%';
        bar.style.marginTop = '7px';
        bar.style.height = '12px';
        bar.max = 1;
        bar.value = 0;
        panel.appendChild(bar);

        var elapsed = document.createElement('div');
        elapsed.className = 'opacitymedium small navinvoice-sync-progress-elapsed';
        elapsed.style.marginTop = '4px';
        panel.appendChild(elapsed);

        form.insertAdjacentElement('afterend', panel);
        return panel;
    }

    function setPanelState(panel, state, message, progress) {
        var text = panel.querySelector('.navinvoice-sync-progress-text');
        var bar = panel.querySelector('.navinvoice-sync-progress-bar');
        if (text) {
            text.textContent = message || '';
        }
        panel.classList.remove('info', 'ok', 'error', 'warning');
        panel.classList.add(state === 'done' ? 'ok' : (state === 'error' ? 'error' : 'info'));
        if (!bar) {
            return;
        }

        bar.max = 1;
        if (state === 'done') {
            bar.value = 1;
            return;
        }

        if (typeof progress === 'number' && isFinite(progress)) {
            bar.value = Math.max(0, Math.min(1, progress));
            return;
        }

        // Keep running/error states determinate even before the first structured
        // status arrives. An absent value would activate the native indeterminate
        // ("Knight Rider") animation and falsely suggest unknown progress.
        if (!bar.hasAttribute('value')) {
            bar.value = 0;
        }
    }

    function statusUrl(key) {
        var url = new URL('syncstatus.php', window.location.href);
        url.search = '?run_key=' + encodeURIComponent(key);
        return url.toString();
    }

    function executeUrl() {
        return new URL('syncajax.php', window.location.href).toString();
    }

    function init() {
        if (window.location.pathname.indexOf('/navinvoice/index.php') === -1) {
            return;
        }

        var form = findSyncForm();
        if (!form || form.dataset.navinvoiceProgressBound === '1') {
            return;
        }

        compactIndexLayout(form);
        form.dataset.navinvoiceProgressBound = '1';

        form.addEventListener('submit', function (event) {
            if (form.dataset.navinvoiceSyncRunning === '1') {
                event.preventDefault();
                return;
            }

            event.preventDefault();
            form.dataset.navinvoiceSyncRunning = '1';

            var button = submitButton(form);
            var originalButtonLabel = buttonLabel(button);
            if (button) {
                button.disabled = true;
                setButtonLabel(button, originalButtonLabel + '…');
            }

            var key = runKey();
            var data = new FormData(form);
            data.set('run_key', key);

            var panel = progressPanel(form, originalButtonLabel + '…');
            var started = Date.now();
            var elapsedNode = panel.querySelector('.navinvoice-sync-progress-elapsed');
            var lastProgressPercent = null;
            var elapsedTimer = window.setInterval(function () {
                if (elapsedNode) {
                    var seconds = Math.max(0, Math.round((Date.now() - started) / 1000));
                    elapsedNode.textContent = (lastProgressPercent !== null ? lastProgressPercent + '% · ' : '') + seconds + ' s';
                }
            }, 1000);

            var finished = false;
            var reloadScheduled = false;
            var pollTimer = null;

            function stopPolling() {
                if (pollTimer !== null) {
                    window.clearInterval(pollTimer);
                    pollTimer = null;
                }
            }

            function unlock() {
                form.dataset.navinvoiceSyncRunning = '0';
                if (button) {
                    button.disabled = false;
                    setButtonLabel(button, originalButtonLabel);
                }
                window.clearInterval(elapsedTimer);
                stopPolling();
            }

            function scheduleReload() {
                if (reloadScheduled) {
                    return;
                }
                reloadScheduled = true;
                window.setTimeout(function () {
                    window.location.reload();
                }, 1400);
            }

            function poll() {
                if (finished) {
                    return;
                }
                fetch(statusUrl(key), {
                    credentials: 'same-origin',
                    headers: {'Accept': 'application/json'},
                    cache: 'no-store'
                })
                    .then(function (response) {
                        if (!response.ok) {
                            throw new Error('progress request failed');
                        }
                        return response.json();
                    })
                    .then(function (payload) {
                        if (!payload || !payload.ok || !payload.found) {
                            return;
                        }
                        if (typeof payload.progress_percent === 'number') {
                            lastProgressPercent = Math.max(0, Math.min(100, payload.progress_percent));
                        }
                        setPanelState(panel, payload.status, payload.message || '', payload.progress);
                        if (payload.status === 'done') {
                            lastProgressPercent = 100;
                            finished = true;
                            unlock();
                            scheduleReload();
                        } else if (payload.status === 'error') {
                            finished = true;
                            unlock();
                        }
                    })
                    .catch(function () {
                        // The execution request remains authoritative. A single
                        // failed polling request must not cancel the NAV sync.
                    });
            }

            pollTimer = window.setInterval(poll, 750);
            window.setTimeout(poll, 150);

            fetch(executeUrl(), {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: {'Accept': 'application/json'},
                cache: 'no-store'
            })
                .then(function (response) {
                    return response.json().catch(function () {
                        return {ok: false, error: 'Invalid synchronization response'};
                    }).then(function (payload) {
                        return {response: response, payload: payload};
                    });
                })
                .then(function (result) {
                    if (result.response.ok && result.payload && result.payload.ok) {
                        poll();
                        if (!finished) {
                            finished = true;
                            lastProgressPercent = 100;
                            var stats = result.payload.stats || {};
                            var text = '✓ ' + originalButtonLabel + ': '
                                + (stats.seen || 0) + ' / '
                                + (stats.inserted || 0) + ' / '
                                + (stats.updated || 0) + ' / '
                                + (stats.downloaded || 0);
                            setPanelState(panel, 'done', text, 1);
                            unlock();
                            scheduleReload();
                        }
                        return;
                    }
                    finished = true;
                    setPanelState(panel, 'error', (result.payload && result.payload.error) ? result.payload.error : ('HTTP ' + result.response.status));
                    unlock();
                })
                .catch(function (error) {
                    finished = true;
                    setPanelState(panel, 'error', error && error.message ? error.message : String(error));
                    unlock();
                })
                .finally(function () {
                    stopPolling();
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
