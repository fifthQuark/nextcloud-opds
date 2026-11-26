/* global OC, OCP */
(function () {
    'use strict';

    const root = document.getElementById('opds-library');
    if (!root) {
        return;
    }
    const state = OCP.InitialState.loadState('opds', 'library');
    const $ = (id) => document.getElementById(id);
    const content = $('ol-content');
    const dialog = $('ol-dialog');
    const KINDS = {
        author: 'Authors', series: 'Series', subject: 'Genres', publisher: 'Publishers',
        language: 'Languages', year: 'Years', format: 'Formats',
    };
    const FILTERS = ['author', 'series', 'subject', 'publisher', 'language', 'year', 'format', 'rating', 'column', 'value', 'standalone', 'q'];
    const COLUMN = 'column-';
    const extraKinds = new Set();

    let requestId = 0; // newest view; answers for older ones are dropped
    let list = null; // { params, page, pages, grid }
    let shownList = null; // hash of the list behind the dialog
    let languageNames = {};
    const valueLabels = {};
    let notice = ''; // shown once by the next view

    // -------------------------------------------------------------- helpers

    function el(tag, props, ...children) {
        const node = document.createElement(tag);
        for (const [key, value] of Object.entries(props || {})) {
            if (value === undefined || value === null || value === false) {
                continue;
            }
            if (key === 'class') {
                node.className = value;
            } else if (key.startsWith('on')) {
                node.addEventListener(key.slice(2), value);
            } else if (key in node && key !== 'list' && key !== 'form') {
                node[key] = value;
            } else {
                node.setAttribute(key, value === true ? '' : value);
            }
        }
        for (const child of children.flat()) {
            if (child !== null && child !== undefined && child !== false) {
                node.append(child);
            }
        }
        return node;
    }

    async function api(method, path, body) {
        const headers = { requesttoken: OC.requestToken, Accept: 'application/json' };
        let payload;
        if (body instanceof FormData) {
            payload = body;
        } else if (body !== undefined) {
            headers['Content-Type'] = 'application/json';
            payload = JSON.stringify(body);
        }
        const response = await fetch(OC.generateUrl('/apps/opds' + path), { method, headers, body: payload, credentials: 'same-origin' });
        let data = {};
        try {
            data = await response.json();
        } catch (e) {
            // not JSON
        }
        if (!response.ok) {
            throw new Error(data.error || data.message || 'The server answered with error ' + response.status);
        }
        return data;
    }

    function query(params) {
        const q = new URLSearchParams();
        for (const [key, value] of Object.entries(params)) {
            if (value !== '' && value !== undefined && value !== null && value !== false) {
                q.set(key, value);
            }
        }
        const s = q.toString();
        return s ? '?' + s : '';
    }

    function plural(n, one, many) {
        return n + ' ' + (n === 1 ? one : (many || one + 's'));
    }

    function size(bytes) {
        const units = ['B', 'KB', 'MB', 'GB'];
        let i = 0;
        while (bytes >= 1024 && i < units.length - 1) {
            bytes /= 1024;
            i++;
        }
        return (i > 0 && bytes < 10 ? bytes.toFixed(1) : Math.round(bytes)) + ' ' + units[i];
    }

    function hue(text) {
        let h = 0;
        for (const c of text) {
            h = (h * 31 + c.codePointAt(0)) % 360;
        }
        return h;
    }

    function cover(book, url, lazy) {
        const blank = () => {
            const node = el('span', { class: 'ol-blank', 'aria-hidden': 'true' },
                el('span', { class: 'ol-blank-title' }, book.title),
                el('span', { class: 'ol-blank-author' }, (book.authors || []).join(', ')));
            node.style.setProperty('--ol-hue', hue(book.title));
            return node;
        };
        if (!url) {
            return blank();
        }
        const img = el('img', { alt: '', loading: lazy ? 'lazy' : 'eager', decoding: 'async' });
        img.addEventListener('error', () => img.replaceWith(blank()), { once: true });
        img.src = url;
        return img;
    }

    function seriesText(book) {
        return book.series ? book.series + (book.seriesIndex ? ' ' + String(book.seriesIndex) : '') : '';
    }

    // -------------------------------------------------------------- routing

    function parse() {
        const raw = location.hash.replace(/^#\/?/, '');
        const [view, search] = raw.split('?');
        return { view: view || 'home', params: Object.fromEntries(new URLSearchParams(search || '')) };
    }

    function go(view, params) {
        location.hash = '#/' + view + query(params || {});
    }

    function withoutBook() {
        const r = parse();
        delete r.params.book;
        return '#/' + r.view + query(r.params);
    }

    function route() {
        const { view, params } = parse();
        const bookId = params.book;
        const here = withoutBook();
        if (here !== shownList) {
            shownList = here;
            show(view, params);
        }
        if (bookId) {
            openBook(bookId);
        } else if (dialog.open) {
            dialog.close();
        }
    }

    function openLink(id) {
        const r = parse();
        return '#/' + r.view + query({ ...r.params, book: id });
    }

    function show(view, params) {
        const mine = ++requestId;
        list = null;
        content.replaceChildren();
        $('ol-more').hidden = true;
        $('ol-count').textContent = '';
        message(notice);
        notice = '';
        $('ol-scroll').scrollTop = 0;
        for (const a of root.querySelectorAll('.ol-nav a[data-view]')) {
            const own = view === 'books' ? activeView(params) : view;
            a.toggleAttribute('aria-current', a.dataset.view === own);
            if (a.dataset.view === own) {
                a.setAttribute('aria-current', 'page');
            }
        }
        if (document.activeElement !== $('ol-search')) {
            $('ol-search').value = params.q || '';
        }
        const isList = !(view in KINDS);
        $('ol-sort').hidden = !isList;
        if (!isList) {
            return showKind(view, mine, params.parent || '');
        }
        if (view === 'home') {
            return showBooks({ ...params }, params.q ? 'Search results' : 'All books', mine);
        }
        if (view === 'recent') {
            return showBooks({ sort: 'added', order: 'desc', ...params }, 'Recently added', mine);
        }
        if (view === 'books') {
            return showBooks(params, titleOf(params), mine);
        }
        go('home');
    }

    /** Parameters of the list of books with this value */
    function filterFor(kind, value) {
        return kind.startsWith(COLUMN) ? { column: kind.slice(COLUMN.length), value } : { [kind]: value };
    }

    function stars(n) {
        return '★'.repeat(Math.floor(n / 2)) + (n % 2 ? '½' : '');
    }

    function activeView(params) {
        if (params.column) {
            return COLUMN + params.column;
        }
        return Object.keys(KINDS).find((k) => params[k]) || 'home';
    }

    function titleOf(params) {
        if (params.author && params.series) {
            return params.series;
        }
        if (params.language) {
            return languageNames[params.language] || params.language;
        }
        if (params.format) {
            return params.format.toUpperCase();
        }
        if (params.rating) {
            return stars(Number(params.rating));
        }
        if (params.column && params.value) {
            return (KINDS[COLUMN + params.column] || params.column) + ': ' + (valueLabels[params.column + ' ' + params.value] || params.value);
        }
        for (const key of ['author', 'series', 'subject', 'publisher', 'year']) {
            if (params[key]) {
                return params[key];
            }
        }
        return params.q ? 'Search results' : 'Books';
    }

    function message(text, isError) {
        const node = $('ol-message');
        node.textContent = text;
        node.hidden = !text;
        node.classList.toggle('ol-error', Boolean(isError));
    }

    // ---------------------------------------------------------------- lists

    async function showKind(kind, mine, parent) {
        $('ol-title').textContent = parent || KINDS[kind];
        content.setAttribute('aria-busy', 'true');
        let data;
        try {
            data = await api('GET', '/api/browse/' + kind);
        } catch (e) {
            if (mine === requestId) {
                message(e.message, true);
            }
            return;
        } finally {
            content.removeAttribute('aria-busy');
        }
        if (mine !== requestId) {
            return;
        }
        if (kind === 'language') {
            for (const entry of data.entries) {
                languageNames[entry.value] = entry.label;
            }
        }
        for (const entry of data.entries) {
            if (entry.label !== entry.value) {
                valueLabels[kind.slice(COLUMN.length) + ' ' + entry.value] = entry.label;
            }
        }
        const level = data.nested ? nest(data.entries, parent) : data.entries;
        $('ol-count').textContent = level.length ? String(level.length) : '';
        if (data.entries.length === 0) {
            message('Nothing here yet.');
            return;
        }
        const tiles = el('ul', { class: 'ol-tiles' });
        const filter = el('input', { type: 'search', class: 'ol-filter', placeholder: 'Filter ' + KINDS[kind].toLowerCase(), 'aria-label': 'Filter ' + KINDS[kind].toLowerCase() });
        const draw = () => {
            const word = filter.value.trim().toLocaleLowerCase();
            // Searching looks through all levels
            const found = word ? data.entries.filter((e) => e.label.toLocaleLowerCase().includes(word)) : level;
            let shown = 0;
            const more = () => {
                const next = found.slice(shown, shown + 200);
                shown += next.length;
                tiles.append(...next.map((entry) => tile(entry, entry.children
                    ? '#/' + kind + query({ parent: entry.value }) : '#/books' + query(filterFor(kind, entry.value)))));
                $('ol-more').hidden = shown >= found.length;
            };
            tiles.replaceChildren();
            list = { more };
            more();
            $('ol-count').textContent = String(found.length);
        };
        filter.addEventListener('input', draw);
        if (data.nested && parent) {
            const up = parent.includes('.') ? parent.slice(0, parent.lastIndexOf('.')) : '';
            content.append(el('p', { class: 'ol-crumb' }, 'in ', el('a', { href: '#/' + kind + query({ parent: up }) }, up || KINDS[kind])),
                el('nav', { class: 'ol-chips' }, el('a', { class: 'ol-chip', href: '#/books' + query(filterFor(kind, parent)) }, 'All books in ' + parent)));
        }
        content.append(...(data.entries.length > 20 ? [filter] : []), tiles);
        draw();
    }

    /** One level of a list in which "A.B" is below "A" */
    function nest(entries, parent) {
        const known = new Set(entries.map((e) => e.value));
        const upOf = (value) => {
            const up = value.slice(0, Math.max(0, value.lastIndexOf('.')));
            return known.has(up) ? up : '';
        };
        const children = {};
        for (const e of entries) {
            children[upOf(e.value)] = (children[upOf(e.value)] || 0) + 1;
        }
        return entries.filter((e) => upOf(e.value) === parent)
            .map((e) => ({ ...e, label: parent ? e.value.slice(parent.length + 1) : e.label, children: children[e.value] || 0 }));
    }

    function tile(entry, href) {
        const stack = el('span', { class: 'ol-stack', 'aria-hidden': 'true' });
        for (const url of entry.covers) {
            stack.append(el('img', { src: url, alt: '', loading: 'lazy', decoding: 'async' }));
        }
        if (entry.covers.length === 0) {
            const letter = el('span', { class: 'ol-letter' }, (entry.label.trim()[0] || '?').toLocaleUpperCase());
            letter.style.setProperty('--ol-hue', hue(entry.label));
            stack.append(letter);
        }
        return el('li', null, el('a', { class: 'ol-tile', href },
            stack,
            el('span', { class: 'ol-tile-text' },
                el('span', { class: 'ol-tile-name' }, entry.label),
                el('span', { class: 'ol-sub' }, plural(entry.count, 'book')))));
    }

    async function showBooks(params, title, mine) {
        $('ol-title').textContent = title;
        const filters = {};
        for (const key of FILTERS) {
            if (params[key]) {
                filters[key] = params[key];
            }
        }
        if (params.author) {
            await showAuthorSeries(params, mine);
            if (mine !== requestId) {
                return;
            }
        }
        const rename = renameControl(params);
        if (rename) {
            content.prepend(rename);
        }
        const grid = el('ul', { class: 'ol-grid' });
        content.append(grid);
        list = { params: { ...filters, sort: params.sort, order: params.order }, page: 0, pages: 1, grid, busy: false };
        list.more = () => loadPage(mine);
        await loadPage(mine);
    }

    const RENAMED = { author: 'author', series: 'series', subject: 'genre', publisher: 'publisher' };

    /** Renaming the author, series, genre, publisher or value of a column that the list shows, in all Calibre libraries */
    function renameControl(params) {
        if (!state.calibre || params.q) {
            return null;
        }
        const keys = Object.keys(RENAMED).filter((k) => params[k]);
        let kind;
        let key;
        let what;
        if (params.column && params.value && keys.length === 0) {
            const column = (state.columns || []).find((c) => c.key === params.column);
            if (!column || !['text', 'series'].includes(column.type)) {
                return null;
            }
            [kind, key, what] = [COLUMN + params.column, 'value', column.name];
        } else if (keys.length === 1 && !params.column) {
            [kind, key, what] = [keys[0], keys[0], RENAMED[keys[0]]];
        } else if (keys.length === 2 && params.author && params.series) {
            [kind, key, what] = ['series', 'series', 'series'];
        } else {
            return null;
        }
        const from = params[key];
        const box = el('div', { class: 'ol-rename' });
        const open = () => {
            const input = el('input', { type: 'text', value: from, required: true, id: 'ol-rename-to', autocomplete: 'off' });
            const status = el('p', { class: 'ol-status', role: 'status' });
            const save = el('button', { type: 'submit', class: 'primary' }, 'Rename');
            const cancel = el('button', { type: 'button', onclick: () => box.replaceChildren(start) }, 'Cancel');
            box.replaceChildren(el('form', { onsubmit: async (e) => {
                e.preventDefault();
                const to = input.value.trim();
                if (!to || to === from) {
                    box.replaceChildren(start);
                    return;
                }
                save.disabled = cancel.disabled = true;
                status.className = 'ol-status';
                status.textContent = 'Renaming…';
                try {
                    const data = await api('POST', '/api/rename', { kind, from, to });
                    const parts = ['Renamed in ' + plural(data.books, 'book') + '.'];
                    for (const s of data.skipped) {
                        parts.push('Not in ' + s.library + ': ' + s.reason + '.');
                    }
                    notice = parts.join(' ');
                    shownList = null;
                    go('books', { ...params, [key]: data.name, book: undefined });
                } catch (problem) {
                    status.textContent = problem.message;
                    status.className = 'ol-status ol-error';
                    save.disabled = cancel.disabled = false;
                }
            } },
                el('label', { htmlFor: input.id }, 'New name of this ' + what),
                el('div', { class: 'ol-rename-row' }, input, save, cancel),
                el('p', { class: 'ol-sub' }, 'Changes all books with this name in your Calibre libraries, and the folders of the books if it is an author. '
                    + 'If the new name is used already, the two become one. Close Calibre first.'),
                status));
            input.focus();
            input.select();
        };
        const start = el('button', { type: 'button', class: 'ol-rename-start', onclick: open }, 'Rename ' + what + '…');
        box.append(start, el('span', { class: 'ol-beta' }, 'Beta'));
        return box;
    }

    async function showAuthorSeries(params, mine) {
        let data;
        try {
            data = await api('GET', '/api/author' + query({ name: params.author }));
        } catch (e) {
            return;
        }
        if (mine !== requestId || data.series.length === 0) {
            return;
        }
        const base = { author: params.author };
        const chip = (label, count, extra, active) => el('a', {
            class: 'ol-chip', href: '#/books' + query({ ...base, ...extra }), 'aria-current': active ? 'true' : null,
        }, label, el('span', { class: 'ol-chip-count' }, String(count)));
        const chips = el('nav', { class: 'ol-chips', 'aria-label': 'Series by ' + params.author },
            chip('All books', data.total, {}, !params.series && !params.standalone),
            data.series.map((s) => chip(s.label, s.count, { series: s.value }, params.series === s.value)),
            data.standalone > 0 ? chip('Not in a series', data.standalone, { standalone: 1 }, Boolean(params.standalone)) : null);
        if (params.series) {
            content.append(el('p', { class: 'ol-crumb' }, 'by ', el('a', { href: '#/books' + query(base) }, params.author)));
        }
        content.append(chips);
    }

    async function loadPage(mine) {
        const current = list;
        if (!current || current.busy || current.page >= current.pages) {
            return;
        }
        current.busy = true;
        content.setAttribute('aria-busy', 'true');
        try {
            const data = await api('GET', '/api/books' + query({ ...current.params, page: current.page + 1 }));
            if (mine !== requestId) {
                return;
            }
            current.page = data.page;
            current.pages = data.pages;
            current.grid.append(...data.books.map(card));
            $('ol-count').textContent = plural(data.total, 'book');
            setSort(data.sort, data.order);
            $('ol-more').hidden = data.page >= data.pages;
            if (data.total === 0) {
                emptyText(current.params);
            }
        } catch (e) {
            if (mine === requestId) {
                message(e.message, true);
                $('ol-more').hidden = current.page === 0;
            }
        } finally {
            current.busy = false;
            if (mine === requestId) {
                content.removeAttribute('aria-busy');
            }
        }
    }

    function emptyText(params) {
        const filtered = FILTERS.some((k) => params[k]);
        if (params.q) {
            message('No book matches “' + params.q + '”.');
        } else if (filtered) {
            message('There are no books here any more.');
        } else {
            message('No books found in ' + state.root + '. Put e-books into that folder, or choose another one in the settings.');
        }
    }

    function card(book) {
        const sub = seriesText(book);
        return el('li', { 'data-id': book.id }, el('a', { class: 'ol-card', href: openLink(book.id), onclick: (e) => {
            // The list may have been sorted or filtered since the card was made
            e.currentTarget.href = openLink(book.id);
        } },
            el('span', { class: 'ol-cover' }, cover(book, book.thumb, true)),
            el('span', { class: 'ol-card-title' }, book.title),
            book.authors.length ? el('span', { class: 'ol-sub' }, book.authors.join(', ')) : null,
            sub ? el('span', { class: 'ol-sub ol-series' }, sub) : null));
    }

    function setSort(sort, order) {
        $('ol-sort-by').value = sort;
        const b = $('ol-order');
        b.textContent = order === 'desc' ? '↓' : '↑';
        b.title = order === 'desc' ? 'Descending' : 'Ascending';
        b.dataset.order = order;
    }

    function changeSort(sort, order) {
        const r = parse();
        delete r.params.book;
        go(r.view, { ...r.params, sort, order });
    }

    // --------------------------------------------------------------- dialog

    let openId = null;

    async function openBook(id) {
        if (openId === id && dialog.open) {
            return;
        }
        openId = id;
        dialog.replaceChildren(el('p', { class: 'ol-message', id: 'ol-dialog-title' }, 'Loading…'));
        if (!dialog.open) {
            dialog.showModal();
        }
        try {
            const book = await api('GET', '/api/books/' + encodeURIComponent(id));
            if (openId === id) {
                drawBook(book);
            }
        } catch (e) {
            if (openId === id) {
                dialog.replaceChildren(closeButton(), el('p', { class: 'ol-message ol-error', id: 'ol-dialog-title' }, e.message));
            }
        }
    }

    function closeButton() {
        return el('button', { type: 'button', class: 'ol-close', 'aria-label': 'Close', title: 'Close', onclick: () => dialog.close() }, '×');
    }

    function link(params, text) {
        return el('a', { href: '#/books' + query(params) }, text);
    }

    function joined(nodes) {
        return nodes.flatMap((n, i) => (i ? [', ', n] : [n]));
    }

    function drawBook(book) {
        stopEditing();
        for (const l of book.languages) {
            languageNames[l.code] = l.name;
        }
        const facts = el('dl', { class: 'ol-facts' });
        const fact = (name, value) => {
            if (value && (!Array.isArray(value) || value.length)) {
                facts.append(el('dt', null, name), el('dd', null, value));
            }
        };
        fact('Series', book.series ? [link({ series: book.series }, book.series), book.seriesIndex ? ', number ' + book.seriesIndex : ''] : null);
        fact('Genres', joined(book.subjects.map((s) => link({ subject: s }, s))));
        fact('Publisher', book.publisher ? link({ publisher: book.publisher }, book.publisher) : null);
        fact('Published', book.pubdate);
        fact(book.languages.length > 1 ? 'Languages' : 'Language', joined(book.languages.map((l) => link({ language: l.code }, l.name))));
        if (book.rating) {
            fact('Rating', link({ rating: book.rating }, stars(book.rating) + ' (' + book.rating / 2 + ' of 5)'));
        }
        for (const c of book.columns) {
            for (const v of c.values) {
                if (v.label !== v.value) {
                    valueLabels[c.key + ' ' + v.value] = v.label;
                }
            }
            fact(c.name, c.type === 'comments' ? c.text.split('\n').map((t) => el('p', null, t))
                : c.values.length === 0 ? c.text
                    : c.type === 'series' ? link({ column: c.key, value: c.values[0].value }, c.text)
                        : joined(c.values.map((v) => link({ column: c.key, value: v.value }, v.label))));
        }
        fact('ISBN', book.isbn);
        fact('Identifiers', book.identifiers.join(', '));
        fact('Folder', book.folder);

        const files = el('ul', { class: 'ol-files' }, book.formats.map((f) => el('li', null,
            el('a', { class: 'button primary', href: f.url, download: f.name }, 'Download ' + f.format.toUpperCase()),
            el('span', { class: 'ol-sub' }, size(f.size)),
            el('a', { class: 'ol-inline', href: OC.generateUrl('/f/' + f.id) }, 'Show in Files'))));

        const actions = [];
        if (book.calibre && !book.calibre.readOnly) {
            actions.push(el('button', { type: 'button', onclick: () => drawEditor(book) }, 'Edit'), el('span', { class: 'ol-beta' }, 'Beta'));
        }
        dialog.replaceChildren(closeButton(), el('div', { class: 'ol-book' },
            el('div', { class: 'ol-book-cover' }, cover(book, book.cover, false)),
            el('div', { class: 'ol-book-text' },
                el('h3', { id: 'ol-dialog-title' }, book.title),
                book.authors.length ? el('p', { class: 'ol-authors' }, joined(book.authors.map((a) => link({ author: a }, a)))) : null,
                files,
                actions.length ? el('div', { class: 'ol-actions' }, actions) : null,
                book.calibre && book.calibre.readOnly ? el('p', { class: 'ol-sub' }, 'Cannot be edited: ' + book.calibre.readOnly + '.') : null,
                book.description ? el('div', { class: 'ol-description' }, book.description.split('\n').map((t) => el('p', null, t))) : null,
                facts)));
    }

    // --------------------------------------------------------------- editor

    function field(id, label, input, hint) {
        input.id = 'ol-f-' + id;
        const parts = [el('label', { htmlFor: input.id }, label), input];
        if (hint) {
            const h = el('p', { class: 'ol-sub', id: input.id + '-hint' }, hint);
            input.setAttribute('aria-describedby', h.id);
            parts.push(h);
        }
        return el('div', { class: 'ol-field ol-field-' + id }, parts);
    }

    // Names may contain commas, so lists use one line or & / ; as separators
    const splitAuthors = (s) => s.split('&').map((x) => x.trim()).filter(Boolean);
    const splitTags = (s) => s.split(',').map((x) => x.trim()).filter(Boolean);

    function suggest(input, id, names, separator) {
        const options = el('datalist', { id });
        input.setAttribute('list', id);
        const fill = () => {
            const at = separator ? input.value.lastIndexOf(separator) : -1;
            const before = at >= 0 ? input.value.slice(0, at + 1) + ' ' : '';
            const word = input.value.slice(at + 1).trim().toLocaleLowerCase();
            options.replaceChildren(...(word ? names.filter((n) => n.toLocaleLowerCase().includes(word)).slice(0, 12) : [])
                .map((n) => el('option', { value: before + n })));
        };
        input.addEventListener('input', fill);
        return options;
    }

    function drawEditor(book) {
        const c = book.calibre;
        const text = (value, props) => el('input', { type: 'text', value, ...props });
        const inputs = {
            title: text(c.title, { required: true }),
            sort: text(c.sort),
            authors: text(c.authors.join(' & ')),
            author_sort: text(c.author_sort),
            series: text(c.series),
            series_index: el('input', { type: 'number', step: '0.01', value: c.series ? c.series_index : 1 }),
            tags: text(c.tags.join(', ')),
            publisher: text(c.publisher),
            pubdate: el('input', { type: 'date', value: c.pubdate }),
            languages: text(c.languageNames.join(', ')),
            rating: el('select', null, [0, 2, 4, 6, 8, 10, 1, 3, 5, 7, 9].sort((a, b) => a - b)
                .map((n) => el('option', { value: n, selected: n === c.rating }, n ? n / 2 + ' of 5' : 'No rating'))),
            identifiers: text(Object.entries(c.identifiers).map(([k, v]) => k + ':' + v).join(', ')),
            comments: el('textarea', { rows: 7, value: c.comments }),
        };
        const initial = Object.fromEntries(Object.entries(inputs).map(([k, i]) => [k, i.value]));
        const columns = (c.fields || []).filter((f) => f.editable).map(columnInput).filter(Boolean);
        const lists = el('div', { hidden: true });
        api('GET', '/api/books/' + book.id + '/names').then((names) => {
            lists.append(
                suggest(inputs.authors, 'ol-l-authors', names.authors, '&'),
                suggest(inputs.series, 'ol-l-series', names.series),
                suggest(inputs.tags, 'ol-l-tags', names.tags, ','),
                suggest(inputs.publisher, 'ol-l-publishers', names.publishers),
                suggest(inputs.languages, 'ol-l-languages', state.languages, ','),
                columns.filter((x) => names.columns && names.columns[x.f.label])
                    .map((x) => suggest(x.input, 'ol-l-c-' + x.f.label, names.columns[x.f.label], x.f.multiple ? (x.f.names ? '&' : ',') : null)));
        }).catch(() => {});

        // Cover: chosen here, written together with the other fields
        let newCover = null; // File | 'file' | 'remove'
        const picture = el('div', { class: 'ol-book-cover ol-drop' }, cover(book, book.cover, false));
        const coverNote = el('p', { class: 'ol-sub', role: 'status' });
        const pick = el('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif', class: 'ol-visually-hidden', id: 'ol-cover-file', tabIndex: -1, 'aria-hidden': 'true' });
        let preview = null;
        const choose = (file) => {
            if (!file) {
                return;
            }
            if (!/^image\/(jpeg|png|webp|gif)$/.test(file.type)) {
                coverNote.textContent = 'Choose a JPEG, PNG, WebP or GIF image.';
                return;
            }
            newCover = file;
            if (preview) {
                URL.revokeObjectURL(preview);
            }
            preview = URL.createObjectURL(file);
            picture.replaceChildren(el('img', { src: preview, alt: 'New cover' }));
            coverNote.textContent = 'New cover, not saved yet.';
        };
        pick.addEventListener('change', () => choose(pick.files[0]));
        for (const type of ['dragenter', 'dragover']) {
            picture.addEventListener(type, (e) => {
                e.preventDefault();
                picture.classList.add('ol-over');
            });
        }
        picture.addEventListener('dragleave', () => picture.classList.remove('ol-over'));
        picture.addEventListener('drop', (e) => {
            e.preventDefault();
            picture.classList.remove('ol-over');
            choose(e.dataTransfer.files[0]);
        });
        const coverButtons = el('div', { class: 'ol-cover-buttons' },
            el('button', { type: 'button', onclick: () => pick.click() }, 'Choose image…'),
            el('button', { type: 'button', onclick: () => {
                newCover = 'file';
                coverNote.textContent = 'The cover inside the book file is used when you save.';
            } }, 'Take from book file'),
            c.has_cover ? el('button', { type: 'button', onclick: () => {
                newCover = 'remove';
                picture.replaceChildren(cover(book, '', false));
                coverNote.textContent = 'The cover is removed when you save.';
            } }, 'Remove cover') : null,
            pick, coverNote);

        const status = el('p', { class: 'ol-status', role: 'status' });
        const save = el('button', { type: 'submit', class: 'primary' }, 'Save');
        const cancel = el('button', { type: 'button', onclick: () => leave() && drawBook(book) }, 'Cancel');
        const changed = () => Object.keys(inputs).filter((k) => inputs[k].value !== initial[k]);
        const changedColumns = () => columns.filter((x) => x.state() !== x.initial);
        const dirty = () => changed().length > 0 || changedColumns().length > 0 || newCover !== null;
        const leave = () => !dirty() || window.confirm('Discard your changes?');
        dialog.dataset.editing = '1';
        dialog.mayLeave = leave;

        const form = el('form', { class: 'ol-book ol-edit', onsubmit: async (e) => {
            e.preventDefault();
            const fields = {};
            try {
                for (const key of changed()) {
                    const value = inputs[key].value;
                    fields[key] = key === 'authors' ? splitAuthors(value)
                        : key === 'tags' || key === 'languages' ? splitTags(value)
                            : key === 'identifiers' ? readIdentifiers(value)
                                : key === 'rating' ? Number(value) : value;
                }
                if (changedColumns().length) {
                    fields.custom = Object.fromEntries(changedColumns().map((x) => [x.f.label, x.read()]));
                }
            } catch (problem) {
                status.textContent = problem.message;
                status.className = 'ol-status ol-error';
                return;
            }
            save.disabled = cancel.disabled = true;
            status.className = 'ol-status';
            status.textContent = 'Saving…';
            try {
                if (Object.keys(fields).length) {
                    await api('PUT', '/api/books/' + book.id, { fields, rev: c.rev });
                    for (const key of Object.keys(fields)) {
                        if (key in inputs) {
                            initial[key] = inputs[key].value;
                        }
                    }
                    for (const x of columns) {
                        x.initial = x.state();
                    }
                    c.rev = '';
                }
                if (newCover === 'remove') {
                    await api('DELETE', '/api/books/' + book.id + '/cover');
                } else if (newCover === 'file') {
                    await api('POST', '/api/books/' + book.id + '/cover/file');
                } else if (newCover) {
                    const data = new FormData();
                    data.append('cover', newCover);
                    await api('POST', '/api/books/' + book.id + '/cover', data);
                }
                newCover = null;
                const fresh = await api('GET', '/api/books/' + book.id);
                drawBook(fresh);
                updateCard(fresh);
            } catch (problem) {
                status.textContent = problem.message;
                status.className = 'ol-status ol-error';
                save.disabled = cancel.disabled = false;
            }
        } },
            el('div', null, picture, coverButtons),
            el('div', { class: 'ol-book-text' },
                el('h3', { id: 'ol-dialog-title' }, 'Edit book ', el('span', { class: 'ol-beta' }, 'Beta')),
                field('title', 'Title', inputs.title),
                field('authors', 'Authors', inputs.authors, 'Separate several authors with &'),
                el('div', { class: 'ol-pair' }, field('series', 'Series', inputs.series), field('series_index', 'Number', inputs.series_index)),
                field('tags', 'Tags', inputs.tags, 'Separate with commas'),
                el('div', { class: 'ol-pair' }, field('publisher', 'Publisher', inputs.publisher), field('pubdate', 'Published', inputs.pubdate)),
                el('div', { class: 'ol-pair' }, field('languages', 'Languages', inputs.languages), field('rating', 'Rating', inputs.rating)),
                field('comments', 'Description', inputs.comments, c.formatted ? 'This description has formatting, which is lost if you change it here' : null),
                columns.length ? el('details', { open: true, class: 'ol-columns' }, el('summary', null, 'Columns'),
                    columns.map((x) => x.index
                        ? el('div', { class: 'ol-pair' }, field('c-' + x.f.label, x.f.name, x.input, x.hint), field('c-' + x.f.label + '-index', 'Number', x.index))
                        : field('c-' + x.f.label, x.f.name, x.input, x.hint))) : null,
                el('details', null, el('summary', null, 'Sorting and identifiers'),
                    field('sort', 'Sort title as', inputs.sort, 'Follows the title unless you change it'),
                    field('author_sort', 'Sort authors as', inputs.author_sort, 'Follows the authors unless you change it'),
                    field('identifiers', 'Identifiers', inputs.identifiers, 'For example isbn:9780552166591, goodreads:34497')),
                el('p', { class: 'ol-sub' }, 'Changes metadata.db in ' + c.library + ' and renames the folder and files of the book to match. Close Calibre before saving.'),
                status,
                el('div', { class: 'ol-actions' }, save, cancel)),
            lists);
        dialog.replaceChildren(closeButton(), form);
        inputs.title.focus();
    }

    /** An input for a custom column of Calibre, with how to read it */
    function columnInput(f) {
        const text = (value) => el('input', { type: 'text', value });
        const choice = (options, value) => el('select', null, options.map(([v, label]) => el('option', { value: v, selected: v === value }, label)));
        let input;
        let index = null;
        let hint = null;
        let read = () => input.value;
        switch (f.type) {
        case 'text':
            if (f.multiple) {
                input = text(f.value.join(f.names ? ' & ' : ', '));
                hint = f.names ? 'Separate several with &' : 'Separate with commas';
                read = () => (f.names ? splitAuthors : splitTags)(input.value);
            } else {
                input = text(f.value);
            }
            break;
        case 'series':
            input = text(f.value);
            index = el('input', { type: 'number', step: '0.01', value: f.value ? f.index : 1 });
            read = () => ({ value: input.value.trim(), index: index.value === '' ? 1 : Number(index.value) });
            break;
        case 'enumeration':
            input = choice([['', '—'], ...f.values.map((v) => [v, v])], f.value);
            break;
        case 'comments':
            input = el('textarea', { rows: 4, value: f.value });
            hint = f.formatted ? 'This text has formatting, which is lost if you change it here' : null;
            break;
        case 'datetime':
            input = el('input', { type: 'date', value: f.value });
            break;
        case 'int':
        case 'float':
            input = el('input', { type: 'number', step: f.type === 'int' ? '1' : 'any', value: f.value === '' || f.value === null ? '' : String(f.value) });
            read = () => (input.value === '' ? null : Number(input.value));
            break;
        case 'bool':
            input = choice([['', '—'], ['yes', 'Yes'], ['no', 'No']], f.value === true ? 'yes' : f.value === false ? 'no' : '');
            read = () => (input.value === '' ? null : input.value === 'yes');
            break;
        case 'rating': {
            const steps = f.half ? [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10] : [0, 2, 4, 6, 8, 10];
            input = choice(steps.map((n) => [String(n), n ? stars(n) + ' (' + n / 2 + ' of 5)' : 'No rating']), String(f.value || 0));
            read = () => Number(input.value);
            break;
        }
        default:
            return null;
        }
        const snapshot = () => input.value + (index ? '\u0000' + index.value : '');
        return { f, input, index, hint, read, state: snapshot, initial: snapshot() };
    }

    function readIdentifiers(value) {
        const out = {};
        for (const part of splitTags(value)) {
            const at = part.indexOf(':');
            if (at < 1) {
                throw new Error('Identifiers look like isbn:9780552166591');
            }
            out[part.slice(0, at).trim()] = part.slice(at + 1).trim();
        }
        return out;
    }

    function updateCard(book) {
        const old = content.querySelector('li[data-id="' + Number(book.id) + '"]');
        if (old) {
            old.replaceWith(card(book));
        }
    }

    // Leaving the editor by Escape, the close button or the back button
    function stopEditing() {
        delete dialog.dataset.editing;
        dialog.mayLeave = null;
    }

    dialog.addEventListener('cancel', (e) => {
        if (dialog.mayLeave && !dialog.mayLeave()) {
            e.preventDefault();
        }
    });
    const nativeClose = dialog.close.bind(dialog);
    dialog.close = () => {
        if (dialog.mayLeave && !dialog.mayLeave()) {
            return;
        }
        nativeClose();
    };
    dialog.addEventListener('close', () => {
        stopEditing();
        openId = null;
        dialog.replaceChildren();
        if (parse().params.book) {
            history.replaceState(null, '', withoutBook());
        }
    });
    dialog.addEventListener('click', (e) => {
        if (e.target === dialog) {
            dialog.close();
        } else if (e.target.closest && e.target.closest('a[href^="#/"]')) {
            stopEditing();
        }
    });

    // --------------------------------------------------------------- events

    /** Lists and sort orders that depend on the books that are there */
    function drawLists(lists) {
        if (!lists.sorts) {
            return;
        }
        const sortBy = $('ol-sort-by').value;
        $('ol-sort-by').replaceChildren(...Object.entries(lists.sorts).map(([key, label]) => el('option', { value: key }, label)));
        if (sortBy in lists.sorts) {
            $('ol-sort-by').value = sortBy;
        }
        for (const kind of extraKinds) {
            delete KINDS[kind];
        }
        extraKinds.clear();
        for (const item of root.querySelectorAll('.ol-nav li.ol-extra')) {
            item.remove();
        }
        const extra = [...(lists.rated ? [{ kind: 'rating', name: 'Ratings' }] : []), ...lists.columns];
        const current = parse();
        for (const { kind, name } of extra) {
            KINDS[kind] = name;
            extraKinds.add(kind);
            const active = kind === (current.view === 'books' ? activeView(current.params) : current.view);
            root.querySelector('.ol-nav ul').append(el('li', { class: 'ol-extra' },
                el('a', { href: '#/' + kind, 'data-view': kind, 'aria-current': active ? 'page' : null }, name)));
        }
    }
    drawLists(state);
    $('ol-settings').href = state.settingsUrl;
    $('ol-sort-by').addEventListener('change', (e) => {
        const sort = e.target.value;
        changeSort(sort, state.newestFirst.includes(sort) ? 'desc' : 'asc');
    });
    $('ol-order').addEventListener('click', () => changeSort($('ol-sort-by').value, $('ol-order').dataset.order === 'desc' ? 'asc' : 'desc'));

    let typing = null;
    const search = () => {
        clearTimeout(typing);
        const q = $('ol-search').value.trim();
        const r = parse();
        if (q === (r.params.q || '')) {
            return;
        }
        const target = '#/home' + query({ q });
        if (r.params.q) {
            // One history entry per search, not one per letter
            history.replaceState(null, '', target);
            route();
        } else {
            location.hash = target;
        }
    };
    $('ol-search').addEventListener('input', () => {
        clearTimeout(typing);
        typing = setTimeout(search, 300);
    });
    $('ol-search-form').addEventListener('submit', (e) => {
        e.preventDefault();
        search();
    });
    $('ol-more').querySelector('button').addEventListener('click', () => list && list.more && list.more());
    if ('IntersectionObserver' in window) {
        new IntersectionObserver((entries) => {
            if (entries.some((e) => e.isIntersecting) && list && list.more) {
                list.more();
            }
        }, { root: $('ol-scroll'), rootMargin: '600px' }).observe($('ol-more'));
    }

    async function refresh(force) {
        const button = $('ol-refresh');
        button.disabled = true;
        button.textContent = 'Checking…';
        try {
            let result;
            let changed = false;
            do {
                result = await api('POST', '/api/refresh', { force });
                drawLists(result);
                changed = changed || result.changed;
                force = true;
            } while (!result.complete && !result.error);
            if (result.error) {
                message(result.error, true);
            } else if (changed && !dialog.open) {
                shownList = null;
                route();
            }
        } catch (e) {
            message(e.message, true);
        } finally {
            button.disabled = false;
            button.textContent = 'Check for new books';
        }
    }
    $('ol-refresh').addEventListener('click', () => refresh(true));

    window.addEventListener('hashchange', route);
    route();
    refresh(false);
}());
