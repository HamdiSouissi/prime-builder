/* The saved UTC date remains readable without JavaScript; the visitor's clock drives the timer. */
(() => {
  const units = ['day', 'hour', 'minute', 'second'];
  const pending = [];
  const enhanced = new WeakSet();
  let timer = null;

  function update() {
    const now = Date.now();
    for (let index = pending.length - 1; index >= 0; index--) {
      const item = pending[index];
      if (!item.widget.isConnected) { pending.splice(index, 1); continue; }
      const remaining = Math.max(0, Math.ceil((item.deadline - now) / 1000));
      if (remaining === 0) {
        item.values.hidden = true;
        item.status.textContent = item.widget.dataset.pbFinished || '';
        pending.splice(index, 1);
        continue;
      }
      const counts = [Math.floor(remaining / 86400), Math.floor(remaining / 3600) % 24, Math.floor(remaining / 60) % 60, remaining % 60];
      item.fields.forEach((field, position) => {
        const count = counts[position];
        field.value.textContent = String(count);
        field.label.textContent = count === 1 ? field.label.dataset.pbOne : field.label.dataset.pbMany;
      });
      item.values.hidden = false;
    }
    if (pending.length === 0) {
      if (timer !== null) { clearInterval(timer); timer = null; }
      document.removeEventListener('visibilitychange', onVisibility);
    }
  }
  function onVisibility() { if (!document.hidden) update(); }
  function initialize(root) {
    const widgets = [...root.querySelectorAll('[data-pb-countdown]')];
    if (root.matches?.('[data-pb-countdown]')) widgets.unshift(root);
    for (const widget of widgets) {
      if (enhanced.has(widget)) continue;
      const deadline = Date.parse(widget.dataset.pbDeadline || '');
      const values = widget.querySelector('[data-pb-countdown-values]');
      const status = widget.querySelector('[data-pb-countdown-status]');
      const fields = units.map(unit => ({
        value: widget.querySelector(`[data-pb-countdown-value="${unit}"]`),
        label: widget.querySelector(`[data-pb-countdown-label="${unit}"]`),
      }));
      if (!Number.isFinite(deadline) || !values || !status || fields.some(field => !field.value || !field.label)) continue;
      enhanced.add(widget);
      pending.push({ widget, deadline, values, status, fields });
    }
    update();
    if (pending.length && timer === null) {
      timer = setInterval(update, 1000);
      document.addEventListener('visibilitychange', onVisibility);
    }
  }
  initialize(document);
  document.addEventListener('prime-builder:fragment', event => {
    if (typeof event.target?.querySelectorAll === 'function') initialize(event.target);
  });
})();
