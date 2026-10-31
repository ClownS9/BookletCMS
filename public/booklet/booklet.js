/*
 * Small helpers for the admin book (Alpine components).
 *
 *   writingDesk  the entry editor: Markdown toolbar, offline notice, a safety
 *                copy of unsaved words in this browser, and who else is here.
 */
(() => {
    function writingDesk({ key, savedAt, others = [], words = {} }) {
        // The note by the toolbar, in the book's language (given by the page; English otherwise).
        const say = Object.assign({ offline: 'Offline · kept on this page', waiting: 'Editing…', saving: 'Saving…', justNow: 'Saved just now', moment: 'Saved a moment ago', manual: 'Unsaved changes · press Save' }, words);
        return {
            online: navigator.onLine,
            others,               // [{ name, typing }]
            newer: null,          // { name, ago } when someone else saved since
            backup: null,
            auto: '',             // '' · 'waiting' · 'saving' · 'saved' · 'manual'
            autoAt: null,
            tick: 0,
            _timer: null,
            _beat: null,
            _auto: null,
            _pinged: 0,

            // Stacy at the desk: { open, busy, how, start, end, paragraph, original, result, error, undo }
            help: { open: false, busy: false, how: null, start: 0, end: 0, paragraph: false, original: '', result: '', error: '', undo: null },
            _helpAsked: 0,

            init() {
                this._on = () => { this.online = true; };
                this._off = () => { this.online = false; };
                window.addEventListener('online', this._on);
                window.addEventListener('offline', this._off);

                // Words kept in this browser that never reached the server?
                const kept = this._read();
                if (kept && kept.at > (Date.parse(String(savedAt || '').replace(' ', 'T')) || 0) && this._differs(kept)) {
                    this.backup = kept;
                }

                this.$el.addEventListener('input', (e) => {
                    if (!e.target.matches('#field-title, #field-content, .field input, .field textarea')) return;
                    if (e.target.matches('#field-title, #field-content')) this._keep();
                    this._scheduleAutosave();
                    this._typing();
                });

                this._saved = () => {
                    this._forget();
                    this.backup = null;
                    this.newer = null;
                    if (this.auto !== 'saving') { this.auto = 'saved'; this.autoAt = Date.now(); }
                };
                window.addEventListener('entry-saved', this._saved);

                // Every 12 seconds: who else is here, and has anyone saved a newer version?
                this._beat = setInterval(() => this._heartbeat(false), 12000);
                this._clock = setInterval(() => { this.tick++; }, 20000); // keeps "just now" honest
            },

            // Autosave ----------------------------------------------------------

            /** 2½ seconds after typing stops, save (drafts only; the server decides). */
            _scheduleAutosave() {
                clearTimeout(this._auto);
                if (!this.online) return;
                if (this.auto !== 'manual') this.auto = 'waiting';
                this._auto = setTimeout(() => this._autosave(), 2500);
            },

            async _autosave() {
                // The body only reaches the server on blur, so send what's typed now.
                const title = document.getElementById('field-title');
                const body = document.getElementById('field-content');
                if (title) this.$wire.$set('values.title', title.value, false);
                if (body) this.$wire.$set('values.content', body.value, false);

                this.auto = 'saving';
                try {
                    const result = await this.$wire.autosave();
                    if (result === 'saved') { this.auto = 'saved'; this.autoAt = Date.now(); this._forget(); this.backup = null; }
                    else if (result === 'manual') this.auto = 'manual';
                    else this.auto = '';
                } catch (e) {
                    this.auto = ''; // the safety copy in this browser still has the words
                }
            },

            get autoLabel() {
                this.tick; // re-read as time passes
                if (!this.online) return say.offline;
                return {
                    waiting: say.waiting,
                    saving: say.saving,
                    saved: this.autoAt && Date.now() - this.autoAt < 45000 ? say.justNow : say.moment,
                    manual: say.manual,
                }[this.auto] || '';
            },

            // Who else is here --------------------------------------------------

            _typing() {
                if (Date.now() - this._pinged < 8000) return;
                this._heartbeat(true);
            },

            _heartbeat(typing) {
                if (!this.$wire.entryId) return;
                this._pinged = Date.now();
                this.$wire.heartbeat(typing).then((r) => {
                    this.others = (r && r.others) || [];
                    this.newer = (r && r.newer) || null;
                }).catch(() => {});
            },

            get othersText() {
                if (!this.others.length) return '';
                const names = this.others.map((o) => o.name);
                const list = names.length > 1 ? names.slice(0, -1).join(', ') + ' and ' + names[names.length - 1] : names[0];
                const typing = this.others.filter((o) => o.typing).map((o) => o.name);
                if (typing.length) return list + (typing.length === 1 && names.length === 1 ? ' is typing here right now.' : ' are here; ' + typing.join(' and ') + ' typing right now.');
                return list + (names.length > 1 ? ' also have' : ' also has') + ' this open.';
            },

            destroy() {
                window.removeEventListener('online', this._on);
                window.removeEventListener('offline', this._off);
                window.removeEventListener('entry-saved', this._saved);
                clearInterval(this._beat);
                clearInterval(this._clock);
                clearTimeout(this._auto);
                clearTimeout(this._undoTimer);
                this._helpAsked++;
            },

            // Toolbar -----------------------------------------------------------

            format(kind) {
                const ta = document.getElementById('field-content');
                if (!ta) return;

                const start = ta.selectionStart, end = ta.selectionEnd;
                const value = ta.value;
                const picked = value.slice(start, end);
                const lineStart = value.lastIndexOf('\n', start - 1) + 1;
                let before = value.slice(0, start), after = value.slice(end), middle = picked, caret = null;

                const wrap = (mark, placeholder) => {
                    middle = mark + (picked || placeholder) + mark;
                    caret = [start + mark.length, start + mark.length + (picked || placeholder).length];
                };
                const prefixLines = (prefix) => {
                    before = value.slice(0, lineStart);
                    const block = value.slice(lineStart, end) || '';
                    middle = block.split('\n').map((line, i) => (typeof prefix === 'function' ? prefix(i) : prefix) + line).join('\n');
                    caret = [lineStart + middle.length, lineStart + middle.length];
                };

                switch (kind) {
                    case 'heading': prefixLines('## '); break;
                    case 'bold': wrap('**', 'bold words'); break;
                    case 'italic': wrap('_', 'slanted words'); break;
                    case 'link': {
                        const url = window.prompt('Link to (https://…)', 'https://');
                        if (!url) return;
                        middle = '[' + (picked || 'link text') + '](' + url + ')';
                        caret = [start + 1, start + 1 + (picked || 'link text').length];
                        break;
                    }
                    case 'list': prefixLines('- '); break;
                    case 'quote': prefixLines('> '); break;
                    default: return;
                }

                ta.value = before + middle + after;
                ta.focus();
                if (caret) ta.setSelectionRange(caret[0], caret[1]);
                this.$wire.$set('values.content', ta.value, false);
                this._keep();
            },

            shortcut(e) {
                if (!(e.metaKey || e.ctrlKey)) return;
                const k = e.key.toLowerCase();
                if (k === 'b') { e.preventDefault(); this.format('bold'); }
                if (k === 'i') { e.preventDefault(); this.format('italic'); }
                if (k === 'k') { e.preventDefault(); e.stopPropagation(); this.format('link'); }
                if (k === 's') { e.preventDefault(); this.$wire.save(); }
                if (k === 'j' && document.querySelector('.format-bar__stacy')) { e.preventDefault(); this.pen(); }
            },

            // Stacy at the desk -------------------------------------------------

            /** Open the little card: remember which words were picked (or the paragraph the caret is in). */
            pen() {
                const ta = document.getElementById('field-content');
                if (!ta) return;
                if (this.help.open && !this.help.busy) return this.closePen();

                let start = ta.selectionStart ?? ta.value.length;
                let end = ta.selectionEnd ?? start;
                let paragraph = false;
                if (start === end) {
                    // Nothing picked: the paragraph around the caret (blank lines are the edges).
                    const v = ta.value;
                    const from = v.lastIndexOf('\n\n', start - 1);
                    const to = v.indexOf('\n\n', start);
                    const a = from === -1 ? 0 : from + 2;
                    const b = to === -1 ? v.length : to;
                    if (v.slice(a, b).trim()) { paragraph = { start: a, end: b }; }
                }
                Object.assign(this.help, { open: true, busy: false, how: null, start, end, paragraph, original: '', result: '', error: '' });
            },

            closePen() {
                this._helpAsked++; // an answer still on its way is ignored
                Object.assign(this.help, { open: false, busy: false, result: '', error: '' });
                document.getElementById('field-content')?.focus();
            },

            get penLabel() {
                const h = this.help;
                if (h.busy) return 'Stacy';
                if (h.result || h.error) return { continue: 'What comes next', fix: 'Fixed', tighten: 'Tighter' }[h.how] || 'Another way to say it';
                const ta = document.getElementById('field-content');
                if (h.start !== h.end && ta) {
                    const n = (ta.value.slice(h.start, h.end).match(/\S+/g) || []).length;
                    return n + (n === 1 ? ' word picked' : ' words picked');
                }
                return h.paragraph ? 'The paragraph you’re in' : 'Pick some words, or keep writing';
            },

            async stacyDo(how) {
                const ta = document.getElementById('field-content');
                if (!ta || this.help.busy) return;

                let { start, end } = this.help;
                if (how !== 'continue' && start === end && this.help.paragraph) ({ start, end } = this.help.paragraph);
                const value = ta.value;
                const text = how === 'continue' ? '' : value.slice(start, end);
                const before = how === 'continue' ? value.slice(0, end) : value.slice(Math.max(0, start - 2500), start);

                Object.assign(this.help, { how, busy: true, error: '', result: '', original: text.trim() });
                this.help.target = { start, end };
                const asked = ++this._helpAsked;

                try {
                    const r = await this.$wire.stacyWrite(how, text, before);
                    if (asked !== this._helpAsked) return;
                    if (r && r.text) this.help.result = r.text;
                    else this.help.error = (r && r.error) || 'I couldn’t come up with anything. Try again?';
                } catch (e) {
                    if (asked === this._helpAsked) this.help.error = 'That didn’t reach me. Check the connection and try again.';
                } finally {
                    if (asked === this._helpAsked) this.help.busy = false;
                }
            },

            /** Put Stacy's words in (replacing the picked words, or after the caret for "keep writing"). */
            accept() {
                const ta = document.getElementById('field-content');
                const t = this.help.target;
                if (!ta || !t || !this.help.result) return;

                const old = ta.value;
                let from = t.start, to = t.end, insert = this.help.result;
                if (this.help.how === 'continue') {
                    from = to = t.end;
                    const head = old.slice(0, from);
                    const lead = head.trim() === '' ? '' : head.endsWith('\n\n') ? '' : head.endsWith('\n') ? '\n' : '\n\n';
                    insert = lead + insert;
                } else if (/^\s/.test(old.slice(t.start, t.end)) || /\s$/.test(old.slice(t.start, t.end))) {
                    // Keep the spaces or line breaks that were round the picked words.
                    const picked = old.slice(t.start, t.end);
                    insert = picked.match(/^\s*/)[0] + insert + picked.match(/\s*$/)[0];
                }

                ta.value = old.slice(0, from) + insert + old.slice(to);
                ta.focus();
                ta.setSelectionRange(from, from + insert.length);
                this.$wire.$set('values.content', ta.value, false);
                this._keep();
                this._scheduleAutosave();

                clearTimeout(this._undoTimer);
                this.help.undo = { value: old, caret: t.end };
                this._undoTimer = setTimeout(() => { this.help.undo = null; }, 12000);
                Object.assign(this.help, { open: false, result: '', error: '' });
            },

            undoPen() {
                const ta = document.getElementById('field-content');
                const u = this.help.undo;
                if (!ta || !u) return;
                ta.value = u.value;
                ta.focus();
                ta.setSelectionRange(u.caret, u.caret);
                this.$wire.$set('values.content', ta.value, false);
                this._keep();
                this._scheduleAutosave();
                this.help.undo = null;
            },

            // Safety copy -------------------------------------------------------

            restore() {
                if (!this.backup) return;
                this.$wire.$set('values.title', this.backup.title, false);
                this.$wire.$set('values.content', this.backup.content, true);
                this.backup = null;
            },

            dismiss() {
                this._forget();
                this.backup = null;
            },

            _keep() {
                clearTimeout(this._timer);
                this._timer = setTimeout(() => {
                    try {
                        localStorage.setItem(key, JSON.stringify({
                            at: Date.now(),
                            title: document.getElementById('field-title')?.value ?? '',
                            content: document.getElementById('field-content')?.value ?? '',
                        }));
                    } catch (e) {}
                }, 400);
            },

            _read() {
                try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { return null; }
            },

            _forget() {
                try { localStorage.removeItem(key); } catch (e) {}
            },

            _differs(kept) {
                const title = document.getElementById('field-title')?.value ?? '';
                const content = document.getElementById('field-content')?.value ?? '';
                return (kept.title || '') !== title || (kept.content || '') !== content;
            },
        };
    }

    // Registered when Alpine starts, or straight away if it already has
    // (this file can arrive after the page, e.g. opening the book right after signing in).
    const register = () => window.Alpine.data('writingDesk', writingDesk);
    if (window.Alpine) register();
    document.addEventListener('alpine:init', register);
})();

