import test from 'node:test';
import assert from 'node:assert/strict';
import { fieldTypes, validFormFields, firstInvalidFormFields } from '../../assets/js/form-fields.js';

const row = (id, type = 'text', step = 1) => ({ id, type, label: id, required: false, step, options: ['select', 'checkbox', 'radio'].includes(type) ? ['Un', 'Deux'] : [], value: '' });

test('custom form definitions match bounded server-side constraints', () => {
  const fields = fieldTypes.map((type, index) => row(`f_${index}`, type, index < 6 ? 1 : 2));
  assert.equal(validFormFields(JSON.stringify(fields)), true);
  const bad = mutation => { const copy = structuredClone(fields); mutation(copy); return JSON.stringify(copy); };
  for (const value of [undefined, null, '', '{}', '[]', '[' , ' '.repeat(20001),
    bad(copy => { copy[1].id = copy[0].id; }),
    bad(copy => { copy[0].id = 'website'; }),
    bad(copy => { copy[0].type = 'unsupported'; }),
    JSON.stringify(Array.from({ length: 5 }, (_, index) => row(`f_file${index}`, 'file'))),
    bad(copy => { copy[0].label = ' '; }),
    bad(copy => { copy[0].label = 'bad\nlabel'; }),
    bad(copy => { copy[0].label = 'a'.repeat(101); }),
    bad(copy => { copy[0].required = 1; }),
    bad(copy => { copy[0].step = 0; }),
    bad(copy => { copy[0].step = 6; }),
    bad(copy => { copy[0].extra = true; }),
    bad(copy => { delete copy[0].value; }),
    bad(copy => { copy[5].options = []; }),
    bad(copy => { copy[5].options = ['A', ' A ']; }),
    bad(copy => { copy[0].options = ['A']; }),
    bad(copy => { copy[0].value = 'bad'; }),
    bad(copy => { copy[10].required = true; }),
    bad(copy => { for (const item of copy) if (item.step === 2) item.step = 3; }),
    JSON.stringify([row('f_only', 'hidden')]),
    JSON.stringify(Array.from({ length: 25 }, (_, index) => row(`f_${index}`))),
  ]) assert.equal(validFormFields(value), false, String(value));
});

test('invalid custom schemas are found in nested layouts without rejecting legacy forms', () => {
  const legacy = { id: 'legacy', type: 'form', settings: {}, children: [] };
  const valid = { id: 'valid', type: 'form', settings: { form_fields: JSON.stringify([row('f_one')]) }, children: [] };
  const invalid = { id: 'invalid', type: 'form', settings: { form_fields: '[' }, children: [] };
  assert.equal(firstInvalidFormFields([legacy, valid]), null);
  assert.equal(firstInvalidFormFields([{ type: 'container', children: [legacy, invalid] }]), invalid);
});
