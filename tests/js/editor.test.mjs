import test from 'node:test';
import assert from 'node:assert/strict';
import { createStore } from '../../assets/js/editor/store.js';

test('store adds container and widget, supports undo/redo and responsive device', () => {
  const store = createStore({ version: 1, elements: [] });
  const cId = store.addContainer('grid');
  assert.equal(store.getState().document.elements.length, 1);
  assert.equal(store.getState().document.elements[0].settings.layout_type, 'grid');

  const wId = store.addWidget('heading', { text: 'Bonjour' }, cId);
  assert.equal(store.getState().document.elements[0].elements.length, 1);

  store.updateSetting(wId, 'text', 'Salut');
  assert.equal(store.getState().document.elements[0].elements[0].settings.text, 'Salut');

  store.undo();
  assert.equal(store.getState().document.elements[0].elements[0].settings.text, 'Bonjour');

  store.redo();
  assert.equal(store.getState().document.elements[0].elements[0].settings.text, 'Salut');

  store.setDevice('mobile');
  assert.equal(store.getState().device, 'mobile');

  store.removeElement(wId);
  assert.equal(store.getState().document.elements[0].elements.length, 0);
});

test('store moves elements up and down in container', () => {
  const store = createStore({ version: 1, elements: [] });
  const cId = store.addContainer('flex');
  const w1 = store.addWidget('heading', { text: 'First' }, cId);
  const w2 = store.addWidget('button', { text: 'Second' }, cId);

  store.moveElement(w2, 'up');
  const children = store.getState().document.elements[0].elements;
  assert.equal(children[0].id, w2);
  assert.equal(children[1].id, w1);

  store.moveElement(w2, 'down');
  const childrenAfter = store.getState().document.elements[0].elements;
  assert.equal(childrenAfter[0].id, w1);
  assert.equal(childrenAfter[1].id, w2);
});
