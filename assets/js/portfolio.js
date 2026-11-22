/* Progressive enhancement: without JavaScript every project remains visible. */
(() => {
  for (const portfolio of document.querySelectorAll('[data-pb-portfolio]')) {
    const list = portfolio.querySelector('.pb-portfolio-list');
    const items = [...portfolio.querySelectorAll('[data-pb-portfolio-item]')];
    const categories = [...new Set(items.map(item => item.dataset.pbCategory).filter(Boolean))];
    const labels = ['pbPortfolioFilterLabel', 'pbPortfolioAll', 'pbPortfolioCategory', 'pbPortfolioSingle', 'pbPortfolioPlural'];
    if (!list || categories.length < 2 || labels.some(key => !portfolio.dataset[key])) continue;

    const bar = document.createElement('div');
    bar.className = 'pb-portfolio-filters';
    bar.setAttribute('role', 'group');
    bar.setAttribute('aria-label', portfolio.dataset.pbPortfolioFilterLabel);
    const status = document.createElement('p');
    status.className = 'pb-portfolio-status';
    status.setAttribute('role', 'status');
    const buttons = [];
    const update = selected => {
      let visible = 0;
      for (const item of items) {
        item.hidden = selected !== null && item.dataset.pbCategory !== selected;
        if (!item.hidden) visible++;
      }
      for (const entry of buttons) entry.button.setAttribute('aria-pressed', String(entry.category === selected));
      const template = visible === 1 ? portfolio.dataset.pbPortfolioSingle : portfolio.dataset.pbPortfolioPlural;
      status.textContent = template.replace(':count', String(visible));
    };
    const addButton = (label, category) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.textContent = label;
      if (category !== null) button.setAttribute('aria-label', portfolio.dataset.pbPortfolioCategory.replace(':category', category));
      button.addEventListener('click', () => update(category));
      buttons.push({ button, category });
      bar.append(button);
    };
    addButton(portfolio.dataset.pbPortfolioAll, null);
    for (const category of categories) addButton(category, category);
    portfolio.insertBefore(bar, list);
    portfolio.insertBefore(status, list);
    update(null);
  }
})();
