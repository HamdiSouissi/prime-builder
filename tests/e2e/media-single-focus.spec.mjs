import { readFile } from 'node:fs/promises';
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

async function login(page) {
  await page.goto('/admin/login');
  await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/), page.locator('form button[type=submit]').click()]);
  await page.goto(`/admin/prime-builder/edit/${process.env.E2E_ENTRY_ID || '1'}`);
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  return (url, method, body) => page.evaluate(async ({ url, method, body, csrf }) => {
    const response = await fetch(url, {
      method, headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf, Accept: 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    return { status: response.status, body: await response.text() };
  }, { url, method, body, csrf });
}

async function createPage(api, slug, elements) {
  const created = await api('/api/v1/content/page', 'POST', {
    title: 'Focus du sélecteur de média', slug, status: 'draft', content: { version: 1, blocks: [] },
  });
  expect(created.status, created.body).toBe(201);
  const id = JSON.parse(created.body).data.id;
  const content = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements } }] };
  const prepared = await api(`/api/prime-builder/v1/documents/${id}`, 'PATCH', { content });
  expect(prepared.status, prepared.body).toBe(200);
  return id;
}

async function deletePage(api, id) {
  if (id === null) return;
  await api(`/api/v1/content/page/${id}`, 'DELETE');
  await api(`/api/v1/content/page/${id}?force=1`, 'DELETE');
}

const node = (id, type) => ({ id, type, settings: {}, styles: {}, children: [] });

async function upload(page, name, bytes, type) {
  return page.evaluate(async ({ name, bytes, type }) => {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const form = new FormData();
    form.append('file', new Blob([new Uint8Array(bytes)], { type }), name);
    const response = await fetch('/api/v1/media', { method: 'POST', headers: { 'X-Prime-CSRF': csrf, Accept: 'application/json' }, body: form });
    return { status: response.status, body: await response.text() };
  }, { name, bytes, type });
}

async function imageBytes(page) {
  return page.evaluate(async () => {
    const canvas = document.createElement('canvas'); canvas.width = 160; canvas.height = 100;
    const context = canvas.getContext('2d'); context.fillStyle = '#7255a7'; context.fillRect(0, 0, 160, 100);
    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
    return Array.from(new Uint8Array(await blob.arrayBuffer()));
  });
}

function waveBytes() {
  const samples = 800;
  const buffer = new ArrayBuffer(44 + samples);
  const data = new DataView(buffer);
  const write = (offset, text) => [...text].forEach((character, index) => data.setUint8(offset + index, character.charCodeAt(0)));
  write(0, 'RIFF'); data.setUint32(4, 36 + samples, true); write(8, 'WAVE'); write(12, 'fmt ');
  data.setUint32(16, 16, true); data.setUint16(20, 1, true); data.setUint16(22, 1, true);
  data.setUint32(24, 8000, true); data.setUint32(28, 8000, true); data.setUint16(32, 1, true); data.setUint16(34, 8, true);
  write(36, 'data'); data.setUint32(40, samples, true);
  new Uint8Array(buffer, 44).fill(128);
  return Array.from(new Uint8Array(buffer));
}

