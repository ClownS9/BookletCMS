/*
 * Page builder: building on the page itself. Only people who can edit the
 * page get this file. "Edit this page" turns the page into its draft, ready
 * to work on: click a section to choose it, click words to type into them,
 * and use the tools beside it, the drawer and the bar along the bottom.
 * Readers keep seeing what's published until Publish.
 *
 * The editor's own buttons live in a sealed-off layer (a shadow root), so
 * the site's theme never changes how they look, and they never change the
 * theme.
 */
(() => {
    const host = document.getElementById('pb-editor');
    const page = document.querySelector('.pb-page[data-pb-page]');
    if (!host || !page || host.dataset.ready) return;
    host.dataset.ready = '1';
    const cfg = host.dataset;

    document.body.appendChild(host);
    host.hidden = false;
    host.setAttribute('style', 'position: fixed; top: 0; left: 0; width: 0; height: 0; z-index: 2147483000;');
    const ui = host.attachShadow({ mode: 'open' });
    ui.innerHTML = `
        <link rel="stylesheet" href="${cfg.css}">
        <button type="button" class="start" part="start"><span aria-hidden="true">✎</span> <span class="start__words"></span></button>
        <div class="tools" hidden>
            <span class="tools__name"></span>
            <button type="button" data-tool="up" aria-label="Move up" title="Move up">↑</button>
            <button type="button" data-tool="down" aria-label="Move down" title="Move down">↓</button>
            <button type="button" data-tool="copy" aria-label="Make a copy" title="Make a copy"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg></button>
            <button type="button" data-tool="add" aria-label="Add a section after this one" title="Add a section after this one">+</button>
            <button type="button" data-tool="settings" aria-label="Its settings" title="Its settings"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/></svg></button>
            <button type="button" data-tool="remove" class="danger" aria-label="Take it out" title="Take it out">×</button>
        </div>
        <button type="button" class="add-here" hidden>+ Add a section here</button>
        <aside class="drawer" aria-label="Page builder" hidden>
            <button type="button" class="drawer__close" aria-label="Close the drawer" title="Close">×</button>
            <div class="drawer__inner"></div>
        </aside>
        <div class="bar" role="toolbar" aria-label="Page builder" hidden>
            <span class="bar__who"><b class="bar__name"></b><span class="bar__state"></span></span>
            <span class="bar__do">
                <button type="button" data-bar="undo" title="Undo (Ctrl+Z)">Undo</button>
                <button type="button" data-bar="phone">Phone view</button>
                <button type="button" data-bar="drawer" aria-pressed="false">Drawer</button>
                <button type="button" data-bar="publish" class="ink">Publish</button>
                <button type="button" data-bar="done">Done</button>
            </span>
        </div>
        <div class="toast" role="status" aria-live="polite" hidden></div>
        <div class="phone" hidden>
            <div class="phone__frame"><iframe title="The draft, as a phone shows it"></iframe></div>
            <button type="button" class="phone__close">Back to editing</button>
        </div>`;

    const $ = (s) => ui.querySelector(s);
    $('.start__words').textContent = cfg.label || 'Edit this page';
    const start = $('.start'), tools = $('.tools'), addHere = $('.add-here'), drawer = $('.drawer'), inner = $('.drawer__inner');
    const bar = $('.bar'), toastEl = $('.toast'), phone = $('.phone');

    let state = { section: null, tab: 'section', adding: false, picking: null };
    let editing = false;
    let queue = Promise.resolve();
    let busy = 0;

    // Talking to Booklet ----------------------------------------------------------------

    const request = (method, url, body) => fetch(url, {
        method,
        credentials: 'same-origin',
        headers: Object.assign({ Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body ? { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': cfg.token } : {}),
        body: body ? JSON.stringify(body) : undefined,
    }).then(async (r) => {
        if (r.status === 419) throw new Error('Your sign-in ran out. Reload the page to carry on (your changes so far are kept).');
        if (r.status === 401 || r.status === 403) throw new Error('You’re signed out. Sign in again to carry on.');
        let data = null;
        try { data = await r.json(); } catch (e) {}
        if (!data) throw new Error('That didn’t work. Try again in a moment.');
        return data;
    });

    // One at a time, in the order they were asked for.
    const act = (action, payload = null, opts = {}) => {
        busy++;
        queue = queue.then(() => request('POST', cfg.act, { action, payload, state, query: cfg.query || '', quiet: !!opts.quiet }))
            .then((data) => apply(data, opts))
            .catch((e) => toast(e.message, true))
            .finally(() => { busy--; });
        return queue;
    };

    const apply = (data, opts = {}) => {
        if (data.gone) { window.location.href = data.gone; return; }
        if (data.state) state = data.state;
        if (data.page !== null && data.page !== undefined) drawPage(data.page);
        if (data.drawer) drawDrawer(data.drawer, opts.quiet);
        if (data.bar) drawBar(data.bar);
        if (data.moved) {
            const u = new URL(data.moved);
            history.replaceState(null, '', u.pathname + location.search);
        }
        if (data.stamp) toast(data.stamp);
        markSelected(opts.scroll);
    };

    // The page --------------------------------------------------------------------------

    const drawPage = (html) => {
        const y = window.scrollY;
        page.innerHTML = html;
        if (!page.querySelector('[data-pb-section]')) {
            page.insertAdjacentHTML('beforeend', '<p class="pb-nothing" style="padding: 80px 24px; text-align: center">Nothing on this page yet. Add a section from the drawer.</p>');
        }
        page.querySelectorAll('[data-pb-field]').forEach((f) => { f.tabIndex = 0; });
        window.scrollTo(0, y);
    };

    const sectionEl = (id) => (id ? page.querySelector('[data-pb-section="' + CSS.escape(id) + '"]') : null);

    const markSelected = (scroll) => {
        page.querySelectorAll('[data-pb-section].is-selected').forEach((s) => s.classList.remove('is-selected'));
        const el = sectionEl(state.section);
        if (el) {
            el.classList.add('is-selected');
            if (scroll) {
                const r = el.getBoundingClientRect();
                if (r.top < 60 || r.top > innerHeight * 0.6) {
                    window.scrollTo({ top: window.scrollY + r.top - 90, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
                }
            }
        }
        placeTools();
    };

    const choose = (id, opts = {}) => {
        if (state.section === id && !state.adding && !state.picking && state.tab === 'section') return;
        state.section = id;
        markSelected(opts.scroll);
        act('select', id, { quiet: true });
    };

    // Typing into words
    const caretAt = (x, y) => {
        let range = null;
        if (document.caretRangeFromPoint) range = document.caretRangeFromPoint(x, y);
        else if (document.caretPositionFromPoint) {
            const p = document.caretPositionFromPoint(x, y);
            if (p) { range = document.createRange(); range.setStart(p.offsetNode, p.offset); }
        }
        if (range) { const sel = getSelection(); sel.removeAllRanges(); sel.addRange(range); }
    };
    const textOf = (el) => (el.innerText || '').replace(/ /g, ' ').replace(/\n$/, '');

    const type = (field, id, x, y) => {
        if (field.isContentEditable) return;
        field.contentEditable = 'plaintext-only';
        if (field.contentEditable !== 'plaintext-only') field.contentEditable = 'true';
        field.classList.add('is-editing');
        field.focus({ preventScroll: true });
        if (x !== undefined) caretAt(x, y);
        else { const r = document.createRange(); r.selectNodeContents(field); r.collapse(false); const s = getSelection(); s.removeAllRanges(); s.addRange(r); }

        const line = field.dataset.pbKind !== 'words';
        let timer = null;
        let sent = textOf(field);
        const push = () => {
            clearTimeout(timer);
            const value = textOf(field);
            if (value === sent) return;
            sent = value;
            act('set', { id, field: field.dataset.pbField, value }, { quiet: true });
        };
        const onInput = () => {
            field.classList.toggle('pb-empty', textOf(field).trim() === '');
            if (field.textContent === '') field.innerHTML = '';
            clearTimeout(timer);
            timer = setTimeout(push, 700);
            placeTools();
        };
        const onKey = (e) => {
            if ((e.key === 'Enter' && line) || e.key === 'Escape') { e.preventDefault(); field.blur(); }
        };
        const onPaste = (e) => {
            e.preventDefault();
            let text = (e.clipboardData || window.clipboardData).getData('text/plain') || '';
            if (line) text = text.replace(/\s+/g, ' ');
            document.execCommand('insertText', false, text);
        };
        const done = () => {
            push();
            field.removeAttribute('contenteditable');
            field.classList.remove('is-editing');
            field.removeEventListener('input', onInput);
            field.removeEventListener('keydown', onKey);
            field.removeEventListener('paste', onPaste);
            field.removeEventListener('blur', done);
        };
        field.addEventListener('input', onInput);
        field.addEventListener('keydown', onKey);
        field.addEventListener('paste', onPaste);
        field.addEventListener('blur', done);
    };

    page.addEventListener('click', (e) => {
        if (!editing) return;
        const section = e.target.closest('[data-pb-section]');
        if (!section) return;
        const id = section.dataset.pbSection;
        if (e.target.closest('a')) e.preventDefault();
        if (e.target.closest('summary')) e.preventDefault(); // questions stay open while editing
        const pick = e.target.closest('[data-pb-pick]');
        if (pick) {
            e.preventDefault();
            state.section = id;
            markSelected();
            openDrawer();
            act('pick', { id, field: pick.dataset.pbPick }, { quiet: true });
            return;
        }
        choose(id);
        const field = e.target.closest('[data-pb-field]');
        if (field) type(field, id, e.clientX, e.clientY);
    }, true);

    page.addEventListener('keydown', (e) => {
        if (!editing) return;
        const field = e.target.closest && e.target.closest('[data-pb-field]');
        if (field && !field.isContentEditable && e.key === 'Enter') {
            e.preventDefault();
            const id = field.closest('[data-pb-section]').dataset.pbSection;
            choose(id);
            type(field, id);
        }
    });
    document.addEventListener('submit', (e) => { if (editing && page.contains(e.target)) { e.preventDefault(); e.stopPropagation(); } }, true);

    // The tools beside the chosen section ---------------------------------------------------

    let placing = false;
    const placeTools = () => {
        if (placing) return;
        placing = true;
        requestAnimationFrame(() => {
            placing = false;
            const el = editing ? sectionEl(state.section) : null;
            tools.hidden = addHere.hidden = !el;
            if (!el) return;
            const r = el.getBoundingClientRect();
            // Never under the bar, or under the drawer when it's a sheet from the bottom.
            let floor = bar.hidden ? innerHeight : bar.getBoundingClientRect().top;
            if (!drawer.hidden) { const d = drawer.getBoundingClientRect(); if (d.left < 40) floor = Math.min(floor, d.top); }
            const top = Math.min(Math.max(r.top + 8, 8), Math.max(r.bottom - 44, 8), floor - 50);
            tools.style.top = top + 'px';
            tools.style.left = Math.max(r.left + 8, 8) + 'px';
            const name = el.dataset.pbName || '';
            if (tools.querySelector('.tools__name').textContent !== name) tools.querySelector('.tools__name').textContent = name;
            const all = [...page.querySelectorAll('[data-pb-section]')];
            tools.querySelector('[data-tool="up"]').disabled = all[0] === el;
            tools.querySelector('[data-tool="down"]').disabled = all[all.length - 1] === el;
            // The blog's posts move, but don't go; its featured story and categories show once.
            const required = el.hasAttribute('data-pb-required');
            tools.querySelector('[data-tool="copy"]').hidden = required || el.hasAttribute('data-pb-once');
            tools.querySelector('[data-tool="remove"]').hidden = required;
            const below = r.bottom - 18;
            addHere.hidden = below < 40 || below > innerHeight - 90;
            addHere.style.top = below + 'px';
            addHere.style.left = (r.left + r.width / 2) + 'px';
        });
    };
    addEventListener('scroll', placeTools, { passive: true });
    addEventListener('resize', placeTools);

    tools.addEventListener('click', (e) => {
        const b = e.target.closest('[data-tool]');
        if (!b || !state.section) return;
        const id = state.section;
        const t = b.dataset.tool;
        if (t === 'up' || t === 'down') act('move', { id, step: t === 'up' ? -1 : 1 }, { scroll: true });
        else if (t === 'copy') act('copy', id, { scroll: true });
        else if (t === 'remove') act('remove', id);
        else if (t === 'settings') { openDrawer(); act('select', id, { quiet: true }); }
        else if (t === 'add') { openDrawer(); act('adding', id, { quiet: true }); }
    });
    addHere.addEventListener('click', () => { openDrawer(); act('adding', state.section, { quiet: true }); });

    // The drawer ----------------------------------------------------------------------------

    const openDrawer = (open = true) => {
        drawer.hidden = !open;
        document.documentElement.classList.toggle('pb-drawer-open', open);
        bar.classList.toggle('with-drawer', open);
        bar.querySelector('[data-bar="drawer"]').setAttribute('aria-pressed', open ? 'true' : 'false');
        placeTools();
    };
    $('.drawer__close').addEventListener('click', () => openDrawer(false));

    const drawDrawer = (html, quiet) => {
        // While a box in the drawer is being typed in, leave it be.
        if (quiet && ui.activeElement && inner.contains(ui.activeElement) && ui.activeElement.matches('input, textarea, select')) return;
        const open = [...inner.querySelectorAll('details[data-item][open]')].map((d) => d.dataset.item);
        // Same view as before (the same section, tab…): keep the place; a different one starts at the top.
        const view = (d) => { const x = d && d.querySelector('.d'); return x ? (x.dataset.section || '') + '|' + (x.dataset.view || '') : ''; };
        const before = view(inner);
        const y = inner.scrollTop;
        inner.innerHTML = html;
        open.forEach((key) => { const d = inner.querySelector('details[data-item="' + CSS.escape(key) + '"]'); if (d) d.open = true; });
        inner.scrollTop = before === view(inner) ? y : 0;
    };

    inner.addEventListener('click', (e) => {
        const b = e.target.closest('[data-do]');
        if (!b || b.disabled) return;
        e.preventDefault();
        if (b.dataset.confirm && !confirm(b.dataset.confirm)) return;
        let payload = null;
        try { payload = b.dataset.payload ? JSON.parse(b.dataset.payload) : null; } catch (err) {}
        const action = b.dataset.do;
        const quiet = ['tab', 'adding', 'pick', 'unpick'].includes(action);
        act(action, payload, { quiet, scroll: ['select', 'add', 'move', 'copy'].includes(action) });
    });
    inner.addEventListener('change', (e) => {
        const box = e.target;
        if (box.matches('[data-home]')) { act('home', box.checked, { quiet: true }); return; }
        if (!box.matches('[data-set]')) return;
        const id = box.closest('[data-section]') && box.closest('[data-section]').dataset.section;
        if (id) act('set', { id, field: box.dataset.set, value: box.type === 'checkbox' ? box.checked : box.value });
    });
    inner.addEventListener('submit', (e) => {
        const form = e.target.closest('[data-form="details"]');
        if (!form) return;
        e.preventDefault();
        act('details', Object.fromEntries(new FormData(form)), { quiet: true });
    });
    // Dragging sections in "All sections"
    let dragging = null;
    inner.addEventListener('dragstart', (e) => {
        const li = e.target.closest('.d-outline li');
        if (!li) return;
        dragging = li.dataset.id;
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', li.textContent.trim());
        li.classList.add('is-dragging');
    });
    inner.addEventListener('dragover', (e) => {
        const li = e.target.closest('.d-outline li');
        if (!li || !dragging) return;
        e.preventDefault();
        inner.querySelectorAll('.is-over').forEach((x) => x.classList.remove('is-over'));
        li.classList.add('is-over');
    });
    inner.addEventListener('drop', (e) => {
        const li = e.target.closest('.d-outline li');
        if (!li || !dragging) return;
        e.preventDefault();
        if (li.dataset.id !== dragging) act('move', { id: dragging, to: Number(li.dataset.at) }, { scroll: true });
        dragging = null;
    });
    inner.addEventListener('dragend', () => { dragging = null; inner.querySelectorAll('.is-over, .is-dragging').forEach((x) => x.classList.remove('is-over', 'is-dragging')); });

    // The bar -------------------------------------------------------------------------------

    const drawBar = (b) => {
        bar.querySelector('.bar__name').textContent = b.name;
        const st = bar.querySelector('.bar__state');
        st.textContent = b.words + ' · ' + b.address;
        st.dataset.status = b.status;
        bar.querySelector('[data-bar="undo"]').disabled = !b.undoable;
        const pub = bar.querySelector('[data-bar="publish"]');
        pub.disabled = b.status === 'live';
        pub.textContent = b.status === 'draft' ? 'Publish' : (b.status === 'changed' ? 'Publish changes' : 'Published');
    };

    bar.addEventListener('click', (e) => {
        const b = e.target.closest('[data-bar]');
        if (!b || b.disabled) return;
        const what = b.dataset.bar;
        if (what === 'undo') act('undo');
        else if (what === 'publish') act('publish', null, { quiet: true });
        else if (what === 'drawer') openDrawer(drawer.hidden);
        else if (what === 'phone') showPhone(true);
        else if (what === 'done') finish();
    });

    const showPhone = (on) => {
        phone.hidden = !on;
        bar.style.visibility = on ? 'hidden' : '';
        const frame = phone.querySelector('iframe');
        if (on) frame.src = cfg.preview + (cfg.preview.includes('?') ? '&' : '?') + (cfg.query ? cfg.query + '&' : '') + 't=' + Date.now();
        else frame.removeAttribute('src');
    };
    phone.querySelector('.phone__close').addEventListener('click', () => showPhone(false));
    phone.addEventListener('click', (e) => { if (e.target === phone) showPhone(false); });

    let toastTimer = null;
    const toast = (words, bad = false) => {
        toastEl.textContent = words;
        toastEl.classList.toggle('is-bad', bad);
        toastEl.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { toastEl.hidden = true; }, bad ? 7000 : 3500);
    };

    // Starting and finishing ---------------------------------------------------------------

    const begin = () => {
        start.disabled = true;
        request('GET', cfg.edit + '?query=' + encodeURIComponent(cfg.query || '')).then((data) => {
            editing = true;
            document.documentElement.classList.add('pb-editing');
            page.classList.add('pb-live');
            start.hidden = true;
            bar.hidden = false;
            openDrawer(innerWidth > 760);
            apply(data);
            const u = new URL(location.href);
            if (!u.searchParams.has('edit')) { u.searchParams.set('edit', '1'); history.replaceState(null, '', u); }
        }).catch((e) => { start.disabled = false; toast(e.message, true); });
    };

    const finish = () => {
        queue.then(() => {
            const u = new URL(location.href);
            u.searchParams.delete('edit');
            // A draft nobody can see yet: back to the list of pages; otherwise, the page as readers see it.
            window.location.href = cfg.draft === '1' && bar.querySelector('.bar__state').dataset.status === 'draft' ? cfg.pages : u.toString();
        });
    };

    start.addEventListener('click', begin);
    document.addEventListener('keydown', (e) => {
        if (!editing) return;
        if (e.key === 'Escape' && !phone.hidden) { showPhone(false); return; }
        const typing = (document.activeElement && (document.activeElement.isContentEditable || document.activeElement.matches('input, textarea, select'))) || (ui.activeElement && ui.activeElement.matches('input, textarea, select'));
        if ((e.metaKey || e.ctrlKey) && !e.shiftKey && e.key.toLowerCase() === 'z' && !typing) { e.preventDefault(); act('undo'); }
    });
    addEventListener('beforeunload', (e) => { if (busy > 0) { e.preventDefault(); e.returnValue = ''; } });

    if (cfg.start === '1') begin();
})();
