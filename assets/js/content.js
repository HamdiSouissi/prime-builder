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
  // Enhance the CMS's ordinary list of links, rather than using the ARIA menu
  // role: native links and disclosure buttons keep their expected Tab behavior.
  const enhanceNavigation = node => {
    const nav = node.querySelector('[data-pb-navigation]');
    const list = nav?.querySelector(':scope > .pb-nav__list');
    if (!nav || !list || nav.dataset.pbEnhanced === 'true') return;
    const doc = nav.ownerDocument;
    const makeId = prefix => {
      let candidate = prefix, suffix = 2;
      while (doc.getElementById(candidate)) candidate = `${prefix}-${suffix++}`;
      return candidate;
    };
    const id = node.dataset.pbId;
    if (!/^[a-zA-Z0-9_-]{1,64}$/.test(id || '')) return;
    list.id = makeId(`pb-nav-${id}`);
    let index = 0;
    for (const item of list.querySelectorAll('.pb-nav__item--has-children')) {
      const submenu = item.querySelector(':scope > .pb-nav__submenu');
      const link = item.querySelector(':scope > .pb-nav__link');
      if (!submenu || !link) continue;
      submenu.id = makeId(`pb-nav-${id}-submenu-${++index}`);
      const button = doc.createElement('button');
      button.type = 'button';
      button.className = 'pb-nav__submenu-toggle';
      button.setAttribute('aria-controls', submenu.id);
      button.setAttribute('aria-expanded', 'false');
      button.setAttribute('aria-label', (nav.dataset.pbNavSubmenu || 'Submenu: :label').replace(':label', link.textContent.trim()));
      const icon = doc.createElement('span');
      icon.setAttribute('aria-hidden', 'true');
      icon.textContent = '▾';
      button.append(icon);
      submenu.hidden = true;
      item.insertBefore(button, submenu);
      button.addEventListener('click', () => {
        const open = button.getAttribute('aria-expanded') !== 'true';
        button.setAttribute('aria-expanded', String(open));
        submenu.hidden = !open;
      });
    }
    const toggle = doc.createElement('button');
    toggle.type = 'button';
    toggle.className = 'pb-nav__mobile-toggle';
    toggle.textContent = nav.dataset.pbNavMenu || 'Menu';
    toggle.setAttribute('aria-controls', list.id);
    nav.insertBefore(toggle, list);
    const mobile = doc.defaultView.matchMedia('(max-width: 767px)');
    const setOpen = open => {
      toggle.setAttribute('aria-expanded', String(open));
      list.hidden = !open;
    };
    const synchronize = () => {
      // Never hide a focused descendant during a resize.
      const open = !mobile.matches || list.contains(doc.activeElement);
      setOpen(open);
      if (!mobile.matches && doc.activeElement === toggle) list.querySelector('a')?.focus();
    };
    toggle.addEventListener('click', () => setOpen(toggle.getAttribute('aria-expanded') !== 'true'));
    mobile.addEventListener('change', synchronize);
    nav.addEventListener('keydown', event => {
      if (event.key !== 'Escape') return;
      const active = doc.activeElement;
      let button = active?.closest('.pb-nav__submenu-toggle');
      if (!button || button.getAttribute('aria-expanded') !== 'true') {
        const enclosing = active?.closest('.pb-nav__submenu');
        button = enclosing?.previousElementSibling;
      }
      if (button?.classList.contains('pb-nav__submenu-toggle') && button.getAttribute('aria-expanded') === 'true') {
        button.click();
        button.focus();
        event.preventDefault();
      } else if (mobile.matches && toggle.getAttribute('aria-expanded') === 'true') {
        setOpen(false);
        toggle.focus();
        event.preventDefault();
      }
    });
    nav.dataset.pbEnhanced = 'true';
    synchronize();
  };
  const replaceWhenUnfocused = (node, replace) => {
    // An in-flight request must not remove a fallback link under the visitor's focus.
    if (!node.contains(document.activeElement)) { replace(); return; }
    const onFocusOut = () => queueMicrotask(() => {
      if (!node.contains(document.activeElement)) {
        node.removeEventListener('focusout', onFocusOut);
        replace();
      }
    });
    node.addEventListener('focusout', onFocusOut);
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
        if (!node.classList.contains('pb-posts') && !node.classList.contains('pb-current_content') && !node.classList.contains('pb-theme_zone') && !node.classList.contains('pb-search_form') && !node.classList.contains('pb-navigation') && !node.classList.contains('pb-breadcrumbs')) continue;
        const html = widgets[node.dataset.pbId];
        if (typeof html !== 'string') continue;
        if (node.classList.contains('pb-theme_zone')) node.replaceChildren(zoneFragment(html, node.dataset.pbId));
        else if (node.classList.contains('pb-navigation')) replaceWhenUnfocused(node, () => { node.innerHTML = html; enhanceNavigation(node); });
        else if (node.classList.contains('pb-breadcrumbs')) replaceWhenUnfocused(node, () => { node.innerHTML = html; });
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
