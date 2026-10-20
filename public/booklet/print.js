/* Booklet: print as a book.
 *
 * The server sends every chosen entry as one long column (the "source"). This
 * pours it into real pages of the chosen size, the way a typesetter would:
 * paragraphs break between lines (never leaving a single line alone at the
 * top or bottom of a page), headings stay with what follows, lists and
 * quotes carry on to the next page, pictures move over whole, and each entry
 * starts on a fresh page (a right-hand one, if asked). Then come the running
 * heads, page numbers and the contents' page numbers, and the pages are
 * laid out as spreads to look through before printing.
 */
(() => {
    const BLOCKS = /^(P|UL|OL|LI|BLOCKQUOTE|PRE|TABLE|FIGURE|H[1-6]|DIV|HR|DL|SECTION|HEADER)$/;
    const SPLITS = /^(P|UL|OL|LI|BLOCKQUOTE|PRE|DIV|DL)$/;

    const pages = [];
    let stage;
    let current = null;
    let lineHeight = 18;

    const fits = (node) => node.getBoundingClientRect().bottom <= current.body.getBoundingClientRect().bottom + 0.5;

    function makePage(kind, chapter = null) {
        const el = document.createElement('div');
        el.className = 'sheet sheet--' + kind;
        el.innerHTML = '<div class="sheet__head"></div><div class="sheet__body"></div><div class="sheet__foot"></div>';
        stage.appendChild(el);
        const page = { el, body: el.querySelector('.sheet__body'), kind, chapter };
        pages.push(page);
        el.classList.add(pages.length % 2 ? 'recto' : 'verso');
        if (kind === 'opening' || kind === 'flow') page.body.classList.add('prose');
        current = page;
        return page;
    }

    const isFigure = (node) => node.tagName === 'FIGURE'
        || (node.tagName === 'P' && node.querySelector('img') && node.textContent.trim() === '');

    const canSplit = (node) => SPLITS.test(node.tagName) && !isFigure(node) && !node.closest('.toc');

    const hasBlocks = (node) => [...node.children].some((c) => BLOCKS.test(c.tagName));

    /* Put one block on the current page, carrying whatever doesn't fit over. */
    function place(node) {
        const body = current.body;

        // A heading needs a couple of lines of what follows under it, or it moves over.
        if (/^H[2-6]$/.test(node.tagName) && body.lastElementChild) {
            body.appendChild(node);
            const room = body.getBoundingClientRect().bottom - node.getBoundingClientRect().bottom;
            if (room < lineHeight * 2.6) {
                node.remove();
                makePage('flow', current.chapter);
                current.body.appendChild(node);
            }
            return;
        }

        body.appendChild(node);
        if (fits(node)) return;

        const alone = body.firstElementChild === node;
        const rest = canSplit(node) ? split(node) : null;

        if (rest) {
            node.classList.add('split');
            makePage('flow', current.chapter);
            place(rest);
            return;
        }
        if (alone) {
            // Taller than a whole page and can't be broken: let it shrink to fit.
            node.classList.add('oversize');
            makePage('flow', current.chapter);
            return;
        }
        node.remove();
        makePage('flow', current.chapter);
        place(node);
    }

    /* Break a block where the page ends. Returns what's left over, or null to move it whole. */
    function split(node) {
        return hasBlocks(node) ? splitBlocks(node) : splitLines(node);
    }

    function splitBlocks(node) {
        const kids = [...node.children];
        let k = kids.findIndex((c) => !fits(c));
        if (k < 0) return null;

        const rest = node.cloneNode(false);
        rest.removeAttribute('id');
        rest.classList.add('cont');

        const restChild = canSplit(kids[k]) ? split(kids[k]) : null;
        if (restChild) {
            kids[k].classList.add('split');
            rest.appendChild(restChild);
            k += 1;
        } else if (k === 0) {
            return null;
        }
        kids.slice(k).forEach((c) => rest.appendChild(c));

        if (node.tagName === 'OL') {
            // Keep the numbering going; a list item carried over doesn't get a second number.
            const kept = [...node.children].filter((c) => c.tagName === 'LI').length;
            rest.start = (node.start || 1) + kept - (restChild ? 1 : 0);
        }
        if (restChild && restChild.tagName === 'LI') restChild.classList.add('cont');

        return rest;
    }

    function splitLines(el) {
        const limit = current.body.getBoundingClientRect().bottom + 0.5;
        const words = [];
        const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
        const range = document.createRange();
        let text;
        while ((text = walker.nextNode())) {
            const re = /\S+/g;
            let m;
            while ((m = re.exec(text.data))) {
                range.setStart(text, m.index);
                range.setEnd(text, m.index + m[0].length);
                const rects = range.getClientRects();
                if (!rects.length) continue;
                words.push({ node: text, offset: m.index, top: rects[0].top, bottom: rects[rects.length - 1].bottom });
            }
        }

        let cut = words.findIndex((w) => w.bottom > limit);
        if (cut <= 0) return null;

        // Lines, by where their words sit.
        const tops = [];
        words.forEach((w) => { if (!tops.length || w.top > tops[tops.length - 1] + 2) tops.push(w.top); });
        const lineOf = (w) => tops.filter((t) => t <= w.top + 2).length - 1;
        const cutLine = lineOf(words[cut]);

        if (cutLine < 2) return null; // only one line would stay behind (an orphan): move it all
        if (tops.length - cutLine < 2 && cutLine >= 3) {
            // only one line would go over (a widow): take one more with it
            const top = tops[cutLine - 1];
            cut = words.findIndex((w) => w.top >= top - 1);
        }

        const after = document.createRange();
        after.setStart(words[cut].node, words[cut].offset);
        after.setEnd(el, el.childNodes.length);
        const rest = el.cloneNode(false);
        rest.removeAttribute('id');
        rest.classList.add('cont');
        rest.appendChild(after.extractContents());

        return rest;
    }

    /* Make sure the type and pictures are in before measuring anything. */
    async function ready(source) {
        const faces = ['400 16px Newsreader', '500 16px Newsreader', 'italic 400 16px Newsreader', '400 16px "Instrument Sans"', '500 16px "Instrument Sans"'];
        const fonts = Promise.all(faces.map((f) => document.fonts?.load(f).catch(() => null))).then(() => document.fonts?.ready);
        const pictures = Promise.all([...source.querySelectorAll('img')].map((img) => {
            img.loading = 'eager';
            return img.complete ? null : new Promise((done) => { img.addEventListener('load', done, { once: true }); img.addEventListener('error', done, { once: true }); });
        }));
        const timeout = new Promise((done) => setTimeout(done, 12000));
        await Promise.race([Promise.all([fonts, pictures]), timeout]);
    }

    const pause = () => new Promise((done) => requestAnimationFrame(() => done()));

    async function typeset() {
        const source = document.querySelector('[data-source]');
        const status = document.querySelector('[data-status]');
        const printBtn = document.querySelector('[data-print]');
        const spreads = document.querySelector('[data-pages]');
        const recto = document.body.dataset.recto === '1';
        const bookTitle = document.body.dataset.title || '';

        stage = spreads;
        await ready(source);

        const opened = {};
        for (const section of [...source.children]) {
            const kind = section.dataset.kind;

            if (kind === 'title' || kind === 'colophon') {
                makePage(kind);
                [...section.children].forEach((c) => current.body.appendChild(c));
                continue;
            }

            if (kind === 'contents') {
                if (pages.length % 2 === 1) makePage('blank'); // the contents start on a right-hand page
                makePage('front', 'Contents');
                lineHeight = parseFloat(getComputedStyle(current.body).lineHeight) || lineHeight;
                [...section.children].forEach((c) => place(c));
                continue;
            }

            if (kind === 'chapter') {
                if (recto && pages.length % 2 === 1) makePage('blank');
                makePage('opening', section.dataset.title || '');
                lineHeight = parseFloat(getComputedStyle(current.body).lineHeight) || lineHeight;
                opened[section.id] = pages.length;

                const prose = section.querySelector('.prose');
                prose?.querySelector(':scope > p:not(:empty)')?.classList.add('lead');
                for (const part of [...section.children]) {
                    if (part === prose) [...prose.children].forEach((c) => place(c));
                    else place(part);
                }

                status.textContent = `Setting the type… ${pages.length} pages`;
                if (pages.length % 12 === 0) await pause();
            }
        }

        // Running heads and page numbers.
        pages.forEach((page, i) => {
            const n = i + 1;
            const quiet = ['title', 'colophon', 'blank'].includes(page.kind);
            if (!quiet) page.el.querySelector('.sheet__foot').textContent = n;
            if (!quiet && page.kind !== 'opening' && !(page.kind === 'front' && pages[i - 1]?.kind !== 'front')) {
                page.el.querySelector('.sheet__head').textContent = n % 2 ? (page.chapter || bookTitle) : bookTitle;
            }
        });

        // The contents' page numbers.
        document.querySelectorAll('.sheet [data-target]').forEach((li) => {
            const pg = li.querySelector('[data-pg]');
            if (pg) pg.textContent = opened[li.dataset.target] ?? '';
        });

        // Facing pages: the first one sits on the right, as in a real book.
        const sheets = pages.map((p) => p.el);
        const pairs = [[null, sheets[0]]];
        for (let i = 1; i < sheets.length; i += 2) pairs.push([sheets[i], sheets[i + 1] ?? null]);
        pairs.forEach(([left, right]) => {
            const spread = document.createElement('div');
            spread.className = 'spread';
            [left, right].forEach((sheet) => {
                if (sheet) spread.appendChild(sheet);
                else {
                    const empty = document.createElement('div');
                    empty.className = 'sheet sheet--none';
                    spread.appendChild(empty);
                }
            });
            spreads.appendChild(spread);
        });

        source.remove();
        fit();
        window.addEventListener('resize', fit);

        const size = document.body.className.match(/size-(\w+)/)?.[1];
        status.textContent = `${pages.length} pages · ${{ a5: 'A5', trade: '6 × 9 in', a4: 'A4' }[size] || ''}`;
        printBtn.disabled = false;
        printBtn.addEventListener('click', () => window.print());
        document.body.classList.add('is-set');
    }

    /* Scale the spreads to the window (the pages themselves stay their real size for printing). */
    function fit() {
        const spreads = document.querySelector('[data-pages]');
        const sheet = spreads.querySelector('.sheet');
        if (!sheet) return;
        spreads.style.zoom = 1;
        const w = sheet.offsetWidth;
        const room = document.documentElement.clientWidth - 32;
        const oneUp = room < w * 1.6;
        document.body.classList.toggle('one-up', oneUp);
        spreads.style.zoom = Math.min(1, room / (oneUp ? w : w * 2 + 2)).toFixed(3);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', typeset);
    else typeset();
})();
