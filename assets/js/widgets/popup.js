document.querySelectorAll('[data-pb-popup]').forEach((dialog) => {
  const trigger = dialog.getAttribute('data-pb-trigger') || 'load';
  const delay = parseInt(dialog.getAttribute('data-pb-delay') || '0', 10);
  const key = 'pb_popup_' + (dialog.getAttribute('data-pb-popup') || 'default');
  if (localStorage.getItem(key)) return;

  const open = () => {
    if (typeof dialog.showModal === 'function' && !dialog.open) {
      dialog.showModal();
      localStorage.setItem(key, '1');
    }
  };

  if (trigger === 'load') {
    setTimeout(open, Math.max(0, delay));
  } else if (trigger === 'scroll') {
    const pct = parseInt(dialog.getAttribute('data-pb-scroll') || '50', 10);
    const onScroll = () => {
      const ratio = (window.scrollY / Math.max(1, document.body.scrollHeight - window.innerHeight)) * 100;
      if (ratio >= pct) {
        window.removeEventListener('scroll', onScroll);
        open();
      }
    };
    window.addEventListener('scroll', onScroll, { passive: true });
  } else if (trigger === 'exit') {
    document.addEventListener('mouseout', (e) => {
      if (e.clientY <= 0) open();
    }, { once: true });
  }

  dialog.querySelector('[data-pb-popup-close]')?.addEventListener('click', () => dialog.close());
});
