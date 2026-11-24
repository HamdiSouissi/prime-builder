import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('tabs and maps in native and asynchronously copied zones load once and remain independent', async ({ page, browser }) => {
  test.setTimeout(120_000);
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
  let releaseLive = () => {};
  try {
    const zoneElements = [
      { id: 'nested_tabs', type: 'tabs', settings: { label: 'Onglets de la zone', title_1: 'Premier', text_1: '<p>Contenu un</p>', title_2: 'Second', text_2: '<p>Contenu deux</p>' }, styles: { desktop: { color: '#176533' } }, children: [] },
      { id: 'nested_map', type: 'map', settings: { title: 'Carte de la zone', latitude: '48.79', longitude: '2.45', zoom: 12 }, styles: {}, children: [] },
    ];
    const saved = await saveZone({ version: 1, blocks: [{ id: 'builder', type: 'prime-builder/layout', data: { version: 1, elements: zoneElements } }] });
    expect(saved.status(), await saved.text()).toBe(200);
    const created = await api('/api/v1/content/post', 'POST', { title: 'Widgets dans les zones', slug: 'pb-nested-zone-widgets', status: 'published', content: { version: 1, blocks: [] } });
    expect(created.status, created.body).toBe(201);
    const entry = JSON.parse(created.body).data;
    entryId = entry.id;
    const elements = [
      { id: 'nested_tabs', type: 'heading', settings: { text: 'Titre hors zone', level: 2 }, styles: { desktop: { color: '#bd1b28' } }, children: [] },
      { id: 'zone1', type: 'theme_zone', settings: { zone: 'sidebar' }, styles: {}, children: [] },
      { id: 'zone2', type: 'theme_zone', settings: { zone: 'sidebar' }, styles: {}, children: [] },
    ];
    const updated = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content: { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements } }] } });
    expect(updated.status, updated.body).toBe(200);
    // Simulate IDs introduced by a theme before the generated tab controls.
    const targetPath = new URL(entry.url, process.env.E2E_BASE_URL || 'http://127.0.0.1:8080').pathname;
    await page.route(url => url.pathname === targetPath, async route => {
      const response = await route.fetch();
      const html = await response.text();
      const collisions = '<div id="pb-tab-nested_tabs-1"></div><div id="pb-tab-nested_tabs-1-2-button"></div>'
        + '<div id="pb-zone-zone1-pb-tab-nested_tabs-1-button"></div>';
      expect(html).toMatch(/<body\b[^>]*>/i);
      await route.fulfill({ response, body: html.replace(/(<body\b[^>]*>)/i, `$1${collisions}`) });
    });
    const requestUrls = [];
    page.on('request', request => { if (request.url().startsWith('https://www.openstreetmap.org/')) requestUrls.push(request.url()); });
    await page.route('https://www.openstreetmap.org/**', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<p>Carte simulée</p>' }));
    let signalLive;
    const liveStarted = new Promise(resolve => { signalLive = resolve; });
    const liveGate = new Promise(resolve => { releaseLive = resolve; });
    await page.route(`**/documents/${entryId}/live`, async route => { signalLive(); await liveGate; await route.continue(); });
    const response = await page.goto(entry.url);
    await liveStarted;
    const native = page.locator('.aurora-sidebar');
    const copies = page.locator('.pb-layout[data-pb-live] .pb-theme_zone');
    await expect(copies).toHaveCount(2);
    // Assets execute before the delayed response inserts copies into the DOM.
    await expect(copies.locator('[data-pb-tabs]')).toHaveCount(0);
    await expect(page.locator('link[href*="/css/widgets/tabs.css"]')).toHaveCount(1);
    await expect(page.locator('script[src*="/js/tabs.js"]')).toHaveCount(1);
    await expect(page.locator('link[href*="/css/widgets/map.css"]')).toHaveCount(1);
    await expect(page.locator('script[src*="/js/map.js"]')).toHaveCount(1);
    expect(response.headers()['content-security-policy']).toContain('https://www.openstreetmap.org');
    expect(requestUrls).toEqual([]);
    await expect(native.getByRole('tablist', { name: 'Onglets de la zone' })).toHaveCount(1);
    releaseLive();
    await expect(copies.locator('[data-pb-tabs]')).toHaveCount(2);
    const zones = [native, ...(await copies.all())];
    for (const zone of zones) {
      await expect(zone.getByRole('tablist', { name: 'Onglets de la zone' })).toHaveCount(1);
      await expect(zone.getByRole('tab', { name: 'Premier' })).toHaveAttribute('aria-selected', 'true');
      await expect(zone.getByRole('tabpanel', { name: 'Premier' })).toContainText('Contenu un');
      await expect(zone.locator('[data-pb-map-load]')).toBeVisible();
      await expect(zone.locator('.pb-tabs')).toHaveCSS('color', 'rgb(23, 101, 51)');
    }
    const first = copies.first();
    await expect(native.getByRole('tabpanel', { name: 'Premier' })).toHaveAttribute('id', 'pb-tab-nested_tabs-1-2');
    await expect(native.getByRole('tab', { name: 'Premier' })).toHaveAttribute('aria-controls', 'pb-tab-nested_tabs-1-2');
    await expect(native.getByRole('tab', { name: 'Premier' })).toHaveAttribute('id', 'pb-tab-nested_tabs-1-2-button-2');
    await expect(first.getByRole('tab', { name: 'Premier' })).toHaveAttribute('id', 'pb-zone-zone1-pb-tab-nested_tabs-1-button-2');
    await expect(page.locator('[id="pb-tab-nested_tabs-1"]')).toHaveCount(1);
    await expect(page.locator('[id="pb-tab-nested_tabs-1-2-button"]')).toHaveCount(1);
    await expect(page.locator('[id="pb-zone-zone1-pb-tab-nested_tabs-1-button"]')).toHaveCount(1);
    // Repeated notifications must not duplicate controls or handlers.
    await first.evaluate(node => node.dispatchEvent(new CustomEvent('prime-builder:fragment', { bubbles: true })));
    await expect(first.getByRole('tablist', { name: 'Onglets de la zone' })).toHaveCount(1);
    await first.getByRole('tab', { name: 'Premier' }).focus();
    await page.keyboard.press('ArrowRight');
    await expect(first.getByRole('tab', { name: 'Second' })).toBeFocused();
    await expect(first.getByRole('tabpanel', { name: 'Second' })).toContainText('Contenu deux');
    await expect(copies.nth(1).getByRole('tab', { name: 'Premier' })).toHaveAttribute('aria-selected', 'true');
    await expect(native.getByRole('tab', { name: 'Premier' })).toHaveAttribute('aria-selected', 'true');
    const accessibility = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(accessibility.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => v.id)).toEqual([]);
    expect(requestUrls).toEqual([]);
    await page.screenshot({ path: test.info().outputPath('nested-zone-public.jpg'), fullPage: true, quality: 15 });
    await page.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    const mobileAccessibility = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(mobileAccessibility.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => v.id)).toEqual([]);
    await page.screenshot({ path: test.info().outputPath('nested-zone-public-mobile.jpg'), fullPage: true, quality: 15 });
    await page.setViewportSize({ width: 1440, height: 900 });
    await first.locator('[data-pb-map-load]').click();
    await expect(first.locator('iframe[title="Carte de la zone"]')).toHaveCount(1);
    await expect.poll(() => requestUrls.length).toBe(1);
    await expect(copies.nth(1).locator('[data-pb-map-load]')).toBeVisible();
    const ids = await page.locator('[data-pb-tab-panel], [role="tab"]').evaluateAll(nodes => nodes.map(node => node.id));
    expect(new Set(ids).size).toBe(ids.length);
    const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
    try {
      const fallback = await noJs.newPage();
      await fallback.goto(entry.url);
      await expect(fallback.locator('.aurora-sidebar [data-pb-tab-panel]')).toHaveCount(2);
      await expect(fallback.locator('.aurora-sidebar [data-pb-tab-panel]').first()).toBeVisible();
      await expect(fallback.locator('.aurora-sidebar [data-pb-tab-panel]').last()).toBeVisible();
      await expect(fallback.locator('.aurora-sidebar [data-pb-map-load]')).toBeHidden();
      await expect(fallback.locator('.aurora-sidebar [data-pb-map] a[href*="openstreetmap.org"]').first()).toBeVisible();
    } finally { await noJs.close(); }
  } finally {
    releaseLive();
    if (entryId !== null) {
      const trashed = await api(`/api/v1/content/post/${entryId}`, 'DELETE');
      expect(trashed.status, trashed.body).toBe(200);
      const deleted = await api(`/api/v1/content/post/${entryId}?force=1`, 'DELETE');
      expect(deleted.status, deleted.body).toBe(204);
    }
    const restored = await saveZone(originalZone);
    expect(restored.status(), await restored.text()).toBe(200);
  }
});
