import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const script = readFileSync(new URL('../../assets/js/content.js', import.meta.url), 'utf8');
const themeNavigationSelector = '.pb-theme-header .pb-navigation[data-pb-id], .pb-theme-footer .pb-navigation[data-pb-id]';

// The small DOM double exercises the actual public script without adding a test dependency.
class Element {
  constructor(doc, tag = 'div', classes = []) {
    this.ownerDocument = doc;
    this.tagName = tag.toUpperCase();
    this.classes = new Set(classes);
    this.classList = { contains: name => this.classes.has(name) };
    this.dataset = {};
    this.attributes = {};
    this.children = [];
    this.listeners = {};
    this.parentElement = null;
    this.hidden = false;
    this.textContent = '';
    this.id = '';
  }
  set className(value) { this.classes = new Set(value.split(/\s+/).filter(Boolean)); }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  getAttribute(name) { return this.attributes[name] ?? null; }
  append(...children) { for (const child of children) { child.parentElement = this; this.children.push(child); } }
  insertBefore(child, reference) {
    const index = this.children.indexOf(reference);
    assert.notEqual(index, -1);
    child.parentElement = this;
    this.children.splice(index, 0, child);
  }
  addEventListener(type, handler) { (this.listeners[type] ||= []).push(handler); }
  removeEventListener(type, handler) { this.listeners[type] = (this.listeners[type] || []).filter(item => item !== handler); }
  dispatch(type, event = {}) { for (const handler of [...(this.listeners[type] || [])]) handler(event); }
  click() { this.dispatch('click'); }
  focus() { this.ownerDocument.activeElement = this; }
  contains(node) { return this === node || this.children.some(child => child.contains(node)); }
  closest(selector) {
    if (selector.startsWith('.') && this.classes.has(selector.slice(1))) return this;
    return this.parentElement?.closest(selector) || null;
  }
  get previousElementSibling() {
    if (!this.parentElement) return null;
    const siblings = this.parentElement.children;
    return siblings[siblings.indexOf(this) - 1] || null;
  }
  descendants() { return this.children.flatMap(child => [child, ...child.descendants()]); }
  querySelectorAll(selector) {
    const nodes = this.descendants();
    if (selector === '.pb-posts[data-pb-id]') return nodes.filter(node => node.classes.has('pb-posts') && node.dataset.pbId);
    if (selector === '[data-pb-id]') return nodes.filter(node => node.dataset.pbId);
    if (selector.startsWith('.')) return nodes.filter(node => node.classes.has(selector.slice(1)));
    if (selector === 'a') return nodes.filter(node => node.tagName === 'A');
    throw new Error(`unsupported selector: ${selector}`);
  }
  querySelector(selector) {
    if (selector === '[data-pb-navigation]') return this.descendants().find(node => node.dataset.pbNavigation !== undefined) || null;
    if (selector.startsWith(':scope > .')) return this.children.find(node => node.classes.has(selector.slice(10))) || null;
    return this.querySelectorAll(selector)[0] || null;
  }
}

function navigation(doc, name, nested = true) {
  const nav = new Element(doc, 'nav', ['pb-nav']);
  nav.dataset = { pbNavigation: '', pbNavMenu: 'Menu', pbNavSubmenu: 'Submenu: :label', name };
  const list = new Element(doc, 'ul', ['pb-nav__list']);
  const item = new Element(doc, 'li', ['pb-nav__item', ...(nested ? ['pb-nav__item--has-children'] : [])]);
  const link = new Element(doc, 'a', ['pb-nav__link']); link.textContent = name;
  item.append(link);
  let submenu, childLink, childToggle;
  if (nested) {
    submenu = new Element(doc, 'ul', ['pb-nav__submenu']);
    const child = new Element(doc, 'li', ['pb-nav__item', 'pb-nav__item--has-children']);
    childLink = new Element(doc, 'a', ['pb-nav__link']); childLink.textContent = 'Services';
    const subsubmenu = new Element(doc, 'ul', ['pb-nav__submenu']);
    const leaf = new Element(doc, 'li', ['pb-nav__item']);
    const leafLink = new Element(doc, 'a', ['pb-nav__link']); leafLink.textContent = 'Details';
    leaf.append(leafLink); subsubmenu.append(leaf); child.append(childLink, subsubmenu); submenu.append(child); item.append(submenu);
    childToggle = () => child.children.find(node => node.classes.has('pb-nav__submenu-toggle'));
  }
  list.append(item); nav.append(list);
  return { nav, list, item, link, submenu, childLink, childToggle };
}

