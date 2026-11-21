/* Progressive enhancement: content is rendered by the server and fetched once per document. */
(() => {
  const requests = new Map();
  for (const layout of document.querySelectorAll('.pb-layout[data-pb-live]')) {
    const url = layout.dataset.pbLive;
    if (!/^\/api\/prime-builder\/v1\/documents\/[1-9]\d*\/live$/.test(url || '')) continue;
    if (!requests.has(url)) {
      requests.set(url, fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
        .then(response => response.ok ? response.json() : null).catch(() => null));
    }
    requests.get(url).then(result => {
      const widgets = result?.data?.widgets;
      if (!widgets || typeof widgets !== 'object') return;
      for (const node of layout.querySelectorAll('[data-pb-id]')) {
        if (!node.classList.contains('pb-posts') && !node.classList.contains('pb-current_content')) continue;
        const html = widgets[node.dataset.pbId];
        if (typeof html === 'string') node.innerHTML = html;
      }
    });
  }
})();
