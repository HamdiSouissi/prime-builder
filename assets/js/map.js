/* Keep OpenStreetMap entirely dormant until the visitor activates the map. */
(() => {
  for (const map of document.querySelectorAll('[data-pb-map]')) {
    const button = map.querySelector('[data-pb-map-load]');
    if (!button) continue;
    let source;
    try { source = new URL(map.dataset.pbMapSrc); } catch { continue; }
    if (source.origin !== 'https://www.openstreetmap.org' || source.pathname !== '/export/embed.html') continue;
    button.hidden = false;
    button.addEventListener('click', () => {
      const frame = document.createElement('iframe');
      frame.title = map.dataset.pbMapTitle || 'OpenStreetMap';
      frame.loading = 'lazy';
      frame.referrerPolicy = 'no-referrer';
      frame.src = source.href;
      button.replaceWith(frame);
      frame.focus();
    }, { once: true });
  }
})();
