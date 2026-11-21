import { createStore } from './store.js';

const docEl = document.getElementById('pb-initial-document');
const widgetsEl = document.getElementById('pb-widgets-meta');
const initialDoc = docEl ? JSON.parse(docEl.textContent || '{}') : { version: 1, elements: [] };
const widgetsMeta = widgetsEl ? JSON.parse(widgetsEl.textContent || '[]') : [];

const store = createStore(initialDoc);
let activeTab = 'widgets';

const canvasEl = document.querySelector('[data-pb-canvas]');
const canvasFrame = document.querySelector('[data-pb-canvas-frame]');
const panelBody = document.querySelector('[data-pb-panel-body]');
const entryId = document.body.dataset.pbEntryId;
const csrf = document.body.dataset.pbCsrf || '';

function renderCanvas(state) {
  if (!canvasEl) return;
  canvasEl.innerHTML = '';
  for (const el of state.document.elements) {
    canvasEl.appendChild(renderNode(el, state.selectedId));
  }
  if (canvasFrame) {
    canvasFrame.setAttribute('data-device', state.device);
  }
}

function renderNode(el, selectedId) {
  const node = document.createElement('div');
  node.className = el.elType === 'container' ? 'pb-container pb-container--boxed' : 'pb-element';
  if (el.id === selectedId) node.classList.add('pb-el-selected');
  node.dataset.pbId = el.id;
  node.tabIndex = 0;

  if (el.elType === 'container') {
    const label = document.createElement('div');
    label.textContent = 'Conteneur (' + (el.settings?.layout_type || 'flex') + ')';
    label.style.fontSize = '12px';
    label.style.color = '#64748b';
    node.appendChild(label);
    for (const child of el.elements || []) {
      node.appendChild(renderNode(child, selectedId));
    }
  } else {
    node.textContent = el.settings?.text || el.settings?.title || el.widgetType;
  }

  node.addEventListener('click', (e) => {
    e.stopPropagation();
    store.select(el.id);
    activeTab = 'inspector';
    renderPanel(store.getState());
  });

  return node;
}

function renderPanel(state) {
  if (!panelBody) return;
  panelBody.innerHTML = '';

  if (activeTab === 'widgets') {
    const addCont = document.createElement('button');
    addCont.type = 'button';
    addCont.className = 'pb-widget-card';
    addCont.style.width = '100%';
    addCont.style.marginBottom = '0.75rem';
    addCont.textContent = '+ Ajouter un conteneur';
    addCont.addEventListener('click', () => store.addContainer('flex'));
    panelBody.appendChild(addCont);

    const grid = document.createElement('div');
    grid.className = 'pb-widget-grid';
    for (const w of widgetsMeta) {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'pb-widget-card';
      btn.textContent = w.title;
      btn.addEventListener('click', () => store.addWidget(w.type, w.defaults || {}));
      grid.appendChild(btn);
    }
    panelBody.appendChild(grid);
  } else if (activeTab === 'inspector') {
    const sel = findEl(state.document.elements, state.selectedId);
    if (!sel) {
      panelBody.textContent = 'Sélectionnez un élément sur le canevas.';
      return;
    }
    const title = document.createElement('h3');
    title.textContent = sel.elType === 'container' ? 'Conteneur' : sel.widgetType;
    panelBody.appendChild(title);

    const textInput = document.createElement('input');
    textInput.type = 'text';
    textInput.value = sel.settings?.text || sel.settings?.title || '';
    textInput.placeholder = 'Texte / Titre';
    textInput.style.width = '100%';
    textInput.style.marginBottom = '0.75rem';
    textInput.addEventListener('change', () => {
      store.updateSetting(sel.id, sel.settings?.title !== undefined ? 'title' : 'text', textInput.value);
    });
    panelBody.appendChild(textInput);

    const delBtn = document.createElement('button');
    delBtn.type = 'button';
    delBtn.className = 'pb-btn pb-btn--primary';
    delBtn.textContent = 'Supprimer';
    delBtn.addEventListener('click', () => store.removeElement(sel.id));
    panelBody.appendChild(delBtn);
  } else if (activeTab === 'navigator') {
    const ul = document.createElement('ul');
    for (const el of state.document.elements) {
      const li = document.createElement('li');
      li.textContent = el.elType === 'container' ? 'Conteneur' : el.widgetType;
      li.style.cursor = 'pointer';
      li.addEventListener('click', () => store.select(el.id));
      ul.appendChild(li);
    }
    panelBody.appendChild(ul);
  }
}

function findEl(list, id) {
  for (const item of list) {
    if (item.id === id) return item;
    if (Array.isArray(item.elements)) {
      const f = findEl(item.elements, id);
      if (f) return f;
    }
  }
  return null;
}

document.querySelectorAll('[data-pb-panel-tab]').forEach((btn) => {
  btn.addEventListener('click', () => {
    activeTab = btn.dataset.pbPanelTab || 'widgets';
    document.querySelectorAll('[data-pb-panel-tab]').forEach((b) => {
      b.classList.toggle('is-active', b === btn);
      b.setAttribute('aria-selected', b === btn ? 'true' : 'false');
    });
    renderPanel(store.getState());
  });
});

document.querySelectorAll('[data-pb-device]').forEach((btn) => {
  btn.addEventListener('click', () => {
    store.setDevice(btn.dataset.pbDevice || 'desktop');
    document.querySelectorAll('[data-pb-device]').forEach((b) => {
      b.classList.toggle('is-active', b === btn);
      b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
    });
  });
});

document.querySelector('[data-pb-undo]')?.addEventListener('click', () => store.undo());
document.querySelector('[data-pb-redo]')?.addEventListener('click', () => store.redo());

document.querySelector('[data-pb-save]')?.addEventListener('click', async () => {
  const res = await fetch(`/api/prime-builder/v1/documents/${entryId}`, {
    method: 'PATCH',
    headers: {
      'Content-Type': 'application/json',
      'X-Prime-CSRF': csrf,
    },
    body: JSON.stringify({ document: store.getState().document }),
  });
  if (res.ok) {
    store.markSaved();
  }
});

document.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {
    e.preventDefault();
    if (e.shiftKey) store.redo();
    else store.undo();
  }
});

store.subscribe((state) => {
  renderCanvas(state);
  renderPanel(state);
});

renderCanvas(store.getState());
renderPanel(store.getState());