/*
 * Turning the page between chapters.
 *
 * When Livewire starts navigating, the pages on screen are copied onto a
 * stage outside <body> (so they survive the page swap) and hold still over
 * the real ones. Once the new chapter is in, one copy turns over the spine:
 * forward turns the right page leftwards, back turns the left page
 * rightwards. Its other side shows the page it lands on.
 *
 * Only transform and opacity are animated, with the Web Animations API, so
 * the browser can run the turn on the graphics card even while the new page
 * is still settling in. Nothing is resized or laid out during the turn.
 */
(() => {
    // No turning for people who'd rather not (Make it yours) or in the Dashboard layout.
    const calm = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches
        || document.body.classList.contains('no-turn') || document.body.classList.contains('layout-dashboard');
    const wide = () => window.matchMedia('(min-width: 901px)').matches;
    const TURN = 1050; // the whole turn, from the corner lifting to the page lying flat

    let pending = null;

    const pagesOf = (book) => {
        const pages = Array.from(book.querySelectorAll('.book__pages .page'));
        return {
            left: pages.find((p) => p.classList.contains('page--left')),
            right: pages.find((p) => p.classList.contains('page--right')) || pages.find((p) => p.classList.contains('page--single')),
        };
    };

    // A lifeless copy of a page: looks the same, does nothing.
    const copy = (page) => {
        const clone = page.cloneNode(true);
        clone.querySelectorAll('script').forEach((s) => s.remove());
        // A drawing (Stacy's hologram) copies blank: keep it as a still picture of how it looks now,
        // so the turning page shows her where she was (and copies of the copy keep her too).
        const drawn = page.querySelectorAll('canvas');
        clone.querySelectorAll('canvas').forEach((c, i) => {
            const still = document.createElement('img');
            still.alt = '';
            still.style.cssText = c.style.cssText;
            still.className = c.className;
            try { still.src = drawn[i].toDataURL(); } catch (e) { /* tainted: leave it out */ }
            c.replaceWith(still);
        });
        [clone, ...clone.querySelectorAll('*')].forEach((el) => {
            for (const attr of Array.from(el.attributes)) {
                if (/^(x-|wire:|@|:|id$|for$|name$|autofocus)/.test(attr.name)) el.removeAttribute(attr.name);
            }
        });
        clone.setAttribute('inert', '');
        clone.setAttribute('aria-hidden', 'true');
        clone.style.setProperty('flex', '1 1 100%', 'important');
        clone.dataset.scroll = String(page.scrollTop);

        // Inside a "book__pages" box, so it is laid out exactly like the real page.
        const pages = document.createElement('div');
        pages.className = 'book__pages leaf__pages';
        // Remember the page's own rounded corners, so the copy keeps them as it turns.
        pages.dataset.radius = getComputedStyle(page).borderRadius;
        pages.appendChild(clone);
        return pages;
    };

    // The new page as it turns over: just the paper, its writing arrives once it lands.
    const blankCopy = (page) => {
        const pages = copy(page);
        pages.classList.add('leaf__pages--blank');
        return pages;
    };

    // The bookmark corner sits on the book, over the right page's top corner. Page
    // copies take a still copy of it, so it moves with the paper while it turns.
    const earCopy = (book, pageRect) => {
        const ear = book?.querySelector(':scope > .dog-ear');
        if (!ear || !pageRect) return null;
        const r = ear.getBoundingClientRect();
        if (!r.width) return null;
        const clone = ear.cloneNode(true);
        [clone, ...clone.querySelectorAll('*')].forEach((el) => {
            for (const attr of Array.from(el.attributes)) {
                if (/^(x-|wire:|@|:|id$|title$|aria-pressed)/.test(attr.name)) el.removeAttribute(attr.name);
            }
        });
        clone.classList.add('dog-ear--copy');
        clone.setAttribute('inert', '');
        clone.setAttribute('aria-hidden', 'true');
        clone.tabIndex = -1;
        clone.style.top = (r.top - pageRect.top) + 'px';
        clone.style.left = (r.left - pageRect.left) + 'px';
        clone.style.right = 'auto';
        return clone;
    };

    // Put the corner on a copy (rounded exactly like the real one).
    const withEar = (el, ear) => { if (ear) el.appendChild(ear); };

    const restoreScroll = (root) => root.querySelectorAll('[data-scroll]').forEach((el) => { el.scrollTop = Number(el.dataset.scroll) || 0; });

    const box = (el, rect, anchor = 'left') => {
        el.style.top = rect.top + 'px';
        el.style.height = rect.height + 'px';
        el.style.width = rect.width + 'px';
        if (anchor === 'left') el.style.left = rect.left + 'px';
        else el.style.right = (document.documentElement.clientWidth - rect.right) + 'px';
        return el;
    };

    // A flat sheet showing a copy, used to hold the old pages still.
    const sheet = (content, rect) => {
        const el = document.createElement('div');
        el.className = 'leaf-sheet';
        el.appendChild(content);
        if (content.dataset.radius) el.style.borderRadius = content.dataset.radius;
        return box(el, rect);
    };

    /* The turn is paper, not board: the reader takes the outer bottom corner and
       peels it over. A fold line runs across the sheet; everything past it is
       folded back on itself (showing the back of the paper), everything before it
       still lies flat. The fold starts as a small dog-ear, sweeps across the page,
       and ends on the spine, where the whole sheet has gone over.

       Geometry is worked out in "paper" units: x runs from the spine (0) out to the
       page's outer edge (W), y from the top (0) to the bottom (H). */

    // Keep the part of a polygon where keep(point) >= 0.
    const clipPoly = (poly, keep) => {
        const out = [];
        for (let i = 0; i < poly.length; i++) {
            const a = poly[i];
            const b = poly[(i + 1) % poly.length];
            const fa = keep(a);
            const fb = keep(b);
            if (fa >= 0) out.push(a);
            if ((fa >= 0) !== (fb >= 0)) {
                const t = fa / (fa - fb);
                out.push([a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t]);
            }
        }
        return out;
    };

    const polygon = (pts) => pts.length < 3
        ? 'polygon(0 0, 0 0, 0 0)'
        : 'polygon(' + pts.map(([x, y]) => `${x.toFixed(2)}px ${y.toFixed(2)}px`).join(', ') + ')';

    // A gradient across a box, measured in pixels from a line through `at` and
    // running in direction `dir` (a unit vector, in the box's own coordinates).
    const gradient = (w, h, at, dir, stops) => {
        const deg = Math.atan2(dir[0], -dir[1]);
        const len = Math.abs(w * Math.sin(deg)) + Math.abs(h * Math.cos(deg));
        const zero = (at[0] - w / 2) * dir[0] + (at[1] - h / 2) * dir[1] + len / 2;
        return `linear-gradient(${(deg * 180 / Math.PI).toFixed(2)}deg, ` +
            stops.map(([d, c]) => `${c} ${(zero + d).toFixed(1)}px`).join(', ') + ')';
    };

    const unit = (x, y) => { const l = Math.hypot(x, y) || 1; return [x / l, y / l]; };

    // Slow to lift, quick through the middle, soft as it lands.
    const ease = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);

    const clearStage = () => {
        document.querySelectorAll('.leaf-stage').forEach((s) => s.remove());
        document.documentElement.classList.remove('is-flipping');
    };

    document.addEventListener('livewire:navigating', () => {
        pending = null;
        clearStage();

        if (calm() || !wide()) return;

        const book = document.querySelector('.desk .book');
        if (!book) return;

        const { left, right } = pagesOf(book);
        if (!right) return;

        pending = {
            from: Number(book.dataset.position || 0),
            left: left ? { rect: left.getBoundingClientRect(), copy: copy(left) } : null,
            right: { rect: right.getBoundingClientRect(), copy: copy(right) },
        };
        pending.ear = earCopy(book, pending.right.rect);

        // Hold the old spread still over itself, so the swap underneath can't be seen.
        const stage = document.createElement('div');
        stage.className = 'leaf-stage';
        stage.dataset.waiting = '1';
        stage.classList.toggle('night', document.body.classList.contains('night'));
        if (pending.left) stage.appendChild(sheet(pending.left.copy.cloneNode(true), pending.left.rect));
        const still = sheet(pending.right.copy.cloneNode(true), pending.right.rect);
        withEar(still, pending.ear?.cloneNode(true));
        stage.appendChild(still);
        document.documentElement.appendChild(stage);
        restoreScroll(stage);
    });

    document.addEventListener('livewire:navigated', () => {
        const stage = document.querySelector('.leaf-stage[data-waiting]');
        const was = pending;
        pending = null;

        const book = document.querySelector('.desk .book');

        // No turn (phones, reduced motion, a page outside the book): the writing just inks in.
        if (!was || !stage) { stage?.remove(); bookletInk(book); return; }

        const { left, right } = book ? pagesOf(book) : {};

        if (!book || !right || !wide() || calm()) { stage.remove(); bookletInk(book); return; }

        const html = document.documentElement;
        const forward = Number(book.dataset.position || 0) >= was.from || !was.left;
        // These pages arrive by the turn, not by their own entrance animation.
        book.querySelectorAll('.book__pages .page').forEach((page) => page.classList.add('no-enter'));
        html.classList.add('is-flipping');

        // Build the turn off-screen in one go, then swap it in.
        const next = document.createElement('div');
        next.className = stage.className;

        // The page being turned (old), what's printed on its back (the new page it
        // lands as), and the spine it is bound to.
        const from = forward ? was.right.rect : was.left.rect;
        const landingPage = forward ? left : right;
        const landing = landingPage ? landingPage.getBoundingClientRect() : from;
        const dir = forward ? 1 : -1; // which way "outwards from the spine" is on screen
        const spine = forward ? from.left : from.right;
        const W = from.width;
        const H = from.height;
        const Wl = landing.width;
        const hidden = landingPage; // the new page the sheet lands on, hidden until it has

        // The other old page stays put; the sheet lands on top of it.
        if (forward) {
            if (was.left) next.appendChild(sheet(was.left.copy, was.left.rect));
        } else {
            const still = sheet(was.right.copy, was.right.rect);
            withEar(still, was.ear); // the old right page keeps its corner until covered
            next.appendChild(still);
        }

        // The part of the old page still lying flat.
        const front = sheet((forward ? was.right : was.left).copy, from);
        front.classList.add('peel-front');
        const frontShade = document.createElement('div');
        frontShade.className = 'peel-shade';
        // Going forward the old right page is the one turning: its corner goes with it.
        if (forward) withEar(front, was.ear);
        front.appendChild(frontShade);
        next.appendChild(front);

        // The shadow the lifted paper casts on the page it uncovers.
        const under = document.createElement('div');
        under.className = 'peel-under';
        box(under, from);
        under.style.borderRadius = front.style.borderRadius;
        next.appendChild(under);

        // The crease down the middle, so the spine reads as one unbroken fold.
        const crease = document.createElement('div');
        crease.className = 'leaf-crease';
        crease.style.top = from.top + 'px';
        crease.style.height = H + 'px';
        crease.style.left = spine + 'px';
        next.appendChild(crease);

        // The folded-over part: the back of the sheet, which is the new page.
        const flap = document.createElement('div');
        flap.className = 'peel-flap';
        const paper = document.createElement('div');
        paper.className = 'peel-flap__paper' + (landingPage ? '' : ' peel-flap__paper--blank');
        paper.style.width = Wl + 'px';
        paper.style.height = H + 'px';
        if (landingPage) {
            const back = blankCopy(landingPage);
            paper.style.borderRadius = back.dataset.radius || '';
            paper.appendChild(back);
        } else {
            paper.style.borderRadius = forward ? '8px 0 0 8px' : '0 8px 8px 0';
        }
        // Going back the sheet lands as the new right page, so it carries the new corner
        // (folded or not) and sets it down in place. Going forward, the new corner is
        // on the page underneath and comes into view as the sheet lifts off it.
        if (!forward) {
            const ear = earCopy(book, landing);
            withEar(paper, ear);
        }
        const shine = document.createElement('div');
        shine.className = 'peel-shine';
        paper.appendChild(shine);
        flap.appendChild(paper);
        next.appendChild(flap);

        hidden?.classList.add('flip-wait');
        stage.replaceWith(next);
        restoreScroll(next);

        // Paper units <-> each element's own pixels.
        const frontLocal = ([x, y]) => [forward ? x : W - x, y];
        // The back of paper point x sits at (W - x) on the landing page, scaled to its width.
        const backLocal = ([x, y]) => [forward ? Wl - x * Wl / W : x * Wl / W, y];
        const backPaper = ([u, v]) => [forward ? (Wl - u) * W / Wl : u * W / Wl, v];
        const toScreen = ([x, y]) => [spine + dir * x, from.top + y];

        const sheetPoly = [[0, 0], [W, 0], [W, H], [0, H]];
        const lift = Math.min(0.4 * W, 0.2 * H); // how far the corner rises on its way over

        const glow = next.classList.contains('night') ? 0.14 : 1; // light on the fold, softer at night
        const dark = next.classList.contains('night') ? 1.8 : 1;

        let done = false;
        const finish = () => {
            if (done || !next.isConnected) return;
            done = true;
            // Start the writing first (it begins hidden), then reveal the pages, in the same frame.
            bookletInk(book);
            book.querySelectorAll('.flip-wait').forEach((el) => el.classList.remove('flip-wait'));
            html.classList.remove('is-flipping');
            next.remove();
        };

        const draw = (t) => {
            // Where the reader's fingers have taken the corner: up and over, in an arc.
            const C = [W, H];
            const P = [W * (1 - 2 * t), H - lift * Math.sin(Math.PI * t)];
            const span = Math.hypot(C[0] - P[0], C[1] - P[1]);

            if (span < 0.5) {
                front.style.clipPath = '';
                flap.style.visibility = under.style.visibility = 'hidden';
                frontShade.style.background = '';
                return;
            }
            flap.style.visibility = under.style.visibility = '';

            // The fold is the line halfway between where the corner was and where it is.
            const M = [(C[0] + P[0]) / 2, (C[1] + P[1]) / 2];
            const n = [(C[0] - P[0]) / span, (C[1] - P[1]) / span]; // points into the folded part
            const side = (q) => (q[0] - M[0]) * n[0] + (q[1] - M[1]) * n[1];
            const flat = clipPoly(sheetPoly, (q) => -side(q));
            const folded = clipPoly(sheetPoly, side);

            // How wide the folded part is: the curl's light and shadow scale with it.
            const depth = Math.max(...folded.map((q) => side(q)), 1);
            const soft = Math.min(depth, 80);
            const fade = t > 0.85 ? (1 - t) / 0.15 : 1; // light and shadow settle as it lands

            // 1. What still lies flat, darkening a touch where it starts to rise.
            front.style.clipPath = polygon(flat.map(frontLocal));
            const fl = frontLocal(M);
            const fn = unit(...(() => { const a = frontLocal([M[0] - n[0], M[1] - n[1]]); return [a[0] - fl[0], a[1] - fl[1]]; })());
            frontShade.style.background = gradient(W, H, fl, fn, [
                [0, `rgba(30, 28, 24, ${(0.16 * dark * fade).toFixed(3)})`],
                [soft * 0.6, 'rgba(30, 28, 24, 0)'],
            ]);

            // 2. The uncovered page, shaded where the sheet is still close above it.
            under.style.clipPath = polygon(folded.map(frontLocal));
            under.style.background = gradient(W, H, fl, [-fn[0], -fn[1]], [
                [0, `rgba(30, 28, 24, ${(0.32 * dark * fade).toFixed(3)})`],
                [soft * 0.5, `rgba(30, 28, 24, ${(0.12 * fade).toFixed(3)})`],
                [soft * 1.4, 'rgba(30, 28, 24, 0)'],
            ]);

            // 3. The folded part: the back of the sheet, mirrored over the fold line.
            //    (When the two pages differ in width, it eases to the landing width.)
            const grow = 1 + (Wl / W - 1) * t;
            const place = (q) => {
                const pq = backPaper(q);
                const d = side(pq);
                const m = [pq[0] - 2 * d * n[0], pq[1] - 2 * d * n[1]]; // mirrored over the fold
                const e = (grow - 1) * -side(m); // stretched away from the fold, if needed
                return toScreen([m[0] - e * n[0], m[1] - e * n[1]]);
            };
            const o = place([0, 0]);
            const ux = place([1, 0]);
            const vy = place([0, 1]);
            flap.style.transform = `matrix(${ux[0] - o[0]}, ${ux[1] - o[1]}, ${vy[0] - o[0]}, ${vy[1] - o[1]}, ${o[0]}, ${o[1]})`;
            paper.style.clipPath = polygon(folded.map(backLocal));

            // Light catches the rounded edge of the fold; the paper darkens away from it.
            const bl = backLocal(M);
            const inside = backLocal([M[0] + n[0] * 10, M[1] + n[1] * 10]);
            const along = (() => { const a = backLocal([M[0] - n[1], M[1] + n[0]]); return unit(a[0] - bl[0], a[1] - bl[1]); })();
            let bn = [-along[1], along[0]];
            if ((inside[0] - bl[0]) * bn[0] + (inside[1] - bl[1]) * bn[1] < 0) bn = [-bn[0], -bn[1]];
            shine.style.background = gradient(Wl, H, bl, bn, [
                [0, `rgba(30, 28, 24, ${(0.10 * fade).toFixed(3)})`],
                [Math.min(soft * 0.18, 10), `rgba(255, 255, 255, ${(0.55 * glow * fade).toFixed(3)})`],
                [soft * 0.55, `rgba(255, 255, 255, ${(0.12 * glow * fade).toFixed(3)})`],
                [soft * 1.2, `rgba(30, 28, 24, ${(0.06 * fade).toFixed(3)})`],
                [depth, `rgba(30, 28, 24, ${(0.14 * fade).toFixed(3)})`],
            ]);
        };

        draw(0);

        // Two frames' grace so the new page has painted before the turn starts.
        requestAnimationFrame(() => requestAnimationFrame(() => {
            window.bookletPageSound?.(TURN / 1000);
            let started = null;
            const step = (now) => {
                if (done || !next.isConnected) return;
                started ??= now;
                const p = Math.min(1, (now - started) / TURN);
                draw(ease(p));
                if (p < 1) requestAnimationFrame(step);
                else finish();
            };
            requestAnimationFrame(step);
        }));
        setTimeout(finish, TURN + 800); // in case frames stop coming (a hidden tab)
    });
})();

