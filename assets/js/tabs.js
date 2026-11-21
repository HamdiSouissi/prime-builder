/* Progressive enhancement: without JavaScript every tab section remains readable. */
(() => {
  for (const widget of document.querySelectorAll('[data-pb-tabs]')) {
    const panels = [...widget.querySelectorAll(':scope > [data-pb-tab-panel]')];
    if (panels.length < 2) continue;
    const list = document.createElement('div');
    list.className = 'pb-tab-list';
    list.setAttribute('role', 'tablist');
    list.setAttribute('aria-label', widget.dataset.pbTabsLabel || '');
    const buttons = panels.map((panel, index) => {
      const heading = panel.querySelector('[data-pb-tab-heading]');
      const button = document.createElement('button');
      button.type = 'button';
      button.id = `${panel.id}-button`;
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
    select(0);
  }
})();
