import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const script = readFileSync(new URL('../../assets/js/portfolio.js', import.meta.url), 'utf8');

function portfolio(categories) {
  const list = {};
  const items = categories.map(category => ({ dataset: { pbCategory: category }, hidden: false }));
  return {
    list, items, inserted: [],
    dataset: { pbPortfolioFilterLabel: 'Filter projects', pbPortfolioAll: 'All projects', pbPortfolioCategory: 'Category: :category', pbPortfolioSingle: ':count project shown', pbPortfolioPlural: ':count projects shown' },
    querySelector: () => list,
    querySelectorAll: () => items,
    insertBefore(node, reference) { assert.equal(reference, list); this.inserted.push(node); },
  };
}
function setup(...portfolios) {
  const document = {
    querySelectorAll: () => portfolios,
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
}

test('portfolio filters by category, reports count, and restores all projects', () => {
  const first = portfolio(['Identity', 'Web', 'Identity']);
  const second = portfolio(['Design', 'Photos']);
  setup(first, second);
  const [filters, status] = first.inserted;
  const [all, identity, web] = filters.children;
  assert.equal(filters.attributes.role, 'group');
  assert.equal(filters.attributes['aria-label'], 'Filter projects');
  assert.equal(all.attributes['aria-pressed'], 'true');
  assert.equal(identity.attributes['aria-label'], 'Category: Identity');
  assert.equal(status.attributes.role, 'status');
  assert.equal(status.textContent, '3 projects shown');
  web.click();
  assert.deepEqual(first.items.map(item => item.hidden), [true, false, true]);
  assert.equal(web.attributes['aria-pressed'], 'true');
  assert.equal(all.attributes['aria-pressed'], 'false');
  assert.equal(status.textContent, '1 project shown');
  assert.deepEqual(second.items.map(item => item.hidden), [false, false]);
  identity.click();
  assert.deepEqual(first.items.map(item => item.hidden), [false, true, false]);
  assert.equal(status.textContent, '2 projects shown');
  all.click();
  assert.deepEqual(first.items.map(item => item.hidden), [false, false, false]);
  assert.equal(all.attributes['aria-pressed'], 'true');
});

test('portfolio uses plain text and omits unneeded or incomplete controls', () => {
  const name = '<img src=x onerror=alert(1)>';
  const many = portfolio([name, 'Another']);
  const one = portfolio(['Only', 'Only']);
  const incomplete = portfolio(['A', 'B']);
  delete incomplete.dataset.pbPortfolioAll;
  setup(many, one, incomplete);
  const malicious = many.inserted[0].children[1];
  assert.equal(malicious.textContent, name);
  assert.equal(malicious.attributes['aria-label'], `Category: ${name}`);
  assert.equal(one.inserted.length, 0);
  assert.equal(incomplete.inserted.length, 0);
  assert.deepEqual(incomplete.items.map(item => item.hidden), [false, false]);
});
