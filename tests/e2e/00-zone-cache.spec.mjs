import { test, expect } from '@playwright/test';

// This must run first against a freshly installed, disposable CMS with no saved
// zones. It proves a genuine cached response exists before a zone is introduced.
test('an existing anonymous page cache is bypassed after adding, changing and clearing a zone', async ({ page, browser }) => {
  test.setTimeout(90_000);
  await page.goto('/admin/login');
  await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/), page.locator('form button[type=submit]').click()]);
  await page.goto('/admin/appearance/zones?zone=sidebar');
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  const originalZone = JSON.parse(await page.locator('[data-prime-editor-input]').inputValue());
  const saveZone = content => page.request.post('/admin/appearance/zones/sidebar/save', {
    headers: { Accept: 'application/json', 'X-CSRF-Token': csrf }, data: { content },
  });
  const api = (url, method, body) => page.evaluate(async ({ url, method, body, csrf }) => {
    const response = await fetch(url, { method, headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf, Accept: 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) });
    return { status: response.status, body: await response.text() };
  }, { url, method, body, csrf });
  let entryId = null;
  let visitor;
  let originalCache = null;
  try {
    const settings = await api('/api/v1/settings', 'GET');
    expect(settings.status, settings.body).toBe(200);
    originalCache = JSON.parse(settings.body).data['cache.page_enabled'];
    const enabled = await api('/api/v1/settings', 'PATCH', { 'cache.page_enabled': true });
    expect(enabled.status, enabled.body).toBe(200);
    const created = await api('/api/v1/content/post', 'POST', { title: 'Cache de zone', slug: 'pb-zone-cache', status: 'published', content: { version: 1, blocks: [] } });
    expect(created.status, created.body).toBe(201);
    const entry = JSON.parse(created.body).data;
    entryId = entry.id;
    visitor = await browser.newContext({ baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
    const anonymous = await visitor.newPage();
    let response = await anonymous.goto(entry.url);
    expect(response.headers()['x-prime-cache']).toBe('MISS');
    response = await anonymous.reload();
    expect(response.headers()['x-prime-cache']).toBe('HIT');
    await expect(anonymous.locator('.aurora-sidebar')).toHaveCount(0);
    const initial = await saveZone({ version: 1, blocks: [{ id: 'old', type: 'paragraph', data: { text: 'Ancienne zone témoin' } }] });
    expect(initial.status(), await initial.text()).toBe(200);
    response = await anonymous.reload();
    expect(response.headers()['x-prime-cache']).toBeUndefined();
    await expect(anonymous.locator('.aurora-sidebar')).toContainText('Ancienne zone témoin');
    const zone = { version: 1, blocks: [{ id: 'new', type: 'prime-builder/layout', data: { version: 1, elements: [
      { id: 'new_heading', type: 'heading', settings: { text: 'Nouvelle zone témoin', level: 2 }, styles: {}, children: [] },
      { id: 'new_map', type: 'map', settings: { title: 'Carte actualisée', latitude: '48.79', longitude: '2.45', zoom: 12 }, styles: {}, children: [] },
    ] } }] };
    const changed = await saveZone(zone);
    expect(changed.status(), await changed.text()).toBe(200);
    response = await anonymous.reload();
    await expect(anonymous.locator('.aurora-sidebar')).toContainText('Nouvelle zone témoin');
    await expect(anonymous.locator('.aurora-sidebar')).not.toContainText('Ancienne zone témoin');
    await expect(anonymous.locator('link[href*="/css/widgets/map.css"]')).toHaveCount(1);
    await expect(anonymous.locator('script[src*="/js/map.js"]')).toHaveCount(1);
    expect(response.headers()['content-security-policy']).toContain('https://www.openstreetmap.org');
    // The middleware returns the uncached response directly when the request
    // filter rejects it, so this CMS version does not add a cache header.
    expect(response.headers()['x-prime-cache']).toBeUndefined();
    const cleared = await saveZone({ version: 1, blocks: [] });
    expect(cleared.status(), await cleared.text()).toBe(200);
    response = await anonymous.reload();
    await expect(anonymous.locator('.aurora-sidebar')).toHaveCount(0);
    await expect(anonymous.locator('link[href*="/css/widgets/map.css"]')).toHaveCount(0);
    await expect(anonymous.locator('script[src*="/js/map.js"]')).toHaveCount(0);
    expect(response.headers()['content-security-policy']).not.toContain('https://www.openstreetmap.org');
    expect(response.headers()['x-prime-cache']).toBeUndefined();
  } finally {
    if (visitor) await visitor.close();
    if (entryId !== null) {
      const trashed = await api(`/api/v1/content/post/${entryId}`, 'DELETE');
      expect(trashed.status, trashed.body).toBe(200);
      const deleted = await api(`/api/v1/content/post/${entryId}?force=1`, 'DELETE');
      expect(deleted.status, deleted.body).toBe(204);
    }
    const restored = await saveZone(originalZone);
    expect(restored.status(), await restored.text()).toBe(200);
    if (originalCache !== null) {
      const restoredSetting = await api('/api/v1/settings', 'PATCH', { 'cache.page_enabled': originalCache });
      expect(restoredSetting.status, restoredSetting.body).toBe(200);
    }
  }
});
