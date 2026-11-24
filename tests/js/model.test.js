import test from 'node:test';
import assert from 'node:assert/strict';
import { History, add, changedElement, containsWidget, createElement, duplicate, find, inheritedStyle, insertAfter, insertRelative, moveInto, moveRelative, remove, reorder, responsiveVisibility, setResponsiveStyle, spacingStyleSource, update, withElementDefaults } from '../../assets/js/model.js';

test('validated old nodes receive optional defaults without losing other values', () => {
  const input = [
    { id: 'empty', type: 'container', settings: null, styles: null, children: null, label: 'Existing' },
    { id: 'parent', type: 'container', children: [{ id: 'heading', type: 'heading', settings: { text: 'Kept' } }] },
  ];
  const before = structuredClone(input);
  const hydrated = withElementDefaults(input);
  assert.deepEqual(input, before);
  assert.deepEqual(hydrated[0], { id: 'empty', type: 'container', settings: {}, styles: {}, children: [], label: 'Existing' });
  assert.deepEqual(hydrated[1].children[0], { id: 'heading', type: 'heading', settings: { text: 'Kept' }, styles: {}, children: [] });
  assert.equal(add(hydrated, createElement('heading'), 'empty')[0].children.length, 1);
  assert.deepEqual(input, before);
});

test('preview diff selects a single changed subtree and rejects structural or multiple changes', () => {
  const parent = createElement('container'), first = createElement('heading'), second = createElement('text');
  const tree = add(add(add([], parent), first, parent.id), second, parent.id);
  assert.equal(changedElement(tree, tree), null);
  assert.equal(changedElement(tree, update(tree, first.id, { settings: { text: 'Salut' } })), first.id);
  assert.equal(changedElement(tree, update(tree, parent.id, { styles: { desktop: { gap: 12 } } })), parent.id);
  const twoChanges = update(update(tree, first.id, { hidden: true }), second.id, { label: 'Texte' });
  assert.equal(changedElement(tree, twoChanges), null);
  assert.equal(changedElement(tree, reorder(tree, first.id, 1)), null);
  assert.equal(changedElement(tree, remove(tree, first.id)), null);
  assert.equal(changedElement(tree, add(tree, createElement('button'), parent.id)), null);
  assert.equal(changedElement(tree, update(tree, first.id, { type: 'button' })), null);
  assert.equal(changedElement(tree, [{ ...parent, children: null }]), null);
});

test('preview detects a table of contents anywhere in the document', () => {
  const parent = createElement('container');
  const toc = createElement('table_of_contents');
  assert.equal(containsWidget([], 'table_of_contents'), false);
  assert.equal(containsWidget([parent], 'table_of_contents'), false);
  assert.equal(containsWidget(add([parent], toc, parent.id), 'table_of_contents'), true);
  assert.equal(containsWidget(null, 'table_of_contents'), false);
});

