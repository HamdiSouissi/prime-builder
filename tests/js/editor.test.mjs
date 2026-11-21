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
