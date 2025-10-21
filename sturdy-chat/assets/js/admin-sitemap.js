(function () {
    'use strict';

    function ready() {
        var settings = window.SturdyChatAdmin || {};
        var button = document.getElementById('sturdychat-start-indexing');
        var logEl = document.getElementById('sturdychat-sitemap-log');
        var summaryEl = document.getElementById('sturdychat-sitemap-summary');

        if (!button || !logEl || !summaryEl || !settings.ajaxUrl) {
            return;
        }

        var strings = settings.strings || {};

        function resetLog() {
            logEl.innerHTML = '';
            logEl.classList.add('is-active');
            logEl.scrollTop = 0;
        }

        function setSummary(message, state) {
            summaryEl.textContent = message || '';
            summaryEl.classList.remove('is-error', 'is-success');
            if (state) {
                summaryEl.classList.add(state);
            }
        }

        function appendLog(message, type) {
            if (!message) {
                return;
            }
            var entry = document.createElement('div');
            entry.className = 'sc-log-entry sc-log-entry--' + (type || 'info');
            entry.textContent = message;
            logEl.appendChild(entry);
            logEl.scrollTop = logEl.scrollHeight;
        }

        async function runIndexing(event) {
            event.preventDefault();
            if (button.dataset.loading === '1') {
                return;
            }

            button.dataset.loading = '1';
            button.disabled = true;
            button.classList.add('is-busy');

            resetLog();
            setSummary('', null);
            appendLog(strings.starting || 'Indexeren gestart…', 'info');

            try {
                var params = new URLSearchParams();
                params.set('action', 'sturdychat_run_sitemap');
                params.set('nonce', settings.nonce || '');

                var response = await fetch(settings.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: params.toString()
                });

                if (!response.ok) {
                    throw new Error(strings.httpError || 'Kon geen verbinding maken met de server.');
                }

                var payload = await response.json();
                if (Array.isArray(payload.logs)) {
                    payload.logs.forEach(function (log) {
                        if (log && typeof log.message === 'string') {
                            appendLog(log.message, log.type || 'info');
                        }
                    });
                }

                if (payload.message) {
                    setSummary(payload.message, payload.ok ? 'is-success' : 'is-error');
                }

                if (!payload.ok && !payload.message) {
                    setSummary(strings.unknownError || 'Er is iets misgegaan.', 'is-error');
                }
            } catch (error) {
                var message = (error && error.message) ? error.message : (strings.unknownError || 'Er is iets misgegaan.');
                appendLog('❌ ' + message, 'error');
                setSummary(message, 'is-error');
            } finally {
                button.dataset.loading = '';
                button.disabled = false;
                button.classList.remove('is-busy');
            }
        }

        button.addEventListener('click', runIndexing);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ready);
    } else {
        ready();
    }
})();
