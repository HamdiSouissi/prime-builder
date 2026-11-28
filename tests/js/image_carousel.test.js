import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const script = readFileSync(new URL('../../assets/js/image_carousel.js', import.meta.url), 'utf8');

function setup(slideCount = 2) {
  const video = { paused: true, pauses: 0, pause() { this.paused = true; this.pauses++; } };
  const slides = Array.from({ length: slideCount }, (_, index) => ({
    hidden: false, attributes: {},
    setAttribute(name, value) { this.attributes[name] = value; },
    querySelectorAll() { return index === 1 ? [video] : []; },
  }));
  const carousel = {
    dataset: { pbCarouselLabel: 'Media', pbCarouselPrevious: 'Previous', pbCarouselNext: 'Next', pbCarouselPosition: ':current of :total' },
    attributes: {}, listeners: {}, controls: null,
    setAttribute(name, value) { this.attributes[name] = value; },
    querySelectorAll() { return slides; },
    addEventListener(name, listener) { this.listeners[name] = listener; },
    append(node) { this.controls = node; },
  };
  const document = {
    addEventListener() {},
    querySelectorAll: () => [carousel],
    createElement(tag) {
      return {
        tag, children: [], attributes: {}, listeners: {}, textContent: '',
        append(node) { this.children.push(node); },
        setAttribute(name, value) { this.attributes[name] = value; },
        addEventListener(name, listener) { this.listeners[name] = listener; },
        click() { this.listeners.click(); },
      };
    },
  };
  runInNewContext(script, { document });
  return { carousel, slides, video };
}

test('carousel pauses hidden media and leaves native media keys alone', () => {
  const { carousel, slides, video } = setup();
  const [previous, next, status] = carousel.controls.children;
  assert.equal(carousel.attributes.role, 'region');
  assert.equal(carousel.attributes['aria-label'], 'Media');
  assert.equal(slides[0].attributes['aria-label'], '1 of 2');
  assert.equal(slides[0].hidden, false);
  assert.equal(slides[1].hidden, true);
  assert.equal(video.pauses, 0);
  next.click();
  assert.equal(slides[1].hidden, false);
  assert.equal(status.textContent, '2 of 2');
  video.paused = false;
  const fromVideo = { key: 'ArrowLeft', target: { closest: () => video }, preventDefault() { throw new Error('video key intercepted'); } };
  carousel.listeners.keydown(fromVideo);
  assert.equal(slides[1].hidden, false);
  const fromButton = { key: 'ArrowLeft', target: { closest: () => null }, prevented: false, preventDefault() { this.prevented = true; } };
  carousel.listeners.keydown(fromButton);
  assert.equal(fromButton.prevented, true);
  assert.equal(slides[0].hidden, false);
  assert.equal(slides[1].hidden, true);
  assert.equal(video.pauses, 1);
  assert.equal(status.textContent, '1 of 2');
  previous.click();
  assert.equal(slides[1].hidden, false);
});

test('carousel does not steal modified or interactive-element arrow keys', () => {
  const { carousel, slides } = setup();
  for (const attributes of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { defaultPrevented: true }, { interactive: true }]) {
    const event = {
      key: 'ArrowRight', ...attributes,
      target: { closest: () => attributes.interactive ? {} : null },
      preventDefault() { throw new Error('unexpectedly intercepted key'); },
    };
    carousel.listeners.keydown(event);
    assert.equal(slides[0].hidden, false);
    assert.equal(slides[1].hidden, true);
  }
});

test('single slide has a named region without unnecessary controls', () => {
  const { carousel, slides } = setup(1);
  assert.equal(carousel.attributes['aria-label'], 'Media');
  assert.equal(slides[0].hidden, false);
  assert.equal(carousel.controls, null);
});