test('adding creates a new tree without mutating the original', () => {
  const root = createElement('container');
  const heading = createElement('heading');
  const initial = add([], root);
  const next = add(initial, heading, root.id);
  assert.equal(initial[0].children.length, 0);
  assert.equal(next[0].children[0].id, heading.id);
});
test('update, remove, and reorder operate on nested elements', () => {
  const a = createElement('heading'), b = createElement('text');
  const parent = createElement('container');
  let tree = add(add(add([], parent), a, parent.id), b, parent.id);
  tree = update(tree, a.id, { settings: { text: 'hello' } });
  assert.equal(find(tree, a.id).settings.text, 'hello');
  tree = reorder(tree, b.id, -1);
  assert.equal(tree[0].children[0].id, b.id);
  tree = remove(tree, a.id);
  assert.equal(find(tree, a.id), null);
});
test('inserting after a nested element is immutable and preserves its siblings', () => {
  const parent = createElement('container'), first = createElement('heading'), last = createElement('text');
  const initial = add(add(add([], parent), first, parent.id), last, parent.id);
  const inserted = createElement('button');
  const next = insertAfter(initial, first.id, inserted);
  assert.deepEqual(next[0].children.map(node => node.id), [first.id, inserted.id, last.id]);
  assert.deepEqual(initial[0].children.map(node => node.id), [first.id, last.id]);
  assert.deepEqual(insertAfter(initial, 'missing', inserted), initial);
});
test('relative drag operations preserve order across parents and reject cycles', () => {
  const parent = createElement('container'), child = createElement('heading');
  const first = createElement('text'), last = createElement('button');
  const initial = add(add(add(add([], parent), child, parent.id), first), last);
  const before = moveRelative(initial, last.id, first.id, 'before');
  assert.deepEqual(before.map(node => node.id), [parent.id, last.id, first.id]);
  const after = moveRelative(before, last.id, first.id, 'after');
  assert.deepEqual(after.map(node => node.id), [parent.id, first.id, last.id]);
  const nested = moveRelative(after, last.id, child.id, 'before');
  assert.deepEqual(nested[0].children.map(node => node.id), [last.id, child.id]);
  const out = moveRelative(nested, last.id, parent.id, 'after');
  assert.deepEqual(out.map(node => node.id), [parent.id, last.id, first.id]);
  assert.deepEqual(moveRelative(initial, parent.id, child.id, 'after'), initial);
  assert.deepEqual(moveRelative(initial, first.id, first.id, 'before'), initial);
  assert.deepEqual(moveRelative(initial, first.id, 'missing', 'after'), initial);
  assert.deepEqual(moveRelative(initial, first.id, last.id, 'invalid'), initial);
  const added = createElement('image');
  assert.deepEqual(insertRelative(initial, child.id, added, 'before')[0].children.map(node => node.id), [added.id, child.id]);
  assert.deepEqual(insertRelative(initial, 'missing', added, 'after'), initial);
  assert.deepEqual(initial.map(node => node.id), [parent.id, first.id, last.id]);
  assert.deepEqual(initial[0].children.map(node => node.id), [child.id]);
});

test('duplicating a subtree changes every identifier', () => {
  const parent = createElement('container'), child = createElement('heading');
  const tree = duplicate(add(add([], parent), child, parent.id), parent.id);
  assert.equal(tree.length, 2);
  assert.notEqual(tree[0].id, tree[1].id);
  assert.notEqual(tree[0].children[0].id, tree[1].children[0].id);
});
test('duplicating a converted heading does not duplicate its public anchor', () => {
  const heading = createElement('prime_block');
  heading.settings = { block_type: 'heading', block_data: '{"text":"Bonjour"}', block_anchor: 'bonjour' };
  const tree = duplicate([heading], heading.id);
  assert.equal(tree[0].settings.block_anchor, 'bonjour');
  assert.equal(Object.hasOwn(tree[1].settings, 'block_anchor'), false);
  assert.equal(heading.settings.block_anchor, 'bonjour');
});
test('moving a container into its descendant is rejected', () => {
  const a = createElement('container'), b = createElement('container');
  const tree = add(add([], a), b, a.id);
  assert.deepEqual(moveInto(tree, a.id, b.id), tree);
});
test('moving elements to a container or back to root is immutable', () => {
  const a = createElement('container'), b = createElement('heading');
  const initial = add(add([], a), b);
  const nested = moveInto(initial, b.id, a.id);
  assert.equal(initial.length, 2);
  assert.equal(nested.length, 1);
  assert.equal(nested[0].children[0].id, b.id);
  assert.equal(moveInto(nested, b.id, null).length, 2);
});
test('history restores snapshots and clears redo on divergent edits', () => {
  const history = new History([]);
  const node = createElement('text');
  assert.equal(history.commit(add([], node)), true);
  assert.equal(history.undo().length, 0);
  assert.equal(history.redo().length, 1);
  history.undo();
  history.commit(add([], createElement('heading')));
  assert.equal(history.redo()[0].type, 'heading');
});
test('history labels and jumps preserve redo and discard a divergent future', () => {
  const history = new History([], 2, 'initial');
  const a = createElement('heading'), b = createElement('text'), c = createElement('button');
  history.commit(add(history.present, a), 'heading');
  history.commit(add(history.present, b), 'text');
  assert.deepEqual(history.entries().map(entry => [entry.label, entry.current]), [['initial', false], ['heading', false], ['text', true]]);
  assert.equal(history.jump(0).length, 0);
  assert.equal(history.jump(2).length, 2);
  assert.equal(history.jump(1).length, 1);
  history.commit(add(history.present, c), 'button');
  assert.deepEqual(history.entries().map(entry => entry.label), ['initial', 'heading', 'button']);
  assert.equal(history.redo().length, 2);
  history.commit(remove(history.present, a.id), 'remove');
  assert.deepEqual(history.entries().map(entry => entry.label), ['heading', 'button', 'remove']);
  assert.deepEqual(history.jump(10), history.present);
});
test('style updates preserve separate responsive values', () => {
  const node = createElement('heading');
  const tree = update([node], node.id, { styles: { desktop: { font_size: 32 }, mobile: { font_size: 20 } } });
  assert.equal(tree[0].styles.desktop.font_size, 32);
  assert.equal(tree[0].styles.mobile.font_size, 20);
});

