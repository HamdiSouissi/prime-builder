import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('image-list widgets accept explicit intrinsic sizes and discard them when an address changes', async ({ page, browser }) => {
  test.setTimeout(240_000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto('/admin/login');
  await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/), page.locator('form button[type=submit]').click()]);
  await page.goto(`/admin/prime-builder/edit/${process.env.E2E_ENTRY_ID || '1'}`);
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content', { timeout: 10_000 });
  expect(csrf).toBeTruthy();
  const api = (url, method, body) => page.evaluate(async ({ url, method, body, csrf }) => {
    const response = await fetch(url, {
      method, headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf, Accept: 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    return { status: response.status, body: await response.text() };
  }, { url, method, body, csrf });
  const accessible = async target => {
    const result = await new AxeBuilder({ page: target }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => ({ id: v.id, targets: v.nodes.map(node => node.target) }))).toEqual([]);
  };
  const media = [];
  let entryId = null;
  const leave = dialog => dialog.accept();
  try {
    for (const [name, width, height, color] of [
      ['manual-wide.png', 320, 200, '#267f62'],
      ['manual-tall.png', 150, 300, '#7255a7'],
    ]) {
      const upload = await page.evaluate(async ({ csrf, name, width, height, color }) => {
        const canvas = document.createElement('canvas'); canvas.width = width; canvas.height = height;
        const context = canvas.getContext('2d'); context.fillStyle = color; context.fillRect(0, 0, width, height);
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
        const form = new FormData(); form.append('file', blob, name);
        const response = await fetch('/api/v1/media', { method: 'POST', headers: { 'X-Prime-CSRF': csrf, Accept: 'application/json' }, body: form });
        return { status: response.status, body: await response.text() };
      }, { csrf, name, width, height, color });
      expect(upload.status, upload.body).toBe(201);
      const item = JSON.parse(upload.body).data;
      expect([item.width, item.height]).toEqual([width, height]);
      item.url = new URL(item.url, process.env.E2E_BASE_URL || 'http://127.0.0.1:8080').pathname;
      media.push(item);
    }
    const [wide, tall] = media;
    const created = await api('/api/v1/content/page', 'POST', {
      title: 'Dimensions des listes d’images', slug: 'pb-manual-list-dimensions', status: 'draft', content: { version: 1, blocks: [] },
    });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;
    const cases = [
      ['manual_gallery', 'gallery', 'images', `${wide.url} | Paysage\n${tall.url} | Portrait`, 200 / 320],
      ['manual_image_carousel', 'image_carousel', 'images', `${wide.url} | Paysage\n${tall.url} | Portrait`, 200 / 320],
      ['manual_media_carousel', 'media_carousel', 'items', `image | ${wide.url} | Paysage\nimage | ${tall.url} | Portrait`, 200 / 320],
      ['manual_slideshow', 'slideshow', 'items', `${wide.url} | Paysage | Première\n${tall.url} | Portrait | Deuxième`, 9 / 16],
      ['manual_portfolio', 'portfolio', 'items', `${wide.url} | Paysage | Projet A | Art\n${tall.url} | Portrait | Projet B | Art`, 3 / 4],
      ['manual_testimonials', 'testimonial_carousel', 'items', `Excellent | Camille | Artiste | ${wide.url}\nTrès bien | Alex | Designer | ${tall.url}`, 1],
    ];
    const imagesFor = (target, id) => target.locator(`[data-pb-id="${id}"] ${id === 'manual_gallery' ? '[data-pb-gallery-item] img' : 'img'}`);
    const elements = cases.map(([id, type, field, items]) => ({ id, type, settings: { [field]: items }, styles: {}, children: [] }));
    const content = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements } }] };
    const prepared = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content, status: 'published', published_at: '' });
    expect(prepared.status, prepared.body).toBe(200);
    await page.goto(`/admin/prime-builder/edit/${entryId}`);
    const frame = page.frameLocator('[data-pb-frame]');
    const expected = `${wide.url} | 320 | 200\n${tall.url} | 150 | 300`;
    for (const [index, [id]] of cases.entries()) {
      await page.locator('[data-pb-panel="navigator"]').click();
      await page.locator(`[data-pb-tree-select="${id}"]`).click();
      const control = page.locator('textarea[data-pb-setting="manual_dimensions"]');
      await expect(control).toHaveAccessibleName(/Dimensions intrinsèques connues/);
      await expect(control).toHaveValue('');
      const first = imagesFor(frame, id).first();
      await expect(first).not.toHaveAttribute('width');
      await expect(first).not.toHaveAttribute('height');
      await control.fill(expected);
      await expect(control).toBeFocused();
      await expect(first).toHaveAttribute('width', '320');
      await expect(first).toHaveAttribute('height', '200');
      await expect(imagesFor(frame, id).nth(1)).toHaveAttribute('width', '150');
      if (index === 0) {
        await page.locator('[data-pb-undo]').click();
        await expect(control).toHaveValue('');
        await page.locator('[data-pb-redo]').click();
        await expect(control).toHaveValue(expected);
      }
      if (id === 'manual_portfolio') {
        await control.scrollIntoViewIfNeeded();
        await accessible(page);
        await page.locator('[data-pb-settings-panel]').screenshot({ path: test.info().outputPath('manual-list-editor-desktop.jpg'), quality: 15 });
        await page.setViewportSize({ width: 375, height: 812 });
        await control.scrollIntoViewIfNeeded();
        await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
        await accessible(page);
        await page.locator('[data-pb-settings-panel]').screenshot({ path: test.info().outputPath('manual-list-editor-mobile.jpg'), quality: 15 });
        await page.setViewportSize({ width: 1440, height: 900 });
      }
    }
    const saving = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click();
    const savedResponse = await saving;
    expect(savedResponse.status()).toBe(200);
    const saved = (await savedResponse.json()).data.content.blocks[0].data;
    for (const element of saved.elements) expect(element.settings.manual_dimensions).toBe(expected);
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await page.reload();
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="manual_portfolio"]').click();
    await expect(page.locator('textarea[data-pb-setting="manual_dimensions"]')).toHaveValue(expected);
    await page.goto('/pb-manual-list-dimensions');
    for (const [id] of cases) {
      const images = imagesFor(page, id);
      await expect(images).toHaveCount(2);
      await expect(images.first()).toHaveAttribute('width', '320');
      await expect(images.first()).toHaveAttribute('height', '200');
      await expect(images.nth(1)).toHaveAttribute('width', '150');
      await expect(images.nth(1)).toHaveAttribute('height', '300');
    }
    await accessible(page);

    for (const [name, width] of [['desktop', 1440], ['mobile', 375]]) {
      const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080', viewport: { width, height: 900 } });
      let release = () => {};
      try {
        const fallback = await noJs.newPage();
        const gate = new Promise(resolve => { release = resolve; });
        let blocked = 0;
        await fallback.route(url => url.pathname === wide.url, async route => { blocked += 1; await gate; await route.continue(); });
        await fallback.goto('/pb-manual-list-dimensions', { waitUntil: 'domcontentloaded' });
        const before = new Map();
        for (const [id, , , , expectedRatio] of cases) {
          const image = imagesFor(fallback, id).first();
          await image.scrollIntoViewIfNeeded();
          await expect.poll(() => blocked).toBeGreaterThan(0);
          expect(await image.evaluate(node => node.naturalWidth)).toBe(0);
          const box = await image.boundingBox();
          expect(box.width).toBeGreaterThan(0);
          expect(Math.abs(box.height / box.width - expectedRatio)).toBeLessThan(0.02);
          before.set(id, box);
        }
        release();
        for (const [id] of cases) {
          const image = imagesFor(fallback, id).first();
          await expect.poll(() => image.evaluate(node => node.naturalWidth)).toBe(320);
          const after = await image.boundingBox();
          expect(Math.abs(after.width - before.get(id).width)).toBeLessThanOrEqual(1);
          expect(Math.abs(after.height - before.get(id).height)).toBeLessThanOrEqual(1);
        }
        await expect.poll(() => fallback.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
        await fallback.screenshot({ path: test.info().outputPath(`manual-list-public-${name}.jpg`), quality: 15, fullPage: true });
      } finally { release(); await noJs.close(); }
    }

    // The URL and its explicit size are separate: editing the source must not
    // attach the old pair to a replacement, even before the next save.
    await page.goto(`/admin/prime-builder/edit/${entryId}`);
    for (const [id, , field] of cases) {
      await page.locator('[data-pb-panel="navigator"]').click();
      await page.locator(`[data-pb-tree-select="${id}"]`).click();
      const list = page.locator(`textarea[data-pb-setting="${field}"]`);
      await list.fill((await list.inputValue()).replace(wide.url, '/uploads/replacement.png'));
      const first = imagesFor(frame, id).first();
      await expect(first).toHaveAttribute('src', '/uploads/replacement.png');
      await expect(first).not.toHaveAttribute('width');
      await expect(first).not.toHaveAttribute('height');
    }
    const resaving = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click();
    const resavedResponse = await resaving;
    expect(resavedResponse.status()).toBe(200);
    const resaved = (await resavedResponse.json()).data.content.blocks[0].data;
    for (const element of resaved.elements) expect(element.settings.manual_dimensions).toBe(`${tall.url} | 150 | 300`);
    await page.goto('/pb-manual-list-dimensions');
    for (const [id] of cases) {
      const images = imagesFor(page, id);
      await expect(images.first()).toHaveAttribute('src', '/uploads/replacement.png');
      await expect(images.first()).not.toHaveAttribute('width');
      await expect(images.first()).not.toHaveAttribute('height');
      await expect(images.nth(1)).toHaveAttribute('width', '150');
      await expect(images.nth(1)).toHaveAttribute('height', '300');
    }
    expect(errors).toEqual([]);
  } finally {
    page.on('dialog', leave);
    try {
      if (entryId !== null) {
        await api(`/api/v1/content/page/${entryId}`, 'DELETE');
        await api(`/api/v1/content/page/${entryId}?force=1`, 'DELETE');
      }
      for (const item of media) await api(`/api/v1/media/${item.id}?force=1`, 'DELETE');
    } finally { page.off('dialog', leave); }
  }
});