/* Switches answer the tap straight away; the server's reply then confirms it.
   (Without this they sat unchanged until the round trip came back, so they felt stuck.) */
(() => {
    document.addEventListener('click', (event) => {
        const sw = event.target.closest('button.switch[role="switch"]');
        if (!sw || sw.disabled || sw.closest('fieldset:disabled')) return;
        sw.setAttribute('aria-checked', sw.getAttribute('aria-checked') === 'true' ? 'false' : 'true');
    }, true);
})();


/* The writing arrives: each time a page appears (first visit, reload, or after a
   page turn lands) its content inks in from top to bottom, left page first. */
function bookletInk(book) {
    if (!book || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    const pages = Array.from(book.querySelectorAll('.book__pages .page'));
    let start = 0;

    pages.forEach((page) => {
        // The lines of the page: its own children, or, for a page that is one big
        // form or wrapper, the children inside that.
        let lines = Array.from(page.children).filter((el) => !el.matches('.ribbon, script, template, [hidden]'));
        while (lines.length === 1 && lines[0].children.length > 1 && !lines[0].matches('ul, ol, .picks, .rows')) {
            lines = Array.from(lines[0].children);
        }

        lines.forEach((el, i) => {
            el.animate([
                { opacity: 0, transform: 'translateY(10px)' },
                { opacity: 1, transform: 'translateY(0)' },
            ], { duration: 460, delay: start + Math.min(i, 10) * 55, easing: 'cubic-bezier(.2, .8, .3, 1)', fill: 'backwards' });
        });

        // The bookmark ribbon unrolls last. Its top stays fixed to the page edge (it
        // is bound into the spine) and only its length grows, so no gap ever opens above it.
        page.querySelectorAll(':scope > .ribbon').forEach((r) => r.animate([
            { clipPath: 'polygon(0 0, 100% 0, 100% 0%, 50% 0%, 0 0%)' },
            { clipPath: 'polygon(0 0, 100% 0, 100% 100%, 50% 84%, 0 100%)' },
        ], { duration: 560, delay: start + 160, easing: 'cubic-bezier(.25, .8, .3, 1)', fill: 'backwards' }));

        start += 120; // the right page follows just behind the left
    });
}

(() => {
    const enter = () => {
        const book = document.querySelector('.desk .book');
        if (!book) return;
        if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            book.animate([
                { opacity: 0, transform: 'translateY(16px) scale(.985)' },
                { opacity: 1, transform: 'none' },
            ], { duration: 450, easing: 'cubic-bezier(.2, .8, .25, 1)', fill: 'backwards' });
        }
        bookletInk(book);
    };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', enter, { once: true });
    else enter();
})();

/* The search window: opens over the book from anywhere (⌘K, Ctrl+K, "/" or the
   search flag), moves with the arrow keys, and remembers what you opened. */
