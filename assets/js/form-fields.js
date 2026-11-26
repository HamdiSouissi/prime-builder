// Editor-only controls. Published definitions are independently validated by Forms\Fields.
export const fieldTypes = Object.freeze(['text', 'email', 'phone', 'number', 'textarea', 'select', 'checkbox', 'radio', 'date', 'acceptance', 'hidden']);
const choiceTypes = ['select', 'checkbox', 'radio'];
const encoder = new TextEncoder();
const lines = value => [...value].length;
const singleLine = (value, max, empty = false) => typeof value === 'string' && encoder.encode(value).length <= max * 4 && lines(value) <= max && (empty || value.trim() !== '') && !/[\x00-\x1f\x7f]/.test(value);

function editableFields(value) {
  if (typeof value !== 'string' || encoder.encode(value).length > 40000) return null;
  try {
    const fields = JSON.parse(value);
    if (!Array.isArray(fields) || !fields.length || fields.length > 24) return null;
    if (!fields.every(field => field && typeof field === 'object' && !Array.isArray(field) &&
      typeof field.id === 'string' && /^f_[a-z0-9]{1,24}$/.test(field.id) && fieldTypes.includes(field.type) &&
      typeof field.label === 'string' && typeof field.required === 'boolean' && Number.isInteger(field.step) && field.step >= 1 && field.step <= 5 &&
      Array.isArray(field.options) && field.options.every(option => typeof option === 'string') && typeof field.value === 'string')) return null;
    if (new Set(fields.map(field => field.id)).size !== fields.length) return null;
    return fields;
  } catch { return null; }
}

export function validFormFields(value) {
  if (typeof value !== 'string' || encoder.encode(value).length > 20000) return false;
  const fields = editableFields(value);
  if (!fields) return false;
  const visible = new Set();
  let maxStep = 0;
  for (const field of fields) {
    if (Object.keys(field).length !== 7 || !['id', 'type', 'label', 'required', 'step', 'options', 'value'].every(key => Object.hasOwn(field, key)) ||
      !singleLine(field.label, 100) || !singleLine(field.value, 160, true) || field.options.length > 20) return false;
    if (choiceTypes.includes(field.type)) {
      if (!field.options.length || field.options.some(option => !singleLine(option, 100))) return false;
      if (new Set(field.options.map(option => option.trim())).size !== field.options.length) return false;
    } else if (field.options.length) return false;
    if ((field.type !== 'hidden' && field.value !== '') || (field.type === 'hidden' && field.required)) return false;
    if (field.type !== 'hidden') visible.add(field.step);
    maxStep = Math.max(maxStep, field.step);
  }
  return Array.from({ length: maxStep }, (_, index) => index + 1).every(step => visible.has(step));
}

export function firstInvalidFormFields(elements) {
  for (const element of elements) {
    if (element?.type === 'form' && Object.hasOwn(element.settings || {}, 'form_fields') && !validFormFields(element.settings.form_fields)) return element;
    const child = firstInvalidFormFields(Array.isArray(element?.children) ? element.children : []);
    if (child) return child;
  }
  return null;
}

