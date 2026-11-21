import test from 'node:test';
import assert from 'node:assert/strict';
import { History, add, createElement, duplicate, find, moveInto, remove, reorder, update } from '../../assets/js/model.js';

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
test('duplicating a subtree changes every identifier', () => {
  const parent = createElement('container'), child = createElement('heading');
  const tree = duplicate(add(add([], parent), child, parent.id), parent.id);
  assert.equal(tree.length, 2);
  assert.notEqual(tree[0].id, tree[1].id);
  assert.notEqual(tree[0].children[0].id, tree[1].children[0].id);
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
test('style updates preserve separate responsive values', () => {
  const node = createElement('heading');
  const tree = update([node], node.id, { styles: { desktop: { font_size: 32 }, mobile: { font_size: 20 } } });
  assert.equal(tree[0].styles.desktop.font_size, 32);
  assert.equal(tree[0].styles.mobile.font_size, 20);
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
