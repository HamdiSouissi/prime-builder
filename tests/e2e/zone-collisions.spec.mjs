import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('live theme zones avoid existing document IDs and image-map names', async ({ page, browser }) => {
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
  let postId = null;
  try {
    // This is a real privileged Prime HTML block in the real core zone. The
    // sidebar renders the original map; the entry inserts two more zone copies.
    const probe = '<div hidden data-zone-probe><label for="target">Champ</label><input id="target" aria-describedby="note">'
      + '<p id="note">Note</p><a href="#note">Voir la note</a>'
      + '<img src="/assets/plugins/prime-builder/icon.svg" alt="Plan" usemap="#pb-zone-zone1-map-1">'
      + '<map name="pb-zone-zone1-map-1"><area href="#note" alt="Note"></map>'
      + '<svg viewBox="0 0 10 10"><defs><linearGradient id="paint"><stop offset="0%" stop-color="red"></stop></linearGradient></defs><rect width="10" height="10" fill="url(#paint)"></rect></svg></div>';
    const content = { version: 1, blocks: [
      { id: 'text', type: 'paragraph', data: { text: 'Contenu vivant de la zone' } },
      { id: 'search', type: 'dynamic', data: { name: 'search_form', attrs: {} } },
      { id: 'html', type: 'html', data: { html: probe } },
    ] };
    const saved = await saveZone(content);
    expect(saved.status(), await saved.text()).toBe(200);
    const created = await api('/api/v1/content/post', 'POST', {
      title: 'Zones et identifiants', slug: 'pb-zones-identifiants', status: 'published', content: { version: 1, blocks: [] },
    });
    expect(created.status, created.body).toBe(201);
    const post = JSON.parse(created.body).data;
    postId = post.id;
    const elements = [
      { id: 'existing', type: 'anchor', settings: { anchor: 'pb-zone-zone1-target' }, styles: {}, children: [] },
      { id: 'zone1', type: 'theme_zone', settings: { zone: 'sidebar' }, styles: {}, children: [] },
      { id: 'zone2', type: 'theme_zone', settings: { zone: 'sidebar' }, styles: {}, children: [] },
    ];
    const updated = await api(`/api/prime-builder/v1/documents/${postId}`, 'PATCH', {
      content: { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements } }] },
    });
    expect(updated.status, updated.body).toBe(200);
    await page.goto(post.url);
    const originalMap = page.locator('.aurora-sidebar map[name="pb-zone-zone1-map-1"]');
    await expect(originalMap).toHaveCount(1);
    const zones = page.locator('.pb-theme_zone');
    await expect(zones).toHaveCount(2);
    for (const zone of await zones.all()) {
      await expect(zone).toContainText('Contenu vivant de la zone');
      const search = zone.getByRole('searchbox', { name: 'Rechercher sur le site' });
      const searchId = await search.getAttribute('id');
      await expect(zone.locator('label').filter({ hasText: 'Rechercher sur le site' })).toHaveAttribute('for', searchId);
      const instance = zone.locator('[data-zone-probe]');
      const inputId = await instance.locator('input').getAttribute('id');
      const noteId = await instance.locator('p').getAttribute('id');
      await expect(instance.locator('label')).toHaveAttribute('for', inputId);
      await expect(instance.locator('input')).toHaveAttribute('aria-describedby', noteId);
      await expect(instance.locator('a')).toHaveAttribute('href', `#${noteId}`);
      await expect(instance.locator('area')).toHaveAttribute('href', `#${noteId}`);
      const mapName = await instance.locator('map').getAttribute('name');
      await expect(instance.locator('img')).toHaveAttribute('usemap', `#${mapName}`);
      const gradientId = await instance.locator('linearGradient').getAttribute('id');
      await expect(instance.locator('rect')).toHaveAttribute('fill', `url(#${gradientId})`);
    }
    const first = zones.first();
    await expect(first.locator('[data-zone-probe] input')).toHaveAttribute('id', 'pb-zone-zone1-target-2');
    await expect(first.locator('[data-zone-probe] map')).toHaveAttribute('name', 'pb-zone-zone1-map-1-2');
    const ids = await page.locator('[id]').evaluateAll(nodes => nodes.map(node => node.id).filter(Boolean));
    expect(new Set(ids).size).toBe(ids.length);
    const mapNames = await page.locator('map[name]').evaluateAll(nodes => nodes.map(node => node.name));
    expect(new Set(mapNames).size).toBe(mapNames.length);
    const search = first.getByRole('searchbox', { name: 'Rechercher sur le site' });
    await search.focus();
    await page.keyboard.press('Tab');
    await expect(first.getByRole('button', { name: 'Rechercher' })).toBeFocused();
    let accessibility = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(accessibility.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => v.id)).toEqual([]);
    await page.screenshot({ path: test.info().outputPath('zone-collision-public.jpg'), fullPage: true, quality: 15 });
    await page.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    accessibility = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(accessibility.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => v.id)).toEqual([]);
    await page.screenshot({ path: test.info().outputPath('zone-collision-public-mobile.jpg'), fullPage: true, quality: 15 });
    const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
    try {
      const fallback = await noJs.newPage();
      await fallback.goto(post.url);
      await expect(fallback.locator('.pb-theme_zone')).toHaveCount(2);
      for (const zone of await fallback.locator('.pb-theme_zone').all()) {
        await expect(zone).toContainText('La zone du thème est disponible avec JavaScript');
        await expect(zone).not.toContainText('Contenu vivant de la zone');
      }
      await expect(fallback.locator('.aurora-sidebar')).toContainText('Contenu vivant de la zone');
    } finally { await noJs.close(); }
  } finally {
    if (postId !== null) {
      const trashed = await api(`/api/v1/content/post/${postId}`, 'DELETE');
      expect(trashed.status, trashed.body).toBe(200);
      const deleted = await api(`/api/v1/content/post/${postId}?force=1`, 'DELETE');
      expect(deleted.status, deleted.body).toBe(204);
    }
    const restored = await saveZone(originalZone);
    expect(restored.status(), await restored.text()).toBe(200);
  }
});
