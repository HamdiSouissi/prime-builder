const root = document.querySelector('[data-pb-theme-rules]');
if (root) {
  const config = JSON.parse(root.querySelector('[data-pb-theme-config]').value);
  const template = root.querySelector('[data-pb-rule-template]');
  const make = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text) node.textContent = text;
    return node;
  };
  const option = (value, label) => {
    const node = make('option', '', label);
    node.value = value;
    return node;
  };
  function mount(row) {
    const type = row.querySelector('[data-pb-rule-type]');
    const value = row.querySelector('[data-pb-rule-value]');
    const controls = row.querySelector('[data-pb-rule-controls]');
    let sequence = 0;
    let timer;
    function showControls() {
      ++sequence;
      clearTimeout(timer);
      controls.replaceChildren();
      const kind = type.value;
      if (['content_type', 'taxonomy', 'term'].includes(kind)) {
        const choices = kind === 'content_type' ? config.types : kind === 'taxonomy' ? config.taxonomies : config.terms;
        const label = make('label', 'pc-field');
        label.append(make('span', 'pc-label', type.selectedOptions[0].textContent));
        const select = make('select', 'pc-input');
        select.append(option('', config.labels.choose));
        for (const item of choices) select.append(option(item.value, item.label));
        // A deleted reference stays visible and fails validation rather than
        // silently changing the rule to a site-wide condition.
        if (value.value && ![...select.options].some(item => item.value === value.value)) {
          select.append(option(value.value, value.value));
        }
        select.value = value.value;
        select.addEventListener('change', () => { value.value = select.value; });
        label.append(select); controls.append(label);
        return;
      }
      if (kind !== 'entry') { value.value = ''; return; }
      const selection = make('div', 'pb-theme-selection');
      const label = make('label', 'pc-field');
      label.append(make('span', 'pc-label', config.labels.search));
      const input = make('input', 'pc-input');
      input.type = 'search'; input.autocomplete = 'off'; input.maxLength = 100;
      label.append(input);
      const status = make('p', 'pc-field__help'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
      const results = make('div', 'pb-theme-results'); results.setAttribute('role', 'group'); results.setAttribute('aria-label', config.labels.results);
      controls.append(selection, label, status, results);
      function showSelection(title) {
        selection.replaceChildren();
        if (!value.value) return;
        const tag = make('span', 'pb-theme-tag');
        tag.append(make('span', '', title || config.entries[value.value] || `#${value.value}`));
        const remove = make('button', '', '×'); remove.type = 'button';
        remove.setAttribute('aria-label', `${config.labels.remove} — ${title || config.entries[value.value] || `#${value.value}`}`);
        remove.addEventListener('click', () => { value.value = ''; showSelection(''); input.focus(); });
        tag.append(remove); selection.append(tag);
      }
      showSelection('');
      input.addEventListener('input', () => {
        const query = input.value.trim();
        const current = ++sequence;
        clearTimeout(timer); results.replaceChildren(); status.textContent = '';
        if (query.length < 2) return;
        timer = setTimeout(async () => {
          try {
            const response = await fetch(`/admin/prime-builder/theme/content-search?q=${encodeURIComponent(query)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error('search');
            const data = await response.json();
            if (current !== sequence) return;
            const items = Array.isArray(data.items) ? data.items : [];
            status.textContent = items.length ? '' : config.labels.no_results;
            for (const item of items) {
              if (!Number.isSafeInteger(item.id) || item.id < 1 || typeof item.title !== 'string') continue;
              const button = make('button', 'pb-theme-result', `${item.title} — ${item.type}`); button.type = 'button';
              button.addEventListener('click', () => {
                value.value = String(item.id); config.entries[value.value] = item.title;
                ++sequence; results.replaceChildren(); status.textContent = ''; input.value = '';
                showSelection(item.title); selection.querySelector('button')?.focus();
              });
              results.append(button);
            }
          } catch {
            if (current === sequence) status.textContent = config.labels.search_error;
          }
        }, 200);
      });
      input.addEventListener('keydown', event => {
        if (event.key === 'Enter') { event.preventDefault(); results.querySelector('button')?.click(); }
        if (event.key === 'Escape') { ++sequence; clearTimeout(timer); results.replaceChildren(); status.textContent = ''; }
      });
    }
    type.addEventListener('change', () => { value.value = ''; showControls(); });
    row.querySelector('[data-pb-remove-rule]').addEventListener('click', () => {
      const group = row.closest('[data-pb-rule-group]');
      const next = row.nextElementSibling || row.previousElementSibling;
      row.remove();
      (next?.querySelector('[data-pb-rule-type]') || group.querySelector('[data-pb-add-rule]')).focus();
    });
    showControls();
  }
  root.querySelectorAll('[data-pb-rule]').forEach(mount);
  root.querySelectorAll('[data-pb-rule-group]').forEach(group => {
    group.querySelector('[data-pb-add-rule]').addEventListener('click', () => {
      const list = group.querySelector('[data-pb-rule-list]');
      if (list.children.length >= 100) return;
      const row = template.content.firstElementChild.cloneNode(true);
      row.querySelector('[data-pb-rule-type]').name = `${group.dataset.pbRuleGroup}_type[]`;
      row.querySelector('[data-pb-rule-value]').name = `${group.dataset.pbRuleGroup}_value[]`;
      list.append(row); mount(row); row.querySelector('[data-pb-rule-type]').focus();
    });
  });
}