function setup(ids = ['one', 'two']) {
  const media = {
    matches: false, listeners: [],
    addEventListener(type, handler) { assert.equal(type, 'change'); this.listeners.push(handler); },
    setMobile(value) { this.matches = value; for (const handler of this.listeners) handler(); },
  };
  const document = {
    activeElement: null,
    defaultView: { matchMedia: query => { assert.equal(query, '(max-width: 767px)'); return media; } },
    createElement(tag) { return new Element(this, tag); },
    querySelectorAll(selector) { if (selector === themeNavigationSelector) return []; assert.equal(selector, '.pb-layout[data-pb-live]'); return [layout]; },
    getElementById(id) { return [external, layout, ...layout.descendants()].find(node => node.id === id) || null; },
  };
  // Test collision handling with a pre-existing ID elsewhere on the page.
  const external = new Element(document); external.id = 'pb-nav-one';
  const layout = new Element(document); layout.dataset.pbLive = '/api/prime-builder/v1/documents/7/live';
  const nodes = ids.map(id => {
    const node = new Element(document, 'div', ['pb-navigation']); node.dataset.pbId = id;
    node.navParts = navigation(document, 'Fallback', false);
    node.append(node.navParts.nav);
    Object.defineProperty(node, 'innerHTML', {
      set(value) {
        this.navParts = navigation(document, value);
        this.children = [];
        this.append(this.navParts.nav);
      },
    });
    layout.append(node);
    return node;
  });
  let release;
  const response = new Promise(resolve => { release = resolve; });
  const requests = [];
  runInNewContext(script, {
    document, window: { location: { search: '' } }, URLSearchParams, queueMicrotask,
    fetch(url, options) { requests.push({ url, options }); return response; },
  });
  return {
    document, media, nodes, requests,
    respond(widgets) { release({ ok: true, json: () => Promise.resolve({ data: { widgets } }) }); },
    fail() { release({ ok: false }); },
  };
}
const flush = () => new Promise(resolve => setImmediate(resolve));
const button = item => item.children.find(node => node.classes.has('pb-nav__submenu-toggle'));
const mobileButton = nav => nav.children.find(node => node.classes.has('pb-nav__mobile-toggle'));
const escape = nav => {
  const event = { key: 'Escape', prevented: false, preventDefault() { this.prevented = true; } };
  nav.dispatch('keydown', event);
  return event.prevented;
};

test('navigation disclosures have unique controls and nested Escape restores focus', async () => {
  const { nodes, document, requests, respond } = setup();
  assert.equal(requests.length, 1);
  assert.equal(requests[0].url, '/api/prime-builder/v1/documents/7/live');
  assert.equal(requests[0].options.cache, 'no-store');
  respond({ one: 'Discover', two: 'Other' });
  await flush();
  const first = nodes[0].navParts, second = nodes[1].navParts;
  const toggle = button(first.item), nested = first.childToggle();
  assert.equal(first.list.id, 'pb-nav-one-2');
  assert.equal(mobileButton(first.nav).getAttribute('aria-controls'), first.list.id);
  assert.equal(toggle.getAttribute('aria-controls'), first.submenu.id);
  assert.equal(toggle.getAttribute('aria-label'), 'Submenu: Discover');
  assert.equal(toggle.getAttribute('aria-expanded'), 'false');
  assert.equal(first.submenu.hidden, true);
  assert.notEqual(first.submenu.id, second.submenu.id);
  toggle.click(); nested.click();
  assert.equal(first.submenu.hidden, false);
  assert.equal(nested.getAttribute('aria-expanded'), 'true');
  const leaf = first.submenu.querySelectorAll('a')[1];
  leaf.focus();
  assert.equal(escape(first.nav), true);
  assert.equal(document.activeElement, nested);
  assert.equal(nested.getAttribute('aria-expanded'), 'false');
  assert.equal(escape(first.nav), true);
  assert.equal(document.activeElement, toggle);
  assert.equal(toggle.getAttribute('aria-expanded'), 'false');
  assert.equal(first.submenu.hidden, true);
});

