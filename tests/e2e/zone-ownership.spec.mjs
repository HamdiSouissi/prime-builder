import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('native and copied theme zones keep their own responsive Builder styles', async ({ page, browser }) => {
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
    const response = await fetch(url, {
      method, headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf, Accept: 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    return { status: response.status, body: await response.text() };
  }, { url, method, body, csrf });
  let entryId = null;
  try {
    // Aurora renders this zone directly in the sidebar, in addition to the two
    // asynchronously inserted copies. Both documents deliberately reuse IDs.
    const zoneElements = [
      { id: 'shared_color', type: 'heading', settings: { text: 'Titre de la zone', level: 2 }, styles: { desktop: { color: '#178041', padding_left: 6 } }, children: [] },
      { id: 'shared_hidden', type: 'heading', settings: { text: 'Zone visible sur grand écran', level: 3 }, styles: { mobile: { visibility: 'hide' } }, children: [] },
    ];
    const saved = await saveZone({ version: 1, blocks: [
      { id: 'builder', type: 'prime-builder/layout', data: { version: 1, elements: zoneElements } },
      { id: 'search', type: 'dynamic', data: { name: 'search_form', attrs: {} } },
    ] });
    expect(saved.status(), await saved.text()).toBe(200);
    const created = await api('/api/v1/content/post', 'POST', {
      title: 'Styles indépendants des zones', slug: 'pb-styles-independants-zones', status: 'published', content: { version: 1, blocks: [] },
    });
    expect(created.status, created.body).toBe(201);
    const entry = JSON.parse(created.body).data;
    entryId = entry.id;
    const elements = [
      { id: 'shared_color', type: 'heading', settings: { text: 'Titre de l’article', level: 2 }, styles: { desktop: { color: '#bd1b28', padding_left: 40 } }, children: [] },
      { id: 'shared_hidden', type: 'heading', settings: { text: 'Article visible sur mobile', level: 3 }, styles: { desktop: { visibility: 'hide' }, mobile: { visibility: 'show' } }, children: [] },
      { id: 'parent', type: 'container', settings: { layout: 'flex', direction: 'column' }, styles: { desktop: { color: '#174a99' } }, children: [
        { id: 'zone1', type: 'theme_zone', settings: { zone: 'sidebar' }, styles: { desktop: { background: '#eef7ff', padding: 12 } }, children: [] },
        { id: 'zone2', type: 'theme_zone', settings: { zone: 'sidebar' }, styles: {}, children: [] },
      ] },
    ];
    const updated = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', {
      content: { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements } }] },
    });
    expect(updated.status, updated.body).toBe(200);
    await page.goto(entry.url);
    const root = page.locator('.pb-layout[data-pb-live]').first();
    const outside = root.locator(':scope > [data-pb-id="shared_color"]');
    const hiddenOutside = root.locator(':scope > [data-pb-id="shared_hidden"]');
    const copies = root.locator('.pb-theme_zone');
    const native = page.locator('.aurora-sidebar');
    await expect(copies).toHaveCount(2);
    await expect(native.getByRole('heading', { name: 'Titre de la zone' })).toBeVisible();
    const owner = await outside.getAttribute('data-pb-owner');
    expect(owner).toMatch(/^[a-f0-9]{24}$/);
    expect(await native.locator('[data-pb-id="shared_color"]').getAttribute('data-pb-owner')).not.toBe(owner);
    await expect(outside.locator('h2')).toHaveCSS('color', 'rgb(189, 27, 40)');
    await expect(outside).toHaveCSS('padding-left', '40px');
    await expect(hiddenOutside).toBeHidden();
    for (const zone of [native, ...(await copies.all())]) {
      const heading = zone.locator('[data-pb-id="shared_color"]');
      await expect(heading).toBeVisible();
      await expect(heading).toHaveCSS('padding-left', '6px');
      await expect(heading.locator('h2')).toHaveCSS('color', 'rgb(23, 128, 65)');
      await expect(zone.getByRole('heading', { name: 'Zone visible sur grand écran' })).toBeVisible();
    }
    const search = copies.first().getByRole('searchbox', { name: 'Rechercher sur le site' });
    await search.focus();
    await page.keyboard.press('Tab');
    await expect(copies.first().getByRole('button', { name: 'Rechercher' })).toBeFocused();
    let accessibility = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(accessibility.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => v.id)).toEqual([]);
    await page.screenshot({ path: test.info().outputPath('zone-owned-public.jpg'), fullPage: true, quality: 15 });
    await page.setViewportSize({ width: 375, height: 812 });
    await expect(hiddenOutside).toBeVisible();
    for (const zone of [native, ...(await copies.all())]) {
      await expect(zone.locator('[data-pb-id="shared_color"] h2')).toHaveCSS('color', 'rgb(23, 128, 65)');
      await expect(zone.getByRole('heading', { name: 'Zone visible sur grand écran' })).toBeHidden();
    }
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    accessibility = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(accessibility.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => v.id)).toEqual([]);
    await page.screenshot({ path: test.info().outputPath('zone-owned-public-mobile.jpg'), fullPage: true, quality: 15 });
    const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
    try {
      const fallback = await noJs.newPage();
      await fallback.goto(entry.url);
      await expect(fallback.locator('.pb-layout[data-pb-live] > [data-pb-id="shared_color"] h2')).toHaveCSS('color', 'rgb(189, 27, 40)');
      await expect(fallback.locator('.aurora-sidebar [data-pb-id="shared_color"] h2')).toHaveCSS('color', 'rgb(23, 128, 65)');
      for (const zone of await fallback.locator('.pb-theme_zone').all()) {
        await expect(zone).toContainText('La zone du thème est disponible avec JavaScript');
        await expect(zone).not.toContainText('Titre de la zone');
      }
    } finally { await noJs.close(); }

    // Simulate pre-upgrade persisted entry HTML, which has no owner attribute.
    // The current stylesheet must still style that entry while leaving the
    // freshly rendered native zone's independent owner and styles intact.
    const legacy = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
    try {
      const targetPath = new URL(entry.url, process.env.E2E_BASE_URL || 'http://127.0.0.1:8080').pathname;
      await legacy.route(url => url.pathname === targetPath, async route => {
        const response = await route.fetch();
        const html = await response.text();
        const marker = ` data-pb-owner="${owner}"`;
        expect(html).toContain(marker);
        await route.fulfill({ response, body: html.replaceAll(marker, '') });
      });
      const fallback = await legacy.newPage();
      await fallback.goto(entry.url);
      const oldHeading = fallback.locator('.pb-layout[data-pb-live] > [data-pb-id="shared_color"]');
      await expect(oldHeading).not.toHaveAttribute('data-pb-owner');
      await expect(oldHeading).toHaveCSS('padding-left', '40px');
      await expect(oldHeading.locator('h2')).toHaveCSS('color', 'rgb(189, 27, 40)');
      await expect(fallback.locator('.aurora-sidebar [data-pb-id="shared_color"]')).toHaveCSS('padding-left', '6px');
      await expect(fallback.locator('.aurora-sidebar [data-pb-id="shared_color"] h2')).toHaveCSS('color', 'rgb(23, 128, 65)');
    } finally { await legacy.close(); }
  } finally {
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
