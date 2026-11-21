import { History, add, changedElement, createElement, duplicate, find, id, inheritedStyle, insertAfter, insertRelative, moveInto, moveRelative, remove, reorder, update } from './model.js';

const root = document.querySelector('[data-pb-app]');
const config = JSON.parse(root.querySelector('[data-pb-config]').value);
const ui = config.ui;
function newElement(type) {
  const element = createElement(type);
  const defaults = config.widgetDefaults?.[type];
  if (defaults) element.settings = { ...element.settings, ...defaults };
  return element;
}
const $ = selector => root.querySelector(selector);
const $$ = selector => [...root.querySelectorAll(selector)];
const originalBlocks = config.document.blocks || [];
const savedLayout = originalBlocks.find(block => block.type === 'prime-builder/layout');
const foreign = Boolean(config.conversionFailed) || (originalBlocks.length > 0 && (!savedLayout || originalBlocks.length !== 1));
let history = new History(savedLayout?.data?.elements || [], 100, ui.history_initial);
let selected = null;
let device = 'desktop';
let tab = 'content';
let panel = 'widgets';
let dirty = false;
let requestNumber = 0;
let previewTimer;
let previewNeedsFull = true;
const previewTargets = new Set();
let clipboard = null;
let contextOpener = null;
let renaming = null;
const collapsed = new Set();
const toolbar = $('[data-pb-selection-toolbar]');
const contextMenu = $('[data-pb-context-menu]');
const frame = $('[data-pb-frame]');
const status = $('[data-pb-status]');
const schedule = $('[data-pb-schedule]');
const parentSelect = $('[data-pb-parent]');
const containerTypes = Object.entries(config.widgets).filter(([, definition]) => definition.container).map(([type]) => type);
const isContainer = node => !!node && containerTypes.includes(node.type);
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
const localKey = `prime-builder:document:${config.userId}:${config.entryId}`;
const favoritesKey = `prime-builder:favorites:${config.userId}`;
let favorites = new Set();
try { const saved = JSON.parse(localStorage.getItem(favoritesKey) || '[]'); if (Array.isArray(saved)) favorites = new Set(saved.filter(item => typeof item === 'string')); } catch { /* Storage is optional. */ }
const defaults = { heading: 'H', text: '¶', image: '▧', button: '↗', container: '▦', separator: '—', spacer: '↕', quote: '❝', alert: '!', progress: '◒', anchor: '#' };

