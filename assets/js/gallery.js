/* Ordinary image links remain usable when JS or dialog support is unavailable. */
(() => {
  for (const gallery of document.querySelectorAll('[data-pb-gallery]')) {
    const items = [...gallery.querySelectorAll('[data-pb-gallery-item]')];
    const dialog = document.createElement('dialog');
    if (!items.length || typeof dialog.showModal !== 'function') continue;
    dialog.className = 'pb-gallery-dialog';
    dialog.setAttribute('aria-label', gallery.dataset.pbGalleryDialog || 'Image');
    dialog.setAttribute('aria-modal', 'true');
    const image = document.createElement('img');
    const caption = document.createElement('p');
    caption.setAttribute('role', 'status');
    const controls = document.createElement('div');
    controls.className = 'pb-gallery-controls';
    const button = label => {
      const node = document.createElement('button');
      node.type = 'button'; node.textContent = label;
      controls.append(node);
      return node;
    };
    const close = button(gallery.dataset.pbGalleryClose || 'Close');
    const previous = button(gallery.dataset.pbGalleryPrevious || 'Previous');
    const next = button(gallery.dataset.pbGalleryNext || 'Next');
    if (items.length === 1) { previous.hidden = true; next.hidden = true; }
    dialog.append(image, caption, controls);
    gallery.append(dialog);
    let index = 0;
    let opener = null;
    const show = position => {
      index = (position + items.length) % items.length;
      const item = items[index];
      const alt = item.querySelector('img')?.alt || '';
      image.src = item.href;
      image.alt = alt;
      const count = (gallery.dataset.pbGalleryPosition || ':current / :total')
        .replace(':current', String(index + 1)).replace(':total', String(items.length));
      caption.textContent = alt ? `${count} — ${alt}` : count;
    };
    items.forEach((item, position) => {
      item.setAttribute('aria-haspopup', 'dialog');
      item.addEventListener('click', event => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        opener = item;
        show(position);
        try { dialog.showModal(); } catch { return; }
        event.preventDefault();
        close.focus();
      });
    });
    close.addEventListener('click', () => dialog.close());
    previous.addEventListener('click', () => show(index - 1));
    next.addEventListener('click', () => show(index + 1));
    dialog.addEventListener('keydown', event => {
      if (event.key === 'Tab') {
        const focusable = [close, previous, next].filter(control => !control.hidden && !control.disabled);
        const first = focusable[0], last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        return;
      }
      if (items.length < 2) return;
      if (event.key === 'ArrowLeft') { event.preventDefault(); show(index - 1); }
      else if (event.key === 'ArrowRight') { event.preventDefault(); show(index + 1); }
    });
    dialog.addEventListener('close', () => { if (opener?.isConnected) opener.focus(); });
  }
})();
