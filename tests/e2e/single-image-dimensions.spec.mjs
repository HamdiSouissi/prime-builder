import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('single-image widgets replace stale sizes and reserve known proportions before loading', async ({ page, browser }) => {
  test.setTimeout(180_000);
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
    expect(result.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => ({ id: v.id, targets: v.nodes.map(node => node.target) }))).toEqual([]);
  };
  const media = [];
  let entryId = null;
  try {
    for (const [name, width, height, alt, color] of [
      ['taille-large.png', 320, 200, 'Panorama large', '#267f62'],
      ['taille-haute.png', 150, 300, 'Portrait haut', '#7255a7'],
    ]) {
      const upload = await page.evaluate(async ({ csrf, name, width, height, color }) => {
        const canvas = document.createElement('canvas'); canvas.width = width; canvas.height = height;
        const context = canvas.getContext('2d');
        context.fillStyle = '#f5f0dd'; context.fillRect(0, 0, width, height);
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
      media.push({ id: item.id, url: url.pathname + url.search, width, height, alt });
      const updated = await api(`/api/v1/media/${item.id}`, 'PATCH', { alt });
      expect(updated.status, updated.body).toBe(200);
    }
    const [wide, tall] = media;
    const created = await api('/api/v1/content/page', 'POST', {
      title: 'Dimensions des images seules', slug: 'pb-single-image-dimensions', status: 'draft', content: { version: 1, blocks: [] },
    });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;
    const widgets = [
      ['single_image', 'image', 'url'], ['single_box', 'image_box', 'image_url'],
      ['single_testimonial', 'testimonial', 'image_url'], ['single_review', 'review', 'image_url'],
      ['single_cta', 'call_to_action', 'image_url'], ['single_card', 'card', 'image_url'],
    ];
    const element = (id, type, settings) => ({ id, type, settings, styles: {}, children: [] });
    const elements = widgets.map(([id, type, field]) => element(id, type, {
      [field]: wide.url, alt: wide.alt, ...(type === 'card' ? {} : { width: wide.width, height: wide.height }),
    }));
    const dimensions = JSON.stringify([{ url: wide.url, width: wide.width, height: wide.height }]);
    elements.push(element('single_gallery', 'gallery', { images: `${wide.url}|${wide.alt}`, images_dimensions: dimensions }));
    elements.push(element('single_carousel', 'image_carousel', { label: 'Autre image', images: `${wide.url}|${wide.alt}`, images_dimensions: dimensions }));
    const content = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements } }] };
    const prepared = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content, status: 'published', published_at: '' });
    expect(prepared.status, prepared.body).toBe(200);
    await page.goto(`/admin/prime-builder/edit/${entryId}`);
    const frame = page.frameLocator('[data-pb-frame]');

    const pick = async item => {
      await page.getByRole('button', { name: 'Choisir dans la médiathèque' }).click();
      const picker = page.getByRole('dialog', { name: 'Choisir dans la médiathèque' });
      await expect(picker).toBeVisible();
      await picker.locator(`[data-prime-picker-card="${item.id}"]`).click();
      await picker.locator('[data-prime-picker-confirm]').click();
      await expect(picker).toBeHidden();
    };
    for (const [id, type, field] of widgets) {
      await page.locator('[data-pb-panel="navigator"]').click();
      await page.locator(`[data-pb-tree-select="${id}"]`).click();
      const source = page.getByLabel(field === 'url' ? 'Adresse URL' : 'Adresse de l’image', { exact: true });
      const width = page.getByLabel('Largeur', { exact: true });
      const height = page.getByLabel('Hauteur', { exact: true });
      await expect(source).toHaveValue(wide.url);
      if (type !== 'card') {
        await expect(width).toHaveValue('320');
        await expect(height).toHaveValue('200');
      }
      // A typed source is not necessarily the same image: old dimensions must
      // disappear from both the controls and preview, without losing focus.
      await source.fill(tall.url);
      await expect(source).toBeFocused();
      await expect(width).toHaveValue('');
      await expect(height).toHaveValue('');
      const image = frame.locator(`[data-pb-id="${id}"] img`);
      await expect(image).toHaveAttribute('src', tall.url);
      await expect(image).not.toHaveAttribute('width');
      await expect(image).not.toHaveAttribute('height');
      await pick(tall);
      await expect(source).toHaveValue(tall.url);
      await expect(width).toHaveValue('150');
      await expect(height).toHaveValue('300');
      await expect(page.getByLabel('Texte alternatif', { exact: true })).toHaveValue(tall.alt);
      await expect(image).toHaveAttribute('width', '150');
      await expect(image).toHaveAttribute('height', '300');
      if (type === 'image') {
        await accessible(page);
        await page.locator('[data-pb-settings-panel]').screenshot({ path: test.info().outputPath('single-image-editor-desktop.jpg'), quality: 15 });
        await page.setViewportSize({ width: 375, height: 812 });
        await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
        await accessible(page);
        await page.locator('[data-pb-settings-panel]').screenshot({ path: test.info().outputPath('single-image-editor-mobile.jpg'), quality: 15 });
        await height.scrollIntoViewIfNeeded();
        await expect(width).toBeVisible();
        await expect(height).toBeVisible();
        await page.locator('[data-pb-settings-panel]').screenshot({ path: test.info().outputPath('single-image-editor-mobile-dimensions.jpg'), quality: 15 });
        await page.setViewportSize({ width: 1440, height: 900 });

        // Exercise incomplete metadata without changing the CMS: retain its
        // real response but remove dimensions and alternative for this item.
        let changedResponse = 0;
        const pickerItems = url => url.pathname === '/admin/media/api/items';
        await page.route(pickerItems, async route => {
          const response = await route.fetch();
          const data = await response.json();
          const item = data.items.find(candidate => candidate.id === wide.id);
          if (item) { item.width = null; item.height = null; item.alt = null; changedResponse++; }
          await route.fulfill({ response, json: data });
        });
        try { await pick(wide); } finally { await page.unroute(pickerItems); }
        expect(changedResponse).toBeGreaterThan(0);
        await expect(source).toHaveValue(wide.url);
        await expect(width).toHaveValue('');
        await expect(height).toHaveValue('');
        await expect(page.getByLabel('Texte alternatif', { exact: true })).toHaveValue('');
        await expect(image).toHaveAttribute('src', wide.url);
        await expect(image).not.toHaveAttribute('width');
        await expect(image).not.toHaveAttribute('height');
        await pick(tall);
        await expect(width).toHaveValue('150');
        await expect(height).toHaveValue('300');
      }
      // Undo of a later manual source edit must restore the selected metadata.
      await source.fill(wide.url);
      await expect(width).toHaveValue('');
      await expect(height).toHaveValue('');
      await page.locator('[data-pb-undo]').click();
      await expect(source).toHaveValue(tall.url);
      await expect(width).toHaveValue('150');
      await expect(height).toHaveValue('300');
    }

    const saving = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click();
    const savedResponse = await saving;
    expect(savedResponse.status()).toBe(200);
    const saved = (await savedResponse.json()).data.content.blocks[0].data;
    for (const [index, [, , field]] of widgets.entries()) {
      expect(saved.elements[index].settings).toMatchObject({ [field]: tall.url, alt: tall.alt, width: 150, height: 300 });
    }
    expect(saved.items.map(item => item.mediaId).sort((a, b) => a - b)).toEqual(media.map(item => item.id).sort((a, b) => a - b));
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');

    for (const [name, width] of [['desktop', 1440], ['mobile', 375]]) {
      const publicPage = await page.context().newPage();
      let releaseWide = () => {}, releaseTall = () => {};
      try {
        await publicPage.setViewportSize({ width, height: 900 });
        let wideRequested = false, tallRequested = false;
        const wideGate = new Promise(resolve => { releaseWide = resolve; });
        const tallGate = new Promise(resolve => { releaseTall = resolve; });
        await publicPage.route(url => url.pathname + url.search === wide.url, async route => { wideRequested = true; await wideGate; await route.continue(); });
        await publicPage.route(url => url.pathname + url.search === tall.url, async route => { tallRequested = true; await tallGate; await route.continue(); });
        await publicPage.goto('/pb-single-image-dimensions', { waitUntil: 'domcontentloaded' });
        const checkReservation = async (image, requested, release, expectedWidth, expectedHeight) => {
          await expect(image).toHaveAttribute('width', String(expectedWidth));
          await expect(image).toHaveAttribute('height', String(expectedHeight));
          await image.scrollIntoViewIfNeeded();
          await expect.poll(requested).toBe(true);
          expect(await image.evaluate(node => node.naturalWidth)).toBe(0);
          await expect.poll(async () => {
            const box = await image.boundingBox();
            return box && box.width > 0 ? box.height / box.width : 0;
          }).toBeCloseTo(expectedHeight / expectedWidth, 2);
          const before = await image.boundingBox();
          release();
          await expect.poll(() => image.evaluate(node => node.naturalWidth)).toBe(expectedWidth);
          const after = await image.boundingBox();
          expect(Math.abs(after.width - before.width)).toBeLessThanOrEqual(1);
          expect(Math.abs(after.height - before.height)).toBeLessThanOrEqual(1);
        };
        await checkReservation(publicPage.locator('[data-pb-id="single_image"] img'), () => tallRequested, releaseTall, tall.width, tall.height);
        await checkReservation(publicPage.locator('[data-pb-id="single_gallery"] [data-pb-gallery-item] img'), () => wideRequested, releaseWide, wide.width, wide.height);
        await expect(publicPage.locator('[data-pb-id="single_carousel"] img')).toHaveAttribute('width', '320');
        await expect(publicPage.locator('[data-pb-id="single_carousel"] img')).toHaveAttribute('height', '200');
        await expect.poll(() => publicPage.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
        await accessible(publicPage);
        await publicPage.evaluate(() => window.scrollTo({ top: 0, behavior: 'instant' }));
        await expect.poll(() => publicPage.evaluate(() => window.scrollY)).toBe(0);
        await publicPage.screenshot({ path: test.info().outputPath(`single-image-public-${name}.jpg`), fullPage: true, quality: 15 });
      } finally { releaseWide(); releaseTall(); await publicPage.close(); }
    }
    const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
    try {
      const fallback = await noJs.newPage();
      await fallback.goto('/pb-single-image-dimensions');
      await expect(fallback.locator('[data-pb-id="single_image"] img')).toHaveAttribute('width', '150');
      await expect(fallback.locator('[data-pb-id="single_gallery"] img')).toHaveAttribute('height', '200');
      await expect(fallback.locator('[data-pb-id="single_gallery"] a')).toHaveAttribute('href', wide.url);
    } finally { await noJs.close(); }
    expect(errors).toEqual([]);
  } finally {
    const leave = dialog => dialog.accept();
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
