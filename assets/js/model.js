/** Immutable tree operations shared by the editor and its tests. */
export const clone = value => structuredClone(value);

// Keep in sync with CssClasses::normalize. Only simple ASCII identifiers are
// accepted; raw CSS, selectors and the Builder/CMS namespaces are never allowed.
export function normalizeWidgetClasses(value) {
  if (typeof value !== 'string' || value.length > 512) return null;
  const trimmed = value.replace(/^[ \t\r\n\f]+|[ \t\r\n\f]+$/g, '');
  if (trimmed === '') return '';
  const parts = trimmed.split(/[ \t\r\n\f]+/);
  if (parts.length > 20) return null;
  const seen = new Set();
  for (const part of parts) {
    if (part.length > 64 || !/^(?:-?[_a-zA-Z]|--)[_a-zA-Z0-9-]*(?![\s\S])/.test(part) || /^(?:pb|pc)(?:[-_]|$)/i.test(part)) return null;
    seen.add(part);
  }
  return [...seen].join(' ');
}

// An invalid draft remains editable, but must never be silently saved or lost
// when the user has moved to another element or panel.
export function firstInvalidWidgetClasses(elements) {
  if (!Array.isArray(elements)) return null;
  for (const node of elements) {
    if (!node || typeof node !== 'object') continue;
    if (Object.hasOwn(node, 'classes')) {
      const normalized = normalizeWidgetClasses(node.classes);
      if (normalized === null || (['original_content', 'original_group'].includes(node.type) && normalized !== '')) return node;
    }
    const child = firstInvalidWidgetClasses(node.children);
    if (child) return child;
  }
  return null;
}

// Width and height describe one source, not the widget as a whole. A typed
// replacement invalidates the old dimensions; a media selection supplies a
// fresh pair only when both values are usable. The alternative stays editable.
export function replaceImageSource(settings, field, source, selection) {
  const next = { ...settings };
  if (source === undefined) delete next[field]; else next[field] = source;
  if (selection !== undefined || source !== settings[field]) {
    delete next.width;
    delete next.height;
  }
  if (selection !== undefined) {
    next.alt = typeof selection?.alt === 'string' ? selection.alt : '';
    if (Number.isInteger(selection?.width) && selection.width >= 1 && selection.width <= 10000 &&
      Number.isInteger(selection?.height) && selection.height >= 1 && selection.height <= 10000) {
      next.width = selection.width;
      next.height = selection.height;
    }
  }
  return next;
}

// Gallery and image-carousel fields use one URL|alternative-text row per image.
// Keep the operation atomic: never silently drop selected images or truncate text.
export function appendMediaList(current, selection) {
  if (typeof current !== 'string' || !Array.isArray(selection) || selection.length === 0) return null;
  const base = current.trim() ? current.trimEnd() : '';
  const newline = /\r\n|[\n\r\v\f\u0085\u2028\u2029]/u;
  const existing = base ? base.split(newline) : [];
  if (existing.some(line => !line.trim()) || existing.length + selection.length > 20) return null;
  const bytes = value => new TextEncoder().encode(value).length;
  const appended = [];
  for (const item of selection) {
    if (!item || item.kind !== 'image' || typeof item.url !== 'string') return null;
    const url = item.url.trim();
    if (!url || /[|\u0000-\u001f\u007f\u0085\u2028\u2029]/u.test(url)) return null;
    const alt = typeof item.alt === 'string' ? item.alt.replace(/[\u0000-\u001f\u007f\u0085\u2028\u2029]/gu, ' ').trim() : '';
    if (bytes(alt) > 300) return null;
    appended.push(`${url}|${alt}`);
  }
  const value = [...existing, ...appended].join('\n');
  return bytes(value) <= 10000 ? value : null;
}

