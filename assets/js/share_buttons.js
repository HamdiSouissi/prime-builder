/* Refresh the current public address without forwarding query strings or contacting a provider. */
(() => {
  const { location } = window;
  if (!['http:', 'https:'].includes(location.protocol) || !location.host || location.pathname === '/admin' || location.pathname.startsWith('/admin/')) return;
  const parameters = new URLSearchParams(location.search);
  if (['1', 'true'].includes(parameters.get('preview'))) return;
  const url = encodeURIComponent(location.origin + location.pathname);
  for (const share of document.querySelectorAll('[data-pb-share]')) {
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
})();
