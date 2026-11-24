import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('gallery and image carousel append real media selections without losing existing images', async ({ page, browser }) => {
  test.setTimeout(150_000);
  const pageErrors = [];
  page.on('pageerror', error => pageErrors.push(error.message));
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
    expect(result.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => ({ id: v.id, targets: v.nodes.map(node => node.target) }))).toEqual([]);
  };
  const media = [];
  let entryId = null;
  try {
    for (const [name, alt, color] of [['picker-montagne.png', 'Montagne & ciel', '#28613f'], ['picker-ocean.png', 'Océan & soleil', '#254b83']]) {
      const uploaded = await page.evaluate(async ({ csrf, name, color }) => {
        const canvas = document.createElement('canvas'); canvas.width = 320; canvas.height = 200;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#e8eeeb'; ctx.fillRect(0, 0, 320, 200);
        ctx.fillStyle = color; ctx.beginPath(); ctx.moveTo(0, 180); ctx.lineTo(120, 45); ctx.lineTo(230, 170); ctx.lineTo(320, 100); ctx.lineTo(320, 200); ctx.lineTo(0, 200); ctx.fill();
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
        const form = new FormData(); form.append('file', blob, name);
        const response = await fetch('/api/v1/media', { method: 'POST', headers: { 'X-Prime-CSRF': csrf, Accept: 'application/json' }, body: form });
        return { status: response.status, body: await response.text() };
      }, { csrf, name, color });
      expect(uploaded.status, uploaded.body).toBe(201);
      const item = JSON.parse(uploaded.body).data;
      // The public API returns an absolute URL; the admin picker exposes the local path.
      const url = new URL(item.url, process.env.E2E_BASE_URL || 'http://127.0.0.1:8080');
      media.push({ id: item.id, url: url.pathname + url.search, alt });
      const updated = await api(`/api/v1/media/${item.id}`, 'PATCH', { alt });
      expect(updated.status, updated.body).toBe(200);
    }
    const created = await api('/api/v1/content/page', 'POST', {
      title: 'Images de la médiathèque', slug: 'pb-image-list-picker', status: 'draft', content: { version: 1, blocks: [] },
    });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;
    const original = '/assets/plugins/prime-builder/icon.svg|Image existante';
    const element = (id, type, settings = {}) => ({ id, type, settings, styles: {}, children: [] });
    const content = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: [
      element('gallery_picker', 'gallery', { images: original }),
      element('carousel_picker', 'image_carousel', { label: 'Images choisies', images: '' }),
    ] } }] };
    const prepared = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content, status: 'published', published_at: '' });
    expect(prepared.status, prepared.body).toBe(200);

    await page.goto(`/admin/prime-builder/edit/${entryId}`);
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="gallery_picker"]').click();
    const list = page.getByLabel('Images : URL | texte alternatif, une par ligne');
    const choose = page.getByRole('button', { name: 'Ajouter des images de la médiathèque' });
    await expect(list).toHaveValue(original);
    await choose.focus();
    await page.keyboard.press('Enter');
    const picker = page.getByRole('dialog', { name: 'Ajouter des images de la médiathèque' });
    await expect(picker).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(picker).toBeHidden();
    await expect(list).toHaveValue(original);
    await expect(choose).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(picker).toBeVisible();
    await expect(picker.getByRole('listbox', { name: 'Médias disponibles' })).toHaveAttribute('aria-multiselectable', 'true');
    for (const item of media) {
      const option = picker.locator(`[data-prime-picker-card="${item.id}"]`);
      await option.focus();
      await page.keyboard.press('Enter');
      await expect(option).toHaveAttribute('aria-selected', 'true');
    }
    await accessible(page);
    await picker.screenshot({ path: test.info().outputPath('media-list-editor-desktop.jpg'), quality: 15 });
    await page.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await accessible(page);
    await picker.screenshot({ path: test.info().outputPath('media-list-editor-mobile.jpg'), quality: 15 });
    await page.setViewportSize({ width: 1440, height: 900 });
    await picker.locator('[data-prime-picker-confirm]').focus();
    await page.keyboard.press('Enter');
    await expect(picker).toBeHidden();
    const combined = [original, ...media.map(item => `${item.url}|${item.alt}`)].join('\n');
    await expect(list).toHaveValue(combined);
    await expect(choose).toBeFocused();
    await expect(page.locator('[data-pb-live]')).toHaveText('Images ajoutées à la liste.');
    const frame = page.frameLocator('[data-pb-frame]');
    await expect(frame.locator('[data-pb-id="gallery_picker"] img')).toHaveCount(3);
    await page.locator('[data-pb-undo]').focus();
    await page.keyboard.press('Control+z');
    await expect(list).toHaveValue(original);
    await page.keyboard.press('Control+Shift+z');
    await expect(list).toHaveValue(combined);

    // A selection that would cross the 20-image limit is refused atomically.
    const nineteen = Array.from({ length: 19 }, () => original).join('\n');
    await list.fill(nineteen);
    await choose.click();
    await expect(picker).toBeVisible();
    for (const item of media) await picker.locator(`[data-prime-picker-card="${item.id}"]`).click();
    await picker.locator('[data-prime-picker-confirm]').click();
    const notice = page.getByRole('dialog', { name: 'Information' });
    await expect(notice).toContainText('Aucune image n’a été ajoutée.');
    await expect(list).toHaveValue(nineteen);
    await accessible(page);
    await page.keyboard.press('Escape');
    await expect(notice).toBeHidden();
    await expect(choose).toBeFocused();
    await list.fill(combined);

    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="carousel_picker"]').click();
    await expect(list).toHaveValue('');
    await choose.click();
    await expect(picker).toBeVisible();
    await picker.locator(`[data-prime-picker-card="${media[1].id}"]`).click();
    await picker.locator('[data-prime-picker-confirm]').click();
    await expect(list).toHaveValue(`${media[1].url}|${media[1].alt}`);
    await expect(choose).toBeFocused();
    const saving = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click();
    const response = await saving;
    expect(response.status()).toBe(200);
    const saved = (await response.json()).data.content.blocks[0].data;
    expect(saved.elements[0].settings.images).toBe(combined);
    expect(saved.elements[1].settings.images).toBe(`${media[1].url}|${media[1].alt}`);
    expect(saved.items.map(item => item.mediaId).sort((a, b) => a - b)).toEqual(media.map(item => item.id).sort((a, b) => a - b));
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    for (const item of media) {
      const detail = await api(`/api/v1/media/${item.id}`, 'GET');
      expect(detail.status, detail.body).toBe(200);
      expect(JSON.parse(detail.body).data.usages.some(usage => usage.entry_id === entryId && usage.context === 'block')).toBe(true);
      expect((await api(`/api/v1/media/${item.id}`, 'DELETE')).status).toBe(409);
    }
    await page.reload();
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="gallery_picker"]').click();
    await expect(list).toHaveValue(combined);
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="carousel_picker"]').click();
    await expect(list).toHaveValue(`${media[1].url}|${media[1].alt}`);

    const publicPage = await page.context().newPage();
    try {
      await publicPage.goto('/pb-image-list-picker');
      const gallery = publicPage.locator('[data-pb-id="gallery_picker"]');
      const images = gallery.locator('[data-pb-gallery-item] img');
      await expect(images).toHaveCount(3);
      await expect(images.nth(1)).toHaveAttribute('alt', media[0].alt);
      await expect(images.nth(2)).toHaveAttribute('alt', media[1].alt);
      await images.nth(1).scrollIntoViewIfNeeded();
      await expect.poll(() => images.nth(1).evaluate(node => node.naturalWidth)).toBe(320);
      const link = gallery.getByRole('link', { name: /Montagne & ciel/ });
      await link.focus(); await publicPage.keyboard.press('Enter');
      const lightbox = publicPage.getByRole('dialog', { name: 'Image agrandie' });
      await expect(lightbox).toBeVisible();
      await expect(lightbox.locator('img')).toHaveAttribute('alt', media[0].alt);
      await publicPage.keyboard.press('Escape');
      await expect(link).toBeFocused();
      await accessible(publicPage);
      await publicPage.screenshot({ path: test.info().outputPath('media-list-public-desktop.jpg'), quality: 15, fullPage: true });
      await publicPage.setViewportSize({ width: 375, height: 812 });
      await expect.poll(() => publicPage.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
      await accessible(publicPage);
      await publicPage.screenshot({ path: test.info().outputPath('media-list-public-mobile.jpg'), quality: 15, fullPage: true });
    } finally { await publicPage.close(); }
    const noJs = await browser.newContext({ javaScriptEnabled: false });
    try {
      const fallback = await noJs.newPage();
      await fallback.goto('/pb-image-list-picker');
      await expect(fallback.locator('[data-pb-id="gallery_picker"] img')).toHaveCount(3);
      await expect(fallback.locator('[data-pb-id="carousel_picker"] img')).toHaveCount(1);
      await expect(fallback.getByRole('link', { name: /Montagne & ciel/ })).toHaveAttribute('href', media[0].url);
    } finally { await noJs.close(); }
    expect(pageErrors).toEqual([]);

    const empty = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: [] } }] };
    const cleared = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content: empty });
    expect(cleared.status, cleared.body).toBe(200);
    for (const item of media) {
      expect((await api(`/api/v1/media/${item.id}`, 'DELETE')).status).toBe(204);
      item.deleted = true;
    }
  } finally {
    const leave = dialog => dialog.accept();
    page.on('dialog', leave);
    try {
      if (entryId !== null) {
        await api(`/api/v1/content/page/${entryId}`, 'DELETE');
        await api(`/api/v1/content/page/${entryId}?force=1`, 'DELETE');
      }
      for (const item of media) if (!item.deleted) await api(`/api/v1/media/${item.id}?force=1`, 'DELETE');
    } finally { page.off('dialog', leave); }
  }
});
