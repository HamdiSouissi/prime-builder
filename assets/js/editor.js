import { History, add, createElement, duplicate, find, moveInto, remove, reorder, update } from './model.js';

const root = document.querySelector('[data-pb-app]');
const config = JSON.parse(root.querySelector('[data-pb-config]').value);
const ui = config.ui;
const $ = selector => root.querySelector(selector);
const $$ = selector => [...root.querySelectorAll(selector)];
const savedLayout = (config.document.blocks || []).find(block => block.type === 'prime-builder/layout');
let foreign = !savedLayout && (config.document.blocks || []).length > 0;
let history = new History(savedLayout?.data?.elements || []);
let selected = null;
let device = 'desktop';
let tab = 'content';
let panel = 'widgets';
let dirty = false;
let requestNumber = 0;
let previewTimer;
const frame = $('[data-pb-frame]');
const status = $('[data-pb-status]');
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
const localKey = `prime-builder:document:${config.entryId}`;
const defaults = { heading: 'H', text: '¶', image: '▧', button: '↗', container: '▦', separator: '—', spacer: '↕', quote: '❝', alert: '!', progress: '◒', anchor: '#' };

function text(tag, value, className = '') {
  const node = document.createElement(tag); node.textContent = value;
  if (className) node.className = className;
  return node;
}
function markDirty() {
  dirty = true; $('[data-pb-save-state]').textContent = ui.unsaved.split('?')[0];
  try { localStorage.setItem(localKey, JSON.stringify({ elements: history.present, status: status.value, time: Date.now() })); } catch { /* Storage can be disabled. */ }
}
function commit(elements, rebuild = false) {
  if (!history.commit(elements)) return;
  markDirty();
  if (rebuild) renderPanel();
  schedulePreview();
}
function ensureLayout() {
  if (!foreign) return true;
  if (!confirm(ui.confirm_replace)) return false;
  foreign = false; history = new History([]); return true;
}
function addWidget(type) {
  if (!config.widgets[type] || !ensureLayout()) return;
  const parent = find(history.present, selected);
  const node = createElement(type);
  selected = node.id; tab = 'content'; panel = 'settings';
  commit(add(history.present, node, parent?.type === 'container' ? parent.id : null), true);
}
function renderWidgets() {
  const list = $('[data-pb-widget-list]'); list.replaceChildren();
  const search = $('[data-pb-search]').value.trim().toLocaleLowerCase();
  const groups = new Map();
  for (const [type, definition] of Object.entries(config.widgets)) {
    if (!definition.label.toLocaleLowerCase().includes(search)) continue;
    const category = definition.category === 'structure' ? 'structure' : 'base';
    if (!groups.has(category)) groups.set(category, []);
    groups.get(category).push([type, definition]);
  }
  for (const [category, definitions] of groups) {
    list.append(text('h2', ui[category], 'pb-category'));
    const grid = document.createElement('div'); grid.className = 'pb-widget-grid';
    for (const [type, definition] of definitions) {
      const button = document.createElement('button'); button.type = 'button'; button.className = 'pb-widget';
      button.draggable = true; button.setAttribute('aria-label', `${ui.add} ${definition.label}`);
      button.append(text('span', defaults[type] || '◇', 'pb-widget-icon'), text('span', definition.label));
      button.addEventListener('click', () => addWidget(type));
      button.addEventListener('dragstart', event => { event.dataTransfer.setData('text/prime-builder-widget', type); event.dataTransfer.effectAllowed = 'copy'; });
      grid.append(button);
    }
    list.append(grid);
  }
  if (!list.children.length) list.append(text('p', ui.empty, 'pb-empty'));
}
function renderTree() {
  const target = $('[data-pb-tree]'); target.replaceChildren();
  if (!history.present.length) { target.append(text('p', ui.empty, 'pb-empty')); return; }
  function build(elements) {
    const list = document.createElement('ul'); list.className = 'pb-tree';
    for (const element of elements) {
      const item = document.createElement('li'); const row = document.createElement('div'); row.className = 'pb-tree-row'; row.dataset.selected = String(selected === element.id);
      const handle = text('span', '⠿', 'pb-tree-handle'); handle.setAttribute('aria-hidden', 'true');
      row.draggable = true;
      row.addEventListener('dragstart', event => { event.stopPropagation(); event.dataTransfer.setData('text/prime-builder-element', element.id); event.dataTransfer.effectAllowed = 'move'; });
      row.addEventListener('dragover', event => { if (element.type === 'container') event.preventDefault(); });
      row.addEventListener('drop', event => {
        if (element.type !== 'container') return;
        event.preventDefault(); event.stopPropagation();
        const source = event.dataTransfer.getData('text/prime-builder-element');
        if (source) commit(moveInto(history.present, source, element.id), true);
      });
      const button = text('button', config.widgets[element.type]?.label || element.type); button.type = 'button';
      button.addEventListener('click', () => { selected = element.id; tab = 'content'; panel = 'settings'; renderPanel(); schedulePreview(); });
      row.append(handle, button); item.append(row);
      if (element.children?.length) item.append(build(element.children));
      list.append(item);
    }
    return list;
  }
  target.append(build(history.present));
}
function makeField(name, schema, current, onChange) {
  const wrapper = document.createElement('label'); wrapper.className = 'pb-field';
  wrapper.append(text('span', ui[name] || name));
  let input;
  if (schema.type === 'enum') {
    input = document.createElement('select');
    if (current == null) { const option = text('option', '—'); option.value = ''; input.append(option); }
    for (const value of schema.values) { const option = text('option', ui[value] || value); option.value = value; input.append(option); }
  } else if (schema.type === 'rich') {
    input = document.createElement('textarea');
  } else {
    input = document.createElement('input');
    input.type = schema.type === 'integer' ? 'number' : 'text';
    if (schema.min != null) input.min = schema.min;
    if (schema.max != null && schema.type === 'integer') input.max = schema.max;
    if (schema.max != null && schema.type !== 'integer') input.maxLength = schema.max;
  }
  input.value = current ?? '';
  input.addEventListener(schema.type === 'enum' ? 'change' : 'input', () => {
    if (input.value === '') onChange(undefined);
    else if (schema.type === 'integer') {
      const n = Number(input.value);
      if (Number.isInteger(n) && n >= (schema.min ?? 0) && n <= (schema.max ?? 2000)) onChange(n);
    } else onChange(input.value);
  });
  wrapper.append(input); return wrapper;
}
function renderSettings() {
  const element = find(history.present, selected);
  if (!element) { panel = 'widgets'; renderPanel(); return; }
  const definition = config.widgets[element.type];
  $('[data-pb-selected-title]').textContent = definition?.label || element.type;
  $$('[data-pb-tab]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.pbTab === tab)));
  const fields = $('[data-pb-fields]'); fields.replaceChildren();
  if (tab === 'content') {
    for (const [name, schema] of Object.entries(definition?.fields || {})) {
      fields.append(makeField(name, schema, element.settings[name], value => {
        const current = find(history.present, selected); if (!current) return;
        const settings = { ...current.settings };
        if (value === undefined) delete settings[name]; else settings[name] = value;
        commit(update(history.present, selected, { settings }));
      }));
    }
    if (!fields.children.length) fields.append(text('p', ui.empty, 'pb-empty'));
  } else {
    fields.append(text('p', ui.responsive_help, 'pb-intro'));
    const schemas = tab === 'style'
      ? { color: { type: 'text', max: 7 }, background: { type: 'text', max: 7 }, font_size: { type: 'integer', min: 0, max: 2000 }, align: { type: 'enum', values: ['left', 'center', 'right'] }, radius: { type: 'integer', min: 0, max: 2000 } }
      : { padding: { type: 'integer', min: 0, max: 2000 }, margin: { type: 'integer', min: 0, max: 2000 }, gap: { type: 'integer', min: 0, max: 2000 }, min_height: { type: 'integer', min: 0, max: 2000 }, width: { type: 'text', max: 5 } };
    for (const [name, schema] of Object.entries(schemas)) {
      fields.append(makeField(name, schema, element.styles?.[device]?.[name], value => {
        const current = find(history.present, selected); if (!current) return;
        const styles = structuredClone(current.styles || {}); styles[device] ||= {};
        if (value === undefined) delete styles[device][name]; else styles[device][name] = value;
        commit(update(history.present, selected, { styles }));
      }));
    }
  }
}
function renderPanel() {
  $('[data-pb-widget-panel]').hidden = panel !== 'widgets';
  $('[data-pb-navigator-panel]').hidden = panel !== 'navigator';
  $('[data-pb-settings-panel]').hidden = panel !== 'settings';
  $$('[data-pb-panel]').forEach(button => {
    const active = button.dataset.pbPanel === panel;
    button.classList.toggle('pb-nav-active', active); button.setAttribute('aria-pressed', String(active));
  });
  if (panel === 'widgets') renderWidgets();
  if (panel === 'navigator') renderTree();
  if (panel === 'settings') renderSettings();
}
function applyVisualStyles(doc) {
  function walk(elements) {
    for (const element of elements) {
      const node = doc.querySelector(`[data-pb-id="${CSS.escape(element.id)}"]`);
      if (node) {
        if (element.type === 'container') {
          node.style.display = element.settings.layout === 'grid' ? 'grid' : 'flex';
          node.style.flexDirection = element.settings.direction === 'row' ? 'row' : 'column';
          node.style.gridTemplateColumns = `repeat(${Math.max(1, Math.min(6, Number(element.settings.columns) || 1))},minmax(0,1fr))`;
        }
        const order = device === 'mobile' ? ['desktop', 'tablet', 'mobile'] : device === 'tablet' ? ['desktop', 'tablet'] : ['desktop'];
        for (const mode of order) {
          const style = element.styles?.[mode] || {};
          const properties = { color: 'color', background: 'backgroundColor', padding: 'padding', margin: 'margin', gap: 'gap', font_size: 'fontSize', radius: 'borderRadius', min_height: 'minHeight', align: 'textAlign', width: 'width' };
          for (const [key, value] of Object.entries(style)) {
            if (!(key in properties)) continue;
            const allowed = typeof value === 'number' ? Number.isInteger(value) && value >= 0 && value <= 2000 : typeof value === 'string' && (/^#[a-f\d]{3}([a-f\d]{3})?$/i.test(value) || /^(100|\d{1,2})(%|px)$/.test(value) || ['left', 'center', 'right'].includes(value));
            if (allowed) node.style[properties[key]] = typeof value === 'number' ? `${value}px` : value;
          }
        }
        if (element.id === selected) { node.style.outline = '2px solid #ed6647'; node.style.outlineOffset = '2px'; }
      }
      walk(element.children || []);
    }
  }
  walk(history.present);
}
async function renderPreview() {
  if (foreign || !frame.contentDocument) return;
  const sequence = ++requestNumber;
  try {
    const response = await fetch(`/api/prime-builder/v1/documents/${config.entryId}/render`, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf },
      body: JSON.stringify({ data: { version: 1, elements: history.present } }),
    });
    if (!response.ok || sequence !== requestNumber) return;
    const result = await response.json();
    if (sequence !== requestNumber) return;
    const doc = frame.contentDocument;
    if (!doc.querySelector('[data-pb-base-css]')) {
      const link = doc.createElement('link'); link.rel = 'stylesheet'; link.href = config.publicCssUrl; link.dataset.pbBaseCss = ''; doc.head.append(link);
    }
    const current = doc.querySelector('.pb-layout');
    if (current) current.outerHTML = result.data.html;
    else {
      const area = doc.querySelector('.aurora-prose') || doc.querySelector('main');
      if (area) area.innerHTML = result.data.html;
    }
    applyVisualStyles(doc);
  } catch { $('[data-pb-save-state]').textContent = ui.connection_error; }
}
function schedulePreview() { clearTimeout(previewTimer); previewTimer = setTimeout(renderPreview, 180); }
function attachFrameEvents() {
  const doc = frame.contentDocument;
  if (!doc) return;
  doc.addEventListener('click', event => {
    const target = event.target.closest('[data-pb-id]');
    if (target) { event.preventDefault(); event.stopPropagation(); selected = target.dataset.pbId; panel = 'settings'; tab = 'content'; renderPanel(); schedulePreview(); }
    else if (event.target.closest('a')) event.preventDefault();
  }, true);
  doc.addEventListener('dragover', event => event.preventDefault());
  doc.addEventListener('drop', event => {
    event.preventDefault();
    const type = event.dataTransfer.getData('text/prime-builder-widget');
    if (!type) return;
    const target = event.target.closest('[data-pb-id]');
    const node = target ? find(history.present, target.dataset.pbId) : null;
    selected = node?.type === 'container' ? node.id : null;
    addWidget(type);
  });
  schedulePreview();
}
async function save() {
  if (!ensureLayout()) return;
  const snapshot = JSON.stringify(history.present);
  const currentStatus = status.value;
  const data = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: history.present } }] };
  const button = $('[data-pb-save]'); button.disabled = true; $('[data-pb-save-state]').textContent = ui.saving;
  try {
    const response = await fetch(`/api/prime-builder/v1/documents/${config.entryId}`, {
      method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf },
      body: JSON.stringify({ content: data, status: currentStatus }),
    });
    const result = await response.json();
    if (!response.ok) { $('[data-pb-save-state]').textContent = result?.error?.message || ui.save_error; return; }
    if (snapshot === JSON.stringify(history.present) && currentStatus === status.value) {
      history = new History(result.data.content.blocks[0].data.elements);
      dirty = false;
      try { localStorage.removeItem(localKey); } catch { /* Ignore unavailable storage. */ }
      $('[data-pb-save-state]').textContent = ui.saved;
      frame.src = config.previewUrl;
      renderPanel();
    } else $('[data-pb-save-state]').textContent = ui.unsaved.split('?')[0];
  } catch { $('[data-pb-save-state]').textContent = ui.connection_error; }
  finally { button.disabled = false; }
}
for (const [value, label] of [['draft', ui.draft], ...(config.canPublish ? [['published', ui.publish]] : [])]) {
  const option = text('option', label); option.value = value; status.append(option);
}
if (![...status.options].some(option => option.value === config.status)) {
  const option = text('option', config.status); option.value = config.status; status.append(option);
}
status.value = config.status;
try {
  const cached = JSON.parse(localStorage.getItem(localKey) || 'null');
  if (cached && Array.isArray(cached.elements) && confirm(ui.autosave_found)) {
    if (!foreign || ensureLayout()) { history = new History(cached.elements); if ([...status.options].some(o => o.value === cached.status)) status.value = cached.status; markDirty(); }
  }
} catch { /* Unavailable or corrupt local drafts do not block editing. */ }
$('[data-pb-search]').addEventListener('input', renderWidgets);
$$('[data-pb-panel]').forEach(button => button.addEventListener('click', () => { panel = button.dataset.pbPanel; renderPanel(); }));
$('[data-pb-close-settings]').addEventListener('click', () => { panel = 'widgets'; renderPanel(); });
$$('[data-pb-tab]').forEach(button => button.addEventListener('click', () => { tab = button.dataset.pbTab; renderSettings(); }));
$$('[data-pb-device]').forEach(button => button.addEventListener('click', () => {
  device = button.dataset.pbDevice;
  $$('[data-pb-device]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
  $('[data-pb-frame-wrap]').className = `pb-canvas-frame pb-device-${device}`;
  if (panel === 'settings') renderSettings(); schedulePreview();
}));
$$('[data-pb-action]').forEach(button => button.addEventListener('click', () => {
  if (!selected) return;
  const action = button.dataset.pbAction;
  if (action === 'remove') { commit(remove(history.present, selected)); selected = null; panel = 'widgets'; }
  if (action === 'duplicate') commit(duplicate(history.present, selected));
  if (action === 'move_up') commit(reorder(history.present, selected, -1));
  if (action === 'move_down') commit(reorder(history.present, selected, 1));
  renderPanel();
}));
function undo() { const before = JSON.stringify(history.present); history.undo(); if (before !== JSON.stringify(history.present)) { markDirty(); renderPanel(); schedulePreview(); } }
function redo() { const before = JSON.stringify(history.present); history.redo(); if (before !== JSON.stringify(history.present)) { markDirty(); renderPanel(); schedulePreview(); } }
$('[data-pb-undo]').addEventListener('click', undo);
$('[data-pb-redo]').addEventListener('click', redo);
$('[data-pb-save]').addEventListener('click', save);
status.addEventListener('change', markDirty);
frame.addEventListener('load', attachFrameEvents);
window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
document.addEventListener('keydown', event => {
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') { event.preventDefault(); save(); }
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z' && !event.target.matches('input,textarea')) { event.preventDefault(); if (event.shiftKey) redo(); else undo(); }
});
renderPanel();
