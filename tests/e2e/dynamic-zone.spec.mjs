import { test, expect } from '@playwright/test';

test('dynamic zones rendered by a Builder widget retain their visitor through live refresh', async ({ page, browser }) => {
  test.setTimeout(150_000);
  await page.goto('/admin/login');
  await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/), page.locator('form button[type=submit]').click()]);
  await page.goto('/admin/appearance/zones?zone=sidebar');
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  const original = JSON.parse(await page.locator('[data-prime-editor-input]').inputValue());
  const saveZone = content => page.request.post('/admin/appearance/zones/sidebar/save', {
    headers: { Accept: 'application/json', 'X-CSRF-Token': csrf }, data: { content },
  });
  const api = (url, method, body) => page.evaluate(async ({ url, method, body, csrf }) => {
    const response = await fetch(url, {
      method, headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf, Accept: 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    return { status: response.status, body: await response.text() };
  }, { url, method, body, csrf });
  const suffix = `${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;
  const title = `Page zone dynamique ${suffix}`;
  const slug = `pb-zone-dynamique-${suffix}`;
  let id = null;
  try {
    const zone = { version: 1, blocks: [{ id: 'builder', type: 'prime-builder/layout', data: { version: 1, elements: [{
      id: 'dynamic_zone_heading', type: 'heading', settings: { text: 'ZONE {{entry.title}} / {{user.name}}', level: 2 },
      styles: {}, children: [], conditions: { auth: 'authenticated' },
    }] } }] };
    const saved = await saveZone(zone);
    expect(saved.status(), await saved.text()).toBe(200);
    const created = await api('/api/v1/content/page', 'POST', { title, slug, status: 'draft', content: { version: 1, blocks: [] } });
    expect(created.status, created.body).toBe(201);
    id = JSON.parse(created.body).data.id;
    const elements = [{ id: 'zone', type: 'theme_zone', settings: { zone: 'sidebar' }, styles: {}, children: [] }];
    const content = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements } }] };
    const updated = await api(`/api/prime-builder/v1/documents/${id}`, 'PATCH', { content, status: 'published', published_at: '' });
    expect(updated.status, updated.body).toBe(200);

    const baseURL = process.env.E2E_BASE_URL || 'http://127.0.0.1:8080';
    const authorized = await browser.newContext({ baseURL, storageState: await page.context().storageState(), javaScriptEnabled: false });
    try {
      const noJs = await authorized.newPage();
      const response = await noJs.goto(`/${slug}`);
      expect(response.status()).toBe(200);
      expect(response.headers()['cache-control']).toContain('no-store');
      const heading = noJs.locator('.pb-theme_zone h2');
      await expect(heading).toContainText(`ZONE ${title} / `);
      const value = await heading.innerText();
      expect(value).not.toContain('{{');
      expect(value.split(' / ')[1].trim()).not.toBe('');
    } finally { await authorized.close(); }
    const guest = await browser.newContext({ baseURL, javaScriptEnabled: false });
    try {
      const noJs = await guest.newPage();
      await noJs.goto(`/${slug}`);
      await expect(noJs.locator('.pb-theme_zone h2')).toHaveCount(0);
    } finally { await guest.close(); }

    const live = page.waitForResponse(response => response.url().includes(`/documents/${id}/live`) && response.status() === 200);
    await page.goto(`/${slug}`);
    expect((await live).ok()).toBe(true);
    await expect(page.locator('.pb-theme_zone h2')).toContainText(`ZONE ${title} / `);
    await expect(page.locator('.pb-theme_zone h2')).not.toContainText('{{');
  } finally {
    await page.goto('/admin/appearance/zones?zone=sidebar');
    if (id !== null) {
      const trashed = await api(`/api/v1/content/page/${id}`, 'DELETE');
      expect(trashed.status, trashed.body).toBe(200);
      const deleted = await api(`/api/v1/content/page/${id}?force=1`, 'DELETE');
      expect(deleted.status, deleted.body).toBe(204);
    }
    const restored = await saveZone(original);
    expect(restored.status(), await restored.text()).toBe(200);
  }
});
