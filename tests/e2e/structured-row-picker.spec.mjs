import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('structured image rows use the media library without losing their content', async ({ page, browser }) => {
  test.setTimeout(240_000);
  page.setDefaultNavigationTimeout(90_000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.goto('/admin/login');
  await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/), page.locator('form button[type=submit]').click()]);
  await page.goto(`/admin/prime-builder/edit/${process.env.E2E_ENTRY_ID || '1'}`);
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
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
    // axe uses and closes a temporary page; reactivate the tested page so
    // headless Chromium does not throttle subsequent screenshot/scroll work.
    await target.bringToFront();
    expect(result.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => ({ id: v.id, targets: v.nodes.map(node => node.target) }))).toEqual([]);
  };
  const media = [];
  let entryId = null;
  let releaseImport = () => {};
  const leave = dialog => dialog.accept();
  try {
    for (const [name, width, height, color, alt] of [
      ['row-picker-wide.png', 320, 200, '#267f62', 'Paysage | interdit'],
      ['row-picker-tall.png', 150, 300, '#7255a7', 'Portrait'],
      ['row-picker-original.png', 640, 480, '#c27940', 'Original'],
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
      const updated = await api(`/api/v1/media/${item.id}`, 'PATCH', { alt });
      expect(updated.status, updated.body).toBe(200);
    }
    const [wide, tall, original] = media;
    const slug = `pb-structured-row-${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;
    const created = await api('/api/v1/content/page', 'POST', {
      title: 'Images des lignes structurées', slug, status: 'draft', content: { version: 1, blocks: [] },
    });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;
    const old = original.url;
    const previous = {
      row_slide: `${old} | Ancien | Première | Description | Voir | /suite\n${old} | Autre | Deuxième`,
      row_portfolio: `${old} | Ancien | Projet A | Art | Notre travail | /projet\n${old} | Autre | Projet B | Sites`,
      row_testimonial: `Excellent | Camille | Artiste | ${old}\nTrès bien | Alex | Designer`,
    };
    const oldDimensions = JSON.stringify([{ url: old, width: 640, height: 480 }]);
    const elements = [
      { id: 'row_slide', type: 'slideshow', settings: { label: 'Nos images', items: previous.row_slide, images_dimensions: oldDimensions }, styles: {}, children: [] },
      { id: 'row_portfolio', type: 'portfolio', settings: { items: previous.row_portfolio, images_dimensions: oldDimensions, manual_dimensions: `${old} | 400 | 300` }, styles: {}, children: [] },
      { id: 'row_testimonial', type: 'testimonial_carousel', settings: { label: 'Avis de nos clients', items: previous.row_testimonial, images_dimensions: oldDimensions }, styles: {}, children: [] },
    ];
    const content = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements } }] };
    const prepared = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content, status: 'published', published_at: '' });
    expect(prepared.status, prepared.body).toBe(200);

    // Delay the import: editing the row before the picker opens must never
    // apply a stale selection to a different version of that row.
    const blockImport = async () => {
      const imported = { seen: false };
      const gate = new Promise(resolve => { releaseImport = resolve; });
      await page.route('**/assets/admin/js/media/picker.js', async route => { imported.seen = true; await gate; await route.continue(); });
      return imported;
    };
    const firstImport = await blockImport();
    await page.goto(`/admin/prime-builder/edit/${entryId}`);
    const open = async id => {
      await page.locator('[data-pb-panel="navigator"]').click();
      await page.locator(`[data-pb-tree-select="${id}"]`).click();
    };
    const list = page.locator('textarea[data-pb-setting="items"]');
    const row = page.getByRole('combobox', { name: 'Ligne à illustrer', exact: true });
    const choose = page.getByRole('button', { name: 'Choisir l’image de la ligne', exact: true });
    const picker = page.getByRole('dialog', { name: 'Choisir dans la médiathèque' });
    const notice = page.getByRole('dialog', { name: 'Information' });
    const chooseRow = async () => {
      await row.focus();
      await page.keyboard.press('End');
      await expect(row).toHaveValue('1');
    };
    const confirmImage = async item => {
      await expect(picker).toBeVisible();
      const card = picker.locator(`[data-prime-picker-card="${item.id}"]`);
      await card.focus(); await page.keyboard.press('Enter');
      await expect(card).toHaveAttribute('aria-selected', 'true');
      await picker.locator('[data-prime-picker-confirm]').focus(); await page.keyboard.press('Enter');
      await expect(picker).toBeHidden();
    };
    const selectImage = async item => {
      await choose.focus(); await page.keyboard.press('Enter'); await confirmImage(item);
    };
    const dismiss = async (message, focus = choose) => {
      await expect(notice).toContainText(message);
      await page.keyboard.press('Escape');
      await expect(notice).toBeHidden();
      await expect(focus).toBeFocused();
    };
    await open('row_slide');
    await expect(list).toHaveValue(previous.row_slide);
    await expect(row).toBeVisible({ timeout: 10_000 });
    await expect(choose).toBeEnabled({ timeout: 10_000 });
    await chooseRow();
    await choose.focus(); await page.keyboard.press('Enter');
    await expect.poll(() => firstImport.seen, { timeout: 15_000 }).toBe(true);
    await row.focus(); await row.selectOption('0');
    releaseImport();
    await dismiss('La ligne choisie ou la liste a changé pendant la sélection', row);
    await expect(picker).toBeHidden();
    await expect(list).toHaveValue(previous.row_slide);
    await page.unroute('**/assets/admin/js/media/picker.js');

    // Repeat with the same row selected but edited contents.
    const secondImport = await blockImport();
    await page.reload();
    await open('row_slide');
    await chooseRow();
    await choose.focus(); await page.keyboard.press('Enter');
    await expect.poll(() => secondImport.seen, { timeout: 15_000 }).toBe(true);
    const changed = previous.row_slide.replace('Deuxième', 'Deuxième modifiée');
    await list.fill(changed);
    releaseImport();
    await dismiss('La ligne choisie ou la liste a changé pendant la sélection', list);
    await expect(picker).toBeHidden();
    await expect(list).toHaveValue(changed);
    await page.unroute('**/assets/admin/js/media/picker.js');
    await list.fill(previous.row_slide);
    await chooseRow();
    // Also reject a programmatic change after the modal is already open.
    await choose.focus(); await page.keyboard.press('Enter');
    await expect(picker).toBeVisible();
    await list.evaluate((input, value) => { input.value = value; input.dispatchEvent(new Event('input', { bubbles: true })); }, changed);
    await confirmImage(wide);
    await dismiss('La ligne choisie ou la liste a changé pendant la sélection');
    await expect(list).toHaveValue(changed);
    await list.fill(previous.row_slide);
    await chooseRow();
    await selectImage(wide);
    await dismiss('Aucune modification n’a été faite');
    await expect(list).toHaveValue(previous.row_slide);
    const corrected = await api(`/api/v1/media/${wide.id}`, 'PATCH', { alt: 'Paysage' });
    expect(corrected.status, corrected.body).toBe(200);
    await selectImage(wide);
    const slideItems = `${old} | Ancien | Première | Description | Voir | /suite\n${wide.url} | Paysage | Deuxième`;
    await expect(list).toHaveValue(slideItems);
    await expect(choose).toBeFocused();
    await expect(row).toHaveValue('1');
    const frame = page.frameLocator('[data-pb-frame]');
    await expect(frame.locator('[data-pb-id="row_slide"] img').nth(1)).toHaveAttribute('width', '320');
    await page.keyboard.press('Control+z');
    await expect(list).toHaveValue(previous.row_slide);
    await page.keyboard.press('Control+Shift+z');
    await expect(list).toHaveValue(slideItems);

    await open('row_portfolio');
    const blankLine = previous.row_portfolio.replace('\n', '\n\n');
    await list.fill(blankLine);
    await expect(row.locator('option')).toHaveCount(2);
    await expect(row.locator('option').nth(1)).toContainText('Ligne 3');
    await row.selectOption('2');
    await selectImage(tall);
    const projectItems = `${old} | Ancien | Projet A | Art | Notre travail | /projet\n\n${tall.url} | Portrait | Projet B | Sites`;
    await expect(list).toHaveValue(projectItems);
    await expect(choose).toBeFocused();
    await expect(row).toHaveValue('2');
    await expect(frame.locator('[data-pb-id="row_portfolio"] img').nth(1)).toHaveAttribute('width', '150');
    await expect(page.locator('textarea[data-pb-setting="manual_dimensions"]')).toHaveValue(`${old} | 400 | 300`);
    await accessible(page);
    await page.locator('[data-pb-settings-panel]').screenshot({ path: test.info().outputPath('structured-row-editor-desktop.jpg'), quality: 15 });
    await page.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await accessible(page);
    await choose.scrollIntoViewIfNeeded();
    await page.locator('[data-pb-settings-panel]').screenshot({ path: test.info().outputPath('structured-row-editor-mobile.jpg'), quality: 15 });
    await page.setViewportSize({ width: 1440, height: 900 });
    // Reject a malformed row atomically and let the author keep editing it.
    await list.fill('/incomplete');
    await selectImage(tall);
    await dismiss('Aucune modification n’a été faite');
    await expect(list).toHaveValue('/incomplete');
    await list.fill(Array.from({ length: 25 }, (_, index) => `/project${index}.png | Image | Projet | Art`).join('\n'));
    await expect(choose).toBeDisabled();
    await expect(row.locator('option')).toHaveCount(1);
    await expect(row).toContainText('Trop de lignes');
    await expect(page.locator('[data-pb-media-row-status]')).toHaveText('Trop de lignes pour ce widget ; corrigez d’abord la liste.');
    await list.fill(projectItems);
    await expect(choose).toBeEnabled();
    await expect(page.locator('[data-pb-media-row-status]')).toHaveText('');

    await open('row_testimonial');
    await list.fill('');
    await expect(choose).toBeDisabled();
    await expect(page.locator('[data-pb-media-row-status]')).toHaveText('Ajoutez d’abord une ligne.');
    await list.fill(previous.row_testimonial);
    await expect(choose).toBeEnabled();
    await chooseRow();
    // Portraits are decorative; their media alternative is not inserted into
    // the testimonial's text/author/role cells.
    const ignoredAlt = await api(`/api/v1/media/${wide.id}`, 'PATCH', { alt: 'Alternative | non utilisée' });
    expect(ignoredAlt.status, ignoredAlt.body).toBe(200);
    await selectImage(wide);
    const testimonialItems = `Excellent | Camille | Artiste | ${old}\nTrès bien | Alex | Designer | ${wide.url}`;
    await expect(list).toHaveValue(testimonialItems);
    await expect(choose).toBeFocused();
    await expect(frame.locator('[data-pb-id="row_testimonial"] img').nth(1)).toHaveAttribute('alt', '');
    await expect(frame.locator('[data-pb-id="row_testimonial"] img').nth(1)).toHaveAttribute('height', '200');
    const saving = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click();
    const savedResponse = await saving;
    expect(savedResponse.status()).toBe(200);
    const saved = (await savedResponse.json()).data.content.blocks[0].data.elements;
    expect(saved[0].settings.items).toBe(slideItems);
    expect(saved[1].settings.items).toBe(projectItems.replace('\n\n', '\n'));
    expect(saved[2].settings.items).toBe(testimonialItems);
    for (const [element, selected] of [[saved[0], wide], [saved[1], tall], [saved[2], wide]]) {
      expect(JSON.parse(element.settings.images_dimensions)).toEqual([
        { url: old, width: 640, height: 480 }, { url: selected.url, width: selected.width, height: selected.height },
      ]);
    }
    expect(saved[1].settings.manual_dimensions).toBe(`${old} | 400 | 300`);
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await page.reload();
    await open('row_testimonial');
    await expect(list).toHaveValue(testimonialItems);
    await page.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
    for (const [id, selected] of [['row_slide', wide], ['row_portfolio', tall], ['row_testimonial', wide]]) {
      const images = page.locator(`[data-pb-id="${id}"] img`);
      await expect(images).toHaveCount(2);
      await expect(images.nth(1)).toHaveAttribute('src', selected.url);
      await expect(images.nth(1)).toHaveAttribute('width', String(selected.width));
      await expect(images.nth(1)).toHaveAttribute('height', String(selected.height));
    }
    await expect(page.locator('[data-pb-id="row_portfolio"] img').first()).toHaveAttribute('width', '400');
    await accessible(page);
    for (const [name, width] of [['desktop', 1440], ['mobile', 375]]) {
      const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080', viewport: { width, height: 900 } });
      try {
        const fallback = await noJs.newPage(); fallback.setDefaultNavigationTimeout(90_000);
        await fallback.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
        for (const [id, selected] of [['row_slide', wide], ['row_portfolio', tall], ['row_testimonial', wide]]) {
          const images = fallback.locator(`[data-pb-id="${id}"] img`);
          await expect(images.nth(1)).toHaveAttribute('src', selected.url);
          await expect(images.nth(1)).toHaveAttribute('width', String(selected.width));
          await expect(images.nth(1)).toHaveAttribute('height', String(selected.height));
          // Lazy images outside the viewport need scrolling before their real
          // files can be checked and captured in a full-page screenshot.
          for (const [index, naturalWidth] of [[0, original.width], [1, selected.width]]) {
            const image = images.nth(index);
            await image.scrollIntoViewIfNeeded();
            await expect.poll(() => image.evaluate(node => node.complete ? node.naturalWidth : 0), { timeout: 20_000 }).toBe(naturalWidth);
          }
        }
        await expect.poll(() => fallback.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(width);
        await fallback.evaluate(() => scrollTo(0, 0));
        await fallback.screenshot({ path: test.info().outputPath(`structured-row-public-${name}.jpg`), quality: 15, fullPage: true });
      } finally { await noJs.close(); }
    }
    expect(errors).toEqual([]);
  } finally {
    releaseImport();
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