window.bookletSearch = (scopes) => ({
    open: false,
    active: 0,
    recent: [],
    scopes,

    init() {
        try { this.recent = JSON.parse(localStorage.getItem('booklet.search.recent') || '[]').slice(0, 5); } catch (e) { this.recent = []; }
        // After every server reply, keep the highlighted row valid.
        this.$watch('open', (on) => on && this.$nextTick(() => this.paint()));
        window.Livewire?.hook?.('commit', ({ component, succeed }) => succeed(() => {
            if (this.open && component.name === 'admin.search-palette') this.$nextTick(() => this.paint());
        }));
        this.$refs.results?.addEventListener('mousemove', (e) => {
            const hit = e.target.closest('[data-hit]');
            if (!hit) return;
            const i = this.hits().indexOf(hit);
            if (i !== -1 && i !== this.active) { this.active = i; this.paint(false); }
        });
    },

    show(q) {
        // Remember where they were, so closing puts them back there.
        if (!this.open) this._returnTo = document.activeElement !== document.body ? document.activeElement : null;
        this.open = true;
        if (typeof q === 'string') this.$wire.set('q', q);
        this.$nextTick(() => { this.$refs.input?.focus(); this.$refs.input?.select(); this.active = 0; this.paint(); });
    },

    close(quiet = false) {
        if (!this.open) return;
        this.open = false;
        const back = this._returnTo;
        this._returnTo = null;
        if (!quiet) {
            this.$nextTick(() => (back && document.contains(back) ? back : document.getElementById('main'))?.focus?.({ preventScroll: true }));
        }
    },

    shortcut(e) {
        const typing = e.target.closest?.('input, textarea, select, [contenteditable="true"]');
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            this.open ? this.close() : this.show();
        } else if (e.key === '/' && !typing && !this.open) {
            e.preventDefault();
            this.show();
        }
    },

    hits() { return Array.from(this.$refs.results?.querySelectorAll('[data-hit]') || []).filter((el) => el.offsetParent !== null); },

    paint(scroll = true) {
        const hits = this.hits();
        if (this.active >= hits.length) this.active = Math.max(0, hits.length - 1);
        hits.forEach((el, i) => el.classList.toggle('hit--active', i === this.active));
        if (scroll) hits[this.active]?.scrollIntoView({ block: 'nearest' });
    },

    move(step) {
        const n = this.hits().length;
        if (!n) return;
        this.active = (this.active + step + n) % n;
        this.paint();
    },

    go(newTab = false) {
        const hit = this.hits()[this.active];
        if (!hit) return;
        if (newTab) { window.open(hit.href, '_blank', 'noopener'); return; }
        hit.click();
    },

    nextScope(step) {
        const current = this.$wire.scope;
        const i = this.scopes.indexOf(current);
        this.active = 0;
        this.$wire.setScope(this.scopes[(i + step + this.scopes.length) % this.scopes.length]);
    },

    toggleStatus(value) {
        let q = (this.$wire.q || '').replace(/\bstatus:[a-z]+\s*/gi, '').trim();
        if (!new RegExp(`status:${value}`).test(this.$wire.q || '')) q = `status:${value} ${q}`.trim();
        this.active = 0;
        this.$wire.set('q', q);
        this.$refs.input.focus();
    },

    remember(item) {
        if (!item?.href) return;
        this.recent = [item, ...this.recent.filter((r) => r.href !== item.href)].slice(0, 5);
        try { localStorage.setItem('booklet.search.recent', JSON.stringify(this.recent)); } catch (e) { /* not kept */ }
        this.close(true);
    },

    forget() {
        this.recent = [];
        try { localStorage.removeItem('booklet.search.recent'); } catch (e) { /* nothing kept */ }
        this.$refs.input.focus();
    },
});

/* Any link to "#search" (Stacy's links, old bookmarks) opens the search window. */
(() => {
    document.addEventListener('click', (e) => {
        const a = e.target.closest?.('a[href]');
        if (!a || new URL(a.href, location.href).hash !== '#search') return;
        e.preventDefault();
        e.stopImmediatePropagation();
        window.dispatchEvent(new CustomEvent('open-search'));
    }, true);

    const fromAddress = () => {
        if (location.hash !== '#search') return;
        history.replaceState(history.state, '', location.pathname + location.search);
        setTimeout(() => window.dispatchEvent(new CustomEvent('open-search')), 50);
    };
    document.addEventListener('livewire:navigated', fromAddress);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fromAddress, { once: true });
    else fromAddress();
})();

/* The desk lamp (night reading): a pull cord beside the book. Drag the bead
   down and let go (or tap it, or press Enter) to switch the lamp; the cord
   springs back with a click. On: a warm pool of light over the book that leans
   a little towards the mouse. Off: cool, dim moonlight. Remembered. */
(() => {
    const KEY = 'booklet.lamp';
    const html = document.documentElement;
    const calm = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const read = () => { try { return localStorage.getItem(KEY) !== 'off'; } catch (e) { return true; } };
    const write = (on) => { try { localStorage.setItem(KEY, on ? 'on' : 'off'); } catch (e) { /* not kept */ } };

    // Set the class straight away, before the page paints, so there's no flash.
    let on = read();
    const mark = () => {
        html.classList.toggle('lamp-on', on);
        html.classList.toggle('lamp-off', !on);
    };
    mark();

    // Moving between pages replaces the classes on <html>, which is where the lamp's
    // state lives: put it back as the new page goes in (before it shows), and once more after.
    document.addEventListener('livewire:navigating', (e) => e.detail?.onSwap?.(mark));
    document.addEventListener('livewire:navigated', mark);

    let audio = null;
    const click = (pitch = 1) => {
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            audio = audio && audio.state !== 'closed' ? audio : new Ctx();
            if (audio.state === 'suspended') audio.resume().catch(() => {});
            const now = audio.currentTime + 0.005;
            // A short, dry "tick" of the switch: a burst of noise through a narrow filter.
            const len = 0.045;
            const buf = audio.createBuffer(1, Math.floor(audio.sampleRate * len), audio.sampleRate);
            const d = buf.getChannelData(0);
            for (let i = 0; i < d.length; i++) d[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / d.length, 3);
            const src = audio.createBufferSource();
            src.buffer = buf;
            const bp = audio.createBiquadFilter();
            bp.type = 'bandpass';
            bp.frequency.value = 2600 * pitch;
            bp.Q.value = 3;
            const g = audio.createGain();
            g.gain.value = 0.35;
            src.connect(bp).connect(g).connect(audio.destination);
            src.start(now);
        } catch (e) { /* no sound */ }
    };

    const setLamp = (value, { sound = true } = {}) => {
        on = value;
        write(on);
        if (sound) { click(on ? 1 : 0.8); setTimeout(() => click(on ? 1.25 : 0.9), 70); }
        mark();
        html.classList.remove('lamp-flicker');
        if (on && !calm()) { void html.offsetWidth; html.classList.add('lamp-flicker'); }
        document.querySelectorAll('[data-lamp-bead]').forEach((b) => b.setAttribute('aria-checked', on ? 'true' : 'false'));
    };

    // The light leans a little towards the mouse, eased.
    let lx = 0, ly = 0, tx = 0, ty = 0, leanRaf = null;
    const lean = () => {
        lx += (tx - lx) * 0.06;
        ly += (ty - ly) * 0.06;
        html.style.setProperty('--lx', lx.toFixed(1) + 'px');
        html.style.setProperty('--ly', ly.toFixed(1) + 'px');
        leanRaf = Math.abs(tx - lx) + Math.abs(ty - ly) > 0.3 ? requestAnimationFrame(lean) : null;
    };
    window.addEventListener('pointermove', (e) => {
        if (calm() || e.pointerType !== 'mouse' || !document.body?.classList.contains('night') || !on) return;
        tx = (e.clientX - innerWidth / 2) * 0.05;
        ty = (e.clientY - innerHeight / 2) * 0.035;
        if (!leanRaf) leanRaf = requestAnimationFrame(lean);
    }, { passive: true });

    function cord(root) {
        if (root.dataset.ready) { root._lampRun?.(); return; } // back in night reading: swing again
        root.dataset.ready = '1';

        const wrap = root.querySelector('[data-lamp-cord]');
        const bead = root.querySelector('[data-lamp-bead]');
        const path = root.querySelector('[data-lamp-string]');
        bead.setAttribute('aria-checked', on ? 'true' : 'false');

        const REST = 132;                 // how far the bead hangs below the anchor
        const PULL = 46;                  // how far down counts as a pull
        let x = 0, y = REST, vx = 0, vy = 0;   // bead, relative to the anchor
        let dragging = false, moved = 0, grab = null, raf = null, t = 0;

        const draw = () => {
            const w = wrap.clientWidth / 2;
            // The string: anchor at the top, a slight curve down to the bead.
            const midX = w + x * 0.45 + Math.sin(t * 1.3) * 0.6;
            path.setAttribute('d', `M ${w} 0 Q ${midX} ${y * 0.55} ${w + x} ${y - 8}`);
            bead.style.transform = `translate(${x}px, ${y}px) rotate(${(x * 0.5).toFixed(1)}deg)`;
        };

        const step = () => {
            // Only while night reading shows the cord; it starts again on the next page.
            if (!document.body?.classList.contains('night')) { raf = null; return; }
            t += 1 / 60;
            if (!dragging) {
                // A damped spring back to rest, with a breath of sway.
                const sway = calm() ? 0 : Math.sin(t * 1.1) * 1.4;
                vx += (sway - x) * 0.09; vy += (REST - y) * 0.12;
                vx *= 0.86; vy *= 0.8;
                x += vx; y += vy;
            }
            draw();
            const resting = !dragging && Math.abs(vx) + Math.abs(vy) < 0.02 && Math.abs(y - REST) < 0.3;
            raf = resting && calm() ? null : requestAnimationFrame(step);
        };
        const run = () => { if (!raf) raf = requestAnimationFrame(step); };

        // Pull it with the mouse or a finger.
        bead.addEventListener('pointerdown', (e) => {
            e.preventDefault();
            dragging = true; moved = 0;
            grab = { x: e.clientX - x, y: e.clientY - y };
            bead.setPointerCapture(e.pointerId);
            wrap.classList.add('is-held');
            run();
        });
        bead.addEventListener('pointermove', (e) => {
            if (!dragging) return;
            const nx = Math.max(-38, Math.min(38, e.clientX - grab.x));
            const ny = Math.max(REST - 18, Math.min(REST + 78, e.clientY - grab.y));
            moved = Math.max(moved, Math.abs(ny - REST) + Math.abs(nx));
            vx = nx - x; vy = ny - y; x = nx; y = ny;
        });
        const release = () => {
            if (!dragging) return;
            dragging = false;
            wrap.classList.remove('is-held');
            if (y - REST > PULL) setLamp(!on);
            else if (moved < 6) tug(); // a tap
            vy = -Math.max(6, (y - REST) * 0.35); // spring up
        };
        bead.addEventListener('pointerup', release);
        bead.addEventListener('pointercancel', () => { dragging = false; wrap.classList.remove('is-held'); });

        // A tap or the keyboard: give it a little tug ourselves.
        const tug = () => {
            if (calm()) { setLamp(!on); return; }
            let n = 0;
            dragging = true; // hold the spring off while we pull
            const down = () => {
                y += (REST + PULL + 14 - y) * 0.45; draw();
                if (++n < 7) { requestAnimationFrame(down); return; }
                dragging = false;
                setLamp(!on);
                vy = -9;
                run();
            };
            requestAnimationFrame(down);
            run();
        };
        bead.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); tug(); }
        });
        // Pointer clicks are handled above; keep the button from double-firing.
        bead.addEventListener('click', (e) => e.preventDefault());

        // Brushing past the cord sets it swinging.
        window.addEventListener('pointermove', (e) => {
            if (dragging || calm() || e.pointerType !== 'mouse') return;
            const box = wrap.getBoundingClientRect();
            if (e.clientY > box.top + REST + 20 || Math.abs(e.clientX - (box.left + box.width / 2 + x)) > 14) return;
            vx += Math.max(-2.5, Math.min(2.5, e.movementX * 0.25));
            run();
        }, { passive: true });

        root._lampRun = run;
        draw();
        run();
    }

    const start = () => document.querySelectorAll('[data-lamp]').forEach(cord);
    document.addEventListener('livewire:navigated', start);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
})();

/* The stamp at the bottom ("Saved.", "Moved to the bin."). When something went
   in the bin it offers Undo for a few seconds, which puts it straight back. */
