/* Queue native dialogs without opening over another dialog or in a hidden tab. */
(() => {
  'use strict';

  const states = new Map();
  const queue = [];
  let currentDialog = null;

  const integer = (value, min, max) => {
    if (!/^(0|[1-9][0-9]{0,3})$/.test(value || '')) return null;
    const number = Number(value);
    return number >= min && number <= max ? number : null;
  };
  const controls = state => [...document.querySelectorAll(`a[href="#pb-popup-${state.id}"], [data-pb-popup-open="${state.id}"]`)]
    .filter(el => el.matches('a[href], button, input[type="button"], input[type="submit"]'));
  const storage = state => state.frequency === 'session' ? window.sessionStorage : window.localStorage;
  const key = state => `prime-builder:popup:v1:${state.id}`;
  function allowed(state) {
    if (state.frequency === 'always') return true;
    if (state.seen) return false;
    try {
      const value = storage(state).getItem(key(state));
      if (state.frequency === 'session') return value !== 'seen';
      const expires = Number(value);
      return !Number.isFinite(expires) || expires <= Date.now();
    } catch {
      // Browsers can block storage; retain a per-document cap instead.
      return true;
    }
  }
  function updateControls(state) {
    const blocked = !allowed(state);
    for (const control of controls(state)) {
      control.setAttribute('aria-haspopup', 'dialog');
      control.setAttribute('aria-controls', state.dialog.id);
      const reason = `pb-popup-blocked-${state.id}`;
      const descriptions = (control.getAttribute('aria-describedby') || '').split(/\s+/).filter(value => value && value !== reason);
      if (blocked) {
        control.setAttribute('aria-disabled', 'true');
        descriptions.push(reason);
      } else control.removeAttribute('aria-disabled');
      if (descriptions.length) control.setAttribute('aria-describedby', descriptions.join(' '));
      else control.removeAttribute('aria-describedby');
    }
  }
  function remember(state) {
    if (state.frequency === 'always') return;
    state.seen = true;
    try {
      storage(state).setItem(key(state), state.frequency === 'session' ? 'seen' : String(Date.now() + state.days * 86400000));
    } catch { /* The in-memory cap remains in effect until navigation. */ }
    updateControls(state);
  }
  function focusFallback(state) {
    const target = state.previousFocus;
    if (target instanceof HTMLElement && target.isConnected && target !== document.body && target.getClientRects().length) {
      target.focus({ preventScroll: true });
      return;
    }
    const main = document.querySelector('main');
    if (!(main instanceof HTMLElement)) return;
    const hadTabindex = main.hasAttribute('tabindex');
    if (!hadTabindex) main.setAttribute('tabindex', '-1');
    main.focus({ preventScroll: true });
    if (!hadTabindex) main.removeAttribute('tabindex');
  }
  function pump() {
    // Do not cover a dialog opened by another widget or plugin.
    if (document.hidden || currentDialog || document.querySelector('dialog[open]')) return;
    while (queue.length) {
      const state = queue.shift();
      state.queued = false;
      if (!allowed(state) || state.dialog.open) continue;
      state.previousFocus = document.activeElement;
      try { state.dialog.showModal(); } catch { continue; }
      currentDialog = state;
      remember(state);
      document.documentElement.classList.add('pb-popup-open');
      state.close.focus({ preventScroll: true });
      return;
    }
  }
  function enqueue(state, opener = null) {
    if (!allowed(state)) {
      updateControls(state);
      if (state.status) state.status.textContent = state.blocked;
      return;
    }
    if (state.queued || state.dialog.open) return;
    state.queued = true;
    state.triggered = true;
    state.opener = opener;
    queue.push(state);
    pump();
  }
  function trigger(state) {
    if (!state.triggered) enqueue(state);
  }
  function init(root) {
    const id = root.dataset.pbPopup || '';
    const triggerName = root.dataset.pbTrigger || '';
    const frequency = root.dataset.pbFrequency || '';
    const delay = integer(root.dataset.pbDelay, 0, 120);
    const scroll = integer(root.dataset.pbScroll, 1, 100);
    const idle = integer(root.dataset.pbIdle, 5, 1800);
    const days = integer(root.dataset.pbDays, 1, 365);
    const dialog = root.querySelector('dialog');
    const close = root.querySelector('[data-pb-popup-close]');
    if (!/^[a-f0-9]{32}$/.test(id) || states.has(id) || !['click', 'load', 'scroll', 'exit', 'idle'].includes(triggerName)
        || !['always', 'session', 'days'].includes(frequency) || [delay, scroll, idle, days].includes(null)
        || typeof HTMLDialogElement === 'undefined' || !(dialog instanceof HTMLDialogElement) || typeof dialog.showModal !== 'function' || !(close instanceof HTMLButtonElement)) return;
    const state = {
      id, trigger: triggerName, frequency, delay, scroll, idle, days, dialog, close, seen: false, triggered: false, queued: false,
      status: root.querySelector('[data-pb-popup-status]'), blocked: root.dataset.pbBlocked || '', previousFocus: null, opener: null,
    };
    states.set(id, state);
    updateControls(state);
    close.addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
      if (currentDialog === state) currentDialog = null;
      document.documentElement.classList.remove('pb-popup-open');
      if (state.opener instanceof HTMLElement && state.opener !== document.body && state.opener.isConnected && state.opener.getClientRects().length) {
        state.opener.focus({ preventScroll: true });
      } else focusFallback(state);
      if (state.status && !allowed(state)) state.status.textContent = state.blocked;
      pump();
    });
    dialog.addEventListener('keydown', event => {
      if (event.key !== 'Tab' || !(event.target instanceof Element) || event.target.closest('dialog') !== dialog) return;
      const candidates = [...dialog.querySelectorAll('a[href], button, input:not([type="hidden"]), select, textarea, [tabindex]')]
        .filter(el => el instanceof HTMLElement && el.closest('dialog') === dialog && el.tabIndex >= 0
          && !el.matches(':disabled') && !el.closest('[hidden], [inert]') && el.getClientRects().length);
      if (!candidates.length) { event.preventDefault(); dialog.focus(); return; }
      const first = candidates[0], last = candidates[candidates.length - 1], active = document.activeElement;
      if (event.shiftKey && (active === first || !candidates.includes(active))) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && (active === last || !candidates.includes(active))) { event.preventDefault(); first.focus(); }
    });
    if (triggerName === 'load') {
      const start = () => window.setTimeout(() => trigger(state), delay * 1000);
      if (document.readyState === 'complete') start(); else window.addEventListener('load', start, { once: true });
    } else if (triggerName === 'scroll') {
      window.addEventListener('scroll', () => {
        const page = document.documentElement;
        const range = page.scrollHeight - page.clientHeight;
        if (range > 0 && (window.scrollY / range) * 100 >= scroll) trigger(state);
      }, { passive: true });
    } else if (triggerName === 'exit') {
      document.addEventListener('mouseout', event => {
        if (window.matchMedia?.('(pointer: fine)').matches && !event.relatedTarget && event.clientY <= 0) trigger(state);
      });
    } else if (triggerName === 'idle') {
      let timer;
      const reset = () => {
        window.clearTimeout(timer);
        if (!document.hidden && !state.triggered) timer = window.setTimeout(() => trigger(state), idle * 1000);
      };
      for (const event of ['pointermove', 'pointerdown', 'keydown', 'wheel', 'touchstart']) window.addEventListener(event, reset, { passive: true });
      document.addEventListener('visibilitychange', reset);
      reset();
    }
  }
  for (const root of document.querySelectorAll('[data-pb-popup]')) init(root);
  document.addEventListener('click', event => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || !(event.target instanceof Element)) return;
    const target = event.target.closest('a[href^="#pb-popup-"], [data-pb-popup-open]');
    if (!target || !target.matches('a[href], button, input[type="button"], input[type="submit"]')) return;
    const id = target.getAttribute('data-pb-popup-open') || target.getAttribute('href')?.slice('#pb-popup-'.length) || '';
    const state = states.get(id);
    if (!state) return;
    event.preventDefault();
    enqueue(state, target);
  });
  const refreshControls = () => { for (const state of states.values()) updateControls(state); };
  document.addEventListener('prime-builder:fragment', refreshControls);
  window.addEventListener('storage', refreshControls);
  window.addEventListener('focus', refreshControls);
  document.addEventListener('visibilitychange', pump);
  document.addEventListener('close', pump, true);
})();
