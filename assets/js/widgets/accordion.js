document.querySelectorAll('[data-pb-accordion]').forEach((root) => {
  root.querySelectorAll('[data-pb-acc-trigger]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const expanded = btn.getAttribute('aria-expanded') === 'true';
      btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      const panel = btn.nextElementSibling;
      if (panel) {
        if (expanded) panel.setAttribute('hidden', '');
        else panel.removeAttribute('hidden');
      }
    });
  });
});