window.bookletStamp = (text, undo, binUrl, words = {}) => ({
    text: text || '',
    undo: undo || null,
    tone: 'done',
    show: false,
    busy: false,
    _timer: null,
    _end: 0,
    _left: 0,
    init() { if (this.text) this.pop(this.text, this.undo); },
    /*
     * A new stamp while one is showing takes its place (and its time starts
     * again). Hiding and showing it again here would race the old one's
     * leaving animation, which then hid the new one.
     */
    pop(value, undo = null, tone = 'done') {
        this.text = value;
        this.undo = undo || null;
        this.tone = tone === 'error' ? 'error' : 'done';
        if (this.show) {
            this._bump();
        } else {
            this.show = true;
        }
        this.$nextTick(() => this._arm(this.undo ? 8000 : 4000));
    },
    close() { clearTimeout(this._timer); this.show = false; },
    _arm(ms) {
        clearTimeout(this._timer);
        this._end = Date.now() + ms;
        this._timer = setTimeout(() => { this.show = false; }, ms);
        const bar = this.$refs.time;
        if (bar) {
            bar.style.animation = 'none';
            void bar.offsetWidth; // start the bar over
            bar.style.animation = `toast-time ${ms}ms linear forwards`;
            bar.style.animationPlayState = 'running';
        }
    },
    // A little nudge so a replaced stamp is noticed.
    _bump() {
        const el = this.$refs.toast;
        if (!el) return;
        el.classList.remove('is-bumped');
        void el.offsetWidth;
        el.classList.add('is-bumped');
    },
    hold() {
        if (!this.show) return;
        clearTimeout(this._timer);
        this._left = Math.max(1200, this._end - Date.now());
        if (this.$refs.time) this.$refs.time.style.animationPlayState = 'paused';
    },
    release() {
        if (!this.show || !this._left) return;
        const left = this._left;
        this._left = 0;
        clearTimeout(this._timer);
        this._end = Date.now() + left;
        this._timer = setTimeout(() => { this.show = false; }, left);
        if (this.$refs.time) this.$refs.time.style.animationPlayState = 'running';
    },
    async putBack() {
        if (!this.undo || this.busy) return;
        this.busy = true;
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        try {
            const res = await fetch(`${binUrl}/${this.undo}/restore`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            });
            const body = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(body.message || 'It couldn’t be put back. Try the Bin page.');
            this.pop(words.putBack || 'Put back.');
            // Show it where it was (or refresh the page we're on).
            const go = body.href || window.location.href;
            window.Livewire ? window.Livewire.navigate(go) : (window.location.href = go);
        } catch (e) {
            this.pop(e.message, null, 'error');
        } finally {
            this.busy = false;
        }
    },
});

/* The focal point picker on Media: click or drag to the spot that matters; arrow
   keys nudge it. The previews follow as you move, and it's saved when you let go. */
window.bookletFocus = (x = 50, y = 50) => ({
    x, y,
    _down: false,
    _timer: null,
    _at(event) {
        const box = this.$refs.stage.getBoundingClientRect();
        this.x = Math.round(Math.max(0, Math.min(100, ((event.clientX - box.left) / box.width) * 100)));
        this.y = Math.round(Math.max(0, Math.min(100, ((event.clientY - box.top) / box.height) * 100)));
    },
    grab(event) {
        this._down = true;
        this.$refs.stage.setPointerCapture?.(event.pointerId);
        this._at(event);
    },
    drag(event) { if (this._down) this._at(event); },
    drop() {
        if (!this._down) return;
        this._down = false;
        this.$wire.setFocus(this.x, this.y);
    },
    nudge(event) {
        const step = event.shiftKey ? 10 : 2;
        const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
        const move = moves[event.key];
        if (!move) return;
        event.preventDefault();
        this.x = Math.max(0, Math.min(100, this.x + move[0]));
        this.y = Math.max(0, Math.min(100, this.y + move[1]));
        clearTimeout(this._timer);
        this._timer = setTimeout(() => this.$wire.setFocus(this.x, this.y), 500);
    },
});

/* A QR code (as SVG) for the authenticator app, drawn here so the secret never
   leaves the page. Uses the bundled qrcode-generator (vendor/qrcode.js, MIT). */
window.bookletQr = (text) => ({
    svg: '',
    init() {
        const draw = () => {
            if (typeof window.qrcode !== 'function') return setTimeout(draw, 50);
            const qr = window.qrcode(0, 'M');
            qr.addData(text);
            qr.make();
            const n = qr.getModuleCount();
            const q = 3; // quiet zone
            let path = '';
            for (let r = 0; r < n; r++) for (let c = 0; c < n; c++) if (qr.isDark(r, c)) path += `M${c + q},${r + q}h1v1h-1z`;
            this.svg = `<svg viewBox="0 0 ${n + q * 2} ${n + q * 2}" shape-rendering="crispEdges" xmlns="http://www.w3.org/2000/svg"><rect width="100%" height="100%" fill="#fff"/><path d="${path}" fill="#1E1C18"/></svg>`;
        };
        draw();
    },
});


/* The sound of a page turning: a little lift as the corner comes up, a papery
   rustle that's loudest as the sheet passes the spine, and a soft flap as it
   lands. Made here (no files to load). Off in Your account › Page-turn sound. */
window.bookletPageSound = (seconds = 1.05) => {
    if (document.body?.dataset.pageSound === 'off') return;
    const ua = navigator.userActivation;
    if (ua && !ua.hasBeenActive) return; // browsers only allow sound once the page has been used
    try {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        const ctx = (window.__bookletAudio = window.__bookletAudio && window.__bookletAudio.state !== 'closed' ? window.__bookletAudio : new Ctx());
        if (ctx.state === 'suspended') ctx.resume().catch(() => {});
        const t0 = ctx.currentTime + 0.02;
        const out = ctx.createGain();
        out.gain.value = 1.4;
        out.connect(ctx.destination);

        const noise = (len) => {
            const n = Math.max(1, Math.floor(ctx.sampleRate * len));
            const buf = ctx.createBuffer(1, n, ctx.sampleRate);
            const d = buf.getChannelData(0);
            for (let i = 0; i < n; i++) d[i] = Math.random() * 2 - 1;
            const src = ctx.createBufferSource();
            src.buffer = buf;
            return src;
        };
        const env = (g, points) => {
            g.gain.setValueAtTime(0.0001, t0);
            points.forEach(([at, v]) => g.gain.linearRampToValueAtTime(Math.max(0.0001, v), t0 + at));
        };

        // 1. The corner lifting: a small dry tick.
        const lift = noise(0.05);
        const liftBand = ctx.createBiquadFilter();
        liftBand.type = 'bandpass'; liftBand.frequency.value = 2600; liftBand.Q.value = 1.6;
        const liftGain = ctx.createGain();
        env(liftGain, [[0.004, 0.10], [0.045, 0.0001]]);
        lift.connect(liftBand).connect(liftGain).connect(out);
        lift.start(t0);

        // 2. The rustle: noise through a moving band, with a papery flutter on top.
        const len = seconds * 0.82;
        const rustle = noise(len + 0.05);
        const band = ctx.createBiquadFilter();
        band.type = 'bandpass'; band.Q.value = 0.9;
        band.frequency.setValueAtTime(900, t0 + 0.04);
        band.frequency.exponentialRampToValueAtTime(3400, t0 + len * 0.5);
        band.frequency.exponentialRampToValueAtTime(1500, t0 + len);
        const shelf = ctx.createBiquadFilter();
        shelf.type = 'highpass'; shelf.frequency.value = 350;
        const swell = ctx.createGain();
        env(swell, [[0.06, 0.012], [len * 0.25, 0.05], [len * 0.5, 0.085], [len * 0.75, 0.04], [len, 0.0001]]);
        const flutter = ctx.createGain();
        flutter.gain.setValueAtTime(1, t0);
        for (let at = 0.04; at < len; at += 0.018 + Math.random() * 0.02) {
            flutter.gain.linearRampToValueAtTime(0.45 + Math.random() * 0.75, t0 + at);
        }
        rustle.connect(shelf).connect(band).connect(swell).connect(flutter).connect(out);
        rustle.start(t0 + 0.03);

        // 3. Landing: a soft, low flap of paper settling.
        const land = t0 + seconds * 0.86;
        const flap = noise(0.16);
        const low = ctx.createBiquadFilter();
        low.type = 'lowpass'; low.frequency.value = 700;
        const flapGain = ctx.createGain();
        flapGain.gain.setValueAtTime(0.0001, land);
        flapGain.gain.linearRampToValueAtTime(0.16, land + 0.012);
        flapGain.gain.exponentialRampToValueAtTime(0.0001, land + 0.15);
        flap.connect(low).connect(flapGain).connect(out);
        flap.start(land);
    } catch (e) { /* no sound available */ }
};

/* Keyboard shortcuts card: "?" opens it (not while typing), Esc closes. */
window.bookletKeys = () => ({
    open: false,
    mod: /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent) ? '⌘' : 'Ctrl',
    key(e) {
        if (e.key !== '?' || e.metaKey || e.ctrlKey || e.altKey) return;
        if (e.target.closest?.('input, textarea, select, [contenteditable="true"]')) return;
        e.preventDefault();
        this.open = !this.open;
    },
});

/* A new milestone sticker: it's slapped onto the page (with a soft thwap and a
   little chime, if the book's sounds are on), then put away. */
window.bookletStickerSound = () => {
    if (document.body?.dataset.pageSound === 'off') return;
    const ua = navigator.userActivation;
    if (ua && !ua.hasBeenActive) return;
    try {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        const ctx = (window.__bookletAudio = window.__bookletAudio && window.__bookletAudio.state !== 'closed' ? window.__bookletAudio : new Ctx());
        if (ctx.state === 'suspended') ctx.resume().catch(() => {});
        const t0 = ctx.currentTime + 0.02;
        const out = ctx.createGain();
        out.gain.value = 0.9;
        out.connect(ctx.destination);

        // The thwap: a short burst of low noise as the sticker lands.
        const len = Math.floor(ctx.sampleRate * 0.12);
        const buf = ctx.createBuffer(1, len, ctx.sampleRate);
        const data = buf.getChannelData(0);
        for (let i = 0; i < len; i++) data[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / len, 3);
        const noise = ctx.createBufferSource();
        noise.buffer = buf;
        const low = ctx.createBiquadFilter();
        low.type = 'lowpass';
        low.frequency.setValueAtTime(1400, t0);
        low.frequency.exponentialRampToValueAtTime(260, t0 + 0.1);
        const thud = ctx.createGain();
        thud.gain.setValueAtTime(0.0001, t0);
        thud.gain.exponentialRampToValueAtTime(0.32, t0 + 0.006);
        thud.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.12);
        noise.connect(low).connect(thud).connect(out);
        noise.start(t0);

        // The chime: two soft bell notes, a fifth apart.
        [[784, 0.09], [1175, 0.2]].forEach(([freq, delay]) => {
            const o = ctx.createOscillator();
            o.type = 'sine';
            o.frequency.value = freq;
            const g = ctx.createGain();
            const at = t0 + delay;
            g.gain.setValueAtTime(0.0001, at);
            g.gain.exponentialRampToValueAtTime(0.07, at + 0.012);
            g.gain.exponentialRampToValueAtTime(0.0001, at + 0.9);
            o.connect(g).connect(out);
            o.start(at);
            o.stop(at + 1);
        });
    } catch (e) { /* no sound, no matter */ }
};

window.bookletStickerPop = () => ({
    open: true,
    init() {
        const still = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        setTimeout(() => window.bookletStickerSound(), still ? 0 : 330); // as it lands
    },
    close() {
        this.open = false;
    },
});

/* Password strength, worked out exactly as app/Support/PasswordStrength.php does
   (the server holds the same line), so the meter never promises what the server refuses. */
