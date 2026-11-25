import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('six image lists infer local dimensions without saving a snapshot', async ({ page, browser }) => {
  test.setTimeout(180_000);
  page.setDefaultTimeout(30_000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto('/admin/login');
  await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/), page.locator('form button[type=submit]').click()]);
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
  const slug = `pb-inferred-lists-${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;
  let entryId = null;
  try {
    for (const [name, width, height, color] of [
      ['inference-list-wide.png', 320, 200, '#187e65'],
      ['inference-list-tall.png', 150, 300, '#7253a7'],
    ]) {
      const upload = await page.evaluate(async ({ csrf, name, width, height, color }) => {
        const canvas = document.createElement('canvas'); canvas.width = width; canvas.height = height;
        const context = canvas.getContext('2d');
        context.fillStyle = '#f5e8d8'; context.fillRect(0, 0, width, height);
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
    const created = await api('/api/v1/content/page', 'POST', { title: 'Images des listes', slug, status: 'draft', content: { version: 1, blocks: [] } });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;
    const cases = [
      ['list_gallery', 'gallery', 'images', `${wide.url} | Paysage\n${tall.url} | Portrait`],
      ['list_carousel', 'image_carousel', 'images', `${wide.url} | Paysage\n${tall.url} | Portrait`],
      ['list_media', 'media_carousel', 'items', `image | ${wide.url} | Paysage\nimage | ${tall.url} | Portrait\nvideo | /uploads/non-existent.webm | Présentation`],
      ['list_slides', 'slideshow', 'items', `${wide.url} | Paysage | Première\n${tall.url} | Portrait | Deuxième`],
      ['list_portfolio', 'portfolio', 'items', `${wide.url} | Paysage | Projet A | Art\n${tall.url} | Portrait | Projet B | Art`],
      ['list_testimonials', 'testimonial_carousel', 'items', `Excellent | Camille | Artiste | ${wide.url}\nTrès bien | Alex | Designer | ${tall.url}`],
    ];
    const element = ([id, type, field, value]) => ({ id, type, settings: { [field]: value }, styles: {}, children: [] });
    const data = { version: 1, elements: cases.map(element) };
    const content = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data }] };
    const prepared = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content, status: 'published', published_at: '' });
    expect(prepared.status, prepared.body).toBe(200);
    for (const node of JSON.parse(prepared.body).data.content.blocks[0].data.elements) {
      expect(node.settings).not.toHaveProperty('images_dimensions');
      expect(node.settings).not.toHaveProperty('manual_dimensions');
    }
    const targeted = await api(`/api/prime-builder/v1/documents/${entryId}/render`, 'POST', { data, target: 'list_portfolio' });
    expect(targeted.status, targeted.body).toBe(200);
    expect(JSON.parse(targeted.body).data.html).toContain('width="320" height="200"');

    await page.goto(`/admin/prime-builder/edit/${entryId}`);
    const frame = page.frameLocator('[data-pb-frame]');
    const imagesFor = (target, id) => target.locator(`[data-pb-id="${id}"] ${id === 'list_gallery' ? '[data-pb-gallery-item] img' : 'img'}`);
    for (const [id] of cases) {
      const images = imagesFor(frame, id);
      await expect(images).toHaveCount(2);
      await expect(images.first()).toHaveAttribute('width', '320');
      await expect(images.first()).toHaveAttribute('height', '200');
      await expect(images.nth(1)).toHaveAttribute('width', '150');
      await expect(images.nth(1)).toHaveAttribute('height', '300');
    }
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="list_gallery"]').click();
    const control = page.locator('textarea[data-pb-setting="manual_dimensions"]');
    const list = page.locator('textarea[data-pb-setting="images"]');
    await expect(control).toHaveValue('');
    await control.fill(`${wide.url} | 40 | 50`);
    await expect(imagesFor(frame, 'list_gallery').first()).toHaveAttribute('width', '40');
    await expect(imagesFor(frame, 'list_gallery').first()).toHaveAttribute('height', '50');
    // The explicit dimensions belong to this gallery, not to the other five.
    await expect(imagesFor(frame, 'list_carousel').first()).toHaveAttribute('width', '320');
    await control.fill('');
    await expect(imagesFor(frame, 'list_gallery').first()).toHaveAttribute('width', '320');
    await list.fill((await list.inputValue()).replace(wide.url, '/uploads/not-in-library.png'));
    await expect(list).toBeFocused();
    await expect(imagesFor(frame, 'list_gallery').first()).toHaveAttribute('src', '/uploads/not-in-library.png');
    await expect(imagesFor(frame, 'list_gallery').first()).not.toHaveAttribute('width');
    await expect(imagesFor(frame, 'list_gallery').first()).not.toHaveAttribute('height');
    await list.fill((await list.inputValue()).replace('/uploads/not-in-library.png', tall.url));
    await expect(imagesFor(frame, 'list_gallery').first()).toHaveAttribute('src', tall.url);
    await expect(imagesFor(frame, 'list_gallery').first()).toHaveAttribute('width', '150');
    await expect(imagesFor(frame, 'list_gallery').first()).toHaveAttribute('height', '300');
    await accessible();
    await page.screenshot({ path: test.info().outputPath('inferred-lists-editor-desktop.jpg'), quality: 15, fullPage: true });
    await page.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await accessible();
    await page.screenshot({ path: test.info().outputPath('inferred-lists-editor-mobile.jpg'), quality: 15, fullPage: true });
    await page.setViewportSize({ width: 1440, height: 900 });
    const saving = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click();
    const saved = await saving;
    expect(saved.status()).toBe(200);
    for (const node of (await saved.json()).data.content.blocks[0].data.elements) expect(node.settings).not.toHaveProperty('images_dimensions');
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await page.reload();
    await expect(imagesFor(frame, 'list_gallery').first()).toHaveAttribute('width', '150');

    for (const [name, width] of [['desktop', 1440], ['mobile', 375]]) {
      const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080', viewport: { width, height: 900 } });
      let release;
      const gate = new Promise(resolve => { release = resolve; });
      try {
        const fallback = await noJs.newPage();
        let blocked = 0;
        await fallback.route(url => url.pathname === tall.url, async route => { blocked++; await gate; await route.continue(); });
        await fallback.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
        const image = imagesFor(fallback, 'list_gallery').first();
        await image.scrollIntoViewIfNeeded();
        await expect.poll(() => blocked).toBeGreaterThan(0);
        expect(await image.evaluate(node => node.naturalWidth)).toBe(0);
        const before = await image.boundingBox();
        expect(before.width).toBeGreaterThan(0);
        expect(Math.abs(before.height / before.width - 2)).toBeLessThan(0.02);
        release();
        await expect.poll(() => image.evaluate(node => node.naturalWidth)).toBe(150);
        const after = await image.boundingBox();
        expect(Math.abs(after.height - before.height)).toBeLessThanOrEqual(1);
        await expect.poll(() => fallback.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
        await fallback.screenshot({ path: test.info().outputPath(`inferred-lists-public-${name}.jpg`), quality: 15 });
      } finally { release(); await noJs.close(); }
    }
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
