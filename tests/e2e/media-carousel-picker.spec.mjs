import { readFile } from 'node:fs/promises';
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('media carousel appends library images without losing videos and preserves known image proportions', async ({ page, browser }) => {
  test.setTimeout(240_000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto('/admin/login');
  await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/), page.locator('form button[type=submit]').click()]);
  await page.goto(`/admin/prime-builder/edit/${process.env.E2E_ENTRY_ID || '1'}`);
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  const api = (url, method, body) => page.evaluate(async ({ url, method, body, csrf }) => {
    const response = await fetch(url, {
      method, headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf, Accept: 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    return { status: response.status, body: await response.text() };
  }, { url, method, body, csrf });
  const accessible = async target => {
    const result = await new AxeBuilder({ page: target }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations.filter(violation => ['serious', 'critical'].includes(violation.impact)).map(violation => violation.id)).toEqual([]);
  };
  const media = [];
  let entryId = null;
  try {
    for (const [name, width, height, color, alt] of [
      ['carousel-wide.png', 320, 200, '#267f62', 'Invalide | séparateur'],
      ['carousel-tall.png', 150, 300, '#7255a7', 'Portrait'],
    ]) {
      const uploaded = await page.evaluate(async ({ csrf, name, width, height, color }) => {
        const canvas = document.createElement('canvas'); canvas.width = width; canvas.height = height;
        const context = canvas.getContext('2d'); context.fillStyle = color; context.fillRect(0, 0, width, height);
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
        const form = new FormData(); form.append('file', blob, name);
        const response = await fetch('/api/v1/media', { method: 'POST', headers: { 'X-Prime-CSRF': csrf, Accept: 'application/json' }, body: form });
        return { status: response.status, body: await response.text() };
      }, { csrf, name, width, height, color });
      expect(uploaded.status, uploaded.body).toBe(201);
      const item = JSON.parse(uploaded.body).data;
      expect([item.width, item.height]).toEqual([width, height]);
      item.url = new URL(item.url, process.env.E2E_BASE_URL || 'http://127.0.0.1:8080').pathname;
      media.push(item);
      const updated = await api(`/api/v1/media/${item.id}`, 'PATCH', { alt });
      expect(updated.status, updated.body).toBe(200);
    }
    const bytes = Array.from(await readFile(new URL('./fixtures/media-demo.webm', import.meta.url)));
    const uploadedVideo = await page.evaluate(async ({ csrf, bytes }) => {
      const form = new FormData(); form.append('file', new Blob([new Uint8Array(bytes)], { type: 'video/webm' }), 'carousel-existing.webm');
      const response = await fetch('/api/v1/media', { method: 'POST', headers: { 'X-Prime-CSRF': csrf, Accept: 'application/json' }, body: form });
      return { status: response.status, body: await response.text() };
    }, { csrf, bytes });
    expect(uploadedVideo.status, uploadedVideo.body).toBe(201);
    const video = JSON.parse(uploadedVideo.body).data;
    video.url = new URL(video.url, process.env.E2E_BASE_URL || 'http://127.0.0.1:8080').pathname;
    media.push(video);
    const [wide, tall] = media;
    const previous = `video | ${video.url} | Démonstration |  | Transcription\nimage | /assets/plugins/prime-builder/images/landscape.svg | Image existante`;
    const created = await api('/api/v1/content/page', 'POST', {
      title: 'Carrousel de médias et dimensions', slug: 'pb-media-carousel-picker', status: 'draft', content: { version: 1, blocks: [] },
    });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;
    const element = { id: 'media_carousel_picker', type: 'media_carousel', settings: { label: 'Médias choisis', items: previous }, styles: {}, children: [] };
    const content = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: [element] } }] };
    const prepared = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content, status: 'published', published_at: '' });
    expect(prepared.status, prepared.body).toBe(200);
    await page.goto(`/admin/prime-builder/edit/${entryId}`);
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="media_carousel_picker"]').click();
    const items = page.getByLabel('Une ligne : image | URL', { exact: false });
    await expect(items).toHaveValue(previous);
    const choose = page.getByRole('button', { name: 'Ajouter des images de la médiathèque', exact: true });
    await expect(choose).toBeVisible({ timeout: 5000 });
    const picker = page.getByRole('dialog', { name: 'Ajouter des images de la médiathèque' });
    const selectBoth = async () => {
      await choose.focus();
      await page.keyboard.press('Enter');
      await expect(picker).toBeVisible();
      await expect(picker.locator(`[data-prime-picker-card="${video.id}"]`)).toHaveCount(0);
      for (const item of [wide, tall]) {
        const option = picker.locator(`[data-prime-picker-card="${item.id}"]`);
        await option.focus();
        await page.keyboard.press('Enter');
        await expect(option).toHaveAttribute('aria-selected', 'true');
      }
      await picker.locator('[data-prime-picker-confirm]').focus();
      await page.keyboard.press('Enter');
      await expect(picker).toBeHidden();
    };
    // A delimiter in a copied alternative must reject the whole selection.
    await selectBoth();
    const notice = page.getByRole('dialog', { name: 'Information' });
    await expect(notice).toContainText('Aucune image n’a été ajoutée.');
    await expect(items).toHaveValue(previous);
    await page.keyboard.press('Escape');
    await expect(notice).toBeHidden();
    await expect(choose).toBeFocused();
    const corrected = await api(`/api/v1/media/${wide.id}`, 'PATCH', { alt: 'Paysage' });
    expect(corrected.status, corrected.body).toBe(200);
    await selectBoth();
    const combined = `${previous}\nimage|${wide.url}|Paysage\nimage|${tall.url}|Portrait`;
    await expect(items).toHaveValue(combined);
    await expect(choose).toBeFocused();
    await page.keyboard.press('Control+z');
    await expect(items).toHaveValue(previous);
    await choose.focus();
    await page.keyboard.press('Control+Shift+z');
    await expect(items).toHaveValue(combined);
    await accessible(page);
    await page.screenshot({ path: test.info().outputPath('media-carousel-picker-editor-desktop.jpg'), quality: 15 });
    await page.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await accessible(page);
    await page.screenshot({ path: test.info().outputPath('media-carousel-picker-editor-mobile.jpg'), quality: 15 });
    await page.setViewportSize({ width: 1440, height: 900 });
    const saving = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click();
    expect((await saving).status()).toBe(200);
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await page.reload();
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="media_carousel_picker"]').click();
    await expect(items).toHaveValue(`video | ${video.url} | Démonstration |  | Transcription\nimage | /assets/plugins/prime-builder/images/landscape.svg | Image existante\nimage | ${wide.url} | Paysage\nimage | ${tall.url} | Portrait`);
    await page.goto('/pb-media-carousel-picker');
    const slides = page.locator('.pb-media-carousel [data-pb-slide]');
    await expect(slides).toHaveCount(4);
    await expect(slides.nth(0).locator('video source')).toHaveAttribute('src', video.url);
    await expect(slides.nth(1).locator('img')).not.toHaveAttribute('width');
    await expect(slides.nth(2).locator('img')).toHaveAttribute('width', '320');
    await expect(slides.nth(2).locator('img')).toHaveAttribute('height', '200');
    await expect(slides.nth(3).locator('img')).toHaveAttribute('width', '150');
    await expect(slides.nth(3).locator('img')).toHaveAttribute('height', '300');
    await accessible(page);

    const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080', viewport: { width: 1440, height: 900 } });
    let releaseImages = () => {};
    try {
      const fallback = await noJs.newPage();
      const gate = new Promise(resolve => { releaseImages = resolve; });
      let blocked = 0;
      await fallback.route(url => url.pathname === wide.url || url.pathname === tall.url, async route => { blocked += 1; await gate; await route.continue(); });
      await fallback.goto('/pb-media-carousel-picker', { waitUntil: 'domcontentloaded' });
      const image = fallback.locator('.pb-media-carousel [data-pb-slide]').nth(2).locator('img');
      await image.scrollIntoViewIfNeeded();
      await expect.poll(() => blocked).toBeGreaterThan(0);
      expect(await image.evaluate(node => node.naturalWidth)).toBe(0);
      const before = await image.boundingBox();
      expect(before.width).toBeGreaterThan(0);
      expect(Math.abs(before.height / before.width - 200 / 320)).toBeLessThan(0.01);
      releaseImages();
      await expect.poll(() => image.evaluate(node => node.naturalWidth)).toBe(320);
      const after = await image.boundingBox();
      expect(Math.abs(after.height - before.height)).toBeLessThan(1);
      await fallback.screenshot({ path: test.info().outputPath('media-carousel-picker-public-desktop.jpg'), quality: 15, fullPage: true });
      await fallback.setViewportSize({ width: 375, height: 812 });
      await expect.poll(() => fallback.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
      await fallback.screenshot({ path: test.info().outputPath('media-carousel-picker-public-mobile.jpg'), quality: 15, fullPage: true });
    } finally {
      releaseImages();
      await noJs.close();
    }

    // Changing a row's URL must not silently reuse dimensions of the former image.
    await page.goto(`/admin/prime-builder/edit/${entryId}`);
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="media_carousel_picker"]').click();
    await items.fill((await items.inputValue()).replace(wide.url, '/uploads/replacement.png'));
    const resaving = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click();
    expect((await resaving).status()).toBe(200);
    await page.goto('/pb-media-carousel-picker');
    await expect(slides.nth(2).locator('img')).toHaveAttribute('src', '/uploads/replacement.png');
    await expect(slides.nth(2).locator('img')).not.toHaveAttribute('width');
    await expect(slides.nth(2).locator('img')).not.toHaveAttribute('height');
    await expect(slides.nth(3).locator('img')).toHaveAttribute('width', '150');
    await expect(slides.nth(3).locator('img')).toHaveAttribute('height', '300');
    expect(errors).toEqual([]);
  } finally {
    if (entryId !== null) {
      await api(`/api/v1/content/page/${entryId}`, 'DELETE');
      await api(`/api/v1/content/page/${entryId}?force=1`, 'DELETE');
    }
    for (const item of media) await api(`/api/v1/media/${item.id}?force=1`, 'DELETE');
  }
});