window.bookletPasswordStrength = (() => {
    const MIN_LENGTH = 10, MAX_BYTES = 72, MIN_SCORE = 2;
    const LABELS = ['Too easy to guess', 'Weak', 'Fair', 'Strong', 'Very strong'];
    const WORDS = ['password', 'passw0rd', 'p@ssword', 'admin', 'welcome', 'letmein', 'login', 'qwerty', 'monkey', 'dragon', 'master', 'sunshine', 'princess', 'football', 'baseball', 'iloveyou', 'secret', 'summer', 'winter', 'spring', 'autumn', 'hello', 'shadow', 'superman', 'batman', 'freedom', 'whatever', 'booklet', 'circas', 'love', 'sayang', 'cinta'];
    const ROWS = ['abcdefghijklmnopqrstuvwxyz', 'qwertyuiop', 'asdfghjkl', 'zxcvbnm', '1234567890', '0123456789'];
    const LEET = { 0: 'o', 1: 'i', 3: 'e', 4: 'a', 5: 's', 7: 't', '@': 'a', $: 's', '!': 'i' };
    const byLength = (a, b) => (b.length - a.length) || (a < b ? -1 : a > b ? 1 : 0);
    const SORTED = WORDS.slice().sort(byLength);
    let common = null;

    const commonSet = () => (common ??= new Set(window.BOOKLET_COMMON_PASSWORDS || []));
    const isCommon = (v) => v !== '' && commonSet().has(v);
    const leet = (v) => v.replace(/[013457@$!]/g, (c) => LEET[c]);
    const bytes = (v) => new TextEncoder().encode(v).length;

    function personalTokens(personal) {
        let tokens = [];
        for (let value of personal || []) {
            value = String(value ?? '').trim().toLowerCase();
            if (!value) continue;
            if (value.includes('@')) {
                tokens.push(value); // the whole address, too
                const local = value.split('@')[0];
                tokens.push(local);
                value = local;
            }
            tokens.push(value.replace(/[^\p{L}\p{N}]+/gu, ''));
            tokens.push(...value.split(/[^\p{L}\p{N}]+/u).filter(Boolean));
        }
        tokens = [...new Set(tokens.filter((t) => Array.from(t).length >= 3))];
        return tokens.sort((a, b) => (Array.from(b).length - Array.from(a).length) || (a < b ? -1 : a > b ? 1 : 0));
    }

    function runAt(c, i) {
        const n = c.length;
        let same = 1;
        while (i + same < n && c[i + same] === c[i]) same++;
        let best = same;
        for (const row of ROWS) {
            for (const line of [row, row.split('').reverse().join('')]) {
                let k = 1;
                while (i + k < n) {
                    const at = line.indexOf(c[i + k - 1]);
                    if (at === -1 || at + 1 >= line.length || line[at + 1] !== c[i + k]) break;
                    k++;
                }
                best = Math.max(best, k);
            }
        }
        return best;
    }

    function tokens(c) {
        let count = 0, runs = false;
        for (let i = 0; i < c.length;) {
            const length = runAt(c, i);
            if (length >= 3) { runs = true; count++; i += length; } else { count++; i++; }
        }
        return [count, runs];
    }

    function pool(p) {
        let n = 0;
        if (/[a-z]/.test(p)) n += 26;
        if (/[A-Z]/.test(p)) n += 26;
        if (/[0-9]/.test(p)) n += 10;
        if (/[\x20-\x2F\x3A-\x40\x5B-\x60\x7B-\x7E]/.test(p)) n += 33;
        if (/[^\x00-\x7E]/u.test(p)) n += 100;
        return Math.max(n, 10);
    }

    const result = (score, bits, warnings) => ({ score, label: LABELS[score], bits: Math.round(bits * 10) / 10, warnings });

    function check(password, personal = []) {
        const chars = Array.from(password);
        if (!chars.length) return result(0, 0, ['short']);

        const warnings = [];
        const lower = password.toLowerCase();
        const bare = lower.replace(/[\d!?.@#$*_\-]+$/u, '');
        if (isCommon(lower) || isCommon(bare) || isCommon(leet(lower)) || isCommon(leet(bare))) return result(0, 0, ['common']);

        // (Matched with letters for look-alike digits too: "B00klet" is still "booklet".)
        let s = lower;
        const swap = (needle, placeholder) => {
            const want = Array.from(leet(needle));
            let found = false;
            for (;;) {
                const c = Array.from(s);
                const l = Array.from(leet(s));
                let at = -1;
                for (let i = 0; i + want.length <= l.length && at < 0; i++) {
                    if (want.every((ch, j) => l[i + j] === ch)) at = i;
                }
                if (at < 0) return found;
                s = c.slice(0, at).join('') + placeholder + c.slice(at + want.length).join('');
                found = true;
            }
        };
        for (const token of personalTokens(personal)) {
            if (swap(token, '\x02')) warnings.push('personal');
        }
        for (const word of SORTED) {
            if (swap(word, '\x01')) warnings.push('pattern');
        }
        const before = s;
        s = s.replace(/(19|20)\d{2}/gu, '\x03');
        if (s !== before) warnings.push('pattern');

        const [count, runs] = tokens(Array.from(s));
        if (runs) warnings.push('pattern');

        let bits = count * Math.log2(pool(password));
        if (new Set(chars).size < 4) bits = Math.min(bits, 20);
        if (chars.length < MIN_LENGTH) warnings.push('short');

        const score = bits < 28 ? 0 : bits < 36 ? 1 : bits < 50 ? 2 : bits < 64 ? 3 : 4;
        return result(score, bits, [...new Set(warnings)]);
    }

    function problem(password, personal = []) {
        if (Array.from(password).length < MIN_LENGTH) return 'Use at least ' + MIN_LENGTH + ' characters. Three or four unrelated words work well.';
        if (bytes(password) > MAX_BYTES) return 'That’s longer than passwords can be here (' + MAX_BYTES + ' letters, or fewer with accents or emoji).';
        const c = check(password, personal);
        if (c.score >= MIN_SCORE) return null;
        if (c.warnings.includes('common')) return 'That’s one of the most used passwords, so it’s among the first anyone would try. Pick something only you would think of.';
        if (c.warnings.includes('personal')) return 'Leave your name, your email and the site’s name out of it; they’re the first things someone would try.';
        if (c.warnings.includes('pattern')) return 'Patterns like 1234, aaaa, qwerty, a year or the word “password” are easy to guess. Add a few unrelated words.';
        return 'That’s too easy to guess. Make it longer: a few unrelated words, with a number or symbol between them.';
    }

    return { check, problem, personalTokens, MIN_LENGTH, MIN_SCORE, LABELS };
})();

/* A password box: show or hide it, warn about Caps Lock, and (for new
   passwords) a strength meter, or whether it matches the first box. */
window.bookletPassword = ({ meter = false, personal = [], personalFrom = [], match = null } = {}) => ({
    show: false,
    caps: false,
    value: '',
    result: null,
    problem: null,
    matches: false,
    meter,

    init() {
        const input = this.$refs.input;
        this.value = input.value;
        const update = () => this.update();
        input.addEventListener('input', update);
        // The details it shouldn't lean on can change in the same form (a name typed above).
        personalFrom.forEach((id) => document.getElementById(id)?.addEventListener('input', update));
        if (match) document.getElementById(match)?.addEventListener('input', update);
        this.update();
    },

    update() {
        this.value = this.$refs.input.value;
        if (this.meter) {
            const others = personalFrom.map((id) => document.getElementById(id)?.value || '');
            this.result = this.value ? window.bookletPasswordStrength.check(this.value, personal.concat(others)) : null;
            this.problem = this.value ? window.bookletPasswordStrength.problem(this.value, personal.concat(others)) : null;
        }
        if (match) this.matches = this.value !== '' && this.value === (document.getElementById(match)?.value || '');
    },

    keys(e) {
        if (e.getModifierState) this.caps = e.getModifierState('CapsLock');
    },

    get hint() {
        if (!this.result) return '';
        if (this.problem) return this.problem;
        return this.result.score >= 4 ? 'Lovely. Keep it in a password manager.' : this.result.score === 3 ? 'Good. A word or two more would make it even better.' : 'Good enough. Longer would be stronger.';
    },
});

// Errors belong to their fields: each message under a field is tied to it
// (aria-describedby, aria-invalid), so screen readers say what's wrong when
// someone is on the field. Kept up to date as the page changes.
(() => {
    const MARK = 'data-booklet-error';
    const link = () => {
        document.querySelectorAll(`[${MARK}]`).forEach((input) => {
            const id = input.getAttribute(MARK);
            if (!document.getElementById(id)) {
                input.removeAttribute('aria-invalid');
                const rest = (input.getAttribute('aria-describedby') || '').split(' ').filter((x) => x && x !== id).join(' ');
                rest ? input.setAttribute('aria-describedby', rest) : input.removeAttribute('aria-describedby');
                input.removeAttribute(MARK);
            }
        });
        document.querySelectorAll('.field__error').forEach((error, i) => {
            const box = error.closest('.field, .setting-row, form, section') || error.parentElement;
            let input = box?.querySelector('input:not([type=hidden]):not([tabindex="-1"]), select, textarea');
            if (!input) {
                let prev = error.previousElementSibling;
                while (prev && !input) { input = prev.matches?.('input, select, textarea') ? prev : prev.querySelector?.('input:not([type=hidden]):not([tabindex="-1"]), select, textarea'); prev = prev.previousElementSibling; }
            }
            if (!input) return;
            if (!error.id) error.id = `booklet-error-${Date.now().toString(36)}-${i}`;
            const ids = new Set((input.getAttribute('aria-describedby') || '').split(' ').filter(Boolean));
            ids.add(error.id);
            input.setAttribute('aria-describedby', [...ids].join(' '));
            input.setAttribute('aria-invalid', 'true');
            input.setAttribute(MARK, error.id);
        });
    };
    let queued = false;
    const soon = () => { if (!queued) { queued = true; requestAnimationFrame(() => { queued = false; link(); }); } };
    const observer = new MutationObserver(soon);
    const watch = () => { link(); observer.disconnect(); observer.observe(document.body, { childList: true, subtree: true }); };
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', watch) : watch();
    document.addEventListener('livewire:navigated', watch); // a new page body after wire:navigate
})();

/*
 * Passkeys: Face ID, Touch ID, Windows Hello, a phone or a security key.
 * The server hands over options (bytes as base64url); the device makes or
 * signs a key; what comes back goes to the server, bytes as base64url again.
 *   x-data="bookletPasskey('make')"   Your account › Signing in
 *   x-data="bookletPasskey('signin')" the sign-in page
 * Both expect the Livewire component to have passkeyOptions(), passkeyFailed(why),
 * and addPasskey(credential) or passkeySignIn(credential).
 */
window.bookletPasskeys = (() => {
    const bytes = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4)), (c) => c.charCodeAt(0));
    const text = (buffer) => {
        let out = '';
        new Uint8Array(buffer).forEach((b) => { out += String.fromCharCode(b); });
        return btoa(out).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    };
    const ids = (list) => (list || []).map((c) => ({ ...c, id: bytes(c.id) }));
    const available = () => !!(window.isSecureContext && window.PublicKeyCredential && navigator.credentials);
    const numbers = () => /^\d{1,3}(\.\d{1,3}){3}$/.test(location.hostname) || location.hostname.startsWith('[');
    const reason = () => (!window.isSecureContext ? 'insecure' : (!window.PublicKeyCredential ? 'unsupported' : (numbers() ? 'numbers' : null)));

    async function make(o) {
        const made = await navigator.credentials.create({ publicKey: {
            ...o, challenge: bytes(o.challenge), user: { ...o.user, id: bytes(o.user.id) }, excludeCredentials: ids(o.excludeCredentials),
        } });
        const r = made.response;
        return { id: made.id, rawId: text(made.rawId), type: made.type, response: {
            clientDataJSON: text(r.clientDataJSON), attestationObject: text(r.attestationObject),
            transports: typeof r.getTransports === 'function' ? r.getTransports() : [],
        } };
    }

    async function sign(o) {
        const got = await navigator.credentials.get({ publicKey: { ...o, challenge: bytes(o.challenge), allowCredentials: ids(o.allowCredentials) } });
        const r = got.response;
        return { id: got.id, rawId: text(got.rawId), type: got.type, response: {
            clientDataJSON: text(r.clientDataJSON), authenticatorData: text(r.authenticatorData),
            signature: text(r.signature), userHandle: r.userHandle ? text(r.userHandle) : '',
        } };
    }

    return { available, reason, make, sign };
})();

