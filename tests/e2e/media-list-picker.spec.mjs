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
  let entryId = null, uploadedId = null;
  try {
    for (const [name, alt, color, width, height] of [['picker-montagne.png', 'Montagne & ciel', '#28613f', 320, 200], ['picker-ocean.png', 'Océan & soleil', '#254b83', 240, 300]]) {
      const uploaded = await page.evaluate(async ({ csrf, name, color, width, height }) => {
        const canvas = document.createElement('canvas'); canvas.width = width; canvas.height = height;
        const ctx = canvas.getContext('2d'); ctx.scale(width / 320, height / 200);
        ctx.fillStyle = '#e8eeeb'; ctx.fillRect(0, 0, 320, 200);
        ctx.fillStyle = color; ctx.beginPath(); ctx.moveTo(0, 180); ctx.lineTo(120, 45); ctx.lineTo(230, 170); ctx.lineTo(320, 100); ctx.lineTo(320, 200); ctx.lineTo(0, 200); ctx.fill();
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
        const form = new FormData(); form.append('file', blob, name);
        const response = await fetch('/api/v1/media', { method: 'POST', headers: { 'X-Prime-CSRF': csrf, Accept: 'application/json' }, body: form });
        return { status: response.status, body: await response.text() };
      }, { csrf, name, color, width, height });
      expect(uploaded.status, uploaded.body).toBe(201);
      const item = JSON.parse(uploaded.body).data;
      expect([item.width, item.height]).toEqual([width, height]);
      // The public API returns an absolute URL; the admin picker exposes the local path.
      const url = new URL(item.url, process.env.E2E_BASE_URL || 'http://127.0.0.1:8080');
      media.push({ id: item.id, url: url.pathname + url.search, alt, width, height });
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
    const assertPickerFits = async (width, height) => {
      await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
      const geometry = await picker.evaluate(dialog => {
        const bounds = dialog.getBoundingClientRect();
        const controls = [...dialog.querySelectorAll('[data-prime-picker-summary], .pc-media-picker__footer [data-prime-picker-close], [data-prime-picker-confirm]')].map(node => {
          const box = node.getBoundingClientRect();
          return { name: node.textContent.trim(), left: box.left, right: box.right, top: box.top, bottom: box.bottom, clientWidth: node.clientWidth, scrollWidth: node.scrollWidth };
        });
        const library = dialog.querySelector('[data-prime-picker-panel="library"]').getBoundingClientRect();
        const toolbar = dialog.querySelector('.pc-media-picker__toolbar').getBoundingClientRect();
        const filters = [...dialog.querySelectorAll('[data-prime-picker-search], [data-prime-picker-folder]')].map(node => {
          const box = node.getBoundingClientRect();
          return { name: node.getAttribute('aria-label') || node.id, left: box.left, right: box.right, top: box.top, bottom: box.bottom, height: box.height };
        });
        return {
          left: bounds.left, right: bounds.right, top: bounds.top, bottom: bounds.bottom, controls, filters,
          library: { top: library.top, bottom: library.bottom }, toolbar: { top: toolbar.top, bottom: toolbar.bottom },
        };
      });
      expect(geometry.controls).toHaveLength(3);
      expect(geometry.filters).toHaveLength(2);
      for (const filter of geometry.filters) {
        expect(filter.height, `${width}×${height}px: ${filter.name}`).toBeGreaterThanOrEqual(30);
        expect(filter.left, `${width}×${height}px: ${filter.name}`).toBeGreaterThanOrEqual(geometry.left);
        expect(filter.right, `${width}×${height}px: ${filter.name}`).toBeLessThanOrEqual(geometry.right);
        expect(filter.top, `${width}×${height}px: ${filter.name}`).toBeGreaterThanOrEqual(Math.max(geometry.library.top, geometry.toolbar.top));
        expect(filter.bottom, `${width}×${height}px: ${filter.name}`).toBeLessThanOrEqual(Math.min(geometry.library.bottom, geometry.toolbar.bottom));
      }
      for (const control of geometry.controls) {
        expect(control.left, `${width}px: ${control.name}`).toBeGreaterThanOrEqual(geometry.left);
        expect(control.right, `${width}px: ${control.name}`).toBeLessThanOrEqual(geometry.right);
        expect(control.top, `${width}px: ${control.name}`).toBeGreaterThanOrEqual(geometry.top);
        expect(control.bottom, `${width}px: ${control.name}`).toBeLessThanOrEqual(geometry.bottom);
        expect(control.scrollWidth, `${width}px: ${control.name}`).toBeLessThanOrEqual(control.clientWidth);
      }
      for (const [index, a] of geometry.controls.entries()) for (const b of geometry.controls.slice(index + 1)) {
        expect(a.right <= b.left || b.right <= a.left || a.bottom <= b.top || b.bottom <= a.top, `${width}px: ${a.name} / ${b.name}`).toBe(true);
      }
    };
    for (const { width, height } of [{ width: 320, height: 400 }, { width: 320, height: 480 }, { width: 320, height: 568 }, { width: 375, height: 667 }, { width: 375, height: 812 }]) {
      await page.setViewportSize({ width, height });
      await assertPickerFits(width, height);
      await accessible(page);
      if (width === 375 && height === 812) await picker.screenshot({ path: test.info().outputPath('media-list-editor-mobile.jpg'), quality: 15 });
    }
    // Filtering, selection and confirmation must work in the narrow viewport, not just after resizing to desktop.
    await page.setViewportSize({ width: 320, height: 480 });
    const search = picker.locator('[data-prime-picker-search]');
    const filtered = page.waitForResponse(response => {
      const url = new URL(response.url());
      return url.pathname === '/admin/media/api/items' && url.searchParams.get('q') === 'ocean';
    });
    await search.fill('ocean');
    expect((await filtered).status()).toBe(200);
    await expect(picker.locator('[data-prime-picker-card]')).toHaveCount(1);
    await expect(picker.locator(`[data-prime-picker-card="${media[1].id}"]`)).toHaveAttribute('aria-selected', 'true');
    const unfiltered = page.waitForResponse(response => {
      const url = new URL(response.url());
      return url.pathname === '/admin/media/api/items' && !url.searchParams.has('q');
    });
    await search.fill('');
    expect((await unfiltered).status()).toBe(200);
    await expect(picker.locator('[data-prime-picker-card]')).toHaveCount(2);
    const mobileOption = picker.locator(`[data-prime-picker-card="${media[1].id}"]`);
    await mobileOption.click();
    await expect(mobileOption).toHaveAttribute('aria-selected', 'false');
    await mobileOption.click();
    await expect(mobileOption).toHaveAttribute('aria-selected', 'true');
    // Clicking a clipped card must not scroll the search and folder controls out of view.
    await assertPickerFits(320, 480);
    for (const height of [400, 480]) {
      await page.setViewportSize({ width: 320, height });
      const scrolled = await picker.locator('[data-prime-picker-scroll]').evaluate(body => {
        body.scrollTop = body.scrollHeight;
        const bounds = body.getBoundingClientRect();
        const names = [...body.querySelectorAll('.pc-media-card__name')].map(name => {
          const box = name.getBoundingClientRect();
          return { text: name.textContent, top: box.top, bottom: box.bottom };
        });
        return { scrollTop: body.scrollTop, clientHeight: body.clientHeight, scrollHeight: body.scrollHeight, bodyTop: bounds.top, bodyBottom: bounds.bottom, names };
      });
      if (height === 400) await picker.screenshot({ path: test.info().outputPath('media-list-editor-short.jpg'), quality: 15 });
      const detail = `${height}px: ${JSON.stringify(scrolled)}`;
      expect(scrolled.scrollTop, detail).toBeGreaterThan(0);
      expect(scrolled.names, detail).toHaveLength(2);
      for (const name of scrolled.names) {
        expect(name.top, detail).toBeGreaterThanOrEqual(scrolled.bodyTop);
        expect(name.bottom, detail).toBeLessThanOrEqual(scrolled.bodyBottom);
      }
      await assertPickerFits(320, height);
    }
    await picker.screenshot({ path: test.info().outputPath('media-list-editor-mobile-small.jpg'), quality: 15 });

    // The upload tab must also remain usable at the keyboard when its content exceeds the panel.
    await page.setViewportSize({ width: 320, height: 400 });
    await picker.getByRole('tab', { name: 'Téléverser' }).focus();
    await page.keyboard.press('Enter');
    const upload = picker.locator('[data-prime-picker-panel="upload"]');
    await expect(upload).toBeVisible();
    const fileInput = upload.locator('[data-prime-picker-file-input]');
    await page.keyboard.press('Tab');
    await expect(fileInput).toBeFocused();
    await expect(fileInput).toHaveAccessibleName('Parcourir l’ordinateur');
    for (const height of [400, 480]) {
      await page.setViewportSize({ width: 320, height });
      const uploadMetrics = await upload.evaluate(node => ({ height: node.clientHeight, content: node.scrollHeight, overflow: getComputedStyle(node).overflowY }));
      if (uploadMetrics.content > uploadMetrics.height) expect(['auto', 'scroll']).toContain(uploadMetrics.overflow);
      const uploadGeometry = await upload.evaluate(panel => {
        const bounds = panel.getBoundingClientRect();
        const label = panel.querySelector('label').getBoundingClientRect();
        return { top: bounds.top, bottom: bounds.bottom, labelTop: label.top, labelBottom: label.bottom };
      });
      // Include the focus outline, not just the label's border box.
      expect(uploadGeometry.labelTop - 5, `${height}px`).toBeGreaterThanOrEqual(uploadGeometry.top);
      expect(uploadGeometry.labelBottom + 5, `${height}px`).toBeLessThanOrEqual(uploadGeometry.bottom);
      await expect(fileInput).toBeFocused();
      await accessible(page);
      const name = height === 400 ? 'media-list-editor-upload-short.jpg' : 'media-list-editor-upload.jpg';
      await picker.screenshot({ path: test.info().outputPath(name), quality: 15 });
    }
    const png = await page.evaluate(() => {
      const canvas = document.createElement('canvas'); canvas.width = 64; canvas.height = 32;
      const ctx = canvas.getContext('2d'); ctx.fillStyle = '#88445c'; ctx.fillRect(0, 0, 64, 32);
      return canvas.toDataURL('image/png').split(',')[1];
    });
    const uploaded = page.waitForResponse(response => new URL(response.url()).pathname === '/admin/media/api/upload' && response.request().method() === 'POST');
    const chooser = page.waitForEvent('filechooser');
    await page.keyboard.press('Enter');
    await (await chooser).setFiles({ name: 'picker-clavier.png', mimeType: 'image/png', buffer: Buffer.from(png, 'base64') });
    const uploadResponse = await uploaded;
    expect(uploadResponse.status()).toBe(201);
    uploadedId = (await uploadResponse.json()).item.id;
    expect(uploadedId).toBeGreaterThan(0);
    await expect(picker.locator('[data-prime-picker-summary]')).toContainText('3 médias sélectionnés');
    const pointerChooser = page.waitForEvent('filechooser');
    await upload.getByText('Parcourir l’ordinateur', { exact: true }).click();
    await (await pointerChooser).setFiles([]);
    await picker.getByRole('tab', { name: 'Médiathèque' }).focus();
    await page.keyboard.press('Enter');
    const uploadedOption = picker.locator(`[data-prime-picker-card="${uploadedId}"]`);
    await expect(uploadedOption).toHaveAttribute('aria-selected', 'true');
    await uploadedOption.click();
    await expect(uploadedOption).toHaveAttribute('aria-selected', 'false');
    await expect(mobileOption).toHaveAttribute('aria-selected', 'true');
    await assertPickerFits(320, 480);
    await picker.locator('[data-prime-picker-confirm]').click();
    await expect(picker).toBeHidden();
    const combined = [original, ...media.map(item => `${item.url}|${item.alt}`)].join('\n');
    await expect(list).toHaveValue(combined);
    await expect(choose).toBeFocused();
    await expect(page.locator('[data-pb-live]')).toHaveText('Images ajoutées à la liste.');
    await page.setViewportSize({ width: 1440, height: 900 });
    const frame = page.frameLocator('[data-pb-frame]');
    const previewImages = frame.locator('[data-pb-id="gallery_picker"] img');
    await expect(previewImages).toHaveCount(3);
    await expect(previewImages.nth(0)).not.toHaveAttribute('width');
    for (const [index, item] of media.entries()) {
      await expect(previewImages.nth(index + 1)).toHaveAttribute('width', String(item.width));
      await expect(previewImages.nth(index + 1)).toHaveAttribute('height', String(item.height));
    }
    await page.locator('[data-pb-undo]').focus();
    await page.keyboard.press('Control+z');
    await expect(list).toHaveValue(original);
    await expect(previewImages).toHaveCount(1);
    await page.keyboard.press('Control+Shift+z');
    await expect(list).toHaveValue(combined);
    await expect(previewImages.nth(1)).toHaveAttribute('width', '320');
    await expect(previewImages.nth(1)).toHaveAttribute('height', '200');
    // Reordering rows must follow the URL rather than the former row index.
    await list.fill([original, ...[...media].reverse().map(item => `${item.url}|${item.alt}`)].join('\n'));
    await expect(previewImages.nth(1)).toHaveAttribute('src', media[1].url);
    await expect(previewImages.nth(1)).toHaveAttribute('width', '240');
    await expect(previewImages.nth(1)).toHaveAttribute('height', '300');
    await expect(previewImages.nth(2)).toHaveAttribute('width', '320');
    await list.fill(combined);

    // The editor retains the URL-keyed snapshot during an unsaved edit. The
    // preview must first remove the absent image, then restore its dimensions
    // if that same URL is reintroduced before saving.
    await list.fill([original, `${media[1].url}|${media[1].alt}`].join('\n'));
    await expect(previewImages).toHaveCount(2);
    await expect(previewImages.nth(1)).toHaveAttribute('src', media[1].url);
    await expect(previewImages.nth(1)).toHaveAttribute('width', '240');
    await list.fill(combined);
    await expect(previewImages).toHaveCount(3);
    await expect(previewImages.nth(1)).toHaveAttribute('src', media[0].url);
    await expect(previewImages.nth(1)).toHaveAttribute('width', '320');
    await expect(previewImages.nth(1)).toHaveAttribute('height', '200');

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
    expect(JSON.parse(saved.elements[0].settings.images_dimensions)).toEqual(media.map(({ url, width, height }) => ({ url, width, height })));
    expect(JSON.parse(saved.elements[1].settings.images_dimensions)).toEqual([{ url: media[1].url, width: 240, height: 300 }]);
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
      await expect(images.nth(0)).not.toHaveAttribute('width');
      await expect(images.nth(1)).toHaveAttribute('alt', media[0].alt);
      await expect(images.nth(2)).toHaveAttribute('alt', media[1].alt);
      for (const [index, item] of media.entries()) {
        await expect(images.nth(index + 1)).toHaveAttribute('width', String(item.width));
        await expect(images.nth(index + 1)).toHaveAttribute('height', String(item.height));
      }
      await expect(publicPage.locator('[data-pb-id="carousel_picker"] img')).toHaveAttribute('width', '240');
      await expect(publicPage.locator('[data-pb-id="carousel_picker"] img')).toHaveAttribute('height', '300');
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
      const galleryImages = fallback.locator('[data-pb-id="gallery_picker"] img');
      const carouselImage = fallback.locator('[data-pb-id="carousel_picker"] img');
      await expect(galleryImages).toHaveCount(3);
      await expect(carouselImage).toHaveCount(1);
      await expect(galleryImages.nth(1)).toHaveAttribute('width', '320');
      await expect(galleryImages.nth(1)).toHaveAttribute('height', '200');
      await expect(carouselImage).toHaveAttribute('width', '240');
      await expect(carouselImage).toHaveAttribute('height', '300');
      await expect(fallback.getByRole('link', { name: /Montagne & ciel/ })).toHaveAttribute('href', media[0].url);
    } finally { await noJs.close(); }

    // Removing a URL and saving prunes its stored metadata. Typing that URL
    // back in can still infer its current library size without restoring the
    // old snapshot; the surviving URL keeps its own explicit size.
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="gallery_picker"]').click();
    await list.fill([original, `${media[1].url}|${media[1].alt}`].join('\n'));
    const removal = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click();
    const removedResponse = await removal;
    expect(removedResponse.status()).toBe(200);
    const removedSettings = (await removedResponse.json()).data.content.blocks[0].data.elements[0].settings;
    expect(JSON.parse(removedSettings.images_dimensions)).toEqual([{ url: media[1].url, width: 240, height: 300 }]);
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await list.fill(combined);
    await expect(previewImages).toHaveCount(3);
    await expect(previewImages.nth(1)).toHaveAttribute('width', '320');
    await expect(previewImages.nth(1)).toHaveAttribute('height', '200');
    await expect(previewImages.nth(2)).toHaveAttribute('width', '240');
    const readdition = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click();
    const readdedResponse = await readdition;
    expect(readdedResponse.status()).toBe(200);
    const readdedSettings = (await readdedResponse.json()).data.content.blocks[0].data.elements[0].settings;
    expect(JSON.parse(readdedSettings.images_dimensions)).toEqual([{ url: media[1].url, width: 240, height: 300 }]);
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
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
      if (uploadedId !== null) await api(`/api/v1/media/${uploadedId}?force=1`, 'DELETE');
    } finally { page.off('dialog', leave); }
  }
});
