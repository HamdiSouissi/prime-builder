/* Refresh the public canonical address without forwarding query strings or contacting a provider. */
(() => {
  const { location } = window;
  if (!['http:', 'https:'].includes(location.protocol) || !location.host || location.pathname === '/admin' || location.pathname.startsWith('/admin/')) return;
  const parameters = new URLSearchParams(location.search);
  if (['1', 'true'].includes(parameters.get('preview')) || parameters.get('customizer_preview') === '1') return;
  // The CMS emits its SEO canonical in the head. Only accept an absolute HTTP(S)
  // URL without credentials or control characters; otherwise retain the current
  // path as a safe fallback. A theme or extension can omit or override the tag.
  let publicUrl = location.origin + location.pathname;
  const canonical = document.head?.querySelector('link[rel~="canonical"]')?.getAttribute('href');
  if (typeof canonical === 'string' && canonical.length <= 4096 && canonical === canonical.trim()
      && /^https?:\/\//i.test(canonical) && !/^https?:\/\/[^/?#]*@/i.test(canonical)
      && !/[\u0000-\u001f\u007f]/.test(canonical)) {
    try {
      const parsed = new URL(canonical);
      if (['http:', 'https:'].includes(parsed.protocol) && parsed.hostname && !parsed.username && !parsed.password) {
        parsed.search = ''; parsed.hash = '';
        publicUrl = parsed.href;
      }
    } catch { /* Keep the current public path. */ }
  }
  const url = encodeURIComponent(publicUrl);
  const initialize = root => {
    const widgets = [...root.querySelectorAll('[data-pb-share]')];
    if (root.matches?.('[data-pb-share]')) widgets.unshift(root);
    for (const share of widgets) {
      const message = encodeURIComponent((share.dataset.pbShareMessage || '').replace(/[\u0000-\u001f\u007f]+/g, ' ').trim());
      for (const link of share.querySelectorAll('[data-pb-share-service]')) {
        const href = {
          facebook: `https://www.facebook.com/sharer/sharer.php?u=${url}`,
          linkedin: `https://www.linkedin.com/sharing/share-offsite/?url=${url}`,
          x: `https://twitter.com/intent/tweet?url=${url}&text=${message}`,
          email: `mailto:?subject=${message}&body=${url}`,
        }[link.dataset.pbShareService];
        if (href) link.setAttribute('href', href);
      }
    }
  };
  initialize(document);
  document.addEventListener('prime-builder:fragment', event => {
    if (typeof event.target?.querySelectorAll === 'function') initialize(event.target);
  });
})();