window.bookletPasskey = (what) => ({
    busy: false,
    available: window.bookletPasskeys.available(),
    // Options fetched ahead, so the device is asked the moment the button is
    // pressed: Safari (and iPhones) refuse with "cancelled" when the click
    // waited on the server first. A challenge is good for 5 minutes.
    ready: null,
    readyAt: 0,
    init() {
        if (what === 'signin' && this.available) this.prepare();
    },
    async prepare() {
        try {
            const options = await this.$wire.passkeyOptions();
            if (options && options.challenge) { this.ready = options; this.readyAt = Date.now(); }
        } catch (error) { /* asked for again on the click */ }
    },
    async go() {
        if (this.busy) return;
        const why = window.bookletPasskeys.reason();
        if (why) { this.$wire.passkeyFailed(why); return; }
        this.busy = true;
        let done = false;
        try {
            let options = this.ready && Date.now() - this.readyAt < 240000 ? this.ready : null;
            this.ready = null;
            if (!options) options = await this.$wire.passkeyOptions();
            if (!options || !options.challenge) return; // the server said why
            if (what === 'make') {
                await this.$wire.addPasskey(await window.bookletPasskeys.make(options));
            } else {
                await this.$wire.passkeySignIn(await window.bookletPasskeys.sign(options));
            }
            done = true;
        } catch (error) {
            this.$wire.passkeyFailed(error && error.name ? String(error.name) : 'Error');
        } finally {
            this.busy = false;
            if (!done) this.prepare(); // ready for another try
        }
    },
});

/*
 * "Is this device ready for passkeys?": the things a browser needs before it
 * will make one, checked here rather than guessed at from a failed attempt.
 *   x-data="bookletPasskeyCheck(serverOrigin)" on a <details>, words in a
 *   <script type="application/json" data-pk-words> inside it.
 */
window.bookletPasskeyCheck = (server) => ({
    rows: [],
    async run() {
        let w = {};
        try { w = JSON.parse(this.$el.querySelector('[data-pk-words]').textContent); } catch (e) { /* the English below */ }
        const say = (key, values = {}) => String(w[key] || key).replace(/:([a-z]+)/g, (m, k) => (k in values ? values[k] : m));
        const host = location.hostname;
        const numbers = /^\d{1,3}(\.\d{1,3}){3}$/.test(host) || host.includes(':') || host.startsWith('[');
        const rows = [
            { key: 'secure', title: say('secure'), state: window.isSecureContext ? 'ok' : 'no', text: say(window.isSecureContext ? 'secureOk' : 'secureNo', { address: location.origin }) },
            { key: 'named', title: say('named'), state: numbers ? 'no' : 'ok', text: say(numbers ? 'namedNo' : 'namedOk', { host, suggest: location.protocol + '//localhost' + (location.port ? ':' + location.port : '') + location.pathname }) },
        ];
        const browser = !!(window.PublicKeyCredential && navigator.credentials);
        rows.push({ key: 'browser', title: say('browser'), state: browser ? 'ok' : 'no', text: say(browser ? 'browserOk' : 'browserNo') });
        if (browser && window.isSecureContext) {
            let device = false;
            try { device = await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable(); } catch (e) { device = false; }
            rows.push({ key: 'device', title: say('device'), state: device ? 'ok' : 'warn', text: say(device ? 'deviceOk' : 'deviceNo') });
        }
        const same = !server || server === location.origin;
        rows.push({ key: 'server', title: say('server'), state: same ? 'ok' : 'no', text: say(same ? 'serverOk' : 'serverNo', { address: location.origin, browser: location.origin, server }) });
        this.rows = rows;
    },
    init() {
        // Open (and checked) straight away when this browser can't make passkeys here.
        if (!window.bookletPasskeys.available()) this.$el.open = true;
        if (this.$el.open) this.run();
    },
});

/*
 * Categories and tags: what's chosen as stickers, the ones used most to pick
 * with a press, and a box to find one (or add a new one with Enter). What's
 * saved is still the plain list in the hidden box ("Kuih, Weekend"), so the
 * server side doesn't change.
 *   x-data="bookletTerms({ input, model, choices: [{ title, count }], max, words })"
 */
window.bookletTerms = ({ input, model, value = '', choices = [], max = 0, words = {} }) => ({
    box: null, // declared here, or setting it would land on the writing page's own data (shared by every picker)
    chosen: [],
    query: '',
    open: false,
    at: 0,
    words,
    init() {
        this.box = document.getElementById(input);
        this.chosen = this.split(value); // what the page was drawn with (the hidden box may not be filled yet)
        // Changed on the server (a newer version loaded, Stacy's suggestions): show that.
        if (model && this.$wire && this.$wire.$watch) {
            this.$wire.$watch(model, (v) => { this.chosen = this.split(v); });
        }
        this.$watch('query', () => { this.at = 0; this.open = true; });
    },
    split(text) {
        const seen = new Set();
        return String(text || '').split(',').map((t) => t.trim()).filter((t) => {
            const k = t.toLocaleLowerCase();
            if (!t || seen.has(k)) return false;
            seen.add(k);
            return true;
        });
    },
    has(name) { const k = name.toLocaleLowerCase(); return this.chosen.some((c) => c.toLocaleLowerCase() === k); },
    full() { return max > 0 && this.chosen.length >= max && max !== 1; },
    // The ones used most that aren't chosen yet (all of them for a pick-one list like Category).
    often() { return choices.filter((c) => !this.has(c.title)).slice(0, max === 1 ? 24 : 10); },
    matches() {
        const q = this.query.trim().toLocaleLowerCase();
        if (!q) return [];
        const starts = [], within = [];
        choices.forEach((c) => {
            if (this.has(c.title)) return;
            const t = c.title.toLocaleLowerCase();
            if (t.startsWith(q)) starts.push(c); else if (t.includes(q)) within.push(c);
        });
        return starts.concat(within).slice(0, 8);
    },
    isNew() {
        const q = this.query.trim();
        return q !== '' && !q.includes(',') && !choices.some((c) => c.title.toLocaleLowerCase() === q.toLocaleLowerCase()) && !this.has(q);
    },
    add(name) {
        name = String(name || '').replace(/,/g, ' ').trim();
        if (!name) return;
        // An existing one, spelt as it was first written.
        const same = choices.find((c) => c.title.toLocaleLowerCase() === name.toLocaleLowerCase());
        if (same) name = same.title;
        if (max === 1) this.chosen = [name];
        else if (!this.has(name) && !this.full()) this.chosen.push(name);
        this.query = '';
        this.at = 0;
        this.sync();
    },
    drop(i) {
        this.chosen.splice(i, 1);
        this.sync();
        this.$nextTick(() => { const f = document.getElementById(input + '-find'); if (f) f.focus(); });
    },
    key(e) {
        const list = this.matches();
        const last = list.length + (this.isNew() ? 1 : 0) - 1;
        if (e.key === 'ArrowDown') { e.preventDefault(); this.open = true; this.at = Math.min(this.at + 1, Math.max(last, 0)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); this.at = Math.max(this.at - 1, 0); }
        else if (e.key === 'Enter' || e.key === ',' || (e.key === 'Tab' && this.query.trim() !== '')) {
            if (this.query.trim() === '') return;
            e.preventDefault();
            this.add(this.at < list.length ? list[this.at].title : this.query);
        } else if (e.key === 'Backspace' && this.query === '' && this.chosen.length) {
            this.chosen.pop();
            this.sync();
        } else if (e.key === 'Escape') { this.open = false; }
    },
    sync() {
        if (!this.box) return;
        this.box.value = this.chosen.join(', ');
        this.box.dispatchEvent(new Event('input', { bubbles: true })); // the saved list, and "Unsaved changes"
    },
});

/**
 * Night reading with the device (Make it yours): follow the phone's or
 * computer's own dark mode, and change along with it.
 */
(() => {
    const dark = window.matchMedia?.('(prefers-color-scheme: dark)');
    const follow = () => {
        if (document.body?.dataset.nightAuto === 'on' && dark) {
            document.body.classList.toggle('night', dark.matches);
        }
    };
    document.addEventListener('DOMContentLoaded', follow);
    document.addEventListener('livewire:navigated', follow);
    dark?.addEventListener?.('change', follow);
})();

/*
 * A row's own menu (the "⋯" in the Dashboard's tables). It floats above the
 * page, so the card around the table never cuts it off, and opens upwards
 * when the row is near the bottom of the window. Scrolling or resizing
 * closes it, so it never drifts away from its row.
 */
window.bookletRowMenu = () => ({
    open: false,
    place: '',
    toggle() { this.open ? this.close() : this.show(); },
    show() {
        this.open = true;
        this.place = 'visibility: hidden';
        this.$nextTick(() => {
            const b = this.$refs.button.getBoundingClientRect();
            const m = this.$refs.menu;
            const w = m.offsetWidth, h = m.offsetHeight, gap = 4, edge = 8;
            const below = window.innerHeight - b.bottom - edge;
            const top = below >= h + gap || b.top < h + gap + edge ? b.bottom + gap : b.top - h - gap;
            const left = Math.max(edge, Math.min(b.right - w, window.innerWidth - w - edge));
            this.place = `position: fixed; top: ${Math.round(top)}px; left: ${Math.round(left)}px; right: auto`;
            requestAnimationFrame(() => {
                const first = m.querySelector('a, button');
                if (first && this.open) first.focus({ preventScroll: true });
            });
        });
        this._away = () => this.close();
        window.addEventListener('scroll', this._away, true);
        window.addEventListener('resize', this._away);
    },
    close() {
        if (!this.open) return;
        this.open = false;
        window.removeEventListener('scroll', this._away, true);
        window.removeEventListener('resize', this._away);
    },
    destroy() { this.close(); },
});

/*
 * The Dashboard's sidebar, folded to a narrow rail or open. It changes at once
 * and is kept on the person's account, so it stays that way on every page and
 * device (the page is drawn with it already folded, so nothing jumps).
 */
window.bookletSidebarFold = (narrow, url) => ({
    narrow,
    toggle() {
        this.narrow = ! this.narrow;
        document.body.classList.toggle('desk-narrow', this.narrow);
        const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ narrow: this.narrow }),
        }).catch(() => {});
    },
});

/*
 * Names beside the folded rail: hovering or tabbing to an item in the
 * Dashboard's folded sidebar shows its name next to it straight away.
 * One label, made when first needed; it never takes the pointer or focus.
 */
