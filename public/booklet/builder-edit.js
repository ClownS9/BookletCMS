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

    // A page or post that isn't built yet: "Build this page" starts building it (from its own words).
    if (host && host.dataset.begin && !host.dataset.ready) {
        host.dataset.ready = '1';
        document.body.appendChild(host);
        host.hidden = false;
        host.setAttribute('style', 'position: fixed; top: 0; left: 0; width: 0; height: 0; z-index: 2147483000;');
        const shadow = host.attachShadow({ mode: 'open' });
        shadow.innerHTML = `<link rel="stylesheet" href="${host.dataset.css}">
            <button type="button" class="start" part="start"><span aria-hidden="true">✎</span> <span class="start__words"></span></button>
            <div class="toast is-bad" role="status" aria-live="polite" hidden></div>`;
        const button = shadow.querySelector('.start');
        shadow.querySelector('.start__words').textContent = host.dataset.label;
        button.addEventListener('click', () => {
            const name = host.dataset.title ? '“' + host.dataset.title + '”' : 'this page';
            if (!confirm('Build ' + name + ' of sections, here on the page? Its words and pictures become sections to start from. Readers see it as it is until you publish.')) return;
            button.disabled = true;
            fetch(host.dataset.begin, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': host.dataset.token, 'X-Requested-With': 'XMLHttpRequest' } })
                .then((r) => { if (!r.ok) throw new Error(r.status === 419 ? 'Your sign-in ran out. Reload the page and try again.' : 'That didn’t work. Reload the page and try again.'); return r.json(); })
                .then((data) => { window.location.href = data.go; })
                .catch((e) => { button.disabled = false; const t = shadow.querySelector('.toast'); t.textContent = e.message; t.hidden = false; });
        });
        return;
    }

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
            <button type="button" class="tools__grip" data-tool="drag" aria-label="Drag to move" title="Drag to move"><svg width="12" height="16" viewBox="0 0 12 16" fill="currentColor" aria-hidden="true"><circle cx="3" cy="3" r="1.6"/><circle cx="9" cy="3" r="1.6"/><circle cx="3" cy="8" r="1.6"/><circle cx="9" cy="8" r="1.6"/><circle cx="3" cy="13" r="1.6"/><circle cx="9" cy="13" r="1.6"/></svg></button>
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
            <span class="bar__who"><b class="bar__name"></b><span class="bar__state"></span><span class="bar__others" hidden></span></span>
            <span class="bar__do">
                <button type="button" data-bar="undo" title="Undo (Ctrl+Z)">Undo</button>
                <button type="button" data-bar="redo" title="Redo (Ctrl+Shift+Z)">Redo</button>
                <button type="button" data-bar="phone">Phone view</button>
                <button type="button" data-bar="drawer" aria-pressed="false">Drawer</button>
                <button type="button" data-bar="publish" class="ink">Publish</button>
                <button type="button" data-bar="done">Done</button>
            </span>
        </div>
        <div class="fmt" role="toolbar" aria-label="Formatting" hidden>
            <button type="button" data-fmt="bold" aria-label="Bold" title="Bold (Ctrl B)"><b>B</b></button>
            <button type="button" data-fmt="italic" aria-label="Italic" title="Italic (Ctrl I)"><i>I</i></button>
            <button type="button" data-fmt="link" aria-label="Link" title="Link (Ctrl K)"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></svg></button>
            <form class="fmt__link" hidden>
                <input type="text" aria-label="Where the link goes" placeholder="/about, https://…, an email" autocapitalize="off" spellcheck="false">
                <button type="submit" class="fmt__add">Add</button>
                <button type="button" class="fmt__off" data-fmt="unlink">Take off</button>
            </form>
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
    let rev = null; // which change the page is at, as this editor last saw it
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
        queue = queue.then(() => request('POST', cfg.act, { action, payload, state, rev, query: cfg.query || '', quiet: !!opts.quiet }))
            .then((data) => apply(data, opts))
            .catch((e) => toast(e.message, true))
            .finally(() => { busy--; });
        return queue;
    };

    const apply = (data, opts = {}) => {
        if (data.gone) { window.location.href = data.gone; return; }
        if (data.state) state = data.state;
        if (data.page !== null && data.page !== undefined) drawPage(data.page);
        if (data.drawer) drawDrawer(data.drawer, opts.quiet && !opts.redraw);
        if (data.bar) drawBar(data.bar);
        if (data.moved) {
            const u = new URL(data.moved);
            history.replaceState(null, '', u.pathname + location.search);
        }
        if (data.rev !== undefined) rev = data.rev;
        if (data.copy) copyLink(data.copy, data.stamp);
        else if (data.stamp) toast(data.stamp, !!data.stale, data.open ? { href: data.open, words: 'Go to it' } : null);
        else if (data.errors && Object.keys(data.errors).length) toast(Object.values(data.errors)[0], true);
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

    // Words fields keep a little formatting, saved as typed in the drawer: **bold**, *italic*, [a link](/about).
    const escMarks = (t) => t.replace(/[\\*\[\]]/g, (c) => '\\' + c);
    const wrap = (inner, open, close) => {
        const m = inner.match(/^(\s*)([\s\S]*?)(\s*)$/);
        return m[2] === '' ? inner : m[1] + open + m[2] + close + m[3];
    };
    const marked = (node) => {
        let out = '';
        node.childNodes.forEach((n) => {
            if (n.nodeType === 3) { out += escMarks(n.nodeValue.replace(/\u00a0/g, ' ')); return; }
            if (n.nodeType !== 1) return;
            const tag = n.tagName, inner = marked(n);
            if (tag === 'BR') out += '\n';
            else if (tag === 'B' || tag === 'STRONG' || (tag === 'SPAN' && /bold|[6-9]00/.test(n.style.fontWeight))) out += wrap(inner, '**', '**');
            else if (tag === 'I' || tag === 'EM' || (tag === 'SPAN' && n.style.fontStyle === 'italic')) out += wrap(inner, '*', '*');
            else if (tag === 'A' && n.getAttribute('href')) out += wrap(inner, '[', '](' + n.getAttribute('href') + ')');
            else if (tag === 'DIV' || tag === 'P') out += (out !== '' && !out.endsWith('\n') ? '\n' : '') + inner;
            else out += inner;
        });
        return out;
    };
    let rich = null; // the words field being typed into, when it can be formatted

    const type = (field, id, x, y) => {
        if (field.isContentEditable) return;
        const formatted = field.dataset.pbKind === 'words';
        field.contentEditable = formatted ? 'true' : 'plaintext-only';
        if (field.contentEditable !== 'plaintext-only' && !formatted) field.contentEditable = 'true';
        field.classList.add('is-editing');
        field.focus({ preventScroll: true });
        if (x !== undefined) caretAt(x, y);
        else { const r = document.createRange(); r.selectNodeContents(field); r.collapse(false); const s = getSelection(); s.removeAllRanges(); s.addRange(r); }

        const line = field.dataset.pbKind !== 'words';
        const valueOf = () => (formatted ? marked(field).replace(/\n$/, '') : textOf(field));
        if (formatted) rich = field;
        let timer = null;
        let sent = valueOf();
        const push = () => {
            clearTimeout(timer);
            const value = valueOf();
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
            // Done typing here (finished at once: a window without focus may never say it lost it).
            // (Not on to the page's own Enter, which would start typing here again.)
            if ((e.key === 'Enter' && line) || e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); field.blur(); done(); return; }
            if (!formatted) return;
            if (e.key === 'Enter') { e.preventDefault(); document.execCommand('insertLineBreak'); return; }
            const mod = (e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey;
            if (mod && ['b', 'i', 'k'].includes(e.key.toLowerCase())) {
                e.preventDefault();
                formatWith({ b: 'bold', i: 'italic', k: 'link' }[e.key.toLowerCase()]);
            }
        };
        const onPaste = (e) => {
            e.preventDefault();
            let text = (e.clipboardData || window.clipboardData).getData('text/plain') || '';
            if (line) text = text.replace(/\s+/g, ' ');
            document.execCommand('insertText', false, text);
        };
        const done = () => {
            if (!field.hasAttribute('contenteditable')) return; // finished already
            // Into the link box above it: still typing here.
            if (formatted && linking) return;
            push();
            if (rich === field) { rich = null; fmt.hidden = true; }
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

    // The formatting bar: above the words chosen in a words field --------------------------

    const fmt = $('.fmt'), linkForm = $('.fmt__link'), linkBox = linkForm.querySelector('input');
    let linking = false, savedRange = null;
    const linkAt = () => {
        const s = getSelection();
        const n = s.rangeCount ? s.getRangeAt(0).commonAncestorContainer : null;
        const el = n && (n.nodeType === 1 ? n : n.parentElement);
        const a = el && el.closest('a');
        return a && rich && rich.contains(a) ? a : null;
    };
    const placeFmt = () => {
        if (!rich || linking) return;
        const s = getSelection();
        if (!s.rangeCount || !rich.contains(s.anchorNode)) { fmt.hidden = true; return; }
        const r = s.getRangeAt(0);
        if (r.collapsed && !linkAt()) { fmt.hidden = true; return; }
        const box = r.getBoundingClientRect().width ? r.getBoundingClientRect() : rich.getBoundingClientRect();
        fmt.hidden = false;
        fmt.querySelector('[data-fmt="bold"]').setAttribute('aria-pressed', document.queryCommandState('bold') ? 'true' : 'false');
        fmt.querySelector('[data-fmt="italic"]').setAttribute('aria-pressed', document.queryCommandState('italic') ? 'true' : 'false');
        fmt.querySelector('[data-fmt="link"]').setAttribute('aria-pressed', linkAt() ? 'true' : 'false');
        const w = fmt.offsetWidth;
        fmt.style.left = Math.min(Math.max(8, box.left + box.width / 2 - w / 2), innerWidth - w - 8) + 'px';
        fmt.style.top = (box.top > 60 ? box.top - 46 : box.bottom + 8) + 'px';
    };
    document.addEventListener('selectionchange', () => { if (rich) placeFmt(); });
    addEventListener('scroll', () => { if (rich && !fmt.hidden) placeFmt(); }, { passive: true });

    const closeLink = (refocus = true) => {
        linking = false;
        linkForm.hidden = true;
        fmt.classList.remove('is-linking');
        if (refocus && rich) {
            rich.focus({ preventScroll: true });
            if (savedRange) { const s = getSelection(); s.removeAllRanges(); s.addRange(savedRange); }
        }
        savedRange = null;
        placeFmt();
    };
    const formatWith = (what) => {
        if (!rich) return;
        if (what === 'bold' || what === 'italic') { document.execCommand(what); placeFmt(); return; }
        if (what === 'unlink') {
            closeLink();
            const a = linkAt();
            if (a) { const r = document.createRange(); r.selectNodeContents(a); const s = getSelection(); s.removeAllRanges(); s.addRange(r); }
            document.execCommand('unlink');
            return;
        }
        if (what === 'link') {
            const s = getSelection();
            if (!s.rangeCount) return;
            const a = linkAt();
            if (a) { const r = document.createRange(); r.selectNodeContents(a); s.removeAllRanges(); s.addRange(r); }
            if (s.getRangeAt(0).collapsed) { toast('Choose some words first, then make them a link.'); return; }
            savedRange = s.getRangeAt(0).cloneRange();
            linking = true;
            fmt.classList.add('is-linking');
            linkForm.hidden = false;
            fmt.querySelector('.fmt__off').hidden = !a;
            linkBox.value = a ? a.getAttribute('href') : '';
            // Wider now: kept on the screen.
            fmt.style.left = Math.max(8, Math.min(parseFloat(fmt.style.left) || 8, innerWidth - fmt.offsetWidth - 8)) + 'px';
            linkBox.focus();
        }
    };
    fmt.addEventListener('mousedown', (e) => { if (!e.target.closest('input')) e.preventDefault(); }); // keep typing where you were
    fmt.addEventListener('click', (e) => {
        const b = e.target.closest('[data-fmt]');
        if (b) formatWith(b.dataset.fmt);
    });
    linkForm.addEventListener('submit', (e) => {
        e.preventDefault();
        let url = linkBox.value.trim();
        if (url !== '' && !/^(#|\/|\?|[a-z][a-z0-9+.-]*:)/i.test(url)) url = url.includes('@') && !url.includes('/') ? 'mailto:' + url : 'https://' + url;
        closeLink();
        if (url === '') { document.execCommand('unlink'); return; }
        if (/^(javascript|data|vbscript):/i.test(url)) { toast('That isn’t an address a link can go to.', true); return; }
        document.execCommand('createLink', false, url);
        rich && rich.dispatchEvent(new Event('input'));
    });
    linkBox.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.preventDefault(); closeLink(); } });
    linkBox.addEventListener('blur', () => {
        // Clicked away from the link box (not onto its buttons): done with it, and with typing there.
        setTimeout(() => { if (linking && !(ui.activeElement && fmt.contains(ui.activeElement))) { const f = rich; closeLink(false); if (f) f.blur(); f && f.dispatchEvent(new Event('blur')); } }, 0);
    });

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
            // Astride the section's top edge (over its own padding, never its words), following it down while it's long.
            const top = Math.min(Math.max(r.top - 18, 8), Math.max(r.bottom - 44, 8), floor - 50);
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

    // Dragging a section by its grip, up or down the page (mouse, pen or finger).
    const dropLine = document.createElement('div');
    dropLine.className = 'drop-line';
    dropLine.hidden = true;
    ui.appendChild(dropLine);
    let drag = null;
    const dropAt = (y) => {
        const all = [...page.querySelectorAll('[data-pb-section]')];
        let at = all.length;
        for (let i = 0; i < all.length; i++) {
            const r = all[i].getBoundingClientRect();
            if (y < r.top + r.height / 2) { at = i; break; }
        }
        const ref = all[at] ? all[at].getBoundingClientRect() : all[all.length - 1].getBoundingClientRect();
        const p = page.getBoundingClientRect();
        dropLine.style.top = ((all[at] ? ref.top : ref.bottom) - 2) + 'px';
        dropLine.style.left = Math.max(p.left, 0) + 'px';
        dropLine.style.width = Math.min(p.width, innerWidth) + 'px';
        return { at, all };
    };
    const dragScroll = () => {
        if (!drag) return;
        const edge = 90;
        if (drag.y < edge) window.scrollBy(0, -Math.ceil((edge - drag.y) / 6));
        else if (drag.y > innerHeight - edge - 70) window.scrollBy(0, Math.ceil((drag.y - innerHeight + edge + 70) / 6));
        dropAt(drag.y);
        drag.raf = requestAnimationFrame(dragScroll);
    };
    const endDrag = (drop) => {
        if (!drag) return;
        cancelAnimationFrame(drag.raf);
        const { id, el } = drag;
        el.classList.remove('is-dragging');
        document.documentElement.classList.remove('pb-dragging');
        dropLine.hidden = true;
        if (drop) {
            const { at, all } = dropAt(drag.y);
            const from = all.indexOf(el);
            const to = at > from ? at - 1 : at;
            if (from >= 0 && to !== from) act('move', { id, to }, { scroll: true });
        }
        drag = null;
    };
    tools.querySelector('[data-tool="drag"]').addEventListener('pointerdown', (e) => {
        if (!state.section || e.button > 0) return;
        const el = sectionEl(state.section);
        if (!el) return;
        e.preventDefault();
        drag = { id: state.section, el, y: e.clientY, raf: 0 };
        el.classList.add('is-dragging');
        document.documentElement.classList.add('pb-dragging');
        dropLine.hidden = false;
        dropAt(e.clientY);
        drag.raf = requestAnimationFrame(dragScroll);
    });
    addEventListener('pointermove', (e) => { if (drag) { drag.y = e.clientY; e.preventDefault(); } }, { passive: false });
    addEventListener('pointerup', () => endDrag(true));
    addEventListener('pointercancel', () => endDrag(false));
    addEventListener('keydown', (e) => { if (drag && e.key === 'Escape') endDrag(false); });

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
        if (box.matches('[data-alt]')) { act('alt', { path: box.dataset.alt, alt: box.value }, { quiet: true, redraw: true }); return; }
        if (!box.matches('[data-set]')) return;
        const id = box.closest('[data-section]') && box.closest('[data-section]').dataset.section;
        if (id) act('set', { id, field: box.dataset.set, value: box.type === 'checkbox' ? box.checked : box.value });
    });
    inner.addEventListener('submit', (e) => {
        const form = e.target.closest('[data-form]');
        if (!form) return;
        e.preventDefault();
        // "details" saves the page's name and address; "status" makes it a draft, scheduled or published.
        // Busy until its answer comes (Stacy can take a few seconds).
        const send = form.querySelector('[type="submit"]');
        if (send) { send.disabled = true; if (form.dataset.form === 'stacy') send.textContent = 'Writing…'; }
        // A form sent: draw its answer (and any errors) even from a box; Stacy's words go on the page too.
        act(form.dataset.form, Object.fromEntries(new FormData(form)), { quiet: form.dataset.form !== 'stacy', redraw: true });
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
        bar.dataset.live = b.live ? '1' : '0';
        bar.querySelector('[data-bar="undo"]').disabled = !b.undoable;
        bar.querySelector('[data-bar="redo"]').disabled = !b.redoable;
        const pub = bar.querySelector('[data-bar="publish"]');
        pub.disabled = b.status === 'live';
        pub.textContent = { draft: 'Publish', changed: 'Publish changes', scheduled: 'Publish now' }[b.status] || 'Published';
        drawOthers(b.others || []);
    };
    // Someone else building this page right now.
    const drawOthers = (names) => {
        const el = bar.querySelector('.bar__others');
        el.hidden = names.length === 0;
        el.textContent = names.length === 1 ? names[0] + ' is editing this page too' : names.length > 1 ? names.slice(0, -1).join(', ') + ' and ' + names[names.length - 1] + ' are editing this page too' : '';
    };

    // Still here, every 20 seconds: who else is, and whether the page changed (brought up to date if so).
    const typingNow = () => !!((document.activeElement && document.activeElement.isContentEditable) || (ui.activeElement && ui.activeElement.matches('input, textarea, select')));
    const ping = () => {
        if (!editing || !cfg.here) return;
        request('POST', cfg.here, {}).then((data) => {
            drawOthers(data.others || []);
            if (rev !== null && data.rev !== rev && busy === 0 && !typingNow() && !drag) {
                const q = new URLSearchParams({ query: cfg.query || '', section: state.section || '', tab: state.tab });
                request('GET', cfg.edit + '?' + q.toString()).then((fresh) => { apply(fresh); toast('This page changed elsewhere, so it’s brought up to date.'); });
            }
        }).catch(() => {});
    };
    setInterval(ping, 20000);
    addEventListener('pagehide', () => {
        if (!editing || !cfg.here || !navigator.sendBeacon) return;
        const f = new FormData();
        f.append('_token', cfg.token);
        f.append('leaving', '1');
        navigator.sendBeacon(cfg.here, f);
    });

    bar.addEventListener('click', (e) => {
        const b = e.target.closest('[data-bar]');
        if (!b || b.disabled) return;
        const what = b.dataset.bar;
        if (what === 'undo') act('undo');
        else if (what === 'redo') act('redo');
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

    // Copying a link (a proof copy's): to the clipboard, or shown to copy by hand.
    const copyLink = (url, words) => {
        const done = () => toast((words ? words + ' ' : '') + 'The link is copied.');
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(url).then(done, () => toast(url));
        else toast((words ? words + ' ' : '') + url);
    };
    inner.addEventListener('click', (e) => {
        const b = e.target.closest('[data-copy]');
        if (b) { e.preventDefault(); copyLink(b.dataset.copy); return; }
        // The spot that matters in a picture: where it's clicked, in percent.
        const f = e.target.closest('[data-focus]');
        if (f) {
            const r = f.querySelector('img').getBoundingClientRect();
            const x = Math.round(Math.min(100, Math.max(0, (e.clientX - r.left) / r.width * 100)));
            const y = Math.round(Math.min(100, Math.max(0, (e.clientY - r.top) / r.height * 100)));
            f.style.setProperty('--fx', x + '%');
            f.style.setProperty('--fy', y + '%');
            act('focus', { path: f.dataset.focus, x, y });
        }
    });

    let toastTimer = null;
    const toast = (words, bad = false, link = null) => {
        toastEl.textContent = words;
        if (link) {
            // Somewhere to go and look (a page a section was sent to).
            const a = document.createElement('a');
            a.className = 'toast__go';
            a.href = link.href;
            a.textContent = link.words;
            toastEl.append(' ', a);
        }
        toastEl.classList.toggle('is-bad', bad);
        toastEl.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { toastEl.hidden = true; }, bad || link ? 7000 : 3500);
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
            // A draft nobody can see yet: back to its chapter in the book; otherwise, the page as readers see it.
            window.location.href = cfg.draft === '1' && bar.dataset.live !== '1' ? cfg.pages : u.toString();
        });
    };

    start.addEventListener('click', begin);
    document.addEventListener('keydown', (e) => {
        if (!editing) return;
        if (e.key === 'Escape' && !phone.hidden) { showPhone(false); return; }
        const typing = (document.activeElement && (document.activeElement.isContentEditable || document.activeElement.matches('input, textarea, select'))) || (ui.activeElement && ui.activeElement.matches('input, textarea, select'));
        const k = e.key.toLowerCase();
        if ((e.metaKey || e.ctrlKey) && !typing && (k === 'y' || (k === 'z' && e.shiftKey))) { e.preventDefault(); act('redo'); return; }
        if ((e.metaKey || e.ctrlKey) && !e.shiftKey && k === 'z' && !typing) { e.preventDefault(); act('undo'); }
    });
    addEventListener('beforeunload', (e) => { if (busy > 0) { e.preventDefault(); e.returnValue = ''; } });

    if (cfg.start === '1') begin();
})();