test('responsive inheritance finds the nearest previous explicit value, including zero', () => {
  const styles = { desktop: { padding: 12, color: 'kit:primary' }, tablet: { padding: 0 } };
  assert.equal(inheritedStyle(styles, 'desktop', 'padding'), null);
  assert.deepEqual(inheritedStyle(styles, 'tablet', 'padding'), { mode: 'desktop', value: 12 });
  assert.deepEqual(inheritedStyle(styles, 'mobile', 'padding'), { mode: 'tablet', value: 0 });
  assert.deepEqual(inheritedStyle(styles, 'mobile', 'color'), { mode: 'desktop', value: 'kit:primary' });
  assert.equal(inheritedStyle(styles, 'mobile', 'width'), null);
  assert.equal(inheritedStyle(null, 'mobile', 'width'), null);
});
test('border overrides inherit independently and clearing them preserves explicit zero', () => {
  const desktop = { desktop: { border_style: 'solid', border_width: 4, border_color: 'kit:primary' } };
  const tablet = setResponsiveStyle(desktop, 'tablet', 'border_width', 0);
  const mobile = setResponsiveStyle(tablet, 'mobile', 'border_style', 'none');
  assert.deepEqual(inheritedStyle(mobile, 'tablet', 'border_style'), { mode: 'desktop', value: 'solid' });
  assert.deepEqual(inheritedStyle(mobile, 'mobile', 'border_width'), { mode: 'tablet', value: 0 });
  assert.deepEqual(inheritedStyle(mobile, 'mobile', 'border_color'), { mode: 'desktop', value: 'kit:primary' });
  const cleared = setResponsiveStyle(mobile, 'tablet', 'border_width', undefined);
  assert.deepEqual(inheritedStyle(cleared, 'mobile', 'border_width'), { mode: 'desktop', value: 4 });
  assert.equal(mobile.tablet.border_width, 0);
  assert.equal(desktop.tablet, undefined);
  assert.equal(cleared.mobile.border_style, 'none');
});
test('per-side spacing follows the cascade of side and all-sides values at each breakpoint', () => {
  const styles = {
    desktop: { padding_top: 12, padding: 20, margin: '8px', margin_left: 'auto' },
    tablet: { padding: 0, margin_right: -4 },
    mobile: { padding_left: '2rem' },
  };
  assert.deepEqual(spacingStyleSource(styles, 'desktop', 'padding_top'), { mode: 'desktop', key: 'padding_top', value: 12 });
  assert.deepEqual(spacingStyleSource(styles, 'desktop', 'padding_bottom'), { mode: 'desktop', key: 'padding', value: 20 });
  assert.deepEqual(spacingStyleSource(styles, 'tablet', 'padding_top'), { mode: 'tablet', key: 'padding', value: 0 });
  assert.deepEqual(spacingStyleSource(styles, 'mobile', 'padding_top'), { mode: 'tablet', key: 'padding', value: 0 });
  assert.deepEqual(spacingStyleSource(styles, 'mobile', 'padding_left'), { mode: 'mobile', key: 'padding_left', value: '2rem' });
  assert.deepEqual(spacingStyleSource(styles, 'mobile', 'margin_left'), { mode: 'desktop', key: 'margin_left', value: 'auto' });
  assert.deepEqual(spacingStyleSource(styles, 'mobile', 'margin_right'), { mode: 'tablet', key: 'margin_right', value: -4 });
  const cleared = setResponsiveStyle(styles, 'tablet', 'padding', undefined);
  assert.deepEqual(spacingStyleSource(cleared, 'mobile', 'padding_top'), { mode: 'desktop', key: 'padding_top', value: 12 });
  assert.equal(styles.tablet.padding, 0);
  assert.equal(spacingStyleSource(null, 'mobile', 'padding_left'), null);
  assert.equal(spacingStyleSource(styles, 'mobile', 'padding'), null);
  assert.equal(spacingStyleSource(styles, 'mobile', 'padding_unsafe'), null);
});
test('position inheritance distinguishes zero, automatic resets and cleared overrides', () => {
  const desktop = setResponsiveStyle([], 'desktop', 'offset_top', -12);
  const tablet = setResponsiveStyle(desktop, 'tablet', 'offset_top', 0);
  const mobile = setResponsiveStyle(tablet, 'mobile', 'offset_top', 'auto');
  assert.deepEqual(inheritedStyle(mobile, 'tablet', 'offset_top'), { mode: 'desktop', value: -12 });
  assert.deepEqual(inheritedStyle(mobile, 'mobile', 'offset_top'), { mode: 'tablet', value: 0 });
  assert.equal(mobile.mobile.offset_top, 'auto');
  const cleared = setResponsiveStyle(mobile, 'mobile', 'offset_top', undefined);
  assert.deepEqual(cleared, tablet);
  assert.equal(JSON.stringify(mobile), '{"desktop":{"offset_top":-12},"tablet":{"offset_top":0},"mobile":{"offset_top":"auto"}}');
  assert.deepEqual(desktop, { desktop: { offset_top: -12 } });
});
test('style editing handles empty maps returned by PHP without losing changes', () => {
  const empty = [];
  const desktop = setResponsiveStyle(empty, 'desktop', 'visibility', 'hide');
  assert.deepEqual(desktop, { desktop: { visibility: 'hide' } });
  assert.deepEqual(empty, []);
  assert.equal(JSON.stringify(desktop), '{"desktop":{"visibility":"hide"}}');
  const tablet = setResponsiveStyle(desktop, 'tablet', 'padding', 0);
  assert.deepEqual(tablet, { desktop: { visibility: 'hide' }, tablet: { padding: 0 } });
  assert.deepEqual(desktop, { desktop: { visibility: 'hide' } });
  const cleared = setResponsiveStyle(tablet, 'tablet', 'padding', undefined);
  assert.deepEqual(cleared, desktop);
  assert.deepEqual(setResponsiveStyle({ mobile: [] }, 'mobile', 'visibility', 'show'), { mobile: { visibility: 'show' } });
  const node = { ...createElement('heading'), styles: [] };
  const history = new History([node]);
  assert.equal(history.commit(update(history.present, node.id, { styles: desktop })), true);
  assert.equal(history.present[0].styles.desktop.visibility, 'hide');
});
test('responsive visibility inherits and allows explicit show after hide', () => {
  const styles = { desktop: { visibility: 'hide' }, mobile: { visibility: 'show' } };
  assert.equal(responsiveVisibility(styles, 'desktop'), 'hide');
  assert.equal(responsiveVisibility(styles, 'tablet'), 'hide');
  assert.equal(responsiveVisibility(styles, 'mobile'), 'show');
  assert.equal(responsiveVisibility({ tablet: { visibility: 'hide' } }, 'desktop'), 'show');
  assert.equal(responsiveVisibility({ tablet: { visibility: 'hide' } }, 'mobile'), 'hide');
  assert.equal(responsiveVisibility({ desktop: { visibility: 'hide' }, tablet: { visibility: 'invalid' } }, 'mobile'), 'hide');
  assert.equal(responsiveVisibility(null, 'mobile'), 'show');
});
test('extension-defined containers support nesting and safe reparenting', () => {
  const group = { id: 'custom', type: 'third-party-group', settings: {}, styles: {}, children: [] };
  const child = { id: 'child', type: 'heading', settings: {}, styles: {}, children: [] };
  const tree = add([group], child, 'custom', ['container', 'third-party-group']);
  assert.equal(tree[0].children[0].id, 'child');
  const root = moveInto(tree, 'child', null, ['container', 'third-party-group']);
  assert.equal(root[1].id, 'child');
  assert.equal(tree[0].children.length, 1);
});
