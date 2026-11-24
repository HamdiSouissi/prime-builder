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
      let token = '';
      let started = 0;
      let busy = false;

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
        busy = true;
        button.disabled = true;
        status.textContent = form.dataset.pbWait;
        try {
          // The server checks the signed delay too; this pause prevents a premature click
          // from producing a validation error for a person completing the form quickly.
          const delay = Math.max(0, 3100 - (performance.now() - started));
          if (delay) await new Promise(resolve => setTimeout(resolve, delay));
          const values = new FormData(form);
          const response = await fetch(action, {
            method: 'POST', credentials: 'omit', cache: 'no-store',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({
              node, token,
              name: values.get('name'), email: values.get('email'),
              message: values.get('message'), website: values.get('website'),
            }),
          });
          if (response.ok) {
            form.reset();
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
