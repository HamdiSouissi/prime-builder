import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('sharing in late zone copies follows the owning entry without leaking context', async ({ page, browser }) => {
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
  const target = async link => new URL(await link.getAttribute('href')).searchParams.get('u');
  const entries = [];
  let releaseLive = () => {};
  const externalRequests = [];
  page.on('request', request => {
    if (/^(www\.)?(facebook\.com|linkedin\.com|twitter\.com)$/i.test(new URL(request.url()).hostname)) externalRequests.push(request.url());
  });
  try {
    const share = { id: 'share', type: 'share_buttons', settings: { label: 'Partager la zone', message: 'Bonjour & merci', linkedin: 'hide' }, styles: {}, children: [] };
    const saved = await saveZone({ version: 1, blocks: [{ id: 'builder', type: 'prime-builder/layout', data: { version: 1, elements: [share] } }] });
    expect(saved.status(), await saved.text()).toBe(200);
    for (const [slug, canonical] of [['pb-zone-share-first', 'https://example.test/zone-canonical?private_token=secret#fragment'], ['pb-zone-share-second', '']]) {
      const created = await api('/api/v1/content/post', 'POST', {
        title: `Partage ${slug}`, slug, status: 'published', seo: { canonical }, content: { version: 1, blocks: [] },
      });
      expect(created.status, created.body).toBe(201);
      const entry = JSON.parse(created.body).data;
      entries.push(entry);
      const elements = ['zone1', 'zone2'].map(id => ({ id, type: 'theme_zone', settings: { zone: 'sidebar' }, styles: {}, children: [] }));
      const updated = await api(`/api/prime-builder/v1/documents/${entry.id}`, 'PATCH', { content: { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements } }] } });
      expect(updated.status, updated.body).toBe(200);
    }

    let liveStarted = false;
    const gate = new Promise(resolve => { releaseLive = resolve; });
    await page.route(`**/documents/${entries[0].id}/live`, async route => { liveStarted = true; await gate; await route.continue(); });
    await page.goto(`${entries[0].url}?private_token=browser-secret`);
    await expect.poll(() => liveStarted, { timeout: 15_000, message: 'The live zone request should start' }).toBe(true);
    const native = page.locator('.aurora-sidebar');
    const copies = page.locator('.pb-layout[data-pb-live] .pb-theme_zone');
    await expect(copies).toHaveCount(2);
    await expect(copies.locator('[data-pb-share]')).toHaveCount(0);
    await expect(native.locator('.pb-share-message')).toBeVisible();
    await expect(native.locator('[data-pb-share-service]')).toHaveCount(0);
    await expect(page.locator('script[src*="/js/share_buttons.js"]')).toHaveCount(1);
    await expect(page.locator('link[href*="/css/widgets/share_buttons.css"]')).toHaveCount(1);
    await expect(page.locator('head link[rel="canonical"]')).toHaveAttribute('href', 'https://example.test/zone-canonical?private_token=secret#fragment');
    releaseLive();
    await expect(copies.locator('[data-pb-share]')).toHaveCount(2);
    for (const copy of await copies.all()) {
      const nav = copy.getByRole('navigation', { name: 'Partager la zone' });
      const facebook = nav.getByRole('link', { name: 'Partager sur Facebook' });
      await expect.poll(() => target(facebook)).toBe('https://example.test/zone-canonical');
      await expect(facebook).toHaveAttribute('rel', 'noreferrer');
      await expect(nav.getByRole('link', { name: 'Partager sur LinkedIn' })).toHaveCount(0);
      expect(await facebook.getAttribute('href')).not.toMatch(/private_token|secret|fragment/);
      const x = nav.getByRole('link', { name: 'Partager sur X' });
      expect(new URL(await x.getAttribute('href')).searchParams.get('text')).toBe('Bonjour & merci');
    }
    const first = copies.first().getByRole('navigation', { name: 'Partager la zone' });
    await first.getByRole('link', { name: 'Partager sur Facebook' }).focus();
    await page.keyboard.press('Tab');
    await expect(first.getByRole('link', { name: 'Partager sur X' })).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(first.getByRole('link', { name: 'Partager par e-mail' })).toBeFocused();
    await copies.first().evaluate(node => node.dispatchEvent(new CustomEvent('prime-builder:fragment', { bubbles: true })));
    await expect(copies.first().getByRole('navigation', { name: 'Partager la zone' })).toHaveCount(1);
    expect(externalRequests).toEqual([]);
    const accessibility = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(accessibility.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => v.id)).toEqual([]);
    await page.screenshot({ path: test.info().outputPath('zone-share-public-desktop.jpg'), fullPage: true, quality: 15 });
    await page.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    const mobileAccessibility = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(mobileAccessibility.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => v.id)).toEqual([]);
    await page.screenshot({ path: test.info().outputPath('zone-share-public-mobile.jpg'), fullPage: true, quality: 15 });
    await page.setViewportSize({ width: 1440, height: 900 });

    // Customizer previews keep the public entry fallback, never the preview URL.
    await page.goto(`${entries[0].url}?customizer_preview=1&private_token=preview-secret`);
    await expect(copies.locator('[data-pb-share]')).toHaveCount(2);
    const previewLink = copies.first().getByRole('link', { name: 'Partager sur Facebook' });
    expect(await target(previewLink)).toBe(new URL(entries[0].url, page.url()).href);
    expect(await previewLink.getAttribute('href')).not.toMatch(/preview|private_token|secret/);
    await page.goto(`${entries[1].url}?private_token=another-secret`);
    await expect(copies.locator('[data-pb-share]')).toHaveCount(2);
    await expect.poll(() => target(copies.first().getByRole('link', { name: 'Partager sur Facebook' }))).toBe(new URL(entries[1].url, page.url()).href);
    await expect(native.locator('[data-pb-share-service]')).toHaveCount(0);
    expect(externalRequests).toEqual([]);

    const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
    try {
      const fallback = await noJs.newPage();
      await fallback.goto(entries[0].url);
      await expect(fallback.locator('.aurora-sidebar .pb-share-message')).toBeVisible();
      await expect(fallback.locator('.aurora-sidebar [data-pb-share-service]')).toHaveCount(0);
      await expect(fallback.locator('.pb-theme_zone [data-pb-share-service]')).toHaveCount(0);
      await expect(fallback.locator('.pb-theme_zone').first()).toContainText('La zone du thème est disponible avec JavaScript');
    } finally { await noJs.close(); }
  } finally {
    releaseLive();
    for (const entry of entries) {
      const trashed = await api(`/api/v1/content/post/${entry.id}`, 'DELETE');
      expect(trashed.status, trashed.body).toBe(200);
      const deleted = await api(`/api/v1/content/post/${entry.id}?force=1`, 'DELETE');
      expect(deleted.status, deleted.body).toBe(204);
    }
    const restored = await saveZone(originalZone);
    expect(restored.status(), await restored.text()).toBe(200);
  }
});
