import test from 'node:test';
import assert from 'node:assert/strict';
import { History, add, changedElement, createElement, duplicate, find, inheritedStyle, insertAfter, moveInto, remove, reorder, update } from '../../assets/js/model.js';

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
test('extension-defined containers support nesting and safe reparenting', () => {
  const group = { id: 'custom', type: 'third-party-group', settings: {}, styles: {}, children: [] };
  const child = { id: 'child', type: 'heading', settings: {}, styles: {}, children: [] };
  const tree = add([group], child, 'custom', ['container', 'third-party-group']);
  assert.equal(tree[0].children[0].id, 'child');
  const root = moveInto(tree, 'child', null, ['container', 'third-party-group']);
  assert.equal(root[1].id, 'child');
  assert.equal(tree[0].children.length, 1);
});
