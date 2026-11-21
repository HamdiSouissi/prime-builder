document.querySelectorAll('[data-pb-tabs]').forEach((root) => {
  const tabs = root.querySelectorAll('[data-pb-tab]');
  const panels = root.querySelectorAll('[data-pb-panel]');
  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      const idx = tab.getAttribute('data-pb-tab');
      tabs.forEach((t) => t.setAttribute('aria-selected', t === tab ? 'true' : 'false'));
      panels.forEach((p) => {
        if (p.getAttribute('data-pb-panel') === idx) p.removeAttribute('hidden');
        else p.setAttribute('hidden', '');
      });
    });
  });
});
