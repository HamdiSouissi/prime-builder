document.querySelectorAll('[data-pb-form]').forEach((form) => {
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const status = form.querySelector('.pb-form__status');
    const data = Object.fromEntries(new FormData(form).entries());
    try {
      const res = await fetch(form.getAttribute('action') || '', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data),
      });
      const json = await res.json();
      if (status) status.textContent = json.message || json.error || '';
      if (res.ok) form.reset();
    } catch {
      if (status) status.textContent = 'Erreur réseau.';
    }
  });
});