test('mobile disclosure preserves focused links on resize and Escape closes the menu', async () => {
  const { nodes, document, media, respond } = setup(['one']);
  respond({ one: 'Discover' });
  await flush();
  const { nav, list, link } = nodes[0].navParts;
  const menu = mobileButton(nav);
  link.focus();
  media.setMobile(true);
  assert.equal(list.hidden, false);
  assert.equal(menu.getAttribute('aria-expanded'), 'true');
  assert.equal(escape(nav), true);
  assert.equal(document.activeElement, menu);
  assert.equal(list.hidden, true);
  menu.click();
  assert.equal(list.hidden, false);
  assert.equal(menu.getAttribute('aria-expanded'), 'true');
  media.setMobile(false);
  assert.equal(document.activeElement, link);
  assert.equal(list.hidden, false);
});

test('server-rendered theme menus are enhanced without a live request and copied live layouts are skipped', () => {
  const media = { matches: true, addEventListener() {} };
  const document = {
    activeElement: null,
    defaultView: { matchMedia: () => media },
    createElement(tag) { return new Element(this, tag); },
    querySelectorAll(selector) {
      if (selector === themeNavigationSelector) return nodes;
      assert.equal(selector, '.pb-layout[data-pb-live]');
      return [];
    },
    getElementById(id) { return nodes.flatMap(node => [node, ...node.descendants()]).find(node => node.id === id) || null; },
  };
  const nodes = ['header', 'footer', 'copy'].map(id => {
    const node = new Element(document, 'div', ['pb-navigation']);
    node.dataset.pbId = id;
    node.navParts = navigation(document, id);
    node.append(node.navParts.nav);
    if (id === 'copy') node.closest = selector => selector === '.pb-layout[data-pb-live]' ? {} : null;
    return node;
  });
  runInNewContext(script, {
    document, window: { location: { search: '' } }, URLSearchParams, queueMicrotask,
    fetch() { assert.fail('A server-rendered theme menu must not request an entry live endpoint'); },
  });
  const header = nodes[0].navParts, footer = nodes[1].navParts, copy = nodes[2].navParts;
  const menu = mobileButton(header.nav), toggle = button(header.item);
  assert.equal(menu.getAttribute('aria-expanded'), 'false');
  assert.equal(header.list.hidden, true);
  assert.notEqual(header.list.id, footer.list.id);
  assert.notEqual(header.submenu.id, footer.submenu.id);
  assert.equal(mobileButton(copy.nav), undefined);
  menu.focus(); menu.click(); toggle.focus(); toggle.click();
  assert.equal(header.submenu.hidden, false);
  assert.equal(escape(header.nav), true);
  assert.equal(document.activeElement, toggle);
  assert.equal(escape(header.nav), true);
  assert.equal(document.activeElement, menu);
});

