import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('entry CSS does not style unrelated Builder elements in a live theme zone', async ({ page, browser }) => {
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
    // Real Prime Editor zone, with a separate Builder document whose IDs happen
    // to match two IDs of the entry. The zone's own styles are not yet loaded;
    // this checks isolation, not the separate unresolved resource problem.
    const zoneElements = [
      { id: 'shared_color', type: 'heading', settings: { text: 'Titre dans la zone', level: 2 }, styles: {}, children: [] },
      { id: 'shared_hidden', type: 'heading', settings: { text: 'Titre de zone toujours visible', level: 3 }, styles: {}, children: [] },
    ];
    const saved = await saveZone({ version: 1, blocks: [
      { id: 'builder', type: 'prime-builder/layout', data: { version: 1, elements: zoneElements } },
      { id: 'search', type: 'dynamic', data: { name: 'search_form', attrs: {} } },
    ] });
    expect(saved.status(), await saved.text()).toBe(200);
    const created = await api('/api/v1/content/page', 'POST', {
      title: 'Isolation des styles de zone', slug: 'pb-isolation-styles-zone', status: 'published', content: { version: 1, blocks: [] },
    });
    expect(created.status, created.body).toBe(201);
    const entry = JSON.parse(created.body).data;
    entryId = entry.id;
    const elements = [
      { id: 'shared_color', type: 'heading', settings: { text: 'Titre de la page', level: 2 }, styles: { desktop: { color: '#bd1b28', padding_left: 40 } }, children: [] },
      { id: 'shared_hidden', type: 'heading', settings: { text: 'Masqué sur grand écran', level: 2 }, styles: { desktop: { visibility: 'hide' }, mobile: { visibility: 'show' } }, children: [] },
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
    const zones = root.locator('.pb-theme_zone');
    await expect(zones).toHaveCount(2);
    await expect(outside.locator('h2')).toHaveCSS('color', 'rgb(189, 27, 40)');
    await expect(outside).toHaveCSS('padding-left', '40px');
    await expect(hiddenOutside).toBeHidden();
    await expect(zones.first()).toHaveCSS('background-color', 'rgb(238, 247, 255)');
    await expect(zones.first()).toHaveCSS('padding-left', '12px');
    for (const zone of await zones.all()) {
      const heading = zone.locator('[data-pb-id="shared_color"]');
      await expect(heading).toBeVisible();
      await expect(heading).not.toHaveCSS('padding-left', '40px');
      // Aurora sets an explicit heading color; the parent's forced inheritance
      // must not override it across this nested Builder-document boundary.
      await expect(heading.locator('h2')).toHaveCSS('color', 'rgb(23, 23, 23)');
      await expect(zone.getByRole('heading', { name: 'Titre de zone toujours visible' })).toBeVisible();
    }
    const search = zones.first().getByRole('searchbox', { name: 'Rechercher sur le site' });
    await search.focus();
    await page.keyboard.press('Tab');
    await expect(zones.first().getByRole('button', { name: 'Rechercher' })).toBeFocused();
    let accessibility = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(accessibility.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => v.id)).toEqual([]);
    await page.screenshot({ path: test.info().outputPath('zone-style-public.jpg'), fullPage: true, quality: 15 });
    await page.setViewportSize({ width: 375, height: 812 });
    await expect(hiddenOutside).toBeVisible();
    for (const zone of await zones.all()) {
      await expect(zone.locator('[data-pb-id="shared_color"] h2')).toHaveCSS('color', 'rgb(23, 23, 23)');
      await expect(zone.getByRole('heading', { name: 'Titre de zone toujours visible' })).toBeVisible();
    }
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    accessibility = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(accessibility.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => v.id)).toEqual([]);
    await page.screenshot({ path: test.info().outputPath('zone-style-public-mobile.jpg'), fullPage: true, quality: 15 });
    const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
    try {
      const fallback = await noJs.newPage();
      await fallback.goto(entry.url);
      await expect(fallback.locator('.pb-layout > [data-pb-id="shared_color"] h2')).toHaveCSS('color', 'rgb(189, 27, 40)');
      await expect(fallback.locator('.pb-theme_zone')).toHaveCount(2);
      for (const zone of await fallback.locator('.pb-theme_zone').all()) {
        await expect(zone).toContainText('La zone du thème est disponible avec JavaScript');
        await expect(zone).not.toContainText('Titre dans la zone');
      }
    } finally { await noJs.close(); }
  } finally {
    if (entryId !== null) {
      const trashed = await api(`/api/v1/content/page/${entryId}`, 'DELETE');
      expect(trashed.status, trashed.body).toBe(200);
      const deleted = await api(`/api/v1/content/page/${entryId}?force=1`, 'DELETE');
      expect(deleted.status, deleted.body).toBe(204);
    }
    const restored = await saveZone(originalZone);
    expect(restored.status(), await restored.text()).toBe(200);
  }
});
