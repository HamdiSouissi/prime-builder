/** Immutable tree operations shared by the editor and its tests. */
export const clone = value => structuredClone(value);
export function id() { return `pb_${globalThis.crypto.randomUUID().replaceAll('-', '').slice(0, 16)}`; }
export function createElement(type) {
  const defaults = {
    container: { layout: 'flex', direction: 'column', columns: 1 }, heading: { level: 2 },
    progress: { value: 0 }, counter: { value: 0 },
    video: { caption_language: 'fr' }, posts: { limit: 3, show_excerpt: 'hide' }, current_content: { source: 'title' },
  };
  return { id: id(), type, settings: clone(defaults[type] || {}), styles: {}, children: [] };
}
export function inheritedStyle(styles, device, name) {
  const preceding = device === 'mobile' ? ['tablet', 'desktop'] : device === 'tablet' ? ['desktop'] : [];
  for (const mode of preceding) {
    const values = styles?.[mode];
    if (values && typeof values === 'object' && Object.hasOwn(values, name)) return { mode, value: values[name] };
  }
  return null;
}
export function find(elements, id) {
  for (const element of elements) {
    if (element.id === id) return element;
    const match = find(element.children || [], id);
    if (match) return match;
  }
  return null;
}
export function locate(elements, id) {
  for (let index = 0; index < elements.length; index++) {
    const element = elements[index];
    if (element.id === id) return { list: elements, index, element };
    const result = locate(element.children || [], id);
    if (result) return result;
  }
  return null;
}
// Return one changed subtree root only when identity and structure stayed intact.
// Multiple edits or any insertion/removal/reordering require a full preview.
export function changedElement(before, after) {
  let target = null;
  function sameShape(left, right) {
    if (!Array.isArray(left) || !Array.isArray(right) || left.length !== right.length) return false;
    for (let index = 0; index < left.length; index++) {
      const oldNode = left[index], newNode = right[index];
      if (!oldNode || !newNode || typeof oldNode !== 'object' || typeof newNode !== 'object' ||
          typeof oldNode.id !== 'string' || oldNode.id !== newNode.id || oldNode.type !== newNode.type) return false;
      const { children: oldChildren = [], ...oldValues } = oldNode;
      const { children: newChildren = [], ...newValues } = newNode;
      if (JSON.stringify(oldValues) !== JSON.stringify(newValues)) {
        if (target !== null) return false;
        target = oldNode.id;
      }
      if (!sameShape(oldChildren, newChildren)) return false;
    }
    return true;
  }
  return sameShape(before, after) ? target : null;
}
export function add(elements, element, parentId = null, containerTypes = ['container']) {
  const next = clone(elements);
  const parent = parentId ? find(next, parentId) : null;
  (parent && containerTypes.includes(parent.type) ? parent.children : next).push(clone(element));
  return next;
}
export function insertAfter(elements, elementId, element) {
  const next = clone(elements);
  const target = locate(next, elementId);
  if (target) target.list.splice(target.index + 1, 0, clone(element));
  return next;
}
export function update(elements, elementId, patch) {
  const next = clone(elements);
  const element = find(next, elementId);
  if (element) Object.assign(element, clone(patch));
  return next;
}
export function remove(elements, elementId) {
  const next = clone(elements);
  const found = locate(next, elementId);
  if (found) found.list.splice(found.index, 1);
  return next;
}
export function reorder(elements, elementId, direction) {
  const next = clone(elements);
  const found = locate(next, elementId);
  if (found) {
    const target = found.index + direction;
    if (target >= 0 && target < found.list.length) {
      [found.list[found.index], found.list[target]] = [found.list[target], found.list[found.index]];
    }
  }
  return next;
}
export function duplicate(elements, elementId) {
  const next = clone(elements);
  const found = locate(next, elementId);
  if (!found) return next;
  const copy = clone(found.element);
  const renew = node => {
    node.id = id();
    // Converted headings keep their historic anchor; a duplicate must not reuse it.
    if (node.type === 'prime_block' && node.settings) delete node.settings.block_anchor;
    (node.children || []).forEach(renew);
  };
  renew(copy);
  found.list.splice(found.index + 1, 0, copy);
  return next;
}
export function moveInto(elements, elementId, parentId, containerTypes = ['container']) {
  const next = clone(elements);
  const source = locate(next, elementId);
  if (!source || elementId === parentId || (parentId && find(source.element.children || [], parentId))) return next;
  const parent = parentId ? find(next, parentId) : null;
  if (parentId && (!parent || !containerTypes.includes(parent.type))) return next;
  source.list.splice(source.index, 1);
  (parent ? parent.children : next).push(source.element);
  return next;
}
export class History {
  constructor(initial, limit = 100, label = '') {
    this.past = []; this.present = clone(initial); this.future = []; this.limit = limit; this.label = label;
  }
  commit(value, label = '') {
    if (JSON.stringify(value) === JSON.stringify(this.present)) return false;
    this.past.push({ value: clone(this.present), label: this.label });
    if (this.past.length > this.limit) this.past.shift();
    this.present = clone(value); this.label = label; this.future = []; return true;
  }
  undo() {
    if (this.past.length) {
      this.future.push({ value: this.present, label: this.label });
      const state = this.past.pop(); this.present = state.value; this.label = state.label;
    }
    return clone(this.present);
  }
  redo() {
    if (this.future.length) {
      this.past.push({ value: this.present, label: this.label });
      const state = this.future.pop(); this.present = state.value; this.label = state.label;
    }
    return clone(this.present);
  }
  entries() {
    return [...this.past, { label: this.label }, ...this.future.slice().reverse()]
      .map((state, index) => ({ index, label: state.label, current: index === this.past.length }));
  }
  jump(index) {
    if (!Number.isInteger(index) || index < 0 || index > this.past.length + this.future.length) return clone(this.present);
    while (this.past.length > index) this.undo();
    while (this.past.length < index) this.redo();
    return clone(this.present);
  }
}
