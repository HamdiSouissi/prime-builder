import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const tabsScript = readFileSync(new URL('../../assets/js/tabs.js', import.meta.url), 'utf8');
const mapScript = readFileSync(new URL('../../assets/js/map.js', import.meta.url), 'utf8');
const formsScript = readFileSync(new URL('../../assets/js/forms.js', import.meta.url), 'utf8');
const shareScript = readFileSync(new URL('../../assets/js/share_buttons.js', import.meta.url), 'utf8');

function node(tag = 'div') {
  return {
    tag, attributes: {}, children: [], listeners: {}, dataset: {}, hidden: false, id: '',
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return this.attributes[name] ?? null; },
    addEventListener(type, listener, options) { (this.listeners[type] ||= []).push({ listener, options }); },
    append(child) { this.children.push(child); },
    focus() { this.focused = true; },
    replaceWith(replacement) { this.replacement = replacement; },
  };
}

function tabWidget(prefix, document) {
  const panels = [1, 2].map(number => {
    const panel = node('section');
    panel.id = `${prefix}-${number}`;
    const heading = { textContent: `Tab ${number}`, hidden: false };
    panel.querySelector = selector => selector === '[data-pb-tab-heading]' ? heading : null;
    return panel;
  });
  const widget = node();
  widget.dataset.pbTabsLabel = prefix;
  widget.querySelectorAll = selector => selector === ':scope > [data-pb-tab-panel]' ? panels : [];
  widget.matches = selector => selector === '[data-pb-tabs]';
  widget.prepend = list => {
    widget.list = list;
    widget.prependCount = (widget.prependCount || 0) + 1;
    list.children.forEach(button => document.usedIds.add(button.id));
  };
  return { widget, panels };
}

function fakeDocument() {
  return {
    usedIds: new Set(), listeners: {}, widgets: [], maps: [],
    getElementById(id) { return this.usedIds.has(id) ? {} : null; },
    querySelectorAll(selector) {
      if (selector === '[data-pb-tabs]') return this.widgets;
      if (selector === '[data-pb-map]') return this.maps;
      throw new Error(`Unexpected selector: ${selector}`);
    },
    createElement: node,
    addEventListener(type, listener) { (this.listeners[type] ||= []).push(listener); },
    notify(type, target) { for (const listener of this.listeners[type] || []) listener({ target }); },
  };
}

test('tabs enhance late fragments only once, avoid existing IDs and keep keyboard behavior', () => {
  const document = fakeDocument();
  document.usedIds.add('late-1');
  document.usedIds.add('late-1-2-button');
  const initial = tabWidget('initial', document);
  const late = tabWidget('late', document);
  document.widgets.push(initial.widget);
  runInNewContext(tabsScript, { document });
  assert.equal(initial.widget.prependCount, 1);
  assert.equal(initial.panels[0].hidden, false);
  assert.equal(initial.panels[1].hidden, true);

  const fragment = { querySelectorAll: selector => selector === '[data-pb-tabs]' ? [late.widget] : [] };
  document.notify('prime-builder:fragment', fragment);
  document.notify('prime-builder:fragment', fragment);
  document.notify('prime-builder:fragment', late.widget);
  assert.equal(late.widget.prependCount, 1);
  const [first, second] = late.widget.list.children;
  assert.equal(late.panels[0].id, 'late-1-2');
  assert.equal(first.id, 'late-1-2-button-2');
  assert.equal(first.getAttribute('aria-controls'), 'late-1-2');
  assert.equal(late.panels[0].getAttribute('aria-labelledby'), first.id);
  assert.equal(first.getAttribute('aria-selected'), 'true');
  let prevented = false;
  first.listeners.keydown[0].listener({ key: 'ArrowRight', preventDefault() { prevented = true; } });
  assert.equal(prevented, true);
  assert.equal(second.focused, true);
  assert.equal(second.getAttribute('aria-selected'), 'true');
  assert.equal(late.panels[0].hidden, true);
  assert.equal(late.panels[1].hidden, false);
  assert.equal(initial.panels[0].hidden, false);
});

