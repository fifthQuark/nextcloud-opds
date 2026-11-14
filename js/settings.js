/* global OC, t */
(function () {
    'use strict';

    const root = document.getElementById('opds-settings');
    if (!root) {
        return;
    }

    const $ = (id) => document.getElementById(id);
    const saveStatus = $('opds-save-status');
    const scanStatus = $('opds-scan-status');

    function url(path) {
        return OC.generateUrl('/apps/opds' + path);
    }

    function setStatus(el, text, kind) {
        el.textContent = text;
        el.className = 'opds-status' + (kind ? ' opds-status-' + kind : '');
    }

    async function post(path, body) {
        const response = await fetch(url(path), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken,
                'Accept': 'application/json',
            },
            body: JSON.stringify(body || {}),
        });
        let data = {};
        try {
            data = await response.json();
        } catch (e) {
            // not JSON
        }
        if (!response.ok) {
            throw new Error(data.error || data.summary || ('HTTP ' + response.status));
        }
        return data;
    }

    function collect() {
        return {
            root: $('opds-root').value,
            extensions: $('opds-extensions').value,
            filename_pattern: $('opds-filename-pattern').value,
            default_sort: $('opds-default-sort').value,
            default_order: $('opds-default-order').value,
            page_size: $('opds-page-size').value,
            group_threshold: $('opds-group-threshold').value,
            scan_interval: $('opds-scan-interval').value,
        };
    }

    $('opds-save').addEventListener('click', async () => {
        setStatus(saveStatus, 'Saving…');
        try {
            await post('/settings', collect());
            setStatus(saveStatus, 'Saved', 'ok');
        } catch (e) {
            setStatus(saveStatus, e.message, 'error');
        }
    });

    async function runScan(full) {
        setStatus(scanStatus, full ? 'Re-reading all metadata, this can take a while…' : 'Scanning…');
        for (const id of ['opds-scan', 'opds-rescan', 'opds-reset']) {
            $(id).disabled = true;
        }
        try {
            const data = await post('/settings/scan', { full: full });
            setStatus(scanStatus, data.summary, 'ok');
            if (data.result && data.result.complete === false) {
                // Time limit hit: keep going until everything is indexed
                return runScan(false);
            }
        } catch (e) {
            setStatus(scanStatus, e.message, 'error');
        } finally {
            for (const id of ['opds-scan', 'opds-rescan', 'opds-reset']) {
                $(id).disabled = false;
            }
        }
    }

    $('opds-scan').addEventListener('click', () => runScan(false));
    $('opds-rescan').addEventListener('click', () => runScan(true));
    $('opds-reset').addEventListener('click', async () => {
        if (!window.confirm('Remove the whole index and all extracted covers? Your files are not touched.')) {
            return;
        }
        setStatus(scanStatus, 'Clearing…');
        try {
            await post('/settings/reset', {});
            setStatus(scanStatus, 'Index cleared', 'ok');
        } catch (e) {
            setStatus(scanStatus, e.message, 'error');
        }
    });

    $('opds-copy-url').addEventListener('click', async () => {
        const input = $('opds-feed-url');
        try {
            await navigator.clipboard.writeText(input.value);
            setStatus(saveStatus, 'Address copied', 'ok');
        } catch (e) {
            input.select();
            document.execCommand('copy');
        }
    });

    const pick = $('opds-pick-folder');
    if (window.OC && OC.dialogs && typeof OC.dialogs.filepicker === 'function') {
        pick.addEventListener('click', () => {
            OC.dialogs.filepicker(
                'Choose the library folder',
                (path) => { $('opds-root').value = path || '/'; },
                false,
                'httpd/unix-directory',
                true,
                OC.dialogs.FILEPICKER_TYPE_CHOOSE
            );
        });
    } else {
        pick.remove();
    }
})();
