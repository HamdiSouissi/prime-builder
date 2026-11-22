/* Progressive enhancement: content is rendered by the server and fetched once per document. */
(() => {
  // The same core zone may already occur elsewhere in the theme or in several widgets.
  // Namespace local IDs and their references in each inserted copy (labels, ARIA and links).
  const zoneFragment = (html, widgetId) => {
    const template = document.createElement('template');
    template.innerHTML = html;
    const ids = new Map();
    const used = new Set();
    for (const element of template.content.querySelectorAll('[id]')) {
      const old = element.id;
      if (!old) continue;
      const base = `pb-zone-${widgetId}-${old}`;
      let next = base, suffix = 2;
      while (used.has(next)) next = `${base}-${suffix++}`;
      used.add(next);
      if (!ids.has(old)) ids.set(old, next);
      element.id = next;
    }
    const idLists = ['for', 'list', 'form', 'headers', 'aria-labelledby', 'aria-describedby', 'aria-controls', 'aria-owns', 'aria-activedescendant', 'aria-details', 'aria-errormessage'];
    for (const element of template.content.querySelectorAll('*')) {
      for (const attribute of idLists) {
        const value = element.getAttribute(attribute);
        if (!value) continue;
        element.setAttribute(attribute, value.split(/\s+/).map(id => ids.get(id) || id).join(' '));
      }
      for (const attribute of ['href', 'xlink:href']) {
        const value = element.getAttribute(attribute);
        if (value?.startsWith('#') && ids.has(value.slice(1))) element.setAttribute(attribute, `#${ids.get(value.slice(1))}`);
      }
    }
    return template.content;
  };
  const requests = new Map();
  for (const layout of document.querySelectorAll('.pb-layout[data-pb-live]')) {
    const url = layout.dataset.pbLive;
    if (!/^\/api\/prime-builder\/v1\/documents\/[1-9]\d*\/live$/.test(url || '')) continue;
    // Only forward bounded pagination for widgets actually present in this layout;
    // unrelated query parameters may contain private or application-specific data.
    const current = new URLSearchParams(window.location.search);
    const pages = new URLSearchParams();
    for (const node of layout.querySelectorAll('.pb-posts[data-pb-id]')) {
      const id = node.dataset.pbId;
      if (!/^[a-zA-Z0-9_-]{1,64}$/.test(id || '')) continue;
      const key = `pb_page_${id}`, value = current.get(key);
      if (/^[1-9]\d{0,3}$/.test(value || '') && Number(value) <= 1000) pages.set(key, value);
    }
    const suffix = pages.toString();
    const requestUrl = suffix ? `${url}?${suffix}` : url;
    if (!requests.has(requestUrl)) {
      requests.set(requestUrl, fetch(requestUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
        .then(response => response.ok ? response.json() : null).catch(() => null));
    }
    requests.get(requestUrl).then(result => {
      const widgets = result?.data?.widgets;
      if (!widgets || typeof widgets !== 'object') return;
      for (const node of layout.querySelectorAll('[data-pb-id]')) {
        if (!node.classList.contains('pb-posts') && !node.classList.contains('pb-current_content') && !node.classList.contains('pb-theme_zone') && !node.classList.contains('pb-search_form')) continue;
        const html = widgets[node.dataset.pbId];
        if (typeof html !== 'string') continue;
        if (node.classList.contains('pb-theme_zone')) node.replaceChildren(zoneFragment(html, node.dataset.pbId));
        else if (node.classList.contains('pb-search_form')) {
          // Update only the configured search path: do not erase text or move focus if
          // the visitor started typing while this asynchronous request was in flight.
          const template = document.createElement('template');
          template.innerHTML = html;
          const action = template.content.querySelector('form[data-pb-search-form]')?.getAttribute('action');
          const form = node.querySelector('form[data-pb-search-form]');
          if (form && /^\/[a-z0-9]+(?:-[a-z0-9]+)*$/.test(action || '')) form.setAttribute('action', action);
        } else {
          node.innerHTML = html;
          if (node.classList.contains('pb-posts')) {
            // Keep other widgets' pages and the page's own parameters when following a link.
            const key = `pb_page_${node.dataset.pbId}`;
            for (const link of node.querySelectorAll('a[data-pb-post-page]')) {
              const target = Number(link.dataset.pbPostPage);
              if (!Number.isInteger(target) || target < 1 || target > 1000) continue;
              const destination = new URL(window.location.href);
              if (target === 1) destination.searchParams.delete(key);
              else destination.searchParams.set(key, String(target));
              link.setAttribute('href', destination.pathname + destination.search + destination.hash);
            }
          }
        }
      }
    });
  }
})();
