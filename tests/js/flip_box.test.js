import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const script = readFileSync(new URL('../../assets/js/flip_box.js', import.meta.url), 'utf8');

function setup() {
  const boxes = [];
  const document = {
    activeElement: null,
    querySelectorAll: () => boxes,
    getElementById(id) {
      if (id === 'pb-flip-first') return {};
      return boxes.find(box => box.back.id === id)?.back || null;
    },
    createElement(tag) {
      assert.equal(tag, 'button');
      return {
        dataset: {}, attributes: {}, listeners: {}, textContent: '',
        setAttribute(name, value) { this.attributes[name] = value; },
        addEventListener(name, listener) { this.listeners[name] = listener; },
        focus() { document.activeElement = this; },
        click() { this.listeners.click(); },
      };
    },
  };
  function add(id, context = 'Services') {
    const front = { hidden: false, dataset: {} };
    const link = {};
    const back = { hidden: false, dataset: {}, id: '', contains(node) { return node === link; } };
    const box = {
      dataset: { pbShowBack: 'Show back', pbShowFront: 'Show front', pbFlipContext: context },
      front, back, link, button: null,
      querySelector(selector) {
        if (selector === '[data-pb-flip-control]') return this.button;
        if (selector === '[data-pb-flip-front]') return front;
        if (selector === '[data-pb-flip-back]') return back;
        return null;
      },
      closest: () => ({ dataset: { pbId: id } }),
      prepend(node) { this.button = node; },
    };
    boxes.push(box);
    return box;
  }
  return { document, boxes, add, init: () => runInNewContext(script, { document }) };
}

test('flip box uses unique controls, keyboard-native buttons and restores focus', () => {
  const fixture = setup();
  const first = fixture.add('first');
  const second = fixture.add('first', 'Other card');
  fixture.init();
  assert.equal(first.back.id, 'pb-flip-first-2');
  assert.equal(second.back.id, 'pb-flip-first-3');
  assert.equal(first.button.attributes['aria-controls'], first.back.id);
  assert.equal(first.button.attributes['aria-expanded'], 'false');
  assert.equal(first.button.attributes['aria-label'], 'Show back : Services');
  assert.equal(first.button.type, 'button');
  assert.equal(first.front.hidden, false);
  assert.equal(first.back.hidden, true);
  first.button.click();
  assert.equal(first.button.attributes['aria-expanded'], 'true');
  assert.equal(first.button.attributes['aria-label'], 'Show front : Services');
  assert.equal(first.front.hidden, true);
  assert.equal(first.back.hidden, false);
  assert.equal(first.back.dataset.pbFlipActivated, '');
  fixture.document.activeElement = first.link;
  first.button.click();
  assert.equal(fixture.document.activeElement, first.button);
  assert.equal(first.front.hidden, false);
  assert.equal(first.back.hidden, true);
  assert.equal(first.button.attributes['aria-expanded'], 'false');
  const original = first.button;
  fixture.init();
  assert.equal(first.button, original);
  assert.equal(first.back.id, 'pb-flip-first-2');
});

test('incomplete flip box stays readable and creates no control', () => {
  const fixture = setup();
  const box = fixture.add('missing');
  delete box.dataset.pbShowBack;
  fixture.init();
  assert.equal(box.button, null);
  assert.equal(box.front.hidden, false);
  assert.equal(box.back.hidden, false);
});
