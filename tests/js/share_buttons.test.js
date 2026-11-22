import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const script = readFileSync(new URL('../../assets/js/share_buttons.js', import.meta.url), 'utf8');

function setup(location, message = 'Bonjour & merci') {
  const links = ['facebook', 'linkedin', 'x', 'email', 'unknown'].map(service => ({
    dataset: { pbShareService: service }, href: 'original',
    setAttribute(name, value) { assert.equal(name, 'href'); this.href = value; },
  }));
  const share = {
    dataset: { pbShareMessage: message },
    querySelectorAll(selector) { assert.equal(selector, '[data-pb-share-service]'); return links; },
  };
  const document = { querySelectorAll(selector) { assert.equal(selector, '[data-pb-share]'); return [share]; } };
  runInNewContext(script, { document, window: { location }, URLSearchParams, encodeURIComponent });
  return links;
}

test('share links use the current public path, omit query strings, and encode messages', () => {
  const links = setup({ protocol: 'https:', host: 'example.test', origin: 'https://example.test', pathname: '/ma%20page', search: '?token=secret' }, 'Bonjour & merci\r\nBcc:bad@example.test');
  const url = 'https://example.test/ma%20page';
  assert.equal(new URL(links[0].href).searchParams.get('u'), url);
  assert.equal(new URL(links[1].href).searchParams.get('url'), url);
  assert.equal(new URL(links[2].href).searchParams.get('url'), url);
  assert.equal(new URL(links[2].href).searchParams.get('text'), 'Bonjour & merci Bcc:bad@example.test');
  assert.match(links[3].href, /^mailto:\?subject=/);
  assert.equal(new URLSearchParams(links[3].href.split('?')[1]).get('body'), url);
  for (const link of links.slice(0, 4)) {
    assert.doesNotMatch(link.href, /token|secret|%0[aAdD]/);
  }
  assert.equal(links[4].href, 'original');
});

test('admin, preview, and non-web contexts retain the server-rendered fallback', () => {
  for (const location of [
    { protocol: 'https:', host: 'example.test', origin: 'https://example.test', pathname: '/admin/content/1/preview', search: '' },
    { protocol: 'https:', host: 'example.test', origin: 'https://example.test', pathname: '/page', search: '?preview=1&entry_id=3' },
    { protocol: 'https:', host: 'example.test', origin: 'https://example.test', pathname: '/', search: '?preview=true&entry_id=3&token=secret' },
    { protocol: 'file:', host: '', origin: 'null', pathname: '/page', search: '' },
  ]) {
    for (const link of setup(location)) assert.equal(link.href, 'original');
  }
});