test('single media picker restores keyboard focus after selecting image, video and audio', async ({ page }) => {
  test.setTimeout(120_000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const api = await login(page);
  const media = [];
  let entryId = null;
  try {
    const video = Array.from(await readFile(new URL('./fixtures/media-demo.webm', import.meta.url)));
    for (const [kind, name, bytes, type] of [
      ['image', 'focus-image.png', await imageBytes(page), 'image/png'],
      ['video', 'focus-video.webm', video, 'video/webm'],
      ['audio', 'focus-audio.wav', waveBytes(), 'audio/wav'],
    ]) {
      const response = await upload(page, name, bytes, type);
      expect(response.status, response.body).toBe(201);
      media.push({ kind, ...JSON.parse(response.body).data });
    }
    entryId = await createPage(api, 'pb-single-media-focus', [node('focus_image', 'image'), node('focus_video', 'video'), node('focus_audio', 'audio')]);
    await page.goto(`/admin/prime-builder/edit/${entryId}`);
    const picker = page.getByRole('dialog', { name: 'Choisir dans la médiathèque' });
    for (const item of media) {
      await page.locator('[data-pb-panel="navigator"]').click();
      await page.locator(`[data-pb-tree-select="focus_${item.kind}"]`).click();
      const source = page.getByLabel('Adresse URL', { exact: true });
      const previousImage = '/assets/plugins/prime-builder/images/landscape.svg';
      if (item.kind === 'image') await source.fill(previousImage);
      const choose = page.getByRole('button', { name: 'Choisir dans la médiathèque', exact: true });
      await choose.focus();
      await page.keyboard.press('Enter');
      await expect(picker).toBeVisible();
      const option = picker.locator(`[data-prime-picker-card="${item.id}"]`);
      if (item.kind === 'image') {
        // Native undo remains available in the modal's own search field.
        const search = picker.locator('[data-prime-picker-search]');
        const isSearch = (response, query) => {
          const url = new URL(response.url());
          return url.pathname === '/admin/media/api/items' && (url.searchParams.get('q') || '') === query;
        };
        const filtered = page.waitForResponse(response => isSearch(response, 'temporary search'));
        await search.fill('temporary search');
        expect((await filtered).status()).toBe(200);
        const unfiltered = page.waitForResponse(response => isSearch(response, ''));
        await page.keyboard.press('Control+z');
        await expect(search).toHaveValue('');
        expect((await unfiltered).status()).toBe(200);
        await expect(option).toBeVisible();
        await expect(source).toHaveValue(previousImage);
      }
      await option.focus();
      if (item.kind === 'image') {
        // Editor shortcuts and Chromium's native undo must not alter the
        // background document or open its menu from a modal button.
        await expect(option).toBeFocused();
        await page.keyboard.press('Control+z');
        await expect(source).toHaveValue(previousImage);
        await page.keyboard.press('Shift+F10');
        await expect(page.locator('[data-pb-context-menu]')).toBeHidden();
        await expect(option).toBeFocused();
      }
      await page.keyboard.press('Enter');
      await expect(option).toHaveAttribute('aria-selected', 'true');
      const confirm = picker.locator('[data-prime-picker-confirm]');
      await confirm.focus();
      await page.keyboard.press('Enter');
      await expect(picker).toBeHidden();
      // The picker restores the old button before the editor rebuilds its
      // fields; the newly created button must receive focus instead.
      await expect(choose).toBeFocused();
      await expect(page.getByLabel('Adresse URL', { exact: true })).toHaveValue(new URL(item.url, process.env.E2E_BASE_URL || 'http://127.0.0.1:8080').pathname);
      if (item.kind === 'image') {
        await page.screenshot({ path: test.info().outputPath('media-single-focus-desktop.jpg'), quality: 15 });
        await page.setViewportSize({ width: 375, height: 812 });
        await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
        const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
        expect(result.violations.filter(violation => ['serious', 'critical'].includes(violation.impact)).map(violation => violation.id)).toEqual([]);
        await page.screenshot({ path: test.info().outputPath('media-single-focus-mobile.jpg'), quality: 15 });
        await page.setViewportSize({ width: 1440, height: 900 });
      }
      // Escape uses the core picker's cancellation path, without rebuilding.
      await page.keyboard.press('Enter');
      await expect(picker).toBeVisible();
      await page.keyboard.press('Escape');
      await expect(picker).toBeHidden();
      await expect(choose).toBeFocused();
      if (item.kind === 'audio') {
        // Closing the modal re-enables the editor's own history shortcuts.
        await page.keyboard.press('Control+z');
        await expect(source).toHaveValue('');
      }
    }
    expect(errors).toEqual([]);
  } finally {
    await deletePage(api, entryId);
    for (const item of media) await api(`/api/v1/media/${item.id}?force=1`, 'DELETE');
  }
});

test('search refresh keeps focus inside the media picker when a focused result is removed', async ({ page }) => {
  test.setTimeout(90_000);
  const api = await login(page);
  let entryId = null, mediaId = null;
  let releaseRequest = () => {};
  try {
    const uploaded = await upload(page, 'focus-refresh.png', await imageBytes(page), 'image/png');
    expect(uploaded.status, uploaded.body).toBe(201);
    mediaId = JSON.parse(uploaded.body).data.id;
    entryId = await createPage(api, 'pb-media-refresh-focus', [node('refresh_image', 'image')]);
    await page.goto(`/admin/prime-builder/edit/${entryId}`);
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="refresh_image"]').click();
    const choose = page.getByRole('button', { name: 'Choisir dans la médiathèque', exact: true });
    await choose.focus();
    await page.keyboard.press('Enter');
    const picker = page.getByRole('dialog', { name: 'Choisir dans la médiathèque' });
    const search = picker.locator('[data-prime-picker-search]');
    const option = picker.locator(`[data-prime-picker-card="${mediaId}"]`);
    await expect(option).toBeVisible({ timeout: 25_000 });
    let signalRequest = () => {};
    const started = new Promise(resolve => { signalRequest = resolve; });
    const gate = new Promise(resolve => { releaseRequest = resolve; });
    const isFilteredRequest = url => url.pathname === '/admin/media/api/items' && url.searchParams.get('q') === 'focus refresh';
    await page.route(isFilteredRequest, async route => { signalRequest(); await gate; await route.continue(); });
    // Simulate moving immediately from the filter into an existing result,
    // before the core's debounced search replaces the grid with skeletons.
    expect(await page.evaluate(id => {
      const search = document.querySelector('[data-prime-picker-search]');
      const card = document.querySelector(`[data-prime-picker-card="${id}"]`);
      search.value = 'focus refresh'; search.dispatchEvent(new Event('input', { bubbles: true }));
      card.focus(); return document.activeElement === card;
    }, mediaId)).toBe(true);
    await started;
    await expect(search).toBeFocused();
    const filtered = page.waitForResponse(response => isFilteredRequest(new URL(response.url())));
    releaseRequest();
    expect((await filtered).status()).toBe(200);
    await expect(option).toBeVisible();
    await expect(search).toBeFocused();
    // A deliberate move elsewhere in the modal must never be pulled back.
    const unfiltered = page.waitForResponse(response => {
      const url = new URL(response.url());
      return url.pathname === '/admin/media/api/items' && !url.searchParams.has('q');
    });
    await search.fill('');
    const close = picker.locator('[data-prime-picker-close]').first();
    await close.focus();
    expect((await unfiltered).status()).toBe(200);
    await expect(option).toBeVisible();
    await expect(close).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(picker).toBeHidden();
    await expect(choose).toBeFocused();
  } finally {
    releaseRequest();
    await deletePage(api, entryId);
    if (mediaId !== null) await api(`/api/v1/media/${mediaId}?force=1`, 'DELETE');
  }
});

