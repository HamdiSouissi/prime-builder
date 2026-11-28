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
      const model = /^\/api\/prime-builder\/v1\/forms\/models\/[a-f0-9]{32}\/submit$/.test(action.pathname);
      if (action.origin !== window.location.origin || (!model && !/^\/api\/prime-builder\/v1\/forms\/[1-9][0-9]*\/submit$/.test(action.pathname))) continue;
      const page = form.dataset.pbPage || '';
      const pageType = form.dataset.pbPageType || '';
      const proof = form.dataset.pbProof || '';
      if (model && (!page.startsWith('/') || page.startsWith('//') || !['site', 'plugin'].includes(pageType) || !/^[a-f0-9]{64}$/.test(proof))) continue;
      // Do not let an anonymous API request create a session cookie and defeat
      // page caching. A runtime form may need the login session to recheck visibility.
      const credentials = form.dataset.pbAuthenticated === '1' ? 'same-origin' : 'omit';
      enhanced.add(form);
      const startUrl = new URL(action.href.replace(/\/submit$/, '/start'));
      startUrl.searchParams.set('node', node);
      if (model) {
        startUrl.searchParams.set('page', page);
        startUrl.searchParams.set('page_type', pageType);
        startUrl.searchParams.set('proof', proof);
      }
      const custom = form.dataset.pbCustom === '1';
      const steps = custom ? [...form.querySelectorAll('[data-pb-form-step]')] : [];
      const previous = form.querySelector('[data-pb-form-previous]');
      const next = form.querySelector('[data-pb-form-next]');
      let step = 0;
      let token = '';
      let csrf = '';
      let started = 0;
      let busy = false;
      let uploadLimits = { file_kb: 512, total_kb: 512, count: 4 };

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
          } else if (group.dataset.pbKind === 'file') {
            const file = first.files?.[0];
            const valid = !file || (file.size > 0 && file.size <= uploadLimits.file_kb * 1024 && /^.+\.(pdf|png|jpe?g|txt|docx)$/iu.test(file.name));
            first.setCustomValidity(valid ? '' : form.dataset.pbFileError);
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
        csrf = '';
        try {
          const response = await fetch(startUrl, { credentials, cache: 'no-store', headers: { Accept: 'application/json' } });
          if (!response.ok) {
            status.textContent = response.status === 429 ? form.dataset.pbLimited : form.dataset.pbError;
            return;
          }
          const json = await response.json();
          if (typeof json?.data?.token !== 'string') throw new Error('invalid response');
          token = json.data.token;
          const uploads = json.data.uploads;
          if (uploads && Number.isInteger(uploads.file_kb) && uploads.file_kb >= 1 && uploads.file_kb <= 8192
              && Number.isInteger(uploads.total_kb) && uploads.total_kb >= uploads.file_kb && uploads.total_kb <= 8192
              && Number.isInteger(uploads.count) && uploads.count >= 1 && uploads.count <= 4) {
            uploadLimits = uploads;
            if (typeof uploads.error === 'string') form.dataset.pbFileError = uploads.error;
            if (typeof uploads.help === 'string') form.querySelectorAll('.pb-form-help').forEach(help => { help.textContent = uploads.help; });
            // Saved static HTML from an older version can still advertise the old list.
            form.querySelectorAll('[data-pb-kind="file"] input[type="file"]').forEach(input => { input.accept = '.pdf,.png,.jpg,.jpeg,.txt,.docx'; });
          }
          if (credentials === 'same-origin' && typeof json.data.csrf === 'string' && /^[a-f0-9]{64}$/.test(json.data.csrf)) csrf = json.data.csrf;
          started = performance.now();
          button.disabled = false;
        } catch {
          status.textContent = form.dataset.pbError;
        }
      }

      function safeRedirect(value) {
        if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//') || /[\x00-\x20\x7f\\]/.test(value)) return null;
        try {
          const url = new URL(value, window.location.origin);
          return url.origin === window.location.origin ? url.href : null;
        } catch { return null; }
      }

      form.addEventListener('submit', async event => {
        event.preventDefault();
        if (!token || busy) return;
        if (custom) {
          let fileBytes = 0;
          let fileCount = 0;
          for (let index = 0; index < steps.length; index++) {
            const invalid = invalidIn(steps[index]);
            if (invalid) { showStep(index); invalid.reportValidity(); return; }
            for (const input of steps[index].querySelectorAll('[data-pb-kind="file"] input[type="file"]')) {
              const file = input.files?.[0];
              if (file) { fileCount++; fileBytes += file.size; }
              if (fileCount > uploadLimits.count || fileBytes > uploadLimits.total_kb * 1024) {
                input.setCustomValidity(form.dataset.pbFileError);
                showStep(index); input.reportValidity(); return;
              }
            }
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
          const files = [];
          let hasFileField = false;
          let body;
          if (custom) {
            const fields = {};
            for (const group of form.querySelectorAll('[data-pb-field]')) {
              const key = group.dataset.pbField;
              if (!/^f_[a-z0-9]{1,24}$/.test(key)) throw new Error('invalid form field');
              if (group.dataset.pbKind === 'checkbox') fields[key] = values.getAll(key);
              else if (group.dataset.pbKind === 'acceptance') fields[key] = values.has(key);
              else if (group.dataset.pbKind === 'file') {
                hasFileField = true;
                const file = group.querySelector('input[type="file"]')?.files?.[0];
                fields[key] = null;
                if (file) files.push([`upload_${key}`, file]);
              } else fields[key] = values.get(key) ?? '';
            }
            body = { node, token, fields, website: values.get('website') };
          } else {
            body = { node, token, name: values.get('name'), email: values.get('email'), message: values.get('message'), website: values.get('website') };
          }
          if (model) Object.assign(body, { page, page_type: pageType, proof });
          // PHP can silently discard parts above max_file_uploads. Let the
          // server detect that truncation instead of accepting a partial form.
          if (hasFileField) body.upload_count = files.length;
          let requestBody = JSON.stringify(body);
          const submitUrl = new URL(action.href);
          if (hasFileField) {
            const multipart = new FormData();
            multipart.append('payload', requestBody);
            files.forEach(([key, file]) => multipart.append(key, file));
            requestBody = multipart;
            submitUrl.pathname = submitUrl.pathname.replace(/\/submit$/, '/submit-multipart');
          }
          const response = await fetch(submitUrl, {
            method: 'POST', credentials, cache: 'no-store',
            headers: { ...(!hasFileField ? { 'Content-Type': 'application/json' } : {}), Accept: 'application/json', ...(csrf ? { 'X-Prime-CSRF': csrf } : {}) },
            body: requestBody,
          });
          if (response.ok) {
            // Read the current published action from the non-cacheable response,
            // not a potentially stale copy in the cached page HTML.
            const json = await response.json();
            // Do not report success for a proxy error page, malformed response,
            // or a PHP startup warning that accidentally forced HTTP 200.
            if (json?.data?.accepted !== true) throw new Error('invalid submission response');
            const message = typeof json?.data?.message === 'string' && json.data.message.length <= 2000 && json.data.message.trim()
              ? json.data.message : form.dataset.pbSent;
            const redirect = safeRedirect(json?.data?.redirect);
            form.reset();
            if (steps.length) showStep(0);
            status.textContent = redirect && form.dataset.pbRedirecting ? `${message} ${form.dataset.pbRedirecting}` : message;
            if (redirect) {
              token = '';
              window.setTimeout(() => window.location.assign(redirect), 3000);
              return;
            }
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