/** Accessible form schema editor; mutations remain undoable in the parent history. */
export function makeFormFieldsControl({ current, onChange, ui, id }) {
  const root = document.createElement('section');
  root.className = 'pb-form-editor';
  root.dataset.pbFormEditor = '';
  const el = (tag, value, className = '') => { const node = document.createElement(tag); node.textContent = value; if (className) node.className = className; return node; };
  const action = (value, label) => { const button = el('button', value, 'pb-form-editor-button'); button.type = 'button'; if (label) button.setAttribute('aria-label', label); return button; };
  const newId = fields => {
    let candidate;
    do { candidate = `f_${id().replace(/^pb_/, '')}`; } while (fields.some(field => field.id === candidate));
    return candidate;
  };
  const initial = () => [
    { id: 'f_name', type: 'text', label: ui.form_field_name, required: true, step: 1, options: [], value: '' },
    { id: 'f_email', type: 'email', label: ui.form_field_email, required: true, step: 1, options: [], value: '' },
    { id: 'f_message', type: 'textarea', label: ui.form_field_message, required: true, step: 1, options: [], value: '' },
  ];
  let fields = current === undefined ? null : editableFields(current);
  let malformed = current !== undefined && fields === null;
  function render(focus = '') {
    root.replaceChildren();
    root.append(el('h3', ui.form_fields), el('p', ui.form_fields_help, 'pb-intro'));
    if (fields === null) {
      if (malformed) root.append(el('p', ui.form_fields_invalid, 'pb-form-editor-error'));
      const enable = action(malformed ? ui.form_fields_reset : ui.form_fields_customize);
      enable.dataset.pbFormCustomize = '';
      enable.addEventListener('click', () => { fields = initial(); malformed = false; onChange(JSON.stringify(fields)); render('add'); });
      root.append(enable);
      return;
    }
    const status = el('p', '', 'pb-form-editor-error'); status.setAttribute('role', 'status'); status.dataset.pbFormFieldsError = '';
    const refresh = () => { status.textContent = validFormFields(JSON.stringify(fields)) ? '' : ui.form_fields_invalid; };
    const emit = () => { onChange(JSON.stringify(fields)); refresh(); };
    fields.forEach((field, index) => {
      const row = document.createElement('fieldset'); row.className = 'pb-form-editor-row'; row.dataset.pbFormRow = field.id;
      const legend = el('legend', `${ui.form_field} ${index + 1} : ${field.label}`); row.append(legend);
      const labelControl = (caption, control) => { const label = document.createElement('label'); label.className = 'pb-field'; label.append(el('span', caption), control); row.append(label); };
      const label = document.createElement('input'); label.type = 'text'; label.maxLength = 100; label.value = field.label; label.dataset.pbFieldLabel = '';
      label.addEventListener('input', () => {
        field.label = label.value;
        legend.textContent = `${ui.form_field} ${index + 1} : ${field.label}`;
        for (const [selector, caption] of [['[data-pb-field-up]', ui.form_field_up], ['[data-pb-field-down]', ui.form_field_down], ['[data-pb-field-remove]', ui.form_field_remove]]) {
          row.querySelector(selector)?.setAttribute('aria-label', `${caption} : ${field.label}`);
        }
        emit();
      });
      labelControl(ui.form_field_label, label);
      const type = document.createElement('select'); type.dataset.pbFieldType = '';
      for (const name of fieldTypes) { const option = el('option', ui[`form_type_${name}`]); option.value = name; type.append(option); }
      type.value = field.type;
      type.addEventListener('change', () => {
        field.type = type.value;
        field.options = choiceTypes.includes(type.value) ? [ui.form_option_default] : [];
        field.value = '';
        if (type.value === 'hidden') field.required = false;
        emit(); render(`${field.id}:type`);
      });
      labelControl(ui.form_field_type, type);
      const step = document.createElement('select'); step.dataset.pbFieldStep = '';
      for (let number = 1; number <= 5; number++) { const option = el('option', String(number)); option.value = String(number); step.append(option); }
      step.value = String(field.step); step.addEventListener('change', () => { field.step = Number(step.value); emit(); });
      labelControl(ui.form_field_step, step);
      if (choiceTypes.includes(field.type)) {
        const options = document.createElement('textarea'); options.rows = 3; options.maxLength = 2020; options.value = field.options.join('\n'); options.dataset.pbFieldOptions = '';
        options.addEventListener('input', () => { field.options = options.value.replace(/\r\n?/g, '\n').split('\n'); emit(); });
        labelControl(ui.form_field_options, options);
      }
      if (field.type === 'hidden') {
        const value = document.createElement('input'); value.type = 'text'; value.maxLength = 160; value.value = field.value; value.dataset.pbFieldValue = '';
        value.addEventListener('input', () => { field.value = value.value; emit(); });
        labelControl(ui.form_field_value, value);
      } else {
        const requiredLabel = document.createElement('label'); requiredLabel.className = 'pb-form-editor-check';
        const required = document.createElement('input'); required.type = 'checkbox'; required.checked = field.required; required.dataset.pbFieldRequired = '';
        required.addEventListener('change', () => { field.required = required.checked; emit(); });
        requiredLabel.append(required, document.createTextNode(ui.form_field_required)); row.append(requiredLabel);
      }
      const buttons = document.createElement('div'); buttons.className = 'pb-form-editor-actions';
      const up = action(ui.form_field_up, `${ui.form_field_up} : ${field.label}`); up.disabled = index === 0; up.dataset.pbFieldUp = '';
      up.addEventListener('click', () => { [fields[index - 1], fields[index]] = [fields[index], fields[index - 1]]; emit(); render(`${field.id}:up`); });
      const down = action(ui.form_field_down, `${ui.form_field_down} : ${field.label}`); down.disabled = index === fields.length - 1; down.dataset.pbFieldDown = '';
      down.addEventListener('click', () => { [fields[index + 1], fields[index]] = [fields[index], fields[index + 1]]; emit(); render(`${field.id}:down`); });
      const remove = action(ui.form_field_remove, `${ui.form_field_remove} : ${field.label}`); remove.disabled = fields.length === 1; remove.dataset.pbFieldRemove = '';
      remove.addEventListener('click', () => { fields.splice(index, 1); emit(); render('add'); });
      buttons.append(up, down, remove); row.append(buttons); root.append(row);
    });
    root.append(status);
    const add = action(ui.form_field_add); add.disabled = fields.length >= 24; add.dataset.pbFieldAdd = '';
    add.addEventListener('click', () => {
      const field = { id: newId(fields), type: 'text', label: ui.form_field_default, required: false, step: fields[fields.length - 1].step, options: [], value: '' };
      fields.push(field); emit(); render(`${field.id}:label`);
    });
    const reset = action(ui.form_fields_legacy); reset.dataset.pbFormLegacy = '';
    reset.addEventListener('click', () => { fields = null; onChange(undefined); render(); root.querySelector('[data-pb-form-customize]')?.focus(); });
    root.append(add, reset); refresh();
    if (focus === 'add') add.focus();
    else if (focus) {
      const [fieldId, target] = focus.split(':');
      const row = [...root.querySelectorAll('[data-pb-form-row]')].find(node => node.dataset.pbFormRow === fieldId);
      const control = row?.querySelector(`[data-pb-field-${target}]`);
      (control?.disabled ? row?.querySelector('[data-pb-field-label]') : control)?.focus();
    }
  }
  render();
  return root;
}
