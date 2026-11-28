import { test, expect } from '@playwright/test';

// Run before other tests save declared zones, on a disposable CMS. CI uses
// 1.0.3+; the legacy switch also exercises the 1.0.1/1.0.2 fallback locally.
test('saved zones invalidate cached pages, isolate visitors and bypass time-dependent renders', async ({ page, browser }) => {
  test.setTimeout(180_000);
  const legacy = process.env.E2E_EXPECT_LEGACY_ZONE_CACHE === '1';
  const suffix = Date.now().toString(36);
  const entries = [];
  let originalCache = null;
  let visitor;
  let member;
  const heading = (id, text, conditions = undefined) => ({
    id, type: 'heading', settings: { text, level: 2 }, styles: {}, children: [],
    ...(conditions ? { conditions } : {}),
  });
  const layout = elements => ({ version: 1, blocks: [{ id: 'builder', type: 'prime-builder/layout', data: { version: 1, elements } }] });
  const paragraph = text => ({ version: 1, blocks: [{ id: 'text', type: 'paragraph', data: { text } }] });
  const expectCache = (response, modern) => expect(response.headers()['x-prime-cache']).toBe(legacy ? undefined : modern);
  await page.goto('/admin/login');
  await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/), page.locator('form button[type=submit]').click()]);
  await page.goto('/admin/appearance/zones?zone=sidebar');
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  const originalSidebar = JSON.parse(await page.locator('[data-prime-editor-input]').inputValue());
  await page.goto('/admin/appearance/zones?zone=footer_1');
  const originalFooter = JSON.parse(await page.locator('[data-prime-editor-input]').inputValue());
  const saveZone = async (slug, content) => {
    const response = await page.request.post(`/admin/appearance/zones/${slug}/save`, {
      headers: { Accept: 'application/json', 'X-CSRF-Token': csrf }, data: { content },
    });
    expect(response.status(), await response.text()).toBe(200);
  };
  const api = (url, method, body) => page.evaluate(async ({ url, method, body, csrf }) => {
    const response = await fetch(url, { method, headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf, Accept: 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) });
    return { status: response.status, body: await response.text() };
  }, { url, method, body, csrf });
  try {
    const settings = await api('/api/v1/settings', 'GET');
    expect(settings.status, settings.body).toBe(200);
    originalCache = JSON.parse(settings.body).data['cache.page_enabled'];
    const enabled = await api('/api/v1/settings', 'PATCH', { 'cache.page_enabled': true });
    expect(enabled.status, enabled.body).toBe(200);
    const created = await api('/api/v1/content/post', 'POST', { title: 'Cache de zone', slug: `pb-zone-cache-${suffix}`, status: 'published', content: { version: 1, blocks: [] } });
    expect(created.status, created.body).toBe(201);
    const entry = JSON.parse(created.body).data;
    entries.push(['post', entry.id]);
    const createdPage = await api('/api/v1/content/page', 'POST', { title: 'Copie de zone', slug: `pb-zone-copy-${suffix}`, status: 'published', content: { version: 1, blocks: [] } });
    expect(createdPage.status, createdPage.body).toBe(201);
    const copyPage = JSON.parse(createdPage.body).data;
    entries.push(['page', copyPage.id]);
    const savedPage = await api(`/api/prime-builder/v1/documents/${copyPage.id}`, 'PATCH', {
      content: layout([{ id: 'copy', type: 'theme_zone', settings: { zone: 'footer_1' }, styles: {}, children: [] }]),
    });
    expect(savedPage.status, savedPage.body).toBe(200);
    visitor = await browser.newContext({ baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080', javaScriptEnabled: false });
    const anonymous = await visitor.newPage();

    // Existing sidebar regression: after adding, editing and clearing a zone,
    // content, assets and CSP must all reflect the current document.
    let response = await anonymous.goto(entry.url);
    expect(response.headers()['x-prime-cache']).toBe('MISS');
    response = await anonymous.reload();
    expect(response.headers()['x-prime-cache']).toBe('HIT');
    await expect(anonymous.locator('.aurora-sidebar')).toHaveCount(0);
    await saveZone('sidebar', paragraph('Ancienne zone témoin'));
    response = await anonymous.reload();
    expectCache(response, 'MISS');
    await expect(anonymous.locator('.aurora-sidebar')).toContainText('Ancienne zone témoin');
    response = await anonymous.reload();
    expectCache(response, 'HIT');
    await saveZone('sidebar', layout([
      heading('new_heading', 'Nouvelle zone témoin'),
      { id: 'new_map', type: 'map', settings: { title: 'Carte actualisée', latitude: '48.79', longitude: '2.45', zoom: 12 }, styles: {}, children: [] },
    ]));
    response = await anonymous.reload();
    expectCache(response, 'MISS');
    await expect(anonymous.locator('.aurora-sidebar')).toContainText('Nouvelle zone témoin');
    await expect(anonymous.locator('.aurora-sidebar')).not.toContainText('Ancienne zone témoin');
    await expect(anonymous.locator('link[href*="/css/widgets/map.css"]')).toHaveCount(1);
    await expect(anonymous.locator('script[src*="/js/map.js"]')).toHaveCount(1);
    expect(response.headers()['content-security-policy']).toContain('https://www.openstreetmap.org');
    response = await anonymous.reload();
    expectCache(response, 'HIT');
    await saveZone('sidebar', { version: 1, blocks: [] });
    response = await anonymous.reload();
    expectCache(response, 'MISS');
    await expect(anonymous.locator('.aurora-sidebar')).toHaveCount(0);
    await expect(anonymous.locator('link[href*="/css/widgets/map.css"]')).toHaveCount(0);
    await expect(anonymous.locator('script[src*="/js/map.js"]')).toHaveCount(0);
    expect(response.headers()['content-security-policy']).not.toContain('https://www.openstreetmap.org');

    // A real populated native footer remains cacheable on 1.0.3+. Edit it in
    // Appearance > Zones with the actual CMS editor and submit button.
    await saveZone('footer_1', paragraph('Ancien pied témoin'));
    response = await anonymous.goto('/');
    expectCache(response, 'MISS');
    await expect(anonymous.locator('.site-footer__zones')).toContainText('Ancien pied témoin');
    response = await anonymous.reload();
    expectCache(response, 'HIT');
    await page.goto('/admin/appearance/zones?zone=footer_1');
    const footerEditor = page.locator('[data-prime-zone-form] .pc-ed-p-input');
    await expect(footerEditor).toContainText('Ancien pied témoin');
    await footerEditor.fill('Nouveau pied témoin');
    const [formResponse] = await Promise.all([
      page.waitForResponse(r => r.url().includes('/admin/appearance/zones/footer_1/save') && r.request().method() === 'POST'),
      page.locator('[data-prime-zone-form] button[type=submit]').click(),
    ]);
    expect(formResponse.status()).toBe(302);
    response = await anonymous.reload();
    expectCache(response, 'MISS');
    await expect(anonymous.locator('.site-footer__zones')).toContainText('Nouveau pied témoin');
    await expect(anonymous.locator('.site-footer__zones')).not.toContainText('Ancien pied témoin');
    response = await anonymous.reload();
    expectCache(response, 'HIT');

    // The native zone has no visitor context and keeps its static fallback.
    // A date rule still marks that response; a Builder copy evaluates it.
    await saveZone('footer_1', layout([
      heading('stable', 'Zone datée témoin'),
      heading('current', 'Période affichée', { start: '2000-01-01T00:00', end: '2999-01-01T00:00' }),
      heading('future', 'Période future', { start: '2999-01-01T00:00' }),
    ]));
    for (let attempt = 0; attempt < 2; attempt++) {
      response = await anonymous.goto('/');
      expectCache(response, 'BYPASS');
      expect(response.headers()['cache-control']).toContain('no-store');
      await expect(anonymous.locator('.site-footer__zones')).toContainText('Zone datée témoin');
      await expect(anonymous.locator('.site-footer__zones')).not.toContainText('Période affichée');
    }
    for (let attempt = 0; attempt < 2; attempt++) {
      response = await anonymous.goto(copyPage.url);
      expectCache(response, 'BYPASS');
      expect(response.headers()['cache-control']).toContain('no-store');
      await expect(anonymous.locator('.pb-theme_zone')).toContainText('Période affichée');
      await expect(anonymous.locator('.pb-theme_zone')).not.toContainText('Période future');
    }

    // Only the copy resolves today; the native fallback does not display a
    // date, so an unrelated page can be cached on 1.0.3+.
    await saveZone('footer_1', layout([
      heading('today', 'Date copiée {{today}}'),
      heading('hidden', 'Date membre {{today}}', { auth: 'authenticated' }),
    ]));
    response = await anonymous.goto('/');
    expectCache(response, 'MISS');
    response = await anonymous.reload();
    expectCache(response, 'HIT');
    for (let attempt = 0; attempt < 2; attempt++) {
      response = await anonymous.goto(copyPage.url);
      expectCache(response, 'BYPASS');
      expect(response.headers()['cache-control']).toContain('no-store');
      const copy = anonymous.locator('.pb-theme_zone');
      await expect(copy).toContainText(/Date copiée\s+\S+/u);
      await expect(copy).not.toContainText('{{today}}');
      await expect(copy).not.toContainText('Date membre');
    }

    // With a saved, contextual zone and the cache enabled, a member's first
    // request cannot populate the guest cache, and an existing guest HIT must
    // not hide or leak the member's personalized result in either direction.
    await saveZone('footer_1', layout([
      heading('public', 'Page {{entry.title}}'),
      heading('member', 'Membre {{user.name}}', { auth: 'authenticated' }),
    ]));
    member = await browser.newContext({
      baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080',
      storageState: await page.context().storageState(),
      javaScriptEnabled: false,
    });
    const authenticated = await member.newPage();
    response = await authenticated.goto(copyPage.url);
    expect(response.headers()['x-prime-cache']).not.toBe('HIT');
    expect(response.headers()['cache-control']).toContain('no-store');
    await expect(authenticated.locator('.pb-theme_zone')).toContainText('Page Copie de zone');
    await expect(authenticated.locator('.pb-theme_zone')).toContainText('Membre CI Admin');
    await expect(authenticated.locator('.site-footer__zones')).not.toContainText('Membre CI Admin');
    response = await anonymous.goto(copyPage.url);
    expectCache(response, 'MISS');
    await expect(anonymous.locator('.pb-theme_zone')).toContainText('Page Copie de zone');
    await expect(anonymous.locator('.pb-theme_zone')).not.toContainText('Membre');
    response = await anonymous.reload();
    expectCache(response, 'HIT');
    response = await authenticated.reload();
    expect(response.headers()['x-prime-cache']).not.toBe('HIT');
    expect(response.headers()['cache-control']).toContain('no-store');
    await expect(authenticated.locator('.pb-theme_zone')).toContainText('Membre CI Admin');
    response = await anonymous.reload();
    expectCache(response, 'HIT');
    await expect(anonymous.locator('.pb-theme_zone')).not.toContainText('Membre');
  } finally {
    if (member) await member.close();
    if (visitor) await visitor.close();
    for (const [type, id] of entries) {
      const trashed = await api(`/api/v1/content/${type}/${id}`, 'DELETE');
      expect(trashed.status, trashed.body).toBe(200);
      const deleted = await api(`/api/v1/content/${type}/${id}?force=1`, 'DELETE');
      expect(deleted.status, deleted.body).toBe(204);
    }
    await saveZone('sidebar', originalSidebar);
    await saveZone('footer_1', originalFooter);
    if (originalCache !== null) {
      const restoredSetting = await api('/api/v1/settings', 'PATCH', { 'cache.page_enabled': originalCache });
      expect(restoredSetting.status, restoredSetting.body).toBe(200);
    }
  }
});
