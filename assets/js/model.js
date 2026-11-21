/** Immutable tree operations shared by the editor and its tests. */
export const clone = value => structuredClone(value);
export function id() { return `pb_${globalThis.crypto.randomUUID().replaceAll('-', '').slice(0, 16)}`; }
export function createElement(type) {
  const defaults = {
    container: { layout: 'flex', direction: 'column', columns: 1 }, heading: { level: 2 },
    progress: { value: 0 }, counter: { value: 0 },
    video: { caption_language: 'fr' },
  };
  return { id: id(), type, settings: clone(defaults[type] || {}), styles: {}, children: [] };
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
export function add(elements, element, parentId = null, containerTypes = ['container']) {
  const next = clone(elements);
  const parent = parentId ? find(next, parentId) : null;
  (parent && containerTypes.includes(parent.type) ? parent.children : next).push(clone(element));
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
  const renew = node => { node.id = id(); (node.children || []).forEach(renew); };
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
  constructor(initial, limit = 100) { this.past = []; this.present = clone(initial); this.future = []; this.limit = limit; }
  commit(value) {
    if (JSON.stringify(value) === JSON.stringify(this.present)) return false;
    this.past.push(clone(this.present));
    if (this.past.length > this.limit) this.past.shift();
    this.present = clone(value); this.future = []; return true;
  }
  undo() { if (this.past.length) { this.future.push(this.present); this.present = this.past.pop(); } return clone(this.present); }
  redo() { if (this.future.length) { this.past.push(this.present); this.present = this.future.pop(); } return clone(this.present); }
}