for (const returnToImage of [false, true]) {
  test(`a late media picker import does not open after ${returnToImage ? 'returning to the rebuilt opener' : 'selecting another widget'}`, async ({ page }) => {
    test.setTimeout(90_000);
    const api = await login(page);
    let entryId = null;
    let releaseImport = () => {};
    try {
      entryId = await createPage(api, 'pb-late-media-picker', [node('late_image', 'image'), node('late_heading', 'heading')]);
      await page.goto(`/admin/prime-builder/edit/${entryId}`);
      await page.locator('[data-pb-panel="navigator"]').click();
      await page.locator('[data-pb-tree-select="late_image"]').click();
      let signalImport = () => {};
      const started = new Promise(resolve => { signalImport = resolve; });
      const gate = new Promise(resolve => { releaseImport = resolve; });
      const isPickerModule = url => url.pathname === '/assets/admin/js/media/picker.js';
      await page.route(isPickerModule, async route => { signalImport(); await gate; await route.continue(); });
      await page.getByRole('button', { name: 'Choisir dans la médiathèque', exact: true }).click();
      await started;
      await page.locator('[data-pb-panel="navigator"]').click();
      await page.locator('[data-pb-tree-select="late_heading"]').click();
      await expect(page.locator('[data-pb-selected-title]')).toHaveText('Titre');
      if (returnToImage) {
        await page.locator('[data-pb-panel="navigator"]').click();
        await page.locator('[data-pb-tree-select="late_image"]').click();
        await expect(page.locator('[data-pb-selected-title]')).toHaveText('Image');
      }
      const loaded = page.waitForResponse(response => isPickerModule(new URL(response.url())));
      releaseImport();
      expect((await loaded).status()).toBe(200);
      // Wait for all dependencies and the editor's pending continuation, not
      // merely for the HTTP response that starts module evaluation.
      await page.evaluate(async () => {
        await Promise.all([import('/assets/admin/js/media/picker.js'), import('/assets/admin/js/i18n.js')]);
        const sheets = [...document.querySelectorAll('link[rel="stylesheet"]')].filter(link => link.href.includes('media'));
        await Promise.all(sheets.map(link => link.sheet ? Promise.resolve() : new Promise(resolve => link.addEventListener('load', resolve, { once: true }))));
        await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
      });
      await expect(page.locator('#pc-media-picker-modal')).toHaveCount(0);
      await expect(page.locator('[data-pb-selected-title]')).toHaveText(returnToImage ? 'Image' : 'Titre');
      // A subsequent deliberate selection still opens the cached picker.
      if (!returnToImage) {
        await page.locator('[data-pb-panel="navigator"]').click();
        await page.locator('[data-pb-tree-select="late_image"]').click();
      }
      const choose = page.getByRole('button', { name: 'Choisir dans la médiathèque', exact: true });
      await choose.focus();
      await page.keyboard.press('Enter');
      const picker = page.getByRole('dialog', { name: 'Choisir dans la médiathèque' });
      await expect(picker).toBeVisible();
      await page.keyboard.press('Escape');
      await expect(picker).toBeHidden();
      await expect(choose).toBeFocused();
    } finally {
      releaseImport();
      await deletePage(api, entryId);
    }
  });
}

