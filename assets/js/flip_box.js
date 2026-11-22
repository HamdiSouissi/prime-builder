/* Without JavaScript both sides and the optional link remain visible. */
(() => {
  for (const box of document.querySelectorAll('[data-pb-flip]')) {
    if (box.querySelector('[data-pb-flip-control]')) continue;
    const front = box.querySelector('[data-pb-flip-front]');
    const back = box.querySelector('[data-pb-flip-back]');
    const showBack = box.dataset.pbShowBack;
    const showFront = box.dataset.pbShowFront;
    if (!front || !back || !showBack || !showFront) continue;

    const owner = box.closest('[data-pb-id]');
    const elementId = owner?.dataset.pbId || '';
    const base = `pb-flip-${/^[a-zA-Z0-9_-]{1,64}$/.test(elementId) ? elementId : 'panel'}`;
    let panelId = base, suffix = 2;
    while (document.getElementById(panelId)) panelId = `${base}-${suffix++}`;
    back.id = panelId;

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'pb-flip-control';
    button.dataset.pbFlipControl = '';
    button.setAttribute('aria-controls', panelId);
    const select = open => {
      front.hidden = open;
      back.hidden = !open;
      button.setAttribute('aria-expanded', String(open));
      const label = open ? showFront : showBack;
      button.textContent = label;
      button.setAttribute('aria-label', box.dataset.pbFlipContext ? `${label} : ${box.dataset.pbFlipContext}` : label);
    };
    button.addEventListener('click', () => {
      const open = back.hidden;
      if (!open && back.contains(document.activeElement)) button.focus();
      (open ? back : front).dataset.pbFlipActivated = '';
      select(open);
    });
    box.prepend(button);
    select(false);
  }
})();
