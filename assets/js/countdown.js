/* The saved UTC date remains readable without JavaScript; the visitor's clock drives the timer. */
(() => {
  const units = ['day', 'hour', 'minute', 'second'];
  const pending = [];
  for (const widget of document.querySelectorAll('[data-pb-countdown]')) {
    const deadline = Date.parse(widget.dataset.pbDeadline || '');
    const values = widget.querySelector('[data-pb-countdown-values]');
    const status = widget.querySelector('[data-pb-countdown-status]');
    const fields = units.map(unit => ({
      value: widget.querySelector(`[data-pb-countdown-value="${unit}"]`),
      label: widget.querySelector(`[data-pb-countdown-label="${unit}"]`),
    }));
    if (!Number.isFinite(deadline) || !values || !status || fields.some(field => !field.value || !field.label)) continue;
    pending.push({ widget, deadline, values, status, fields });
  }

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
      if (timer !== null) clearInterval(timer);
      document.removeEventListener('visibilitychange', onVisibility);
    }
  }
  function onVisibility() { if (!document.hidden) update(); }
  update();
  if (pending.length) {
    timer = setInterval(update, 1000);
    document.addEventListener('visibilitychange', onVisibility);
  }
})();
