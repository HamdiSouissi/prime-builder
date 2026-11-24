import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const script = readFileSync(new URL('../../assets/js/countdown.js', import.meta.url), 'utf8');
const units = ['day', 'hour', 'minute', 'second'];

function widget(deadline, finished = 'Finished') {
  const values = { hidden: true };
  const status = { textContent: '' };
  const fields = Object.fromEntries(units.map(unit => [unit, {
    value: { textContent: '0' },
    label: { textContent: `${unit}s`, dataset: { pbOne: unit, pbMany: `${unit}s` } },
  }]));
  return {
    dataset: { pbDeadline: deadline, pbFinished: finished },
    isConnected: true, values, status, fields,
    querySelector(selector) {
      if (selector === '[data-pb-countdown-values]') return values;
      if (selector === '[data-pb-countdown-status]') return status;
      const match = selector.match(/^\[data-pb-countdown-(value|label)="(day|hour|minute|second)"\]$/);
      return match ? fields[match[2]][match[1]] : null;
    },
  };
}

function run(widgets, instant) {
  let now = Date.parse(instant);
  let callback = null;
  let listener = null;
  let fragmentListener = null;
  let intervals = 0;
  let clears = 0;
  class FakeDate extends Date { static now() { return now; } }
  const document = {
    hidden: false,
    querySelectorAll: () => widgets,
    addEventListener(type, handler) {
      if (type === 'visibilitychange') listener = handler;
      if (type === 'prime-builder:fragment') fragmentListener = handler;
    },
    removeEventListener(type, handler) { if (type === 'visibilitychange' && listener === handler) listener = null; },
  };
  runInNewContext(script, {
    document, Date: FakeDate,
    setInterval(fn, delay) { assert.equal(delay, 1000); intervals++; callback = fn; return 1; },
    clearInterval(id) { assert.equal(id, 1); clears++; callback = null; },
  });
  return {
    document,
    setTime(iso) { now = Date.parse(iso); },
    tick() { callback?.(); },
    visibility() { listener?.(); },
    fragment(widgets) { fragmentListener?.({ target: { querySelectorAll: () => widgets } }); },
    get intervals() { return intervals; },
    get clears() { return clears; },
    get listening() { return listener !== null; },
  };
}

const counts = target => units.map(unit => target.fields[unit].value.textContent);
const labels = target => units.map(unit => target.fields[unit].label.textContent);

test('countdown uses the UTC deadline, pluralizes units and announces expiry just once', () => {
  const target = widget('2030-01-02T01:01:01Z', 'Open now');
  const clock = run([target], '2030-01-01T00:00:00Z');
  assert.deepEqual(counts(target), ['1', '1', '1', '1']);
  assert.deepEqual(labels(target), ['day', 'hour', 'minute', 'second']);
  assert.equal(target.values.hidden, false);
  assert.equal(target.status.textContent, '');
  assert.equal(clock.intervals, 1);
  clock.setTime('2030-01-02T01:00:59.001Z'); clock.tick();
  assert.deepEqual(counts(target), ['0', '0', '0', '2']);
  assert.deepEqual(labels(target), ['days', 'hours', 'minutes', 'seconds']);
  clock.setTime('2030-01-02T01:01:00.999Z'); clock.tick();
  assert.deepEqual(counts(target), ['0', '0', '0', '1']);
  clock.setTime('2030-01-02T01:01:01Z'); clock.tick();
  assert.equal(target.values.hidden, true);
  assert.equal(target.status.textContent, 'Open now');
  assert.equal(clock.clears, 1);
  assert.equal(clock.listening, false);
  clock.tick();
  assert.equal(clock.clears, 1);
});

test('multiple timers share one interval, handle delayed ticks and remove detached nodes', () => {
  const first = widget('2030-01-01T00:01:00Z', 'First');
  const second = widget('2030-01-01T01:00:00Z', 'Second');
  const clock = run([first, second], '2030-01-01T00:00:00Z');
  assert.equal(clock.intervals, 1);
  clock.setTime('2030-01-01T00:30:00Z');
  clock.document.hidden = true; clock.visibility();
  assert.equal(first.status.textContent, '');
  clock.document.hidden = false; clock.visibility();
  assert.equal(first.status.textContent, 'First');
  assert.deepEqual(counts(second), ['0', '0', '30', '0']);
  assert.equal(clock.clears, 0);
  second.isConnected = false; clock.tick();
  assert.equal(clock.clears, 1);
  assert.equal(clock.listening, false);
});

test('expired deadlines show their status immediately; malformed widgets stay as fallbacks', () => {
  const expired = widget('2000-01-01T00:00:00Z', '<strong>Only text</strong>');
  const invalid = widget('not a date');
  const incomplete = widget('2030-01-01T00:01:00Z');
  const original = incomplete.querySelector;
  incomplete.querySelector = selector => selector.includes('label="second"') ? null : original.call(incomplete, selector);
  const clock = run([expired, invalid, incomplete], '2030-01-01T00:00:00Z');
  assert.equal(expired.status.textContent, '<strong>Only text</strong>');
  assert.equal(expired.values.hidden, true);
  assert.equal(invalid.values.hidden, true);
  assert.equal(incomplete.values.hidden, true);
  assert.equal(clock.intervals, 0);
  assert.equal(clock.listening, false);
});

test('late countdowns share and restart the timer after expiry without duplicate intervals', () => {
  const clock = run([], '2030-01-01T00:00:00Z');
  const first = widget('2030-01-01T00:00:02Z', 'First finished');
  clock.fragment([first]);
  clock.fragment([first]);
  assert.equal(first.values.hidden, false);
  assert.equal(clock.intervals, 1);
  assert.equal(clock.listening, true);
  clock.setTime('2030-01-01T00:00:02Z');
  clock.tick();
  assert.equal(first.status.textContent, 'First finished');
  assert.equal(clock.clears, 1);
  assert.equal(clock.listening, false);
  const second = widget('2030-01-01T00:00:05Z', 'Second finished');
  clock.fragment([first, second]);
  clock.fragment([second]);
  assert.equal(clock.intervals, 2);
  assert.deepEqual(counts(second), ['0', '0', '0', '3']);
  clock.setTime('2030-01-01T00:00:05Z');
  clock.tick();
  assert.equal(second.status.textContent, 'Second finished');
  assert.equal(clock.clears, 2);
  assert.equal(clock.listening, false);
});
