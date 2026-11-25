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
            show_hidden: $('opds-show-hidden').checked,
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

    // ------------------------------------------------------ calibre libraries
    const calibreBox = $('opds-calibre-enabled');
    if (calibreBox) {
        const status = $('opds-calibre-status');
        const listNode = $('opds-calibre-list');
        const useButton = $('opds-calibre-use');
        let created = '';
        let catalog = '';

        const node = (tag, text) => {
            const n = document.createElement(tag);
            n.textContent = text || '';
            return n;
        };

        const drawLibraries = async () => {
            listNode.replaceChildren();
            let data;
            try {
                const response = await fetch(url('/api/calibre'), { headers: { requesttoken: OC.requestToken, Accept: 'application/json' } });
                data = await response.json();
                if (!response.ok) {
                    throw new Error(data.error || 'HTTP ' + response.status);
                }
            } catch (e) {
                setStatus(status, e.message, 'error');
                return;
            }
            catalog = data.root;
            if (data.libraries.length === 0) {
                listNode.append(node('p', 'There is no Calibre library in the library folder.'));
                return;
            }
            for (const library of data.libraries) {
                const p = node('p');
                p.append(node('strong', library.path), ' – ' + library.books + (library.books === 1 ? ' book' : ' books'));
                if (library.problem) {
                    p.append(node('br'), node('span', 'Cannot be edited: ' + library.problem));
                }
                listNode.append(p);
                if (library.backups.length) {
                    const details = node('details');
                    details.append(node('summary', 'Backups of metadata.db made before editing'));
                    const ul = node('ul');
                    for (const b of library.backups) {
                        const a = node('a', new Date(b.time * 1000).toLocaleString());
                        a.href = url('/api/calibre/backup') + '?' + new URLSearchParams({ library: library.path, name: b.name });
                        a.style.textDecoration = 'underline';
                        const li = node('li');
                        li.append(a, ' (' + Math.max(1, Math.round(b.size / 1024)) + ' KB)');
                        ul.append(li);
                    }
                    details.append(ul, node('p', 'Folders renamed since a backup was made are not renamed back. If Calibre reports missing books after using one, choose Library maintenance → Restore database in Calibre, which rebuilds everything from the metadata.opf files.'));
                    listNode.append(details);
                }
            }
        };

        calibreBox.addEventListener('change', async () => {
            calibreBox.disabled = true;
            setStatus(status, 'Saving…');
            try {
                await post('/settings/calibre', { enabled: calibreBox.checked });
                $('opds-calibre-more').hidden = !calibreBox.checked;
                setStatus(status, calibreBox.checked ? 'Turned on' : 'Turned off', 'ok');
                if (calibreBox.checked) {
                    await drawLibraries();
                }
            } catch (e) {
                calibreBox.checked = !calibreBox.checked;
                setStatus(status, e.message, 'error');
            } finally {
                calibreBox.disabled = false;
            }
        });

        const embedBox = $('opds-calibre-embed');
        embedBox.addEventListener('change', async () => {
            embedBox.disabled = true;
            setStatus(status, 'Saving…');
            try {
                await post('/settings/calibre/downloads', { embed: embedBox.checked });
                setStatus(status, 'Saved', 'ok');
            } catch (e) {
                embedBox.checked = !embedBox.checked;
                setStatus(status, e.message, 'error');
            } finally {
                embedBox.disabled = false;
            }
        });

        const buttons = [$('opds-calibre-create'), $('opds-calibre-convert')];
        const setBusy = (busy) => buttons.forEach((b) => { b.disabled = busy; });

        const addBooks = async (path, inPlace) => {
            const sum = { added: 0, skipped: 0, failed: [] };
            let after = '';
            let result;
            do {
                result = await post('/api/calibre/import', { path: path, after: after, inPlace: inPlace });
                after = result.last || after;
                sum.added += result.added;
                sum.skipped += result.skipped;
                sum.failed.push(...result.failed);
                setStatus(status, (inPlace ? 'Moving books: ' : 'Copying books: ') + result.processed + ' of ' + result.total);
            } while (!result.done);
            return sum;
        };

        const showSum = (sum, done, skipped) => {
            setStatus(status, sum.added + (sum.added === 1 ? ' book ' : ' books ') + done
                + (sum.skipped ? ', ' + sum.skipped + ' ' + skipped : '')
                + (sum.failed.length ? ', ' + sum.failed.length + ' failed: ' + sum.failed.slice(0, 10).join(', ') : ''), sum.failed.length ? 'error' : 'ok');
        };

        const convert = async () => {
            if (catalog === '') {
                await drawLibraries();
            }
            if (catalog === '' || !window.confirm('Move the books in ' + catalog + ' into Author/Title folders of a Calibre library?\n\n'
                + 'Files are renamed and moved inside this folder, also on computers that synchronise it. This cannot be undone with one click, so make a backup first. '
                + 'Close Calibre and let your files synchronise before you continue.')) {
                return;
            }
            setBusy(true);
            let started = false;
            try {
                setStatus(status, 'Looking for new books…');
                await runScan(false);
                const path = (await post('/api/calibre', { inPlace: true })).path;
                started = true;
                const sum = await addBooks(path, true);
                setStatus(status, 'Updating the catalog…');
                await runScan(false);
                await drawLibraries();
                if (sum.added + sum.skipped + sum.failed.length === 0) {
                    setStatus(status, 'Nothing to move: all books are in the Calibre library ' + path + ' already', 'ok');
                    return;
                }
                showSum(sum, 'moved into the Calibre library ' + path, 'files left where they are (hidden files, extra files of a book, or files you may not move or that are shared with you)');
            } catch (e) {
                setStatus(status, e.message + (started ? ' Press the button again to continue.' : ''), 'error');
            } finally {
                setBusy(false);
            }
        };
        $('opds-calibre-convert').addEventListener('click', convert);

        $('opds-calibre-create').addEventListener('click', async () => {
            const wanted = '/' + $('opds-calibre-path').value.trim().replace(/^\/+|\/+$/g, '');
            if (created === '' && catalog !== '' && wanted !== '/' && wanted === catalog) {
                return convert();
            }
            setBusy(true);
            setStatus(status, 'Creating the library…');
            try {
                if (created === '') {
                    created = (await post('/api/calibre', { path: $('opds-calibre-path').value })).path;
                    $('opds-calibre-path').disabled = true;
                }
                showSum(await addBooks(created, false), 'copied to ' + created, 'skipped (already there or file missing)');
                useButton.hidden = false;
            } catch (e) {
                setStatus(status, e.message + (created ? ' Press the button again to continue.' : ''), 'error');
            } finally {
                setBusy(false);
            }
        });

        useButton.addEventListener('click', async () => {
            useButton.disabled = true;
            try {
                const data = await post('/api/calibre/use', { path: created });
                $('opds-root').value = data.root;
                useButton.hidden = true;
                await runScan(false);
                setStatus(status, data.root + ' is now the library folder', 'ok');
                await drawLibraries();
            } catch (e) {
                setStatus(status, e.message, 'error');
            } finally {
                useButton.disabled = false;
            }
        });

        if (calibreBox.checked) {
            drawLibraries();
        }
    }

    // ------------------------------------------------------------ news digest
    const news = $('opds-news');
    if (news) {
        const newsStatus = $('opds-news-status');
        const tree = $('opds-news-tree');
        const folderBoxes = () => Array.from(news.querySelectorAll('.opds-news-folder-box'));
        const feedBoxes = () => Array.from(news.querySelectorAll('.opds-news-feed-box'));

        // A selected folder includes all of its feeds, so its feed boxes show as checked and locked
        function syncTree() {
            const some = $('opds-news-some').checked;
            const enabled = $('opds-news-enabled').checked;
            tree.classList.toggle('opds-disabled', !some || !enabled);
            $('opds-news-options').classList.toggle('opds-disabled', !enabled);
            for (const box of feedBoxes()) {
                const parent = box.dataset.parent ? $('opds-news-folder-' + box.dataset.parent) : null;
                const viaFolder = parent !== null && parent.checked;
                if (viaFolder) {
                    if (!box.disabled) {
                        box.dataset.own = box.checked ? '1' : '0';
                    }
                    box.checked = true;
                    box.disabled = true;
                } else if (box.disabled) {
                    box.disabled = false;
                    box.checked = box.dataset.own === '1';
                }
            }
            for (const input of tree.querySelectorAll('input')) {
                if (!some || !enabled) {
                    input.setAttribute('tabindex', '-1');
                } else {
                    input.removeAttribute('tabindex');
                }
            }
        }

        news.addEventListener('change', syncTree);
        syncTree();

        $('opds-news-save').addEventListener('click', async () => {
            const folders = folderBoxes().filter((b) => b.checked).map((b) => Number(b.dataset.folder));
            // Feeds inside a selected folder are covered by the folder itself
            const feeds = feedBoxes().filter((b) => b.checked && !b.disabled).map((b) => Number(b.dataset.feed));
            const all = $('opds-news-all').checked;
            if (!all && folders.length === 0 && feeds.length === 0) {
                setStatus(newsStatus, 'Select at least one folder or feed', 'error');
                return;
            }
            setStatus(newsStatus, 'Saving…');
            try {
                const data = await post('/settings/news', {
                    enabled: $('opds-news-enabled').checked,
                    all: all,
                    folders: folders,
                    feeds: feeds,
                    period: $('opds-news-period').value,
                    max: Number($('opds-news-max').value) || 30,
                    unread: $('opds-news-unread').checked,
                    images: $('opds-news-images').checked,
                });
                setStatus(newsStatus, 'Saved. ' + (data.summary || ''), 'ok');
            } catch (e) {
                setStatus(newsStatus, e.message, 'error');
            }
        });
    }

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