test('late maps validate their provider and never attach duplicate activation listeners', () => {
  const document = fakeDocument();
  const makeMap = source => {
    const map = node();
    const button = node('button');
    button.hidden = true;
    map.dataset = { pbMapSrc: source, pbMapTitle: 'Map' };
    map.querySelector = selector => selector === '[data-pb-map-load]' ? button : null;
    map.querySelectorAll = () => [];
    map.matches = selector => selector === '[data-pb-map]';
    return { map, button };
  };
  const invalid = makeMap('https://example.com/export/embed.html');
  const late = makeMap('https://www.openstreetmap.org/export/embed.html?bbox=1');
  document.maps.push(invalid.map);
  runInNewContext(mapScript, { document, URL });
  assert.equal(invalid.button.hidden, true);
  assert.equal(invalid.button.listeners.click, undefined);

  const fragment = { querySelectorAll: selector => selector === '[data-pb-map]' ? [late.map] : [] };
  document.notify('prime-builder:fragment', fragment);
  document.notify('prime-builder:fragment', fragment);
  document.notify('prime-builder:fragment', late.map);
  assert.equal(late.button.hidden, false);
  assert.equal(late.button.listeners.click.length, 1);
  assert.equal(late.button.listeners.click[0].options.once, true);
  late.button.listeners.click[0].listener();
  assert.equal(late.button.replacement.tag, 'iframe');
  assert.equal(late.button.replacement.src, 'https://www.openstreetmap.org/export/embed.html?bbox=1');
  assert.equal(late.button.replacement.referrerPolicy, 'no-referrer');
  assert.equal(late.button.replacement.focused, true);
});

test('late share links use the safe canonical URL without query or fragment', () => {
  const document = fakeDocument();
  document.head = { querySelector: () => ({ getAttribute: () => 'https://canonical.example/page?private=secret#part' }) };
  const query = document.querySelectorAll;
  document.querySelectorAll = selector => selector === '[data-pb-share]' ? [] : query.call(document, selector);
  const link = node('a');
  link.dataset.pbShareService = 'facebook';
  const share = node('nav');
  share.dataset.pbShareMessage = 'Bonjour';
  share.querySelectorAll = selector => selector === '[data-pb-share-service]' ? [link] : [];
  share.matches = selector => selector === '[data-pb-share]';
  runInNewContext(shareScript, {
    document, window: { location: { protocol: 'https:', host: 'example.test', origin: 'https://example.test', pathname: '/page', search: '?secret=1' } }, URL, URLSearchParams, encodeURIComponent,
  });
  const fragment = { querySelectorAll: selector => selector === '[data-pb-share]' ? [share] : [] };
  document.notify('prime-builder:fragment', fragment);
  document.notify('prime-builder:fragment', fragment);
  assert.equal(new URL(link.getAttribute('href')).searchParams.get('u'), 'https://canonical.example/page');
  assert.doesNotMatch(link.getAttribute('href'), /secret|private|part/);
});

test('late contact forms prepare once and reject invalid or hidden actions', async () => {
  const document = fakeDocument();
  document.querySelectorAll = () => [];
  class FakeButton { constructor() { this.disabled = true; } }
  class FakeForm {
    constructor(action, hidden = false) {
      this.action = action;
      this.button = new FakeButton();
      this.status = { textContent: '' };
      this.dataset = { pbNode: 'contact', pbError: 'Error', pbLimited: 'Limit' };
      this.listeners = {};
      this.hidden = hidden;
    }
    closest(selector) { return selector === '[hidden]' && this.hidden ? {} : null; }
    querySelector(selector) { return selector === 'button[type="submit"]' ? this.button : selector === '[data-pb-form-status]' ? this.status : null; }
    querySelectorAll() { return []; }
    matches(selector) { return selector === '[data-pb-form]'; }
    addEventListener(type, listener) { (this.listeners[type] ||= []).push(listener); }
  }
  const valid = new FakeForm('https://example.test/api/prime-builder/v1/forms/7/submit');
  const zero = new FakeForm('https://example.test/api/prime-builder/v1/forms/0/submit');
  const external = new FakeForm('https://other.test/api/prime-builder/v1/forms/7/submit');
  const hidden = new FakeForm('https://example.test/api/prime-builder/v1/forms/7/submit', true);
  const requests = [];
  runInNewContext(formsScript, {
    document, HTMLFormElement: FakeForm, HTMLButtonElement: FakeButton, URL,
    window: { location: { href: 'https://example.test/page', origin: 'https://example.test' } },
    fetch: async (url, options) => { requests.push({ url: String(url), options }); return { ok: true, json: async () => ({ data: { token: 'signed' } }) }; },
    performance: { now: () => 100 },
  });
  const fragment = { querySelectorAll: selector => selector === '[data-pb-form]' ? [valid, zero, external, hidden] : [] };
  document.notify('prime-builder:fragment', fragment);
  document.notify('prime-builder:fragment', fragment);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(requests.length, 1);
  assert.equal(requests[0].url, 'https://example.test/api/prime-builder/v1/forms/7/start?node=contact');
  assert.equal(requests[0].options.credentials, 'omit');
  assert.equal(valid.button.disabled, false);
  assert.equal(valid.listeners.submit.length, 1);
  for (const form of [zero, external, hidden]) {
    assert.equal(form.button.disabled, true);
    assert.equal(form.listeners.submit, undefined);
  }
});
