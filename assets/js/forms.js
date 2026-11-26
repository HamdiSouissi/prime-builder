(() => {
  'use strict';

  const enhanced = new WeakSet();
  const initialize = root => {
    const widgets = [...root.querySelectorAll('[data-pb-form]')];
    if (root.matches?.('[data-pb-form]')) widgets.unshift(root);
    for (const form of widgets) {
      if (enhanced.has(form)) continue;
      if (!(form instanceof HTMLFormElement) || form.closest('[hidden]')) continue;
      const button = form.querySelector('button[type="submit"]');
      const status = form.querySelector('[data-pb-form-status]');
      const node = form.dataset.pbNode || '';
      if (!(button instanceof HTMLButtonElement) || !status || !/^[a-zA-Z0-9_-]{1,64}$/.test(node)) continue;
      const action = new URL(form.action, window.location.href);
      if (action.origin !== window.location.origin || !/^\/api\/prime-builder\/v1\/forms\/[1-9][0-9]*\/submit$/.test(action.pathname)) continue;
      enhanced.add(form);
      const startUrl = new URL(action.href.replace(/\/submit$/, '/start'));
      startUrl.searchParams.set('node', node);
      const custom = form.dataset.pbCustom === '1';
      const steps = custom ? [...form.querySelectorAll('[data-pb-form-step]')] : [];
      const previous = form.querySelector('[data-pb-form-previous]');
      const next = form.querySelector('[data-pb-form-next]');
      let step = 0;
      let token = '';
      let started = 0;
      let busy = false;

      function showStep(index, focus = false) {
        step = index;
        steps.forEach((section, i) => { section.hidden = i !== index; });
        if (previous) previous.hidden = index === 0;
        if (next) next.hidden = index === steps.length - 1;
        button.hidden = index !== steps.length - 1;
        if (focus) steps[index]?.querySelector('legend')?.focus();
      }
      function invalidIn(section) {
        for (const group of section.querySelectorAll('[data-pb-field]')) {
          const controls = [...group.querySelectorAll('input,select,textarea')];
          const first = controls[0];
          if (!first) continue;
          if (group.dataset.pbKind === 'checkbox') {
            const missing = group.dataset.pbRequired === '1' && !controls.some(control => control.checked);
            first.setCustomValidity(missing ? form.dataset.pbRequiredError : '');
          } else if (group.dataset.pbKind === 'number') {
            const valid = first.value === '' || /^-?[0-9]{1,9}(?:\.[0-9]{1,4})?$/.test(first.value);
            first.setCustomValidity(valid ? '' : form.dataset.pbError);
          } else if (group.dataset.pbKind === 'phone') {
            const valid = first.value === '' || /^[0-9+()./ -]{3,40}$/.test(first.value.trim());
            first.setCustomValidity(valid ? '' : form.dataset.pbError);
          }
          const invalid = controls.find(control => !control.checkValidity());
          if (invalid) return invalid;
        }
        return null;
      }
      if (custom && steps.length) {
        // Hidden required controls cannot receive native focus; reveal the first
        // invalid step before reporting it, rather than relying on browser UI.
        form.noValidate = true;
        showStep(0);
        previous?.addEventListener('click', () => { if (step > 0) showStep(step - 1, true); });
        next?.addEventListener('click', () => {
          const invalid = invalidIn(steps[step]);
          if (invalid) { invalid.reportValidity(); return; }
          if (step < steps.length - 1) showStep(step + 1, true);
        });
      }

      async function prepare() {
        button.disabled = true;
        token = '';
        try {
          const response = await fetch(startUrl, { credentials: 'omit', cache: 'no-store', headers: { Accept: 'application/json' } });
          if (!response.ok) {
            status.textContent = response.status === 429 ? form.dataset.pbLimited : form.dataset.pbError;
            return;
          }
          const json = await response.json();
          if (typeof json?.data?.token !== 'string') throw new Error('invalid response');
          token = json.data.token;
          started = performance.now();
          button.disabled = false;
        } catch {
          status.textContent = form.dataset.pbError;
        }
      }

      form.addEventListener('submit', async event => {
        event.preventDefault();
        if (!token || busy) return;
        if (custom) {
          for (let index = 0; index < steps.length; index++) {
            const invalid = invalidIn(steps[index]);
            if (invalid) { showStep(index); invalid.reportValidity(); return; }
          }
        }
        busy = true;
        button.disabled = true;
        status.textContent = form.dataset.pbWait;
        try {
          // The server checks the signed delay too; this pause prevents a premature click
          // from producing a validation error for a person completing the form quickly.
          const delay = Math.max(0, 3100 - (performance.now() - started));
          if (delay) await new Promise(resolve => setTimeout(resolve, delay));
          const values = new FormData(form);
          let body;
          if (custom) {
            const fields = {};
            for (const group of form.querySelectorAll('[data-pb-field]')) {
              const key = group.dataset.pbField;
              if (!/^f_[a-z0-9]{1,24}$/.test(key)) throw new Error('invalid form field');
              if (group.dataset.pbKind === 'checkbox') fields[key] = values.getAll(key);
              else if (group.dataset.pbKind === 'acceptance') fields[key] = values.has(key);
              else fields[key] = values.get(key) ?? '';
            }
            body = { node, token, fields, website: values.get('website') };
          } else {
            body = { node, token, name: values.get('name'), email: values.get('email'), message: values.get('message'), website: values.get('website') };
          }
          const response = await fetch(action, {
            method: 'POST', credentials: 'omit', cache: 'no-store',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(body),
          });
          if (response.ok) {
            form.reset();
            if (steps.length) showStep(0);
            status.textContent = form.dataset.pbSent;
          } else {
            status.textContent = response.status === 429 ? form.dataset.pbLimited : form.dataset.pbError;
          }
          // A token is single-use; even after a failed attempt fetch a fresh one.
          await prepare();
        } catch {
          status.textContent = form.dataset.pbError;
          await prepare();
        } finally {
          busy = false;
          if (token) button.disabled = false;
        }
      });
      prepare();
    }
  };
  initialize(document);
  document.addEventListener('prime-builder:fragment', event => {
    if (typeof event.target?.querySelectorAll === 'function') initialize(event.target);
  });
})();