(() => {
    let tip = null;
    const folded = () => document.body.classList.contains('desk-narrow') && window.matchMedia('(min-width: 1081px)').matches;
    const hide = () => { if (tip) tip.hidden = true; };
    const show = (el) => {
        if (!folded()) return hide();
        if (!tip || !document.body.contains(tip)) {
            tip = document.createElement('div');
            tip.className = 'rail-tip';
            tip.setAttribute('aria-hidden', 'true');
            document.body.appendChild(tip);
        }
        const r = el.getBoundingClientRect();
        tip.textContent = el.dataset.tip;
        tip.style.left = Math.round(r.right + 10) + 'px';
        tip.style.top = Math.round(r.top + r.height / 2) + 'px';
        tip.hidden = false;
    };
    const find = (t) => t instanceof Element ? t.closest('.sidebar [data-tip]') : null;
    document.addEventListener('mouseover', (e) => { const el = find(e.target); el ? show(el) : hide(); });
    document.addEventListener('focusin', (e) => { const el = find(e.target); el && el.matches(':focus-visible') ? show(el) : hide(); });
    document.addEventListener('focusout', hide);
    document.addEventListener('click', hide, true);
    window.addEventListener('scroll', hide, true);
    document.addEventListener('livewire:navigating', hide);
})();

/*
 * Stacy, docked: no orb floating over the page. Her quick card opens from the
 * Ask Stacy button that's already on screen (the Dashboard's top bar, the
 * Book's sticker, the phone's bottom bar), as a drop-down under it, or as a
 * sheet above the bar on a phone. Her greeting appears there too. The button
 * shows when she's listening or thinking. If no Ask Stacy button is on screen,
 * the orb comes back as it was. On the Ask Stacy page itself (no card), the
 * button simply goes to the page.
 */
window.bookletStacyDock = (() => {
    const root = document.documentElement;
    let anchor = null;
    const visible = (el) => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.bottom > 0 && r.top < innerHeight && getComputedStyle(el).visibility !== 'hidden'; };
    const triggers = () => [...document.querySelectorAll('[data-stacy-dock]')];
    const place = (el) => {
        anchor = el && document.body.contains(el) ? el : triggers().find(visible) || null;
        if (!anchor || !document.querySelector('.orb .orb__card, .orb .orb__greet')) {
            root.classList.remove('stacy-docked', 'stacy-up');
            return;
        }
        const r = anchor.getBoundingClientRect(), edge = 8;
        const up = r.top > innerHeight / 2;
        root.classList.add('stacy-docked');
        root.classList.toggle('stacy-up', up);
        if (up) {
            root.style.setProperty('--dock-bottom', Math.round(innerHeight - r.top + 10) + 'px');
            root.style.setProperty('--dock-left', edge + 'px');
            root.style.setProperty('--dock-right', edge + 'px');
            root.style.setProperty('--dock-align', 'stretch');
        } else {
            root.style.setProperty('--dock-top', Math.round(r.bottom + 8) + 'px');
            const rightHalf = r.left + r.width / 2 > innerWidth / 2;
            root.style.setProperty('--dock-left', rightHalf ? 'auto' : Math.max(edge, Math.round(r.left)) + 'px');
            root.style.setProperty('--dock-right', rightHalf ? Math.max(edge, Math.round(innerWidth - r.right)) + 'px' : 'auto');
            root.style.setProperty('--dock-align', rightHalf ? 'flex-end' : 'flex-start');
        }
    };
    const ours = (e) => {
        const t = e.target instanceof Element ? e.target.closest('[data-stacy-dock]') : null;
        if (!t || e.metaKey || e.ctrlKey || e.shiftKey || (e.button !== undefined && e.button !== 0)) return null;
        return document.querySelector('.orb .orb__card') ? t : null; // her page, or Stacy is off: just go
    };
    // Page links start on press and release (wire:navigate), so keep those from the link too.
    ['mousedown', 'mouseup', 'pointerdown', 'pointerup', 'touchstart', 'touchend'].forEach((type) =>
        document.addEventListener(type, (e) => { if (ours(e)) e.stopPropagation(); }, true));
    document.addEventListener('keydown', (e) => { if (e.key === 'Enter' && ours(e)) e.stopPropagation(); }, true);
    document.addEventListener('click', (e) => {
        const t = ours(e);
        if (!t) return;
        e.preventDefault();
        e.stopPropagation();
        place(t);
        window.dispatchEvent(new CustomEvent('stacy-toggle'));
    }, true);
    const settle = () => requestAnimationFrame(() => place(anchor && visible(anchor) ? anchor : null));
    window.addEventListener('resize', settle);
    document.addEventListener('livewire:navigated', () => { anchor = null; settle(); });
    document.addEventListener('DOMContentLoaded', settle);
    if (document.readyState !== 'loading') settle();
    return {
        // From the orb: reflect open, listening and thinking on the buttons
        state(open, state) {
            if (open) settle();
            triggers().forEach((t) => {
                t.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (state && state !== 'idle') t.dataset.stacyState = state; else delete t.dataset.stacyState;
            });
        },
    };
})();

/*
 * A sub-menu in the Dashboard's sidebar. Open: the chevron folds it, and this
 * browser remembers (the section you're in always starts open). Folded to the
 * rail: hovering or tabbing to the item flies the sub-menu out beside it.
 */
window.bookletSideSub = (key, here) => ({
    open: here,
    flying: false,
    timer: null,
    init() {
        if (here) return;
        try { this.open = localStorage.getItem('booklet.side.' + key) === 'open'; } catch (e) { /* storage off: closed */ }
    },
    toggle() {
        this.open = ! this.open;
        try { localStorage.setItem('booklet.side.' + key, this.open ? 'open' : 'shut'); } catch (e) { /* fine */ }
    },
    railed() { return document.body.classList.contains('desk-narrow') && window.matchMedia('(min-width: 1081px)').matches; },
    fly(el) {
        if (! this.railed()) return;
        clearTimeout(this.timer);
        const r = el.getBoundingClientRect();
        el.style.setProperty('--fly-top', Math.round(r.top) + 'px');
        this.flying = true;
        // A long one near the bottom moves up to stay in the window.
        this.$nextTick(() => {
            const sub = el.querySelector('.sidebar__sub');
            if (!sub) return;
            const over = sub.getBoundingClientRect().bottom - (window.innerHeight - 8);
            if (over > 0) el.style.setProperty('--fly-top', Math.max(8, Math.round(r.top - over)) + 'px');
        });
    },
    land() { clearTimeout(this.timer); this.timer = setTimeout(() => { this.flying = false; }, 120); },
});

/*
 * Drag rows into a new order (Your menu). Press the grip and move: the row
 * follows the pointer (mouse, pen or finger) and the others make room; let go
 * and the new order is saved with $wire.reorder(keys). Escape puts it back.
 * The up and down buttons stay for the keyboard.
 */
window.bookletDragList = ($wire) => ({
    dragging: null,
    grab(e, row) {
        if (!row || (e.button !== undefined && e.button !== 0)) return;
        e.preventDefault();
        const list = this.$root;
        const start = [...list.querySelectorAll('[data-key]')].map((r) => r.dataset.key);
        const from = row.getBoundingClientRect();
        const offset = e.clientY - from.top;
        const ghost = row.cloneNode(true);
        ghost.classList.add('menu-edit__ghost');
        ghost.style.width = from.width + 'px';
        ghost.style.left = from.left + 'px';
        ghost.style.top = from.top + 'px';
        document.body.appendChild(ghost);
        row.classList.add('is-dragging');
        document.body.classList.add('is-drag-sorting');
        this.dragging = row;

        const scroller = list.closest('.book__pages') || document.scrollingElement;
        let raf = null, lastY = e.clientY;
        const place = () => {
            raf = null;
            ghost.style.top = (lastY - offset) + 'px';
            const rows = [...list.querySelectorAll('[data-key]')].filter((r) => r !== row);
            const next = rows.find((r) => { const b = r.getBoundingClientRect(); return lastY < b.top + b.height / 2; });
            if (next) { if (next.previousElementSibling !== row) list.insertBefore(row, next); }
            else if (list.lastElementChild !== row) list.appendChild(row);
            // Near the top or bottom of the page: scroll along.
            const view = scroller === document.scrollingElement ? { top: 0, bottom: innerHeight } : scroller.getBoundingClientRect();
            if (lastY < view.top + 60) scroller.scrollTop -= 12;
            else if (lastY > view.bottom - 60) scroller.scrollTop += 12;
        };
        const move = (ev) => { lastY = ev.clientY; if (!raf) raf = requestAnimationFrame(place); };
        const finish = (keep) => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            window.removeEventListener('pointercancel', cancel);
            window.removeEventListener('keydown', esc);
            if (raf) cancelAnimationFrame(raf);
            ghost.remove();
            row.classList.remove('is-dragging');
            document.body.classList.remove('is-drag-sorting');
            this.dragging = null;
            const now = [...list.querySelectorAll('[data-key]')].map((r) => r.dataset.key);
            if (!keep) {
                start.forEach((k) => list.appendChild(list.querySelector(`[data-key="${CSS.escape(k)}"]`)));
                return;
            }
            if (now.join() !== start.join()) $wire.reorder(now);
        };
        const up = () => finish(true);
        const cancel = () => finish(false);
        const esc = (ev) => { if (ev.key === 'Escape') finish(false); };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
        window.addEventListener('pointercancel', cancel);
        window.addEventListener('keydown', esc);
    },
});

/*
 * Turning a list's pages from the keyboard: ← and → press the pager's
 * Previous and Next, when you're not typing, choosing or in a window.
 * After a turn, the top of the list comes back into view.
 */
(() => {
    if (window.bookletPagerKeys) return;
    window.bookletPagerKeys = true;
    const visible = (el) => !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
    document.addEventListener('keydown', (e) => {
        if (e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) return;
        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
        const t = e.target;
        if (t instanceof Element && t.closest('input, textarea, select, button[role="slider"], [contenteditable=""], [contenteditable="true"], [role="slider"], [role="dialog"], [role="menu"], .crumb-switch')) return;
        if ([...document.querySelectorAll('[aria-modal="true"], dialog[open]')].some(visible)) return;
        const pager = [...document.querySelectorAll('[data-pager]')].find(visible);
        const button = pager?.querySelector(e.key === 'ArrowLeft' ? '[data-pager-prev]' : '[data-pager-next]');
        if (!button || button.disabled) return;
        e.preventDefault();
        button.click();
    });
    window.addEventListener('pager-turned', () => {
        requestAnimationFrame(() => {
            const pager = [...document.querySelectorAll('[data-pager]')].find(visible);
            if (!pager) return;
            const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';
            // A book's page scrolls inside itself: back to the top of that page.
            for (let el = pager.parentElement; el && el !== document.body; el = el.parentElement) {
                const y = getComputedStyle(el).overflowY;
                if ((y === 'auto' || y === 'scroll') && el.scrollHeight > el.clientHeight + 4) {
                    if (el.scrollTop > 0) el.scrollTo({ top: 0, behavior });
                    return;
                }
            }
            // Otherwise the window: the list's card comes back into view.
            const top = pager.closest('.desk-card, .dash-card, .card, section, main');
            const box = top?.getBoundingClientRect();
            if (box && (box.top < 0 || box.top > window.innerHeight)) {
                top.scrollIntoView({ block: 'start', behavior });
            }
        });
    });
})();

/*
 * The Dashboard layout lifts a two-page screen's header above both pages.
 * How tall it is depends on its words, so the room left for it is measured
 * (and measured again when it wraps or changes) instead of guessed.
 */
(() => {
    let watcher = null;
    const fit = () => {
        const pages = document.querySelector('body.layout-dashboard .book__pages');
        watcher?.disconnect();
        if (!pages) return;
        const head = pages.querySelector(':scope > .page .page-head, :scope > * > .page .page-head');
        if (!head || getComputedStyle(head).position !== 'absolute') { pages.style.removeProperty('--desk-head'); return; }
        const measure = () => pages.style.setProperty('--desk-head', `${Math.ceil(head.getBoundingClientRect().height)}px`);
        measure();
        if ('ResizeObserver' in window) { watcher = new ResizeObserver(measure); watcher.observe(head); }
    };
    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', fit) : fit();
    document.addEventListener('livewire:navigated', fit);
    window.addEventListener('resize', fit);
})();
