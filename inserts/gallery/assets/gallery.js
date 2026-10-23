/* Gallery insert: open album pictures large, step through with arrows, Esc to close. */
(() => {
    let dialog, img, text, list = [], at = 0, opener = null;

    const build = () => {
        dialog = document.createElement('dialog');
        dialog.className = 'lightbox';
        dialog.setAttribute('aria-label', 'Picture');
        dialog.innerHTML = '<div class="lightbox__frame"><img alt=""><p class="lightbox__text" aria-live="polite"></p></div>'
            + '<button type="button" class="lightbox__prev" aria-label="Previous picture">‹</button>'
            + '<button type="button" class="lightbox__next" aria-label="Next picture">›</button>'
            + '<button type="button" class="lightbox__close" aria-label="Close">×</button>';
        document.body.appendChild(dialog);
        img = dialog.querySelector('img');
        text = dialog.querySelector('.lightbox__text');
        dialog.querySelector('.lightbox__close').addEventListener('click', () => dialog.close());
        dialog.querySelector('.lightbox__prev').addEventListener('click', () => show(at - 1));
        dialog.querySelector('.lightbox__next').addEventListener('click', () => show(at + 1));
        dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
        dialog.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') { e.preventDefault(); show(at - 1); }
            if (e.key === 'ArrowRight') { e.preventDefault(); show(at + 1); }
        });
        dialog.addEventListener('close', () => opener?.focus());
    };

    const show = (i) => {
        at = (i + list.length) % list.length;
        const link = list[at];
        img.src = link.dataset.full;
        img.alt = link.dataset.alt || '';
        text.textContent = (link.dataset.alt ? link.dataset.alt + ' · ' : '') + (at + 1) + ' of ' + list.length;
        const many = list.length > 1;
        dialog.querySelector('.lightbox__prev').hidden = !many;
        dialog.querySelector('.lightbox__next').hidden = !many;
    };

    document.addEventListener('click', (e) => {
        const link = e.target.closest?.('[data-gallery] .gallery__open');
        if (!link || e.metaKey || e.ctrlKey || e.shiftKey) return;
        e.preventDefault();
        if (!dialog) build();
        list = [...link.closest('[data-gallery]').querySelectorAll('.gallery__open')];
        opener = link;
        show(list.indexOf(link));
        dialog.showModal();
    });
})();
