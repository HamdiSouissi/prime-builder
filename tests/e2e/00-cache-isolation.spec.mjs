import { test, expect } from '@playwright/test';

// Run before any test saves a declared zone on this disposable CMS.
test('anonymous pages cache independently of login rules, dates and today on other pages', async ({ page, browser }) => {
  test.setTimeout(180_000);
  const suffix = Date.now().toString(36);
  const entries = [];
  let originalCache = null;
  let visitors;
  const heading = (id, text, conditions = undefined) => ({
    id, type: 'heading', settings: { text, level: 2 }, styles: {}, children: [],
    ...(conditions ? { conditions } : {}),
  });
  const document = elements => ({ version: 1, blocks: [{ id: 'layout', type: 'prime-builder/layout', data: { version: 1, elements } }] });
  async function api(url, method, body) {
    return page.evaluate(async ({ url, method, body }) => {
      const csrf = document.querySelector('meta[name="csrf-token"]').content;
      const response = await fetch(url, {
        method, headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf, Accept: 'application/json' },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }),
      });
      return { status: response.status, body: await response.text() };
    }, { url, method, body });
  }
  async function createPage(label, elements) {
    const slug = `pb-s7-${label.toLowerCase()}-${suffix}`;
    const created = await api('/api/v1/content/page', 'POST', {
      title: `Cache ${label} ${suffix}`, slug, status: 'published', content: { version: 1, blocks: [] },
    });
    expect(created.status, created.body).toBe(201);
    const entry = JSON.parse(created.body).data;
    entries.push(entry.id);
    const saved = await api(`/api/prime-builder/v1/documents/${entry.id}`, 'PATCH', { content: document(elements) });
    expect(saved.status, saved.body).toBe(200);
    return entry.url || `/${slug}`;
  }

  await page.goto('/admin/login');
  await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/, { timeout: 15_000 }), page.locator('form button[type=submit]').click()]);
  try {
    const settings = await api('/api/v1/settings', 'GET');
    expect(settings.status, settings.body).toBe(200);
    originalCache = JSON.parse(settings.body).data['cache.page_enabled'];
    const enabled = await api('/api/v1/settings', 'PATCH', { 'cache.page_enabled': true });
    expect(enabled.status, enabled.body).toBe(200);
    const pathA = await createPage('A', [
      heading('title', 'Titre dynamique {{entry.title}}'),
      heading('member', 'Membre {{user.name}}', { auth: 'authenticated' }),
      heading('hidden-today', 'Date membre {{today}}', { auth: 'authenticated' }),
    ]);
    const pathB = await createPage('B', [heading('static', 'Page B stable')]);
    const pathC = await createPage('C', [
      heading('current', 'Période visible', { start: '2000-01-01T00:00', end: '2999-01-01T00:00' }),
      heading('future', 'Période future', { start: '2999-01-01T00:00' }),
    ]);
    const pathToday = await createPage('Today', [heading('today', 'Aujourd’hui {{today}}')]);
    visitors = await browser.newContext({ baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080', javaScriptEnabled: false });
    const visitor = await visitors.newPage();

    let response = await visitor.goto(pathB);
    expect(response.headers()['x-prime-cache']).toBe('MISS');
    await expect(visitor.getByText('Page B stable')).toBeVisible();
    response = await visitor.reload();
    expect(response.headers()['x-prime-cache']).toBe('HIT');

    response = await visitor.goto(pathA);
    expect(response.headers()['x-prime-cache']).toBe('MISS');
    await expect(visitor.getByText(`Titre dynamique Cache A ${suffix}`)).toBeVisible();
    await expect(visitor.getByText('Membre', { exact: false })).toHaveCount(0);
    response = await visitor.reload();
    expect(response.headers()['x-prime-cache']).toBe('HIT');
    response = await page.goto(pathA);
    expect(response.headers()['x-prime-cache']).not.toBe('HIT');
    expect(response.headers()['cache-control']).toContain('no-store');
    await expect(page.getByText('Membre CI Admin')).toBeVisible();
    await expect(page.getByText('Date membre', { exact: false })).toBeVisible();
    response = await visitor.reload();
    expect(response.headers()['x-prime-cache']).toBe('HIT');
    await expect(visitor.getByText('Membre', { exact: false })).toHaveCount(0);

    for (let attempt = 0; attempt < 2; attempt++) {
      response = attempt === 0 ? await visitor.goto(pathC) : await visitor.reload();
      expect(response.headers()['x-prime-cache']).toBe('BYPASS');
      expect(response.headers()['cache-control']).toContain('no-store');
      await expect(visitor.getByText('Période visible')).toBeVisible();
      await expect(visitor.getByText('Période future')).toHaveCount(0);
    }
    for (let attempt = 0; attempt < 2; attempt++) {
      response = attempt === 0 ? await visitor.goto(pathToday) : await visitor.reload();
      expect(response.headers()['x-prime-cache']).toBe('BYPASS');
      await expect(visitor.getByText('Aujourd’hui', { exact: false })).toBeVisible();
    }
    response = await visitor.goto(pathB);
    expect(response.headers()['x-prime-cache']).toBe('HIT');
  } finally {
    if (visitors) await visitors.close();
    await page.goto('/admin/prime-builder/theme');
    for (const id of entries) {
      const trashed = await api(`/api/v1/content/page/${id}`, 'DELETE');
      expect(trashed.status, trashed.body).toBe(200);
      const deleted = await api(`/api/v1/content/page/${id}?force=1`, 'DELETE');
      expect(deleted.status, deleted.body).toBe(204);
    }
    if (originalCache !== null) {
      const restored = await api('/api/v1/settings', 'PATCH', { 'cache.page_enabled': originalCache });
      expect(restored.status, restored.body).toBe(200);
    }
  }
});
