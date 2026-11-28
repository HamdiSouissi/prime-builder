import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('known library dimensions follow typed single-image URLs without altering the document', async ({ page, browser }) => {
  test.setTimeout(180_000);
  page.setDefaultTimeout(30_000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto('/admin/login');
  await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/), page.locator('form button[type=submit]').click()]);
  // The authenticated dashboard supplies a token without requiring a fixture
  // entry; this test creates and removes its own page below.
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  expect(csrf).toBeTruthy();
  const api = (url, method, body) => page.evaluate(async ({ url, method, body, csrf }) => {
    const response = await fetch(url, {
      method, headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf, Accept: 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    return { status: response.status, body: await response.text() };
  }, { url, method, body, csrf });
  const accessible = async () => {
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => ({ id: v.id, targets: v.nodes.map(node => node.target) }))).toEqual([]);
  };
  const media = [];
  const slug = `pb-inferred-image-${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;
  let entryId = null;
  try {
    for (const [name, width, height, color] of [
      ['inference-wide.png', 320, 200, '#247d66'],
      ['inference-tall.png', 150, 300, '#745da5'],
    ]) {
      const upload = await page.evaluate(async ({ csrf, name, width, height, color }) => {
        const canvas = document.createElement('canvas'); canvas.width = width; canvas.height = height;
        const context = canvas.getContext('2d');
        context.fillStyle = '#f3ead9'; context.fillRect(0, 0, width, height);
        context.fillStyle = color; context.fillRect(width / 8, height / 8, width * 3 / 4, height * 3 / 4);
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
        const form = new FormData(); form.append('file', blob, name);
        const response = await fetch('/api/v1/media', { method: 'POST', headers: { 'X-Prime-CSRF': csrf, Accept: 'application/json' }, body: form });
        return { status: response.status, body: await response.text() };
      }, { csrf, name, width, height, color });
      expect(upload.status, upload.body).toBe(201);
      const item = JSON.parse(upload.body).data;
      expect([item.width, item.height]).toEqual([width, height]);
      const url = new URL(item.url, process.env.E2E_BASE_URL || 'http://127.0.0.1:8080');
      media.push({ id: item.id, url: url.pathname + url.search, width, height });
    }
    const [wide, tall] = media;
    const created = await api('/api/v1/content/page', 'POST', { title: 'Dimensions de la médiathèque', slug, status: 'draft', content: { version: 1, blocks: [] } });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;
    const elements = [{ id: 'photo', type: 'image', settings: { url: wide.url, alt: 'Image de démonstration' }, styles: {}, children: [] }];
    const data = { version: 1, elements };
    const content = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data }] };
    const prepared = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content, status: 'published', published_at: '' });
    expect(prepared.status, prepared.body).toBe(200);
    expect(JSON.parse(prepared.body).data.content.blocks[0].data.elements[0].settings).not.toHaveProperty('width');
    const targeted = await api(`/api/prime-builder/v1/documents/${entryId}/render`, 'POST', { data, target: 'photo' });
    expect(targeted.status, targeted.body).toBe(200);
    expect(JSON.parse(targeted.body).data.html).toContain('width="320" height="200"');

    await page.goto(`/admin/prime-builder/edit/${entryId}`);
    const frame = page.frameLocator('[data-pb-frame]');
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="photo"]').click();
    const source = page.getByLabel('Adresse URL', { exact: true });
    const width = page.getByLabel('Largeur', { exact: true });
    const height = page.getByLabel('Hauteur', { exact: true });
    const image = frame.locator('[data-pb-id="photo"] img');
    await expect(image).toHaveAttribute('width', '320');
    await expect(image).toHaveAttribute('height', '200');
    await expect(width).toHaveValue('');
    await expect(height).toHaveValue('');
    await source.fill(tall.url);
    await expect(source).toBeFocused();
    await expect(image).toHaveAttribute('src', tall.url);
    await expect(image).toHaveAttribute('width', '150');
    await expect(image).toHaveAttribute('height', '300');
    await expect(width).toHaveValue('');
    await expect(height).toHaveValue('');
    await accessible();
    await page.screenshot({ path: test.info().outputPath('inferred-image-editor-desktop.jpg'), quality: 15, fullPage: true });
    await page.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await accessible();
    await page.screenshot({ path: test.info().outputPath('inferred-image-editor-mobile.jpg'), quality: 15, fullPage: true });
    await page.setViewportSize({ width: 1440, height: 900 });
    let saving = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click();
    const saved = await saving;
    expect(saved.status()).toBe(200);
    const settings = (await saved.json()).data.content.blocks[0].data.elements[0].settings;
    expect(settings).not.toHaveProperty('width');
    expect(settings).not.toHaveProperty('height');
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await page.reload();
    await expect(frame.locator('[data-pb-id="photo"] img')).toHaveAttribute('width', '150');
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="photo"]').click();
    await expect(width).toHaveValue('');
    await expect(height).toHaveValue('');

    for (const [name, screenWidth] of [['desktop', 1440], ['mobile', 375]]) {
      const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080', viewport: { width: screenWidth, height: 900 } });
      let release;
      const gate = new Promise(resolve => { release = resolve; });
      try {
        const fallback = await noJs.newPage();
        let blocked = 0;
        await fallback.route(url => url.pathname === tall.url, async route => { blocked++; await gate; await route.continue(); });
        await fallback.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
        const publicImage = fallback.locator('[data-pb-id="photo"] img');
        await publicImage.scrollIntoViewIfNeeded();
        await expect.poll(() => blocked).toBeGreaterThan(0);
        expect(await publicImage.evaluate(node => node.naturalWidth)).toBe(0);
        const before = await publicImage.boundingBox();
        expect(before.width).toBeGreaterThan(0);
        expect(Math.abs(before.height / before.width - 2)).toBeLessThan(0.02);
        release();
        await expect.poll(() => publicImage.evaluate(node => node.naturalWidth)).toBe(150);
        const after = await publicImage.boundingBox();
        expect(Math.abs(after.height - before.height)).toBeLessThanOrEqual(1);
        await expect.poll(() => fallback.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(screenWidth);
        await fallback.screenshot({ path: test.info().outputPath(`inferred-image-public-${name}.jpg`), quality: 15, fullPage: true });
      } finally { release(); await noJs.close(); }
    }

    // Explicit author sizes take precedence; a new unknown URL must not inherit them.
    await width.fill('80');
    await height.fill('80');
    await expect(image).toHaveAttribute('width', '80');
    await expect(image).toHaveAttribute('height', '80');
    saving = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click();
    expect((await saving).status()).toBe(200);
    await source.fill('/uploads/not-in-library.png');
    await expect(width).toHaveValue('');
    await expect(height).toHaveValue('');
    await expect(image).toHaveAttribute('src', '/uploads/not-in-library.png');
    await expect(image).not.toHaveAttribute('width');
    await expect(image).not.toHaveAttribute('height');
    expect(errors).toEqual([]);
  } finally {
    if (entryId !== null) {
      const trashed = await api(`/api/v1/content/page/${entryId}`, 'DELETE');
      expect(trashed.status, trashed.body).toBe(200);
      const deleted = await api(`/api/v1/content/page/${entryId}?force=1`, 'DELETE');
      expect(deleted.status, deleted.body).toBe(204);
    }
    for (const item of media) {
      const deleted = await api(`/api/v1/media/${item.id}`, 'DELETE');
      expect(deleted.status, deleted.body).toBe(204);
    }
  }
});
