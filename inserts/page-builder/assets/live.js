/*
 * Page builder: the live page inside the editor. Click a section to select
 * it, click words to type into them (sent to the editor as you go), click a
 * picture to choose another. Links and forms don't go anywhere here.
 */
(() => {
    const root = document.querySelector('.pb-live');
    if (!root || window.parent === window) return;

    const send = (msg) => window.parent.postMessage(Object.assign({ pb: 'page-builder' }, msg), location.origin);
    let selected = null;

    const sectionOf = (el) => el && el.closest('[data-pb-section]');
    const select = (id, tell = true, scroll = false) => {
        root.querySelectorAll('[data-pb-section].is-selected').forEach((s) => s.classList.remove('is-selected'));
        const el = id ? root.querySelector('[data-pb-section="' + CSS.escape(id) + '"]') : null;
        if (el) {
            el.classList.add('is-selected');
            if (scroll) {
                const r = el.getBoundingClientRect();
                if (r.top < 0 || r.top > innerHeight * 0.6) el.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
            }
        }
        selected = el ? id : null;
        if (tell && el) send({ type: 'select', id });
    };

    // Put the caret where the click was.
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

    const edit = (field, id, x, y) => {
        if (field.isContentEditable) return;
        field.contentEditable = 'plaintext-only';
        if (field.contentEditable !== 'plaintext-only') field.contentEditable = 'true';
        field.classList.add('is-editing');
        field.spellcheck = true;
        field.focus({ preventScroll: true });
        if (x !== undefined) caretAt(x, y);

        const line = field.dataset.pbKind !== 'words';
        let timer = null;
        let sent = textOf(field);
        const push = () => {
            clearTimeout(timer);
            const value = textOf(field);
            if (value === sent) return;
            sent = value;
            send({ type: 'text', id, field: field.dataset.pbField, value });
        };
        const onInput = () => {
            field.classList.toggle('pb-empty', textOf(field).trim() === '');
            if (field.textContent === '') field.innerHTML = ''; // :empty shows what it's for again
            clearTimeout(timer);
            timer = setTimeout(push, 700);
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
            field.contentEditable = 'false';
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

    root.addEventListener('click', (e) => {
        const section = sectionOf(e.target);
        if (!section) return;
        const id = section.dataset.pbSection;
        const link = e.target.closest('a');
        if (link) e.preventDefault();
        if (e.target.closest('summary')) e.preventDefault(); // questions stay open while editing

        const pick = e.target.closest('[data-pb-pick]');
        if (pick) {
            e.preventDefault();
            select(id, false);
            send({ type: 'pick', id, field: pick.dataset.pbPick });
            return;
        }
        if (selected !== id) select(id);
        const field = e.target.closest('[data-pb-field]');
        if (field) edit(field, id, e.clientX, e.clientY);
    }, true);

    // Keyboard: Tab to a section's words, Enter to type.
    root.querySelectorAll('[data-pb-field]').forEach((f) => { f.tabIndex = 0; });
    root.addEventListener('keydown', (e) => {
        const field = e.target.closest && e.target.closest('[data-pb-field]');
        if (field && !field.isContentEditable && e.key === 'Enter') {
            e.preventDefault();
            const id = sectionOf(field).dataset.pbSection;
            if (selected !== id) select(id);
            edit(field, id);
            const r = document.createRange(); r.selectNodeContents(field); r.collapse(false);
            const sel = getSelection(); sel.removeAllRanges(); sel.addRange(r);
        }
    });

    document.addEventListener('submit', (e) => { e.preventDefault(); e.stopPropagation(); }, true);

    // From the editor: which section is chosen.
    window.addEventListener('message', (e) => {
        if (e.origin !== location.origin || e.source !== window.parent || !e.data || e.data.pb !== 'page-builder') return;
        if (e.data.type === 'select') select(e.data.id, false, !!e.data.scroll);
    });

    send({ type: 'ready' });
})();
