/* Progressive enhancement: without JavaScript every tab section remains readable. */
(() => {
  const enhanced = new WeakSet();
  const initialize = root => {
    const widgets = [...root.querySelectorAll('[data-pb-tabs]')];
    if (root.matches?.('[data-pb-tabs]')) widgets.unshift(root);
    for (const widget of widgets) {
      if (enhanced.has(widget)) continue;
      const panels = [...widget.querySelectorAll(':scope > [data-pb-tab-panel]')];
      if (panels.length < 2) continue;
      const list = document.createElement('div');
      list.className = 'pb-tab-list';
      list.setAttribute('role', 'tablist');
      list.setAttribute('aria-label', widget.dataset.pbTabsLabel || '');
      const reservedIds = new Set();
      const buttons = panels.map((panel, index) => {
        // Native zones can share a panel ID with the theme or another widget.
        // Keep aria-controls on the panel belonging to this particular tablist.
        const owner = panel.id ? document.getElementById(panel.id) : null;
        if (!panel.id || (owner && owner !== panel) || reservedIds.has(panel.id)) {
          const base = panel.id || 'pb-tab-panel';
          let panelId = base, suffix = 2;
          while (document.getElementById(panelId) || reservedIds.has(panelId)) panelId = `${base}-${suffix++}`;
          panel.id = panelId;
        }
        reservedIds.add(panel.id);
        const heading = panel.querySelector('[data-pb-tab-heading]');
        const button = document.createElement('button');
        button.type = 'button';
        // A theme or an earlier copy may already own the conventional button ID.
        const base = `${panel.id}-button`;
        let buttonId = base, suffix = 2;
        while (document.getElementById(buttonId) || reservedIds.has(buttonId)) buttonId = `${base}-${suffix++}`;
        reservedIds.add(buttonId);
        button.id = buttonId;
        button.textContent = heading?.textContent || String(index + 1);
        button.setAttribute('role', 'tab');
        button.setAttribute('aria-controls', panel.id);
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', button.id);
        panel.tabIndex = 0;
        if (heading) heading.hidden = true;
        list.append(button);
        return button;
      });
      const select = (index, focus = false) => {
        buttons.forEach((button, position) => {
          const active = position === index;
          button.setAttribute('aria-selected', String(active));
          button.tabIndex = active ? 0 : -1;
          panels[position].hidden = !active;
        });
        if (focus) buttons[index].focus();
      };
      buttons.forEach((button, index) => {
        button.addEventListener('click', () => select(index));
        button.addEventListener('keydown', event => {
          let next = index;
          if (event.key === 'ArrowRight') next = (index + 1) % buttons.length;
          else if (event.key === 'ArrowLeft') next = (index + buttons.length - 1) % buttons.length;
          else if (event.key === 'Home') next = 0;
          else if (event.key === 'End') next = buttons.length - 1;
          else return;
          event.preventDefault(); select(next, true);
        });
      });
      widget.prepend(list);
      enhanced.add(widget);
      select(0);
    }
  };
  initialize(document);
  document.addEventListener('prime-builder:fragment', event => {
    if (typeof event.target?.querySelectorAll === 'function') initialize(event.target);
  });
})();
