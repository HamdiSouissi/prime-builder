import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const script = readFileSync(new URL('../../assets/js/content.js', import.meta.url), 'utf8');

// Exercise the real public script with a small inert fragment; the CMS/browser
// integration test also checks the actual HTML parser and live zone endpoint.
function element(tag, attributes = {}) {
  return {
    tagName: tag.toUpperCase(), attributes: { ...attributes },
    get id() { return this.attributes.id || ''; },
    set id(value) { this.attributes.id = String(value); },
    getAttribute(name) { return this.attributes[name] ?? null; },
    setAttribute(name, value) { this.attributes[name] = String(value); },
  };
}

function runZone() {
  const external = [
    element('div', { id: 'pb-zone-zone1-label' }),
    element('div', { id: 'pb-zone-zone1-label-2' }),
    element('map', { name: 'pb-zone-zone1-map-1' }),
    element('div', { id: 'pb-zone-zone1-map-1-2' }),
    element('div', { id: 'pb-zone-zone1-map-2' }),
  ];
  const nodes = [
    element('input', { id: 'label' }),
    element('input', { id: 'label' }),
    element('label', { for: 'label', 'aria-describedby': 'label café' }),
    element('p', { id: 'café' }),
    element('a', { href: '#caf%C3%A9' }),
    element('img', { usemap: '#plan' }),
    element('map', { name: 'plan' }),
    element('map', { name: 'plan' }),
    element('linearGradient', { id: 'paint' }),
    element('rect', { fill: 'url(#paint)' }),
  ];
  const fragment = {
    querySelectorAll(selector) {
      if (selector === '[id]') return nodes.filter(node => node.id);
      if (selector === 'map[name]') return nodes.filter(node => node.tagName === 'MAP' && node.getAttribute('name'));
      if (selector === '*') return nodes;
      throw new Error(`unsupported selector: ${selector}`);
    },
  };
  const inserted = [];
  const zone = {
    dataset: { pbId: 'zone1' },
    classList: { contains: name => name === 'pb-theme_zone' },
    closest: () => null,
    replaceChildren(content) { assert.equal(content, fragment); inserted.push(...nodes); },
  };
  const layout = {
    dataset: { pbLive: '/api/prime-builder/v1/documents/7/live' },
    querySelectorAll(selector) {
      if (selector === '.pb-posts[data-pb-id]') return [];
      if (selector === '[data-pb-id]') return [zone];
      throw new Error(`unsupported selector: ${selector}`);
    },
  };
  const document = {
    querySelectorAll(selector) {
      if (selector === '.pb-layout[data-pb-live]') return [layout];
      if (selector === '.pb-theme-header .pb-navigation[data-pb-id], .pb-theme-footer .pb-navigation[data-pb-id]') return [];
      throw new Error(`unsupported selector: ${selector}`);
    },
    getElementById(id) { return [...external, ...inserted].find(node => node.id === id) || null; },
    getElementsByName(name) { return [...external, ...inserted].filter(node => node.getAttribute('name') === name); },
    createElement(tag) {
      assert.equal(tag, 'template');
      return { content: fragment, set innerHTML(value) { assert.equal(value, 'live zone'); } };
    },
  };
  runInNewContext(script, {
    document, window: { location: { search: '' } }, URLSearchParams, queueMicrotask,
    fetch: async () => ({ ok: true, json: async () => ({ data: { widgets: { zone1: 'live zone' } } }) }),
  });
  return { external, nodes, inserted };
}

test('live zone IDs and map names avoid existing page targets and retain local references', async () => {
  const { external, nodes, inserted } = runZone();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(inserted.length, nodes.length);
  const allIds = [...external, ...nodes].map(node => node.id).filter(Boolean);
  assert.equal(new Set(allIds).size, allIds.length);
  const allNames = [...external, ...nodes].filter(node => node.tagName === 'MAP').map(node => node.getAttribute('name'));
  assert.equal(new Set(allNames).size, allNames.length);
  assert.equal(nodes[0].id, 'pb-zone-zone1-label-3');
  assert.equal(nodes[1].id, 'pb-zone-zone1-label-4');
  assert.equal(nodes[2].getAttribute('for'), nodes[0].id);
  assert.equal(nodes[2].getAttribute('aria-describedby'), `${nodes[0].id} ${nodes[3].id}`);
  assert.equal(nodes[4].getAttribute('href'), `#${nodes[3].id}`);
  assert.equal(nodes[5].getAttribute('usemap'), `#${nodes[6].getAttribute('name')}`);
  assert.equal(nodes[6].getAttribute('name'), 'pb-zone-zone1-map-1-3');
  assert.equal(nodes[7].getAttribute('name'), 'pb-zone-zone1-map-2-2');
  assert.equal(nodes[9].getAttribute('fill'), `url(#${nodes[8].id})`);
});