test('breadcrumb refresh waits for focus to leave its saved fallback', async () => {
  const document = {
    activeElement: null,
    querySelectorAll(selector) { if (selector === themeNavigationSelector) return []; assert.equal(selector, '.pb-layout[data-pb-live]'); return [layout]; },
  };
  const layout = new Element(document); layout.dataset.pbLive = '/api/prime-builder/v1/documents/8/live';
  const node = new Element(document, 'div', ['pb-breadcrumbs']); node.dataset.pbId = 'trail';
  const fallback = new Element(document, 'a'); fallback.textContent = 'Home'; node.append(fallback); layout.append(node);
  let replaced = null;
  Object.defineProperty(node, 'innerHTML', { set(value) { replaced = value; this.children = []; } });
  let release;
  const response = new Promise(resolve => { release = resolve; });
  runInNewContext(script, {
    document, window: { location: { search: '' } }, URLSearchParams, queueMicrotask,
    fetch() { return response; },
  });
  fallback.focus();
  release({ ok: true, json: () => Promise.resolve({ data: { widgets: { trail: '<nav>Updated</nav>' } } }) });
  await flush();
  assert.equal(replaced, null);
  assert.equal(document.activeElement, fallback);
  node.dispatch('focusout');
  await flush();
  assert.equal(replaced, null);
  document.activeElement = null;
  node.dispatch('focusout');
  await flush();
  assert.equal(replaced, '<nav>Updated</nav>');
  assert.equal(node.listeners.focusout.length, 0);
});

test('identity and entry widget refresh waits while a fallback contains focus', async () => {
  for (const type of ['site_title', 'site_logo', 'entry_title', 'entry_excerpt', 'entry_featured_image', 'entry_info', 'entry_navigation', 'entry_author']) {
    const document = {
      activeElement: null,
      querySelectorAll(selector) { if (selector === themeNavigationSelector) return []; assert.equal(selector, '.pb-layout[data-pb-live]'); return [layout]; },
    };
    const layout = new Element(document); layout.dataset.pbLive = '/api/prime-builder/v1/documents/8/live';
    const node = new Element(document, 'div', [`pb-${type}`]); node.dataset.pbId = type;
    const fallback = new Element(document, 'a'); fallback.textContent = 'Old site name'; node.append(fallback); layout.append(node);
    let replaced = null;
    Object.defineProperty(node, 'innerHTML', { set(value) { replaced = value; this.children = []; } });
    let release;
    const response = new Promise(resolve => { release = resolve; });
    runInNewContext(script, {
      document, window: { location: { search: '' } }, URLSearchParams, queueMicrotask,
      fetch() { return response; },
    });
    fallback.focus();
    release({ ok: true, json: () => Promise.resolve({ data: { widgets: { [type]: '<a href="/">New site name</a>' } } }) });
    await flush();
    assert.equal(replaced, null);
    assert.equal(document.activeElement, fallback);
    document.activeElement = null;
    node.dispatch('focusout');
    await flush();
    assert.equal(replaced, '<a href="/">New site name</a>');
    assert.equal(node.listeners.focusout.length, 0);
  }
});

test('live refresh never removes a focused fallback and failed requests leave it intact', async () => {
  const first = setup(['one']);
  const fallback = first.nodes[0].navParts.link;
  fallback.focus();
  first.respond({ one: 'Updated' });
  await flush();
  assert.equal(first.nodes[0].navParts.link, fallback);
  assert.equal(first.document.activeElement, fallback);
  // A focusout that stays within the widget must keep waiting.
  first.nodes[0].dispatch('focusout');
  await flush();
  assert.equal(first.nodes[0].navParts.link, fallback);
  assert.equal(first.nodes[0].listeners.focusout.length, 1);
  first.document.activeElement = null;
  first.nodes[0].dispatch('focusout');
  await flush();
  assert.equal(first.nodes[0].navParts.link.textContent, 'Updated');
  assert.equal(first.nodes[0].listeners.focusout.length, 0);
  const second = setup(['one']);
  second.fail();
  await flush();
  assert.equal(second.nodes[0].navParts.link.textContent, 'Fallback');
  assert.equal(second.nodes[0].navParts.nav.children.length, 1);
});