function text(tag, value, className = '') {
  const node = document.createElement(tag); node.textContent = value;
  if (className) node.className = className;
  return node;
}
const confirmation = document.querySelector('[data-pb-confirm]');
let confirmationResolver = null;
let confirmationFocus = null;
function showMessage(message, decision = false) {
  if (confirmation.open || confirmationResolver) return Promise.resolve(false);
  confirmationFocus = document.activeElement;
  confirmation.querySelector('[data-pb-confirm-title]').textContent = decision ? ui.confirm_title : ui.notice_title;
  confirmation.querySelector('[data-pb-confirm-message]').textContent = message;
  const cancel = confirmation.querySelector('[data-pb-confirm-cancel]');
  cancel.hidden = !decision;
  confirmation.returnValue = '';
  const result = new Promise(resolve => { confirmationResolver = resolve; });
  confirmation.showModal();
  (decision ? cancel : confirmation.querySelector('[data-pb-confirm-accept]')).focus();
  return result;
}
confirmation.querySelector('[data-pb-confirm-cancel]').addEventListener('click', () => confirmation.close('cancel'));
confirmation.querySelector('[data-pb-confirm-accept]').addEventListener('click', () => confirmation.close('accept'));
confirmation.addEventListener('close', () => {
  const resolve = confirmationResolver; confirmationResolver = null;
  if (confirmationFocus?.isConnected) confirmationFocus.focus();
  confirmationFocus = null;
  resolve?.(confirmation.returnValue === 'accept');
});
confirmation.addEventListener('keydown', event => {
  if (event.key !== 'Tab') return;
  const focusable = [...confirmation.querySelectorAll('button:not([hidden]):not([disabled])')];
  if (!focusable.length) { event.preventDefault(); return; }
  const first = focusable[0], last = focusable[focusable.length - 1];
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
});
function markDirty() {
  dirty = true; $('[data-pb-save-state]').textContent = ui.unsaved_short;
  try { localStorage.setItem(localKey, JSON.stringify({ elements: history.present, status: status.value, publishedAt: schedule.value, sourceHash: config.sourceHash, time: Date.now() })); } catch { /* Storage can be disabled. */ }
}
function commit(elements, rebuild = false, action = ui.history_change) {
  const target = changedElement(history.present, elements);
  if (!history.commit(elements, action)) return false;
  markDirty();
  if (rebuild) renderPanel();
  schedulePreview(target);
  return true;
}
function ensureLayout() {
  if (!foreign) return true;
  $('[data-pb-conversion-warning]').hidden = false;
  return false;
}
function addWidget(type) {
  if (!config.widgets[type] || config.widgets[type].hidden || !ensureLayout()) return;
  const parent = find(history.present, selected);
  const node = newElement(type);
  selected = node.id; tab = 'content'; panel = 'settings';
  commit(add(history.present, node, isContainer(parent) ? parent.id : null, containerTypes), true, `${ui.add} : ${config.widgets[type].label}`);
  // The widget card is replaced by the settings panel; give keyboard users a useful next focus.
  ($('[data-pb-fields] input, [data-pb-fields] textarea, [data-pb-fields] select') || parentSelect)?.focus({ preventScroll: true });
}
function renderWidgets() {
  const list = $('[data-pb-widget-list]'); list.replaceChildren();
  const search = $('[data-pb-search]').value.trim().toLocaleLowerCase();
  const available = Object.entries(config.widgets).filter(([, definition]) => !definition.hidden && definition.label.toLocaleLowerCase().includes(search));
  const groups = new Map();
  const starred = available.filter(([type]) => favorites.has(type));
  if (starred.length) groups.set('favorites', starred);
  for (const [type, definition] of available) {
    const category = ['structure', 'base', 'pro'].includes(definition.category) ? definition.category : 'base';
    if (!groups.has(category)) groups.set(category, []);
    groups.get(category).push([type, definition]);
  }
  for (const [category, definitions] of groups) {
    list.append(text('h2', ui[category], 'pb-category'));
    const grid = document.createElement('div'); grid.className = 'pb-widget-grid';
    for (const [type, definition] of definitions) {
      const card = document.createElement('div'); card.className = 'pb-widget-card';
      const button = document.createElement('button'); button.type = 'button'; button.className = 'pb-widget';
      button.draggable = true; button.setAttribute('aria-label', `${ui.add} ${definition.label}`);
      button.append(text('span', defaults[type] || '◇', 'pb-widget-icon'), text('span', definition.label));
      button.addEventListener('click', () => addWidget(type));
      button.addEventListener('dragstart', event => { event.dataTransfer.setData('text/prime-builder-widget', type); event.dataTransfer.effectAllowed = 'copy'; });
      const star = text('button', favorites.has(type) ? '★' : '☆', 'pb-favorite'); star.type = 'button';
      star.dataset.pbFavorite = type; star.setAttribute('aria-pressed', String(favorites.has(type)));
      star.setAttribute('aria-label', `${favorites.has(type) ? ui.favorite_remove : ui.favorite_add} : ${definition.label}`);
      star.addEventListener('click', () => {
        if (favorites.has(type)) favorites.delete(type); else favorites.add(type);
        try { localStorage.setItem(favoritesKey, JSON.stringify([...favorites])); } catch { /* Storage is optional. */ }
        renderWidgets(); list.querySelector(`[data-pb-favorite="${CSS.escape(type)}"]`)?.focus();
      });
      card.append(button, star); grid.append(card);
    }
    list.append(grid);
  }
  if (!list.children.length) list.append(text('p', ui.empty, 'pb-empty'));
}
function elementLabel(element) {
  return element.label?.trim() || config.widgets[element.type]?.label || element.type;
}
let dropMarker = null;
function builderDrag(transfer) {
  return transfer && [...transfer.types].some(type => ['text/prime-builder-element', 'text/prime-builder-widget'].includes(type));
}
function clearDropMarker() {
  dropMarker?.removeAttribute('data-pb-drop');
  dropMarker?.removeAttribute('data-pb-drop-axis');
  dropMarker = null;
}
function markDrop(node, placement) {
  if (dropMarker !== node) clearDropMarker();
  dropMarker = node;
  node.dataset.pbDrop = placement.position;
  node.dataset.pbDropAxis = placement.axis;
}
function dropPosition(event, element, node) {
  const rect = node.getBoundingClientRect();
  const parentStyle = node.classList.contains('pb-element') && node.parentElement ? node.ownerDocument.defaultView.getComputedStyle(node.parentElement) : null;
  const horizontal = parentStyle && ((parentStyle.display === 'flex' && parentStyle.flexDirection.startsWith('row')) ||
    (parentStyle.display === 'grid' && parentStyle.gridTemplateColumns.split(' ').filter(Boolean).length > 1));
  const axis = horizontal ? 'horizontal' : 'vertical';
  const start = horizontal ? rect.left : rect.top;
  const size = horizontal ? rect.width : rect.height;
  const point = horizontal ? event.clientX : event.clientY;
  const edge = Math.min(12, Math.max(4, size * 0.2));
  // The top-left gutter also permits dropping inside a short or empty container.
  const gutter = event.clientX <= rect.left + 12 && event.clientY <= rect.top + 12;
  if (isContainer(element) && (gutter || (point >= start + edge && point <= start + size - edge))) return { position: 'inside', axis };
  return { position: point < start + size / 2 ? 'before' : 'after', axis };
}
function applyDrop(transfer, targetId, position, canvas) {
  if (!ensureLayout()) return;
  const target = targetId ? find(history.present, targetId) : null;
  if (targetId && !target) return;
  const inside = target && position === 'inside' && isContainer(target);
  if (position === 'inside' && target && !inside) return;
  const sourceId = transfer.getData('text/prime-builder-element');
  const type = transfer.getData('text/prime-builder-widget');
  let element, next, action;
  if (sourceId) {
    element = find(history.present, sourceId);
    if (!element) return;
    next = target ? (inside ? moveInto(history.present, sourceId, target.id, containerTypes) : moveRelative(history.present, sourceId, target.id, position))
      : moveInto(history.present, sourceId, null, containerTypes);
    const destination = target ? `${ui[`drop_${position}`]} ${elementLabel(target)}` : ui.root;
    action = `${ui.move_to} : ${elementLabel(element)} — ${destination}`;
  } else {
    if (!type || !config.widgets[type] || config.widgets[type].hidden) return;
    element = newElement(type);
    next = target ? (inside ? add(history.present, element, target.id, containerTypes) : insertRelative(history.present, target.id, element, position))
      : add(history.present, element, null, containerTypes);
    action = `${ui.add} : ${config.widgets[type].label}`;
  }
  const previous = { selected, panel, tab };
  selected = element.id; tab = 'content';
  if (canvas || !sourceId) panel = 'settings';
  if (!commit(next, true, action)) {
    ({ selected, panel, tab } = previous);
    $('[data-pb-live]').textContent = ui.drop_invalid;
    return;
  }
  $('[data-pb-live]').textContent = action;
  if (!sourceId) ($('[data-pb-fields] input, [data-pb-fields] textarea, [data-pb-fields] select') || parentSelect)?.focus({ preventScroll: true });
}
function treeControl(label, path) {
  const button = document.createElement('button'); button.type = 'button'; button.className = 'pb-tree-control';
  button.setAttribute('aria-label', label); button.title = label;
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('fill', 'none');
  svg.setAttribute('stroke', 'currentColor'); svg.setAttribute('stroke-width', '1.8'); svg.setAttribute('aria-hidden', 'true');
  const shape = document.createElementNS('http://www.w3.org/2000/svg', 'path'); shape.setAttribute('d', path);
  svg.append(shape); button.append(svg); return button;
}
function renderTree() {
  const target = $('[data-pb-tree]'); target.replaceChildren();
  if (!history.present.length) { target.append(text('p', ui.empty, 'pb-empty')); return; }
  function build(elements) {
    const list = document.createElement('ul'); list.className = 'pb-tree';
    for (const element of elements) {
      const item = document.createElement('li'); const row = document.createElement('div'); row.className = 'pb-tree-row'; row.dataset.pbTreeId = element.id; row.dataset.selected = String(selected === element.id); row.dataset.hidden = String(element.hidden === true);
      const name = elementLabel(element);
      row.draggable = renaming !== element.id;
      row.addEventListener('dragstart', event => { event.stopPropagation(); event.dataTransfer.setData('text/prime-builder-element', element.id); event.dataTransfer.effectAllowed = 'move'; });
      row.addEventListener('dragover', event => {
        if (!builderDrag(event.dataTransfer)) return;
        event.preventDefault(); event.stopPropagation();
        event.dataTransfer.dropEffect = [...event.dataTransfer.types].includes('text/prime-builder-widget') ? 'copy' : 'move';
        markDrop(row, dropPosition(event, element, row));
      });
      row.addEventListener('dragleave', event => { if (!row.contains(event.relatedTarget)) clearDropMarker(); });
      row.addEventListener('drop', event => {
        if (!builderDrag(event.dataTransfer)) return;
        event.preventDefault(); event.stopPropagation();
        const { position } = dropPosition(event, element, row);
        clearDropMarker(); applyDrop(event.dataTransfer, element.id, position, false);
      });
      if (element.children?.length) {
        const closed = collapsed.has(element.id);
        const toggle = treeControl(`${closed ? ui.expand_tree : ui.collapse_tree} : ${name}`, closed ? 'M9 6l6 6-6 6' : 'M6 9l6 6 6-6');
        toggle.dataset.pbTreeCollapse = element.id;
        toggle.setAttribute('aria-expanded', String(!closed)); toggle.setAttribute('aria-controls', `pb-tree-children-${element.id}`);
        toggle.addEventListener('click', () => {
          if (closed) collapsed.delete(element.id); else collapsed.add(element.id);
          renaming = null; renderTree(); target.querySelector(`[data-pb-tree-collapse="${CSS.escape(element.id)}"]`)?.focus();
        });
        row.append(toggle);
      } else {
        const spacer = document.createElement('span'); spacer.className = 'pb-tree-spacer'; spacer.setAttribute('aria-hidden', 'true'); row.append(spacer);
      }
      if (renaming === element.id) {
        const input = document.createElement('input'); input.className = 'pb-tree-rename'; input.maxLength = 80;
        input.value = element.label || ''; input.placeholder = name; input.setAttribute('aria-label', `${ui.rename_element} : ${name}`);
        const finish = saveName => {
          renaming = null;
          if (saveName) commit(update(history.present, element.id, { label: input.value.trim() }), false, `${ui.rename_element} : ${name}`);
          renderTree(); target.querySelector(`[data-pb-tree-rename="${CSS.escape(element.id)}"]`)?.focus();
        };
        input.addEventListener('keydown', event => {
          if (event.key === 'Enter' || event.key === 'Escape') { event.preventDefault(); finish(event.key === 'Enter'); }
        });
        const saveName = treeControl(ui.rename_save, 'M4 12l5 5L20 6');
        const cancelName = treeControl(ui.rename_cancel, 'M6 6l12 12M18 6L6 18');
        saveName.addEventListener('click', () => finish(true)); cancelName.addEventListener('click', () => finish(false));
        row.append(input, saveName, cancelName);
        queueMicrotask(() => { if (input.isConnected) { input.focus(); input.select(); } });
      } else {
        const select = text('button', name, 'pb-tree-select'); select.type = 'button'; select.dataset.pbTreeSelect = element.id;
        select.addEventListener('click', () => { selected = element.id; tab = 'content'; panel = 'settings'; renderPanel(); schedulePreview(); });
        const rename = treeControl(`${ui.rename_element} : ${name}`, 'M4 20l4-1L19 8l-3-3L5 16l-1 4zM14 7l3 3');
        rename.dataset.pbTreeRename = element.id;
        rename.addEventListener('click', () => { renaming = element.id; renderTree(); });
        const visibility = treeControl(`${element.hidden ? ui.show : ui.hide} : ${name}`, element.hidden
          ? 'M3 3l18 18M10 6.2A11 11 0 0 1 12 6c6.5 0 10 6 10 6a16 16 0 0 1-3 3.4M6.2 6.9A15 15 0 0 0 2 12s3.5 6 10 6c1.2 0 2.3-.2 3.3-.5'
          : 'M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12zM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6z');
        visibility.dataset.pbTreeVisibility = element.id;
        visibility.addEventListener('click', () => {
          commit(update(history.present, element.id, { hidden: !element.hidden }), false, `${element.hidden ? ui.show : ui.hide} : ${name}`);
          renderTree(); target.querySelector(`[data-pb-tree-visibility="${CSS.escape(element.id)}"]`)?.focus();
        });
        row.append(select, rename, visibility);
      }
      row.addEventListener('contextmenu', event => {
        event.preventDefault(); event.stopPropagation();
        selected = element.id; tab = 'content'; panel = 'settings'; renderPanel(); schedulePreview();
        openContext(event.clientX, event.clientY, $('[data-pb-context-open]'));
      });
      item.append(row);
      if (element.children?.length) {
        const children = build(element.children); children.id = `pb-tree-children-${element.id}`; children.hidden = collapsed.has(element.id); item.append(children);
      }
      list.append(item);
    }
    return list;
  }
  target.append(build(history.present));
}
const treeDropArea = $('[data-pb-tree]');
treeDropArea.addEventListener('dragover', event => {
  if (!builderDrag(event.dataTransfer)) return;
  event.preventDefault();
  event.dataTransfer.dropEffect = [...event.dataTransfer.types].includes('text/prime-builder-widget') ? 'copy' : 'move';
  clearDropMarker();
});
treeDropArea.addEventListener('drop', event => {
  if (!builderDrag(event.dataTransfer)) return;
  event.preventDefault();
  clearDropMarker(); applyDrop(event.dataTransfer, null, 'inside', false);
});
document.addEventListener('dragend', clearDropMarker);
function freshIds(elements, removeAnchors = false) {
  const copy = structuredClone(elements);
  const visit = node => {
    node.id = id();
    if (removeAnchors && node.type === 'prime_block' && node.settings) delete node.settings.block_anchor;
    (node.children || []).forEach(visit);
  };
  copy.forEach(visit);
  return copy;
}
async function insertTemplate(template) {
  if (!config.canTemplates || !ensureLayout()) return;
  if (template.kind === 'page' && history.present.length && !await showMessage(ui.confirm_template, true)) return;
  const nodes = freshIds(template.elements);
  if (template.kind === 'page') commit(nodes, true, `${ui.insert} : ${template.name}`);
  else {
    let next = history.present;
    const parent = find(next, selected);
    for (const node of nodes) next = add(next, node, isContainer(parent) ? parent.id : null, containerTypes);
    commit(next, true, `${ui.insert} : ${template.name}`);
  }
  selected = null; panel = 'navigator'; renderPanel();
}
function renderLibrary() {
  const list = $('[data-pb-library-list]'); list.replaceChildren();
  for (const template of config.library || []) {
    const card = document.createElement('div'); card.className = 'pb-template-card';
    const info = document.createElement('div'); info.append(text('strong', template.name), text('small', template.kind === 'page' ? ui.page_template : ui.section_template));
    const button = text('button', ui.insert); button.type = 'button'; button.setAttribute('aria-label', `${ui.insert} ${template.name}`);
    button.addEventListener('click', () => insertTemplate(template)); card.append(info, button); list.append(card);
  }
}
async function importDocument(file) {
  if (!config.canTemplates) return;
  if (!file || file.size > 1048576) { await showMessage(ui.invalid_import); return; }
  try {
    const input = JSON.parse(await file.text());
    const layout = Array.isArray(input.blocks) ? input.blocks.find(block => block.type === 'prime-builder/layout')?.data : input;
    if (!layout || !Array.isArray(layout.elements) || layout.version !== 1) throw new Error('invalid');
    const response = await fetch(`/api/prime-builder/v1/documents/${config.entryId}/render`, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf }, body: JSON.stringify({ data: layout }),
    });
    if (!response.ok) throw new Error('invalid');
    const result = await response.json();
    if (!ensureLayout() || (history.present.length && !await showMessage(ui.confirm_template, true))) return;
    selected = null; commit(freshIds(result.data.layout.elements), false, ui.import); panel = 'navigator'; renderPanel();
    $('[data-pb-save-state]').textContent = ui.imported;
  } catch { await showMessage(ui.invalid_import); }
}
function exportDocument() {
  if (!config.canTemplates || foreign) return;
  const payload = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: history.present } }] };
  const url = URL.createObjectURL(new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' }));
  const link = document.createElement('a'); link.href = url; link.download = `prime-builder-${config.entryId}.json`; link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
function sixDigitColor(value, fallback) {
  const match = typeof value === 'string' && value.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);
  if (!match) return fallback;
  const hex = match[1].toLowerCase();
  return `#${hex.length === 3 ? [...hex].map(char => char + char).join('') : hex}`;
}
function makeStyleControl(name, schema, current, onChange) {
  const wrapper = document.createElement('div'); wrapper.className = 'pb-field pb-typed-field';
  const label = text('label', schema.label || ui[name] || name); const input = document.createElement('input');
  input.id = id(); label.htmlFor = input.id; wrapper.append(label);
  const row = document.createElement('div'); row.className = 'pb-typed-row';
  if (schema.type === 'color') {
    input.type = 'text'; input.maxLength = 7;
    const references = { 'kit:primary': sixDigitColor(config.kitColors?.primary, '#c9441f'), 'kit:text': sixDigitColor(config.kitColors?.text, '#1a1d29') };
    input.value = references[current] || current || '';
    const picker = document.createElement('input'); picker.type = 'color';
    picker.setAttribute('aria-label', name === 'color' ? ui.color_picker_text : ui.color_picker_background);
    picker.value = sixDigitColor(input.value, '#000000');
    const reference = document.createElement('select');
    reference.setAttribute('aria-label', name === 'color' ? ui.color_reference_text : ui.color_reference_background);
    for (const [value, title] of [['', ui.color_custom], ['kit:primary', ui.kit_primary], ['kit:text', ui.kit_text]]) {
      const option = text('option', title); option.value = value; reference.append(option);
    }
    reference.value = Object.hasOwn(references, current) ? current : '';
    input.addEventListener('input', () => {
      reference.value = ''; picker.value = sixDigitColor(input.value, '#000000');
      onChange(input.value === '' ? undefined : input.value);
    });
    picker.addEventListener('input', () => { input.value = picker.value; reference.value = ''; onChange(picker.value); });
    reference.addEventListener('change', () => {
      if (reference.value) { input.value = references[reference.value]; picker.value = input.value; onChange(reference.value); }
      else onChange(input.value === '' ? undefined : input.value);
    });
    row.append(input, picker); wrapper.append(row, reference); return wrapper;
  }
  input.type = 'number'; input.min = 0; input.max = 2000; input.step = '0.01';
  const units = document.createElement('select'); units.setAttribute('aria-label', `${ui.unit} : ${ui[name] || name}`);
  for (const unit of ['px', '%', 'em', 'rem', 'vw', 'vh']) { const option = text('option', unit); option.value = unit; units.append(option); }
  const match = typeof current === 'string' ? current.match(/^(\d{1,4}(?:\.\d{1,2})?)(px|%|em|rem|vw|vh)$/) : null;
  input.value = match ? match[1] : Number.isInteger(current) ? String(current) : '';
  units.value = match ? match[2] : 'px';
  const emit = () => {
    input.max = name === 'width' && units.value === '%' ? 100 : 2000;
    if (input.value === '') { onChange(undefined); return; }
    const number = Number(input.value);
    if (!Number.isFinite(number) || number < 0 || number > Number(input.max) || !input.validity.valid) return;
    onChange(units.value === 'px' && Number.isInteger(number) ? number : `${number}${units.value}`);
  };
  input.addEventListener('input', emit); units.addEventListener('change', emit);
  row.append(input, units); wrapper.append(row); return wrapper;
}
function makeField(name, schema, current, onChange) {
  if (schema.type === 'color' || schema.type === 'length') return makeStyleControl(name, schema, current, onChange);
  const wrapper = document.createElement('label'); wrapper.className = 'pb-field';
  wrapper.append(text('span', schema.label || ui[name] || name));
  let input;
  if (schema.type === 'enum') {
    input = document.createElement('select');
    const option = text('option', ui.default_option); option.value = ''; input.append(option);
    for (const value of schema.values) { const option = text('option', schema.option_labels?.[value] || ui[value] || value); option.value = value; input.append(option); }
  } else if (schema.type === 'rich' || schema.type === 'multiline' || schema.type === 'media_list' || schema.type === 'json_object') {
    input = document.createElement('textarea');
    if (schema.max != null) input.maxLength = schema.max;
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
function renderParentOptions(selectedElement) {
  parentSelect.replaceChildren();
  const rootOption = text('option', ui.root); rootOption.value = ''; parentSelect.append(rootOption);
  let currentParent = '';
  let containerNumber = 0;
  function walk(nodes, parentId = '', depth = 0) {
    for (const node of nodes) {
      if (node.id === selectedElement.id) { currentParent = parentId; continue; }
      if (isContainer(node)) {
        containerNumber += 1;
        const name = node.label?.trim() || (typeof node.settings?.title === 'string' && node.settings.title.trim() ? node.settings.title.trim() : `${config.widgets[node.type]?.label || node.type} ${containerNumber}`);
        const option = text('option', `${'— '.repeat(depth)}${name}`);
        option.value = node.id; parentSelect.append(option);
        walk(node.children || [], node.id, depth + 1);
      }
    }
  }
  walk(history.present); parentSelect.value = currentParent;
}
let mediaDependencies;
async function openMedia(elementId, urlField, kind = 'image') {
  try {
    if (!mediaDependencies) {
      const stylesheet = new Promise((resolve, reject) => {
        const link = document.createElement('link'); link.rel = 'stylesheet'; link.href = config.mediaCssUrl;
        link.addEventListener('load', resolve, { once: true }); link.addEventListener('error', reject, { once: true });
        document.head.insertBefore(link, document.querySelector('[data-pb-editor-css]'));
      });
      mediaDependencies = Promise.all([import('/assets/admin/js/media/picker.js'), import('/assets/admin/js/i18n.js'), stylesheet]);
    }
    const [picker, i18n] = await mediaDependencies;
    i18n.setTranslations(config.mediaMessages || {});
    picker.openMediaPicker({ multiple: false, kinds: [kind], title: ui.choose_media, onSelect: selection => {
      const media = Array.isArray(selection) ? selection[0] : selection;
      const node = find(history.present, elementId);
      if (!node || !media || typeof media.url !== 'string') return;
      const settings = { ...node.settings, [urlField]: media.url };
      if (['image', 'card'].includes(node.type) && typeof media.alt === 'string') settings.alt = media.alt;
      if (node.type === 'image') {
        for (const key of ['width', 'height']) if (Number.isInteger(media[key]) && media[key] > 0 && media[key] <= 10000) settings[key] = media[key];
      }
      commit(update(history.present, elementId, { settings }), false, ui.choose_media); renderSettings();
    } });
    const overlay = document.getElementById('pc-media-picker-modal');
    const grid = overlay?.querySelector('[data-prime-picker-grid]');
    grid?.setAttribute('aria-label', ui.media_grid);
    if (overlay && !overlay.dataset.pbFocusTrap) {
      overlay.dataset.pbFocusTrap = 'true';
      overlay.addEventListener('keydown', event => {
        if (event.key !== 'Tab' || overlay.hidden) return;
        const focusable = [...overlay.querySelectorAll('button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[href],[tabindex]:not([tabindex="-1"])')]
          .filter(node => node.getClientRects().length > 0);
        if (!focusable.length) return;
        const first = focusable[0], last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
      });
    }
  } catch {
    mediaDependencies = null;
    $('[data-pb-save-state]').textContent = ui.media_error;
  }
}
function renderSettings() {
  const element = find(history.present, selected);
  if (!element) { panel = 'widgets'; renderPanel(); return; }
  const definition = config.widgets[element.type];
  renderParentOptions(element);
  $('[data-pb-selected-title]').textContent = elementLabel(element);
  $$('[data-pb-tab]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.pbTab === tab)));
  const fields = $('[data-pb-fields]'); fields.replaceChildren();
  if (definition?.readOnly) {
    fields.append(text('p', ui.original_read_only, 'pb-intro'));
    return;
  }
  if (tab === 'content') {
    for (const [name, schema] of Object.entries(definition?.fields || {})) {
      fields.append(makeField(name, schema, element.settings[name], value => {
        const current = find(history.present, selected); if (!current) return;
        const settings = { ...current.settings };
        if (value === undefined) delete settings[name]; else settings[name] = value;
        commit(update(history.present, selected, { settings }), false, `${ui.content} : ${schema.label || ui[name] || name}`);
      }));
      if ((['image', 'video', 'audio'].includes(element.type) && name === 'url') || (element.type === 'card' && name === 'image_url')) {
        const choose = text('button', ui.choose_media, 'pb-media-button'); choose.type = 'button'; choose.dataset.pbMedia = '';
        choose.addEventListener('click', () => openMedia(element.id, name, ['video', 'audio'].includes(element.type) ? element.type : 'image')); fields.append(choose);
      }
    }
    if (!fields.children.length) fields.append(text('p', ui.empty, 'pb-empty'));
  } else {
    fields.append(text('p', ui.responsive_help, 'pb-intro'));
    const schemas = tab === 'style'
      ? { color: { type: 'color' }, background: { type: 'color' }, font_size: { type: 'length' }, align: { type: 'enum', values: ['left', 'center', 'right'] }, radius: { type: 'length' } }
      : { padding: { type: 'length' }, margin: { type: 'length' }, gap: { type: 'length' }, min_height: { type: 'length' }, width: { type: 'length' },
        ...(isContainer(element) ? {
          layout_mode: { type: 'enum', values: ['flex', 'grid'] }, flow: { type: 'enum', values: ['row', 'column'] },
          grid_columns: { type: 'integer', min: 1, max: 6 }, wrap: { type: 'enum', values: ['wrap', 'nowrap'] },
          justify: { type: 'enum', values: ['start', 'center', 'end', 'space-between'] }, items_align: { type: 'enum', values: ['start', 'center', 'end', 'stretch'] },
        } : {}),
      };
    for (const [name, schema] of Object.entries(schemas)) {
      const group = document.createElement('div'); group.className = 'pb-style-field';
      const hint = text('small', '', 'pb-inherit-hint'); hint.id = id();
      const showSource = styles => {
        const own = styles?.[device];
        if (own && Object.hasOwn(own, name)) { hint.textContent = `${ui.value_defined} ${ui[device]}.`; return; }
        const inherited = inheritedStyle(styles, device, name);
        if (!inherited) { hint.textContent = ui.value_default; return; }
        const value = inherited.value === 'kit:primary' ? ui.kit_primary : inherited.value === 'kit:text' ? ui.kit_text
          : typeof inherited.value === 'number' && schema.type === 'length' ? `${inherited.value}px` : ui[inherited.value] || String(inherited.value);
        hint.textContent = `${ui.value_inherited} ${ui[inherited.mode]} : ${value}`;
      };
      const field = makeField(name, schema, element.styles?.[device]?.[name], value => {
        const current = find(history.present, selected); if (!current) return;
        const styles = structuredClone(current.styles || {}); styles[device] ||= {};
        if (value === undefined) delete styles[device][name]; else styles[device][name] = value;
        commit(update(history.present, selected, { styles }), false, `${ui[tab]} : ${ui[name] || name}`);
        showSource(styles);
      });
      field.querySelectorAll('input,select,textarea').forEach(control => control.setAttribute('aria-describedby', hint.id));
      showSource(element.styles || {}); group.append(field, hint); fields.append(group);
    }
  }
}
function renderPanel() {
  $('[data-pb-widget-panel]').hidden = panel !== 'widgets';
  $('[data-pb-navigator-panel]').hidden = panel !== 'navigator';
  $('[data-pb-settings-panel]').hidden = panel !== 'settings';
  $('[data-pb-library-panel]').hidden = panel !== 'library';
  $$('[data-pb-panel]').forEach(button => {
    const active = button.dataset.pbPanel === panel;
    button.classList.toggle('pb-nav-active', active); button.setAttribute('aria-pressed', String(active));
  });
  if (panel === 'widgets') renderWidgets();
  if (panel === 'navigator') renderTree();
  if (panel === 'library') renderLibrary();
  if (panel === 'settings') renderSettings();
  positionToolbar();
}
function positionToolbar() {
  const doc = frame.contentDocument;
  const node = selected && doc ? doc.querySelector(`[data-pb-id="${CSS.escape(selected)}"]`) : null;
  if (!node) { toolbar.hidden = true; return; }
  const outer = frame.getBoundingClientRect(), inner = node.getBoundingClientRect();
  const left = Math.max(0, outer.left, outer.left + inner.left);
  const right = Math.min(innerWidth, outer.right, outer.left + inner.right);
  const top = Math.max(0, outer.top, outer.top + inner.top);
  const bottom = Math.min(innerHeight, outer.bottom, outer.top + inner.bottom);
  if (right <= left || bottom <= top) { toolbar.hidden = true; return; }
  toolbar.hidden = false;
  toolbar.style.left = `${Math.max(8, Math.min(innerWidth - toolbar.offsetWidth - 8, right - toolbar.offsetWidth))}px`;
  toolbar.style.top = `${Math.max(8, Math.min(innerHeight - toolbar.offsetHeight - 8, top - toolbar.offsetHeight))}px`;
}
function closeContext(restore = false) {
  if (contextMenu.hidden) return;
  contextMenu.hidden = true;
  $$('[data-pb-context-open], [data-pb-toolbar-menu]').forEach(button => button.setAttribute('aria-expanded', 'false'));
  if (restore) {
    const fallback = $('[data-pb-context-open]');
    const target = contextOpener?.isConnected && contextOpener.getClientRects().length ? contextOpener : fallback;
    if (target?.getClientRects().length) target.focus();
  }
  contextOpener = null;
}
function openContext(x, y, opener) {
  const element = find(history.present, selected);
  if (!element || !ensureLayout()) return;
  const readOnly = Boolean(config.widgets[element.type]?.readOnly);
  contextMenu.querySelector('[data-pb-context="paste"]').disabled = !clipboard;
  contextMenu.querySelector('[data-pb-context="paste_style"]').disabled = !clipboard || readOnly;
  contextMenu.querySelector('[data-pb-context="reset_style"]').disabled = readOnly || !Object.keys(element.styles || {}).length;
  contextOpener = opener;
  contextMenu.hidden = false;
  contextMenu.style.left = '0px'; contextMenu.style.top = '0px';
  contextMenu.style.left = `${Math.max(8, Math.min(innerWidth - contextMenu.offsetWidth - 8, x))}px`;
  contextMenu.style.top = `${Math.max(8, Math.min(innerHeight - contextMenu.offsetHeight - 8, y))}px`;
  $$('[data-pb-context-open], [data-pb-toolbar-menu]').forEach(button => button.setAttribute('aria-expanded', 'true'));
  contextMenu.querySelector('[role="menuitem"]:not([disabled])')?.focus();
}
function openContextFrom(button) {
  const rect = button.getBoundingClientRect();
  openContext(rect.left, rect.bottom + 4, button);
}
function performElementAction(action) {
  const element = find(history.present, selected);
  if (!element || !ensureLayout()) return;
  const readOnly = Boolean(config.widgets[element.type]?.readOnly);
  if (action === 'copy') {
    clipboard = structuredClone(element);
    $('[data-pb-live]').textContent = ui.copied;
    return;
  }
  if (action === 'paste') {
    if (!clipboard) return;
    const copy = freshIds([clipboard], true)[0];
    const next = isContainer(element) ? add(history.present, copy, element.id, containerTypes) : insertAfter(history.present, element.id, copy);
    selected = copy.id; tab = 'content'; panel = 'settings';
    commit(next, true, `${ui.paste} : ${elementLabel(copy)}`);
    $('[data-pb-live]').textContent = ui.pasted;
    return;
  }
  const label = `${ui[action] || ui.history_change} : ${elementLabel(element)}`;
  let changed = false;
  if (action === 'paste_style' && clipboard && !readOnly) changed = commit(update(history.present, element.id, { styles: structuredClone(clipboard.styles || {}) }), false, label);
  if (action === 'reset_style' && !readOnly) changed = commit(update(history.present, element.id, { styles: {} }), false, label);
  if (action === 'duplicate') changed = commit(duplicate(history.present, element.id), false, label);
  if (action === 'move_up') changed = commit(reorder(history.present, element.id, -1), false, label);
  if (action === 'move_down') changed = commit(reorder(history.present, element.id, 1), false, label);
  if (action === 'remove') { changed = commit(remove(history.present, element.id), false, label); selected = null; panel = 'widgets'; }
  if (changed) $('[data-pb-live]').textContent = label;
  else if (action === 'move_up' || action === 'move_down') $('[data-pb-live]').textContent = ui.drop_invalid;
  renderPanel();
}
function cssLength(value, width = false) {
  if (Number.isInteger(value) && value >= 0 && value <= 2000) return `${value}px`;
  const match = typeof value === 'string' && value.match(/^(\d{1,4}(?:\.\d{1,2})?)(px|%|em|rem|vw|vh)$/);
  if (!match || Number(match[1]) > 2000 || (width && match[2] === '%' && Number(match[1]) > 100)) return null;
  return value;
}
function cssColor(value) {
  if (value === 'kit:primary') return 'var(--pb-kit-primary)';
  if (value === 'kit:text') return 'var(--pb-kit-text)';
  return typeof value === 'string' && /^#[a-f\d]{3}([a-f\d]{3})?$/i.test(value) ? value : null;
}
function applyVisualStyles(doc) {
  function walk(elements) {
    for (const element of elements) {
      const node = doc.querySelector(`[data-pb-id="${CSS.escape(element.id)}"]`);
      if (node) {
        node.draggable = true;
        if (element.type === 'container') {
          node.style.display = element.settings.layout === 'grid' ? 'grid' : 'flex';
          node.style.flexDirection = element.settings.direction === 'row' ? 'row' : 'column';
          node.style.gridTemplateColumns = `repeat(${Math.max(1, Math.min(6, Number(element.settings.columns) || 1))},minmax(0,1fr))`;
        }
        const order = device === 'mobile' ? ['desktop', 'tablet', 'mobile'] : device === 'tablet' ? ['desktop', 'tablet'] : ['desktop'];
        for (const mode of order) {
          const style = element.styles?.[mode] || {};
          const lengths = { padding: 'padding', margin: 'margin', gap: 'gap', font_size: 'fontSize', radius: 'borderRadius', min_height: 'minHeight' };
          for (const [key, value] of Object.entries(style)) {
            if (key in lengths && cssLength(value) !== null) {
              node.style[lengths[key]] = cssLength(value);
              if (key === 'font_size') node.querySelectorAll('h1,h2,h3,h4,h5,h6,p,a,span,li,blockquote,cite,summary').forEach(child => { child.style.fontSize = 'inherit'; });
            }
            else if (['color', 'background'].includes(key) && cssColor(value) !== null) {
              node.style[key === 'color' ? 'color' : 'backgroundColor'] = cssColor(value);
              if (key === 'color') node.querySelectorAll('h1,h2,h3,h4,h5,h6,p,a,span,li,blockquote,cite,summary').forEach(child => { child.style.color = 'inherit'; });
            }
            else if (key === 'align' && ['left', 'center', 'right'].includes(value)) node.style.textAlign = value;
            else if (key === 'width' && cssLength(value, true) !== null) node.style.width = cssLength(value, true);
            else if (key === 'layout_mode' && ['flex', 'grid'].includes(value)) node.style.display = value;
            else if (key === 'flow' && ['row', 'column'].includes(value)) node.style.flexDirection = value;
            else if (key === 'grid_columns' && Number.isInteger(value) && value >= 1 && value <= 6) node.style.gridTemplateColumns = `repeat(${value},minmax(0,1fr))`;
            else if (key === 'wrap' && ['wrap', 'nowrap'].includes(value)) node.style.flexWrap = value;
            else if ((key === 'justify' && ['start', 'center', 'end', 'space-between'].includes(value)) || (key === 'items_align' && ['start', 'center', 'end', 'stretch'].includes(value))) {
              node.style[key === 'justify' ? 'justifyContent' : 'alignItems'] = value === 'start' ? 'flex-start' : value === 'end' ? 'flex-end' : value;
            }
          }
        }
        if (element.id === selected) { node.style.outline = '2px solid #ed6647'; node.style.outlineOffset = '2px'; }
      }
      walk(element.children || []);
    }
  }
  walk(history.present);
}
async function renderPreview(sequence) {
  if (foreign || !frame.contentDocument) return;
  // Keep the complete tree in the request for server-side validation, but render
  // just one subtree when no structural or other pending changes exist.
  const target = !previewNeedsFull && previewTargets.size === 1 ? [...previewTargets][0] : null;
  try {
    const response = await fetch(`/api/prime-builder/v1/documents/${config.entryId}/render`, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf },
      body: JSON.stringify({ data: { version: 1, elements: history.present }, ...(target === null ? {} : { target }) }),
    });
    if (!response.ok || sequence !== requestNumber) return;
    const result = await response.json();
    if (sequence !== requestNumber) return;
    const doc = frame.contentDocument;
    if (!doc?.head) return;
    if (!doc.querySelector('[data-pb-base-css]')) {
      const link = doc.createElement('link'); link.rel = 'stylesheet'; link.href = config.publicCssUrl; link.dataset.pbBaseCss = ''; doc.head.append(link);
    }
    if (!doc.querySelector('[data-pb-kit-css]')) {
      const link = doc.createElement('link'); link.rel = 'stylesheet'; link.href = config.kitCssUrl; link.dataset.pbKitCss = ''; doc.head.append(link);
    }
    // The server stylesheet represents the last saved version. Disable it in the
    // editor iframe so clearing an unsaved value really returns to inheritance.
    doc.querySelectorAll('link[rel="stylesheet"]').forEach(link => {
      const url = new URL(link.href, doc.baseURI);
      if (url.origin === location.origin && url.pathname.startsWith('/prime-builder/css/')) link.disabled = true;
    });
    if (target !== null) {
      const selector = `[data-pb-id="${CSS.escape(target)}"]`;
      const current = doc.querySelector(selector);
      const fragment = doc.createElement('template'); fragment.innerHTML = result.data.html;
      const replacement = fragment.content.querySelector(selector);
      if (!current || !replacement || result.data.target !== target) {
        // The iframe may have reloaded or a third-party widget may have failed.
        // Reconcile the entire canvas rather than silently losing an edit.
        schedulePreview();
        return;
      }
      current.replaceWith(replacement);
    } else {
      const current = doc.querySelector('.pb-layout');
      if (current) current.outerHTML = result.data.html;
      else {
        const area = doc.querySelector('.aurora-prose') || doc.querySelector('main');
        if (!area) return;
        area.innerHTML = result.data.html;
      }
    }
    previewNeedsFull = false;
    previewTargets.clear();
    applyVisualStyles(doc);
    positionToolbar();
  } catch { if (sequence === requestNumber) $('[data-pb-save-state]').textContent = ui.connection_error; }
}
function schedulePreview(target = null) {
  if (target === null) { previewNeedsFull = true; previewTargets.clear(); }
  else previewTargets.add(target);
  clearTimeout(previewTimer);
  // Invalidate in-flight responses as soon as a newer change is queued.
  const sequence = ++requestNumber;
  previewTimer = setTimeout(() => renderPreview(sequence), 180);
}
function attachFrameEvents() {
  const doc = frame.contentDocument;
  if (!doc) return;
  doc.documentElement.dataset.pbEditorPreview = '';
  doc.addEventListener('keydown', shortcuts);
  doc.addEventListener('click', event => {
    const target = event.target.closest('[data-pb-id]');
    if (target) { event.preventDefault(); event.stopPropagation(); selected = target.dataset.pbId; panel = 'settings'; tab = 'content'; renderPanel(); schedulePreview(); }
    else if (event.target.closest('a')) event.preventDefault();
  }, true);
  doc.addEventListener('contextmenu', event => {
    const target = event.target.closest('[data-pb-id]');
    if (!target) return;
    event.preventDefault(); event.stopPropagation();
    selected = target.dataset.pbId; panel = 'settings'; tab = 'content'; renderPanel(); schedulePreview();
    const rect = frame.getBoundingClientRect();
    openContext(rect.left + event.clientX, rect.top + event.clientY, $('[data-pb-context-open]'));
  });
  doc.addEventListener('pointerdown', () => closeContext());
  doc.addEventListener('scroll', positionToolbar, true);
  doc.addEventListener('dragstart', event => {
    const target = event.target.closest('[data-pb-id]');
    if (!target || !event.dataTransfer) return;
    event.dataTransfer.setData('text/prime-builder-element', target.dataset.pbId);
    event.dataTransfer.effectAllowed = 'move';
  });
  doc.addEventListener('dragover', event => {
    if (!builderDrag(event.dataTransfer)) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = [...event.dataTransfer.types].includes('text/prime-builder-widget') ? 'copy' : 'move';
    const target = event.target.closest('[data-pb-id]');
    const element = target ? find(history.present, target.dataset.pbId) : null;
    if (target && element) markDrop(target, dropPosition(event, element, target));
    else clearDropMarker();
  });
  doc.addEventListener('drop', event => {
    if (!builderDrag(event.dataTransfer)) return;
    event.preventDefault();
    const target = event.target.closest('[data-pb-id]');
    const element = target ? find(history.present, target.dataset.pbId) : null;
    const position = target && element ? dropPosition(event, element, target).position : 'inside';
    clearDropMarker(); applyDrop(event.dataTransfer, element?.id || null, position, true);
  });
  doc.addEventListener('dragend', clearDropMarker);
  schedulePreview();
}
async function save() {
  if (!ensureLayout()) return;
  const snapshot = JSON.stringify(history.present);
  const currentStatus = status.value;
  const currentSchedule = schedule.value;
  if (currentStatus === 'scheduled' && (!currentSchedule || !Number.isFinite(Date.parse(`${currentSchedule}Z`)) || Date.parse(`${currentSchedule}Z`) <= Date.now())) {
    $('[data-pb-save-state]').textContent = ui.schedule_invalid; schedule.focus(); return;
  }
  const data = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: history.present } }] };
  const button = $('[data-pb-save]'); button.disabled = true; $('[data-pb-save-state]').textContent = ui.saving;
  try {
    const response = await fetch(`/api/prime-builder/v1/documents/${config.entryId}`, {
      method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf },
      body: JSON.stringify({ content: data, source_hash: config.sourceHash, status: currentStatus, ...(currentStatus === 'scheduled' ? { published_at: currentSchedule } : currentStatus === 'published' && config.status !== 'published' ? { published_at: '' } : {}) }),
    });
    const result = await response.json();
    if (!response.ok) { $('[data-pb-save-state]').textContent = result?.error?.message || ui.save_error; return; }
    config.sourceHash = result.data.source_hash;
    config.status = result.data.status; config.publishedAt = result.data.published_at;
    if (snapshot === JSON.stringify(history.present) && currentStatus === status.value && currentSchedule === schedule.value) {
      status.value = result.data.status; updateSchedule();
      history = new History(result.data.content.blocks[0].data.elements, 100, ui.history_initial);
      dirty = false;
      try { localStorage.removeItem(localKey); } catch { /* Ignore unavailable storage. */ }
      $('[data-pb-save-state]').textContent = ui.saved;
      frame.src = config.previewUrl;
      renderPanel();
    } else markDirty();
  } catch { $('[data-pb-save-state]').textContent = ui.connection_error; }
  finally { button.disabled = false; }
}
for (const [value, label] of [['draft', ui.draft], ...(config.canPublish ? [['published', ui.publish], ['scheduled', ui.scheduled]] : [])]) {
  const option = text('option', label); option.value = value; status.append(option);
}
if (![...status.options].some(option => option.value === config.status)) {
  const option = text('option', config.status); option.value = config.status; status.append(option);
}
status.value = config.status;
schedule.value = (config.publishedAt || '').slice(0, 16).replace(' ', 'T');
function updateSchedule() { $('[data-pb-schedule-wrap]').hidden = status.value !== 'scheduled'; }
updateSchedule();
async function restoreLocalDraft() {
  try {
    const cached = JSON.parse(localStorage.getItem(localKey) || 'null');
    // An old draft must never silently replace a newer or previously unconverted document.
    if (!foreign && cached && cached.sourceHash === config.sourceHash && Array.isArray(cached.elements) && await showMessage(ui.autosave_found, true)) {
      history = new History(cached.elements, 100, ui.history_initial);
      if ([...status.options].some(o => o.value === cached.status)) status.value = cached.status;
      if (typeof cached.publishedAt === 'string') schedule.value = cached.publishedAt;
      updateSchedule(); markDirty(); renderPanel(); schedulePreview();
    }
  } catch { /* Unavailable or corrupt local drafts do not block editing. */ }
}
if (config.converted && !foreign) { dirty = true; $('[data-pb-save-state]').textContent = ui.unsaved_short; }
if (foreign) { $('[data-pb-conversion-warning]').hidden = false; $('[data-pb-save]').disabled = true; status.disabled = true; }
restoreLocalDraft();
$('[data-pb-search]').addEventListener('input', renderWidgets);
$('[data-pb-import]').addEventListener('change', event => { importDocument(event.target.files?.[0]); event.target.value = ''; });
$('[data-pb-export]').addEventListener('click', exportDocument);
$$('[data-pb-panel]').forEach(button => button.addEventListener('click', () => {
  if (button.dataset.pbPanel === 'library' && !config.canTemplates) return;
  panel = button.dataset.pbPanel; renderPanel();
}));
$('[data-pb-close-settings]').addEventListener('click', () => { panel = 'widgets'; renderPanel(); });
$$('[data-pb-tab]').forEach(button => button.addEventListener('click', () => { tab = button.dataset.pbTab; renderSettings(); }));
$$('[data-pb-device]').forEach(button => button.addEventListener('click', () => {
  device = button.dataset.pbDevice;
  $$('[data-pb-device]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
  $('[data-pb-frame-wrap]').className = `pb-canvas-frame pb-device-${device}`;
  $('[data-pb-frame-wrap]').style.maxWidth = device === 'desktop' ? '' : `${device === 'tablet' ? config.tabletBreakpoint : Math.min(config.mobileBreakpoint, 375)}px`;
  if (panel === 'settings') renderSettings(); schedulePreview();
}));
$$('[data-pb-action]').forEach(button => button.addEventListener('click', () => performElementAction(button.dataset.pbAction)));
$$('[data-pb-tool]').forEach(button => button.addEventListener('click', () => performElementAction(button.dataset.pbTool)));
$$('[data-pb-context-open], [data-pb-toolbar-menu]').forEach(button => button.addEventListener('click', () => {
  if (contextMenu.hidden) openContextFrom(button); else closeContext(true);
}));
contextMenu.addEventListener('click', event => {
  const button = event.target.closest('[data-pb-context]');
  if (!button || button.disabled) return;
  const action = button.dataset.pbContext;
  closeContext(); performElementAction(action);
  const nextFocus = panel === 'settings' ? $('[data-pb-context-open]') : $('[data-pb-panel="widgets"]');
  nextFocus?.focus();
});
contextMenu.addEventListener('keydown', event => {
  const items = [...contextMenu.querySelectorAll('[role="menuitem"]:not([disabled])')];
  const index = items.indexOf(document.activeElement);
  if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
    event.preventDefault();
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : (index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
    items[next]?.focus();
  } else if (event.key === 'Escape' || event.key === 'Tab') { event.preventDefault(); closeContext(true); }
});
toolbar.addEventListener('keydown', event => {
  const buttons = [...toolbar.querySelectorAll('button')];
  const index = buttons.indexOf(document.activeElement);
  if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
    event.preventDefault();
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length;
    buttons[next]?.focus();
  }
});
document.addEventListener('pointerdown', event => {
  if (!contextMenu.hidden && !contextMenu.contains(event.target) && !event.target.closest('[data-pb-context-open], [data-pb-toolbar-menu]')) closeContext();
});
window.addEventListener('resize', positionToolbar);
window.addEventListener('scroll', positionToolbar, true);
const historyDialog = document.querySelector('[data-pb-history]');
const historyList = historyDialog.querySelector('[data-pb-history-list]');
function renderHistory() {
  historyList.replaceChildren();
  for (const entry of history.entries()) {
    const item = document.createElement('li');
    const button = text('button', entry.label || ui.history_change); button.type = 'button'; button.dataset.pbHistoryIndex = String(entry.index);
    if (entry.current) { button.setAttribute('aria-current', 'step'); button.append(text('span', ui.history_current)); }
    button.addEventListener('click', () => {
      const before = JSON.stringify(history.present);
      history.jump(entry.index);
      if (before !== JSON.stringify(history.present)) { markDirty(); renderPanel(); schedulePreview(); }
      renderHistory(); historyList.querySelector(`[data-pb-history-index="${entry.index}"]`)?.focus();
    });
    item.append(button); historyList.append(item);
  }
}
function openHistory() {
  renderHistory();
  if (!historyDialog.open) historyDialog.showModal();
  historyList.querySelector('[aria-current="step"]')?.focus();
}
$('[data-pb-history-open]').addEventListener('click', openHistory);
historyDialog.querySelector('[data-pb-history-close]').addEventListener('click', () => historyDialog.close());
function undo() { const before = JSON.stringify(history.present); history.undo(); if (before !== JSON.stringify(history.present)) { markDirty(); renderPanel(); schedulePreview(); if (historyDialog.open) { renderHistory(); historyList.querySelector('[aria-current="step"]')?.focus(); } } }
function redo() { const before = JSON.stringify(history.present); history.redo(); if (before !== JSON.stringify(history.present)) { markDirty(); renderPanel(); schedulePreview(); if (historyDialog.open) { renderHistory(); historyList.querySelector('[aria-current="step"]')?.focus(); } } }
$('[data-pb-undo]').addEventListener('click', undo);
$('[data-pb-redo]').addEventListener('click', redo);
$('[data-pb-save]').addEventListener('click', save);
status.addEventListener('change', () => { updateSchedule(); markDirty(); });
schedule.addEventListener('input', markDirty);
parentSelect.addEventListener('change', () => {
  const element = find(history.present, selected);
  if (!element) return;
  const destination = parentSelect.selectedOptions[0]?.textContent || ui.root;
  const label = `${ui.move_to} : ${elementLabel(element)} — ${destination}`;
  const changed = commit(moveInto(history.present, selected, parentSelect.value || null, containerTypes), false, label);
  $('[data-pb-live]').textContent = changed ? label : ui.drop_invalid;
  renderParentOptions(find(history.present, selected)); parentSelect.focus();
});
frame.addEventListener('load', attachFrameEvents);
root.addEventListener('click', async event => {
  const link = event.target.closest('a[href]');
  if (!dirty || !link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target || link.hasAttribute('download')) return;
  const destination = new URL(link.href, location.href);
  if (destination.origin === location.origin && destination.pathname === location.pathname && destination.search === location.search) return;
  event.preventDefault();
  if (await showMessage(ui.unsaved, true)) { dirty = false; location.assign(destination.href); }
});
window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ui.unsaved; } });
const commandDialog = document.querySelector('[data-pb-commands]');
const commandSearch = document.querySelector('[data-pb-command-search]');
function renderCommands() {
  const target = document.querySelector('[data-pb-command-list]'); target.replaceChildren();
  const commands = [
    [ui.save, save], [ui.undo, undo], [ui.redo, redo], [ui.history, openHistory],
    [ui.widgets, () => { panel = 'widgets'; renderPanel(); }],
    [ui.navigator, () => { panel = 'navigator'; renderPanel(); }],
    ...(config.canTemplates ? [[ui.library, () => { panel = 'library'; renderPanel(); }]] : []),
    ...Object.entries(config.widgets).filter(([, definition]) => !definition.hidden).map(([type, definition]) => [`${ui.add} ${definition.label}`, () => addWidget(type)]),
  ];
  const filter = commandSearch.value.trim().toLocaleLowerCase();
  for (const [label, action] of commands) {
    if (!label.toLocaleLowerCase().includes(filter)) continue;
    const button = text('button', label); button.type = 'button';
    button.addEventListener('click', () => { commandDialog.close(); action(); }); target.append(button);
  }
  if (!target.children.length) target.append(text('p', ui.command_empty, 'pb-empty'));
}
function openCommands() { commandSearch.value = ''; renderCommands(); if (!commandDialog.open) commandDialog.showModal(); commandSearch.focus(); }
$('[data-pb-command-open]').addEventListener('click', openCommands);
document.querySelector('[data-pb-command-close]').addEventListener('click', () => commandDialog.close());
commandSearch.addEventListener('input', renderCommands);
function shortcuts(event) {
  if (selected && (event.key === 'ContextMenu' || (event.shiftKey && event.key === 'F10')) && !event.target.closest('input,textarea,select,[contenteditable="true"]')) {
    event.preventDefault();
    panel = 'settings'; renderPanel();
    openContextFrom($('[data-pb-context-open]'));
    return;
  }
  if (!(event.ctrlKey || event.metaKey)) return;
  const key = event.key.toLowerCase();
  if (key === 's') { event.preventDefault(); save(); }
  if (key === 'k') { event.preventDefault(); openCommands(); }
  if ((key === 'z' || key === 'y') && !event.target.matches('input,textarea,[contenteditable="true"]')) {
    event.preventDefault(); if (key === 'y' || event.shiftKey) redo(); else undo();
  }
}
document.addEventListener('keydown', shortcuts);
renderPanel();
