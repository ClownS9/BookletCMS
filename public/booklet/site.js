/*
 * Booklet public site: small touches, no framework.
 * Light/dark switch, phone menu, header on scroll, reading progress,
 * back to top, and gentle reveal as things scroll into view.
 */
(() => {
    const root = document.documentElement;
    const calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Light / dark ------------------------------------------------------------

    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        const sync = () => button.setAttribute('aria-pressed', String(root.dataset.theme === 'dark'));
        sync();
        button.addEventListener('click', () => {
            root.classList.add('theme-switching');
            root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
            try { localStorage.setItem('booklet.theme', root.dataset.theme); } catch (e) {}
            sync();
            setTimeout(() => root.classList.remove('theme-switching'), 400);
        });
    });

    // Follow the system when the reader never chose.
    const scheme = window.matchMedia('(prefers-color-scheme: dark)');
    scheme.addEventListener?.('change', (e) => {
        let chosen = null;
        try { chosen = localStorage.getItem('booklet.theme'); } catch (err) {}
        if (!chosen) root.dataset.theme = e.matches ? 'dark' : 'light';
    });

    // Phone menu ------------------------------------------------------------

    const toggle = document.querySelector('[data-menu-toggle]');
    const menu = document.getElementById('site-menu');

    const setMenu = (open) => {
        if (!toggle) return;
        if (!open) document.querySelectorAll('.menu__item.is-open').forEach((li) => {
            li.classList.remove('is-open');
            li.querySelector('[data-menu-sub]')?.setAttribute('aria-expanded', 'false');
        });
        toggle.setAttribute('aria-expanded', String(open));
        const head = document.querySelector('[data-masthead]');
        if (open && head) root.style.setProperty('--masthead-h', Math.round(head.getBoundingClientRect().bottom) + 'px');
        document.body.classList.toggle('menu-open', open);
        if (open) menu?.querySelector('a')?.focus({ preventScroll: true });
    };

    toggle?.addEventListener('click', () => setMenu(toggle.getAttribute('aria-expanded') !== 'true'));
    menu?.addEventListener('click', (e) => e.target.closest('a') && setMenu(false));
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && document.body.classList.contains('menu-open')) {
            setMenu(false);
            toggle.focus();
        }
    });
    window.matchMedia('(min-width: 761px)').addEventListener?.('change', (e) => e.matches && setMenu(false));

    // Drop-downs: the caret opens one (and closes the others); a click
    // elsewhere or Escape closes it, and focus goes back to its caret.

    const carets = [...document.querySelectorAll('[data-menu-sub]')];
    const setSub = (caret, open) => {
        const item = caret.parentElement;
        caret.setAttribute('aria-expanded', String(open));
        item.classList.toggle('is-open', open);
        // Closed on purpose while the pointer is over it: keep it shut until the pointer leaves.
        item.classList.toggle('is-shut', !open && item.matches(':hover'));
    };
    carets.forEach((caret) => caret.parentElement.addEventListener('mouseleave', () => caret.parentElement.classList.remove('is-shut')));
    const closeSubs = (except) => carets.forEach((c) => c !== except && setSub(c, false));

    carets.forEach((caret) => {
        caret.addEventListener('click', (e) => {
            e.stopPropagation();
            const open = caret.getAttribute('aria-expanded') !== 'true';
            closeSubs(caret);
            setSub(caret, open);
        });
        // Leaving a drop-down with Tab closes it (wide screens only; on phones it's an accordion).
        caret.parentElement.addEventListener('focusout', (e) => {
            if (window.matchMedia('(min-width: 761px)').matches && !caret.parentElement.contains(e.relatedTarget)) setSub(caret, false);
        });
    });
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.menu__item')) closeSubs();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        const open = carets.find((c) => c.getAttribute('aria-expanded') === 'true');
        if (open) {
            setSub(open, false);
            open.focus();
            e.stopImmediatePropagation();
        }
    }, true);

    // Proof copies: pick out words on the page, then leave a note about them --

    const notes = document.querySelector('[data-proof-notes]');
    const pick = document.querySelector('[data-proof-pick]');
    if (notes && pick) {
        const quoteBox = notes.querySelector('[data-proof-quote]');
        const quoteText = notes.querySelector('[data-proof-quote-text]');
        const quoteInput = notes.querySelector('[data-proof-quote-input]');
        const page = document.getElementById('main');
        let picked = '';

        const openNotes = (focus) => {
            notes.open = true;
            const field = focus || (notes.querySelector('#proof-name').value ? notes.querySelector('#proof-body') : notes.querySelector('#proof-name'));
            setTimeout(() => field?.focus({ preventScroll: false }), 30);
        };
        const setQuote = (text) => {
            quoteText.textContent = text;
            quoteInput.value = text;
            quoteBox.hidden = text === '';
        };

        const offerPick = () => {
            const sel = window.getSelection();
            const text = sel && !sel.isCollapsed ? sel.toString().replace(/\s+/g, ' ').trim() : '';
            if (text.length < 2 || !page || !page.contains(sel.anchorNode)) { pick.hidden = true; return; }
            picked = text.length > 300 ? text.slice(0, 297) + '…' : text;
            const box = sel.getRangeAt(0).getBoundingClientRect();
            pick.style.top = Math.max(8, box.top - 44) + 'px';
            pick.style.left = Math.min(window.innerWidth - 140, Math.max(8, box.left + box.width / 2 - 60)) + 'px';
            pick.hidden = false;
        };
        document.addEventListener('mouseup', () => setTimeout(offerPick, 10));
        document.addEventListener('keyup', (e) => e.shiftKey && offerPick());
        // Phones select by touch (no mouseup): offer the button once the selection settles.
        let settle;
        document.addEventListener('selectionchange', () => {
            clearTimeout(settle);
            if (window.getSelection()?.isCollapsed) { pick.hidden = true; return; }
            settle = setTimeout(offerPick, 450);
        });
        window.addEventListener('scroll', () => { pick.hidden = true; }, { passive: true });

        pick.addEventListener('mousedown', (e) => e.preventDefault()); // keep the selection
        pick.addEventListener('click', () => {
            setQuote(picked);
            pick.hidden = true;
            openNotes();
        });
        notes.querySelector('[data-proof-clear]')?.addEventListener('click', () => setQuote(''));
        document.querySelectorAll('[data-proof-open]').forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); openNotes(); }));
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && notes.open && notes.contains(document.activeElement)) { notes.open = false; } });
    }

    // Header, progress and back-to-top, all from one scroll handler ----------

    const masthead = document.querySelector('[data-masthead]');
    const bar = document.querySelector('.progress span');
    const reading = document.querySelector('[data-reading]');
    const toTop = document.querySelector('[data-to-top]');
    let ticking = false;

    if (reading) root.classList.add('has-progress');

    // For Visits in the admin: a read-through counts once per page view. No cookies.
    const openedAt = Date.now();
    let sentRead = false;
    const sendRead = () => {
        sentRead = true;
        if (navigator.doNotTrack === '1' || root.hasAttribute('data-proof') || root.hasAttribute('data-static')) return; // proof copies and plain-file copies aren't counted
        const body = new Blob([JSON.stringify({ path: location.pathname })], { type: 'application/json' });
        try { navigator.sendBeacon?.('/read', body) || fetch('/read', { method: 'POST', body, keepalive: true, headers: { 'Content-Type': 'application/json' } }); } catch (e) { /* not counted */ }
    };

    const onScroll = () => {
        const y = window.scrollY;

        masthead?.classList.toggle('is-scrolled', y > 8);
        toTop?.classList.toggle('is-shown', y > window.innerHeight * 1.2);

        if (reading && bar) {
            const box = reading.getBoundingClientRect();
            const total = box.height - window.innerHeight * 0.6;
            const done = Math.min(1, Math.max(0, (window.innerHeight * 0.4 - box.top) / Math.max(1, total)));
            bar.style.transform = `scaleX(${done.toFixed(3)})`;

            // Read most of the way (and spent a little while): tell the stats, once.
            if (!sentRead && done > 0.75 && Date.now() - openedAt > 15000) sendRead();
        }

        ticking = false;
    };

    window.addEventListener('scroll', () => {
        if (!ticking) { ticking = true; requestAnimationFrame(onScroll); }
    }, { passive: true });
    onScroll();

    toTop?.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: calm ? 'auto' : 'smooth' });
        document.getElementById('main')?.focus?.({ preventScroll: true });
    });

    // Reveal on scroll --------------------------------------------------------

    const targets = document.querySelectorAll(
        '.card, .feature, .index > li, .results > li, .turn a, .author-card, .margin-notes, .plain-list > li, .note, .post-cover, .prose img, .prose blockquote, .leave-note, .people li, .say-hello'
    );

    if (calm || !('IntersectionObserver' in window)) return;

    const seen = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            entry.target.classList.add('is-in');
            seen.unobserve(entry.target);
        });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });

    targets.forEach((el) => {
        // Things already on screen stay put; only what's below the fold waits.
        if (el.getBoundingClientRect().top < window.innerHeight * 0.92) return;

        const siblings = el.parentElement ? Array.from(el.parentElement.children) : [];
        el.style.setProperty('--reveal-delay', `${Math.min(siblings.indexOf(el) % 4, 3) * 70}ms`);
        el.classList.add('reveal');
        seen.observe(el);
    });
})();

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
