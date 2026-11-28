import test from 'node:test';
import assert from 'node:assert/strict';
import { safeRichHref } from '../../assets/js/rich-text.js';

// A small RCDATA double for URL-unit tests; real entity parsing is also tested
// in Chromium with the actual document and the server remains authoritative.
const doc = {
  createElement(tag) {
    assert.equal(tag, 'textarea');
    return {
      value: '',
      set innerHTML(html) {
        this.value = html.replace(/&(?:#(x[\da-f]+|\d+);?|amp;|lt;|gt;|quot;)/gi, (entity, numeric) => {
          if (numeric) {
            const number = numeric[0].toLowerCase() === 'x' ? parseInt(numeric.slice(1), 16) : parseInt(numeric, 10);
            return number > 0 && number <= 0x10ffff ? String.fromCodePoint(number) : '\ufffd';
          }
          return { '&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"' }[entity.toLowerCase()];
        });
      },
    };
  },
};

test('rich links accept useful URLs and reject dangerous or obfuscated schemes', () => {
  for (const value of ['/', '/guide?x=1#intro', '#ancre', '?page=2', 'guide/index', 'https://example.org/a', 'http://example.org', 'mailto:a@example.org', 'tel:+33123456789']) {
    assert.equal(safeRichHref(value, doc), value);
  }
  for (const value of [null, undefined, 42, '', '  ', '//evil.example', '\\evil.example', '/\\evil.example', 'javascript:alert(1)', 'java\tscript:alert(1)', 'jav&#x61;script:alert(1)', '&amp;#106;avascript:alert(1)', 'data:text/html,x', 'file:///etc/passwd', 'blob:https://example.org/a', 'ftp://example.org', 'https:', 'https://', 'mailto:', 'tel:', 'mailto:a@example.org\r\nb@example.org']) {
    assert.equal(safeRichHref(value, doc), null, String(value));
  }
  assert.equal(safeRichHref('  /guide  ', doc), '/guide');
  assert.equal(safeRichHref('/chapter:one', doc), '/chapter:one');
});
