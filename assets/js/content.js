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
    if (!requests.has(url)) {
      requests.set(url, fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
        .then(response => response.ok ? response.json() : null).catch(() => null));
    }
    requests.get(url).then(result => {
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
        } else node.innerHTML = html;
      }
    });
  }
})();
