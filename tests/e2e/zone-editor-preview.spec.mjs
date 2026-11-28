import { test, expect } from '@playwright/test';

test('editor previews do not confuse an entry element with a copied zone element', async ({ page }) => {
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
  let entryId = null;
  try {
    const saved = await saveZone({ version: 1, blocks: [{ id: 'builder', type: 'prime-builder/layout', data: {
      version: 1, elements: [{
        id: 'shared_heading', type: 'heading', settings: { text: 'Titre de la zone', level: 2 },
        styles: { desktop: { color: '#178041', padding_left: 6 } }, children: [],
      }],
    } }] });
    expect(saved.status(), await saved.text()).toBe(200);
    const created = await api('/api/v1/content/post', 'POST', {
      title: 'Aperçu indépendant des zones', slug: 'pb-apercu-zones-independantes', status: 'published', content: { version: 1, blocks: [] },
    });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;
    const elements = [
      { id: 'parent', type: 'container', settings: { layout: 'flex', direction: 'column' }, styles: { desktop: { color: '#174a99' } }, children: [
        { id: 'zone_first', type: 'theme_zone', settings: { zone: 'sidebar' }, styles: {}, children: [] },
      ] },
      { id: 'shared_heading', type: 'heading', settings: { text: 'Titre de l’article', level: 2 }, styles: { desktop: { color: '#bd1b28', padding_left: 40 } }, children: [] },
      { id: 'sibling', type: 'heading', settings: { text: 'Voisin conservé', level: 2 }, styles: {}, children: [] },
    ];
    const updated = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', {
      content: { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements } }] },
    });
    expect(updated.status, updated.body).toBe(200);
    // Simulate an independently rendered theme layout before the entry. The
    // editor must reconcile the entry, not the first Builder layout in the page.
    await page.route(`**/admin/content/post/${entryId}/preview`, async route => {
      const response = await route.fetch();
      const html = await response.text();
      expect(html).toMatch(/<body\b[^>]*>/i);
      const foreign = '<div class="pb-layout" data-preview-foreign><div class="pb-element pb-heading" data-pb-id="shared_heading" data-pb-owner="ffffffffffffffffffffffff"><h2>En-tête indépendant</h2></div></div>';
      await route.fulfill({ response, body: html.replace(/(<body\b[^>]*>)/i, `$1${foreign}`) });
    });
    // Force the iframe to finish before the editor module is available. A slow
    // module request must not leave the canvas permanently unreconciled.
    let releaseModel;
    let modelRequested;
    const modelGate = new Promise(resolve => { releaseModel = resolve; });
    const modelRequest = new Promise(resolve => { modelRequested = resolve; });
    await page.route('**/assets/plugins/prime-builder/js/model.js', async route => {
      modelRequested();
      await modelGate;
      await route.continue();
    });
    await page.goto(`/admin/prime-builder/edit/${entryId}`, { waitUntil: 'commit' });
    try {
      await page.locator('[data-pb-frame]').waitFor({ state: 'attached' });
      const previewFrame = await (await page.locator('[data-pb-frame]').elementHandle()).contentFrame();
      await previewFrame.waitForURL(new RegExp(`/admin/content/post/${entryId}/preview`));
      await Promise.all([modelRequest, previewFrame.waitForLoadState('load')]);
    } finally { releaseModel(); }
    const frame = page.frameLocator('[data-pb-frame]');
    const foreign = frame.locator('[data-preview-foreign]');
    const canvas = frame.locator('.aurora-prose > .pb-layout');
    await expect(canvas).toHaveAttribute('data-pb-editor-canvas', '');
    const entryHeading = canvas.locator(':scope > [data-pb-id="shared_heading"]');
    const zoneHeading = canvas.locator('.pb-theme_zone .pb-layout [data-pb-id="shared_heading"]');
    const sibling = canvas.locator(':scope > [data-pb-id="sibling"]');
    await expect(foreign.locator('h2')).toHaveText('En-tête indépendant');
    await expect(zoneHeading.locator('h2')).toHaveText('Titre de la zone');
    await expect(entryHeading.locator('h2')).toHaveText('Titre de l’article');
    await expect(entryHeading).toHaveCSS('padding-left', '40px');
    await expect(entryHeading.locator('h2')).toHaveCSS('color', 'rgb(189, 27, 40)');
    await expect(zoneHeading).toHaveCSS('padding-left', '6px');
    await expect(zoneHeading.locator('h2')).toHaveCSS('color', 'rgb(23, 128, 65)');
    await expect(zoneHeading).not.toHaveAttribute('draggable', 'true');
    const oldCopy = await zoneHeading.elementHandle();
    const zoneFull = page.waitForResponse(response => response.url().endsWith('/render') && !response.request().postDataJSON()?.target);
    await zoneHeading.locator('h2').click();
    await expect(page.getByLabel('Choisir une zone du thème')).toHaveValue('sidebar');
    expect((await zoneFull).ok()).toBeTruthy();
    await expect.poll(() => oldCopy.evaluate(node => node.isConnected)).toBe(false);
    await foreign.locator('h2').click();
    await expect(page.getByLabel('Choisir une zone du thème')).toHaveValue('sidebar');
    const previousSibling = await sibling.elementHandle();
    const full = page.waitForResponse(response => response.url().endsWith('/render') && !response.request().postDataJSON()?.target);
    await entryHeading.locator('h2').click();
    expect((await full).ok()).toBeTruthy();
    await expect.poll(() => previousSibling.evaluate(node => node.isConnected)).toBe(false);
    const text = page.getByLabel('Texte', { exact: true });
    await expect(text).toHaveValue('Titre de l’article');
    await sibling.evaluate(node => { node.ownerDocument.defaultView.__zonePreviewSibling = node; });
    const partial = page.waitForResponse(response => response.url().endsWith('/render') && response.request().postDataJSON()?.target === 'shared_heading');
    await text.fill('Titre modifié');
    expect((await partial).ok()).toBeTruthy();
    await expect(entryHeading.locator('h2')).toHaveText('Titre modifié');
    await expect(entryHeading).toHaveCSS('padding-left', '40px');
    await expect(entryHeading.locator('h2')).toHaveCSS('color', 'rgb(189, 27, 40)');
    await expect(zoneHeading.locator('h2')).toHaveText('Titre de la zone');
    await expect(zoneHeading).toHaveCSS('padding-left', '6px');
    await expect(zoneHeading.locator('h2')).toHaveCSS('color', 'rgb(23, 128, 65)');
    expect(await sibling.evaluate(node => node === node.ownerDocument.defaultView.__zonePreviewSibling)).toBe(true);
  } finally {
    if (entryId !== null) {
      const trashed = await api(`/api/v1/content/post/${entryId}`, 'DELETE');
      expect(trashed.status, trashed.body).toBe(200);
      const deleted = await api(`/api/v1/content/post/${entryId}?force=1`, 'DELETE');
      expect(deleted.status, deleted.body).toBe(204);
    }
    const restored = await saveZone(originalZone);
    expect(restored.status(), await restored.text()).toBe(200);
  }
});