// Dimensions are an optional snapshot keyed by the image URL, not its row
// number: reordering or editing alternatives must not swap image proportions.
function selectedDimensions(rawDimensions, listed, selection, maximum = 20) {
  if (rawDimensions !== undefined && typeof rawDimensions !== 'string') return null;
  const bytes = value => new TextEncoder().encode(value).length;
  let prior;
  try { prior = rawDimensions ? JSON.parse(rawDimensions) : []; } catch { return null; }
  if (!Array.isArray(prior) || prior.length > maximum || (rawDimensions && bytes(rawDimensions) > 25000)) return null;
  const dimensions = new Map();
  for (const item of prior) {
    if (!item || Array.isArray(item) || typeof item !== 'object' || Object.keys(item).length !== 3 ||
      typeof item.url !== 'string' || !item.url.trim() || /[|\u0000-\u001f\u007f\u0085\u2028\u2029]/u.test(item.url) ||
      !Number.isInteger(item.width) || item.width < 1 || item.width > 10000 ||
      !Number.isInteger(item.height) || item.height < 1 || item.height > 10000 || dimensions.has(item.url)) return null;
    dimensions.set(item.url, { url: item.url, width: item.width, height: item.height });
  }
  for (const url of dimensions.keys()) if (!listed.has(url)) dimensions.delete(url);
  for (const item of selection) {
    const url = item.url.trim();
    if (Number.isInteger(item.width) && item.width >= 1 && item.width <= 10000 &&
      Number.isInteger(item.height) && item.height >= 1 && item.height <= 10000) {
      dimensions.set(url, { url, width: item.width, height: item.height });
    } else dimensions.delete(url);
  }
  if (dimensions.size > maximum) return null;
  const encoded = dimensions.size ? JSON.stringify([...dimensions.values()]) : '';
  return bytes(encoded) <= 25000 ? encoded : null;
}
export function appendMediaSelection(current, rawDimensions, selection) {
  const images = appendMediaList(current, selection);
  if (images === null) return null;
  const listed = new Set(images.split(/\r\n|[\n\r\v\f\u0085\u2028\u2029]/u).map(line => line.split('|', 1)[0].trim()));
  const dimensions = selectedDimensions(rawDimensions, listed, selection);
  return dimensions === null ? null : { images, dimensions };
}
// Media-carousel rows can also contain videos. Keep image dimensions in the
// URL-keyed snapshot so editing an address cannot reuse its former size.
export function appendMediaCarouselSelection(current, rawDimensions, selection) {
  if (typeof current !== 'string' || !Array.isArray(selection) || selection.length === 0) return null;
  const base = current.trim() ? current.trimEnd() : '';
  const newline = /\r\n|[\n\r\v\f\u0085\u2028\u2029]/u;
  const existing = base ? base.split(newline) : [];
  if (existing.some(line => !line.trim()) || existing.length + selection.length > 20) return null;
  const bytes = value => new TextEncoder().encode(value).length;
  const appended = [];
  for (const item of selection) {
    if (!item || item.kind !== 'image' || typeof item.url !== 'string') return null;
    const url = item.url.trim();
    if (!url || bytes(url) > 2048 || /[|\u0000-\u001f\u007f\u0085\u2028\u2029]/u.test(url)) return null;
    const alt = typeof item.alt === 'string' ? item.alt.replace(/[\u0000-\u001f\u007f\u0085\u2028\u2029]/gu, ' ').trim() : '';
    if (alt.includes('|') || bytes(alt) > 300) return null;
    appended.push(`image|${url}|${alt}`);
  }
  const items = [...existing, ...appended].join('\n');
  if (bytes(items) > 15000) return null;
  const listed = new Set(items.split(newline).map(line => {
    const parts = line.split('|');
    return parts[0].trim() === 'image' ? parts[1]?.trim() : undefined;
  }).filter(Boolean));
  const dimensions = selectedDimensions(rawDimensions, listed, selection);
  return dimensions === null ? null : { items, dimensions };
}
// Portfolio rows need a title and category, and testimonials need a quotation.
// Choose an image for an existing physical line instead of inventing content.
// Other cells and rows remain untouched. A snapshot for the previous URL is
// pruned only when no row still uses it.
export function replaceStructuredImage(type, current, rawDimensions, index, media) {
  const formats = {
    slideshow: { min: 2, max: 6, image: 0, alt: 1, rows: 20, bytes: 15000 },
    portfolio: { min: 4, max: 6, image: 0, alt: 1, rows: 24, bytes: 15000 },
    testimonial_carousel: { min: 1, max: 4, image: 3, rows: 20, bytes: 10000 },
  };
  const format = Object.hasOwn(formats, type) ? formats[type] : null;
  const bytes = value => new TextEncoder().encode(value).length;
  if (!format || typeof current !== 'string' || bytes(current) > format.bytes || !Number.isInteger(index) || index < 0 ||
    !media || media.kind !== 'image' || typeof media.url !== 'string') return null;
  const url = media.url.trim();
  if (!url || bytes(url) > 2048 || /[|\u0000-\u001f\u007f\u0085\u2028\u2029]/u.test(url)) return null;
  const alt = typeof media.alt === 'string' ? media.alt.replace(/[\u0000-\u001f\u007f\u0085\u2028\u2029]/gu, ' ').trim() : '';
  if (format.alt !== undefined && (alt.includes('|') || bytes(alt) > 300)) return null;
  const newline = /\r\n|[\n\r\v\f\u0085\u2028\u2029]/u;
  const lines = current.split(newline);
  if (index >= lines.length || !lines[index].trim() || lines.filter(line => line.trim()).length > format.rows) return null;
  const parts = lines[index].split('|');
  if (parts.length < format.min || parts.length > format.max ||
    (type === 'portfolio' && (!parts[2].trim() || !parts[3].trim())) ||
    (type === 'testimonial_carousel' && !parts[0].trim())) return null;
  // Preserve whitespace around the edited cells and the content of all others.
  const cell = (old, value) => {
    const match = old.match(/^([ \t]*)(.*?)([ \t]*)$/u);
    return match ? `${match[1]}${value}${match[3]}` : value;
  };
  if (format.image >= parts.length) {
    lines[index] += ' | '.repeat(format.image - parts.length + 1) + url;
  } else {
    parts[format.image] = cell(parts[format.image], url);
    if (format.alt !== undefined) parts[format.alt] = cell(parts[format.alt], alt);
    lines[index] = parts.join('|');
  }
  const items = lines.join('\n');
  if (bytes(items) > format.bytes) return null;
  const listed = new Set(lines.map(line => line.split('|')[format.image]?.trim()).filter(Boolean));
  const dimensions = selectedDimensions(rawDimensions, listed, [media], format.rows);
  return dimensions === null ? null : { items, dimensions };
}
export function id() { return `pb_${globalThis.crypto.randomUUID().replaceAll('-', '').slice(0, 16)}`; }
// Only fill optional node fields on an already validated tree. This is not a
// validator: failed conversions must never be passed to the editable model.
export function withElementDefaults(elements) {
  return elements.map(element => ({
    ...element,
    settings: element.settings ?? {},
    styles: element.styles ?? {},
    children: withElementDefaults(element.children ?? []),
  }));
}
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
// For spacing, each breakpoint's shorthand resets all four sides before its
// explicit side overrides. Search from the active breakpoint toward desktop.
export function spacingStyleSource(styles, device, name) {
  const match = typeof name === 'string' && name.match(/^(padding|margin)_(top|right|bottom|left)$/);
  const modes = device === 'mobile' ? ['mobile', 'tablet', 'desktop'] : device === 'tablet' ? ['tablet', 'desktop'] : device === 'desktop' ? ['desktop'] : [];
  if (!match) return null;
  for (const mode of modes) {
    const values = styles?.[mode];
    if (!values || typeof values !== 'object') continue;
    if (Object.hasOwn(values, name)) return { mode, key: name, value: values[name] };
    if (Object.hasOwn(values, match[1])) return { mode, key: match[1], value: values[match[1]] };
  }
  return null;
}
// PHP encodes an empty styles map as []; writing named properties on that
// array would silently lose them during JSON serialization and history checks.
export function setResponsiveStyle(styles, device, name, value) {
  const next = styles && typeof styles === 'object' && !Array.isArray(styles) ? clone(styles) : {};
  const current = next[device];
  const values = current && typeof current === 'object' && !Array.isArray(current) ? { ...current } : {};
  if (value === undefined) delete values[name]; else values[name] = value;
  if (Object.keys(values).length) next[device] = values; else delete next[device];
  return next;
}
export function responsiveVisibility(styles, device) {
  const order = device === 'mobile' ? ['desktop', 'tablet', 'mobile'] : device === 'tablet' ? ['desktop', 'tablet'] : ['desktop'];
  let value = 'show';
  for (const mode of order) {
    const candidate = styles?.[mode]?.visibility;
    if (candidate === 'show' || candidate === 'hide') value = candidate;
  }
  return value;
}
// Mirrors the server's UTC, conjunctive condition grammar; server validation remains authoritative.
export function validDisplayConditions(conditions) {
  if (conditions === undefined) return true;
  if (!conditions || typeof conditions !== 'object' || Array.isArray(conditions)) return false;
  const keys = ['auth', 'role', 'device', 'start', 'end'];
  if (Object.keys(conditions).some(key => !keys.includes(key) || typeof conditions[key] !== 'string')) return false;
  if (conditions.auth && !['any', 'guest', 'authenticated'].includes(conditions.auth)) return false;
  if (conditions.device && !['any', 'desktop', 'tablet', 'mobile'].includes(conditions.device)) return false;
  if (conditions.role && (!/^[a-z][a-z0-9_-]{0,79}$/.test(conditions.role) || conditions.auth === 'guest')) return false;
  const validDate = value => {
    if (!value) return true;
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value) || value.startsWith('0000')) return false;
    const date = new Date(`${value}:00Z`);
    return Number.isFinite(date.valueOf()) && date.toISOString().slice(0, 16) === value;
  };
  return validDate(conditions.start) && validDate(conditions.end) && (!conditions.start || !conditions.end || conditions.start < conditions.end);
}
export function firstInvalidDisplayConditions(elements) {
  for (const element of elements || []) {
    if (!validDisplayConditions(element.conditions)) return element;
    const child = firstInvalidDisplayConditions(element.children);
    if (child) return child;
  }
  return null;
}
export function containsWidget(elements, type) {
  return Array.isArray(elements) && elements.some(element => element?.type === type || containsWidget(element?.children, type));
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
export function insertRelative(elements, elementId, element, position) {
  const next = clone(elements);
  if (!['before', 'after'].includes(position)) return next;
  const target = locate(next, elementId);
  if (target) target.list.splice(target.index + (position === 'after' ? 1 : 0), 0, clone(element));
  return next;
}
export function insertAfter(elements, elementId, element) {
  return insertRelative(elements, elementId, element, 'after');
}
export function moveRelative(elements, elementId, targetId, position) {
  const next = clone(elements);
  if (!['before', 'after'].includes(position)) return next;
  const source = locate(next, elementId);
  if (!source || elementId === targetId || find(source.element.children || [], targetId) || !locate(next, targetId)) return next;
  const [element] = source.list.splice(source.index, 1);
  // The destination's index can change when both elements share a parent.
  const destination = locate(next, targetId);
  if (!destination) return clone(elements);
  destination.list.splice(destination.index + (position === 'after' ? 1 : 0), 0, element);
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
// Give inserted nodes new IDs. On a copy, retain a manual menu anchor only
// when it is not already used by another known Builder anchor or Prime heading.
// Links are intentionally left untouched: callers must tell the user to check
// them, since a link can legitimately target content outside the copied subtree.
export function freshIds(elements, { copied = false, into = [] } = {}) {
  const result = clone(elements);
  const walk = (nodes, callback) => {
    for (const node of nodes) { callback(node); walk(node.children || [], callback); }
  };
  const ids = new Set();
  walk(into, node => { if (typeof node.id === 'string') ids.add(node.id); });
  walk(result, node => {
    let next;
    do { next = id(); } while (ids.has(next));
    node.id = next; ids.add(next);
    // Converted headings keep their historic public anchor only in the source.
    if (copied && node.type === 'prime_block' && node.settings) delete node.settings.block_anchor;
  });
  if (!copied) return result;
  const used = new Set();
  const reserve = node => {
    if (node.type === 'anchor' && typeof node.settings?.anchor === 'string' && node.settings.anchor) used.add(node.settings.anchor);
    if (node.type === 'prime_block') {
      if (typeof node.settings?.block_anchor === 'string' && node.settings.block_anchor) used.add(node.settings.block_anchor);
      if (typeof node.id === 'string') used.add(`pb-${node.id}`);
    }
  };
  walk(into, reserve);
  // Newly generated Prime heading anchors must also remain available.
  walk(result, node => { if (node.type === 'prime_block') used.add(`pb-${node.id}`); });
  const originals = new Set();
  walk(result, node => { if (node.type === 'anchor' && typeof node.settings?.anchor === 'string' && node.settings.anchor) originals.add(node.settings.anchor); });
  walk(result, node => {
    if (node.type !== 'anchor' || !/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/.test(node.settings?.anchor || '')) return;
    const original = node.settings.anchor;
    let candidate = original;
    if (used.has(candidate)) {
      let number = 2;
      do {
        const suffix = `-${number++}`;
        candidate = original.slice(0, 64 - suffix.length) + suffix;
      } while (used.has(candidate) || originals.has(candidate));
      node.settings.anchor = candidate;
    }
    used.add(candidate);
  });
  return result;
}
export function duplicate(elements, elementId) {
  const next = clone(elements);
  const found = locate(next, elementId);
  if (!found) return next;
  const [copy] = freshIds([found.element], { copied: true, into: next });
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