test('repeated activations during a slow import create only one picker session', async ({ page }) => {
  test.setTimeout(90_000);
  const api = await login(page);
  let entryId = null;
  let releaseImport = () => {};
  try {
    entryId = await createPage(api, 'pb-repeated-media-picker', [node('repeat_image', 'image')]);
    await page.goto(`/admin/prime-builder/edit/${entryId}`);
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="repeat_image"]').click();
    let signalImport = () => {};
    const started = new Promise(resolve => { signalImport = resolve; });
    const gate = new Promise(resolve => { releaseImport = resolve; });
    await page.route(url => url.pathname === '/assets/admin/js/media/picker.js', async route => {
      signalImport(); await gate; await route.continue();
    });
    let itemRequests = 0;
    page.on('request', request => { if (new URL(request.url()).pathname === '/admin/media/api/items') itemRequests += 1; });
    const choose = page.getByRole('button', { name: 'Choisir dans la médiathèque', exact: true });
    await choose.focus();
    await page.keyboard.press('Enter');
    await started;
    await page.keyboard.press('Enter');
    const items = page.waitForResponse(response => new URL(response.url()).pathname === '/admin/media/api/items');
    releaseImport();
    const picker = page.getByRole('dialog', { name: 'Choisir dans la médiathèque' });
    await expect(picker).toBeVisible();
    expect((await items).status()).toBe(200);
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    expect(itemRequests).toBe(1);
    await page.keyboard.press('Escape');
    await expect(picker).toBeHidden();
    await expect(choose).toBeFocused();
  } finally {
    releaseImport();
    await deletePage(api, entryId);
  }
});
