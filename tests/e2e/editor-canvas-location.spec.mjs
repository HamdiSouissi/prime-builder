import { test, expect } from '@playwright/test';

test('editor locates an entry without replacing unrelated layouts and keeps partial owners consistent', async ({ page }) => {
  test.setTimeout(120_000);
  await page.goto('/admin/login');
  await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/), page.locator('form button[type=submit]').click()]);
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  const api = (url, method, body) => page.evaluate(async ({ url, method, body, csrf }) => {
    const response = await fetch(url, {
      method, headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf, Accept: 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    return { status: response.status, body: await response.text() };
  }, { url, method, body, csrf });
  const elements = [
    { id: 'entry_heading', type: 'heading', settings: { text: 'Titre du contenu', level: 2 }, styles: { desktop: { color: '#bd1b28' } }, children: [] },
    { id: 'entry_sibling', type: 'heading', settings: { text: 'Voisin du contenu', level: 2 }, styles: {}, children: [] },
  ];
  const document = nodes => ({ version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: nodes } }] });
  let entryId = null;
  try {
    const created = await api('/api/v1/content/post', 'POST', {
      title: 'Aperçu thème générique', slug: 'pb-apercu-theme-generique', status: 'published', content: { version: 1, blocks: [] },
    });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;
    const saveLayout = async nodes => {
      const updated = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content: document(nodes) });
      expect(updated.status, updated.body).toBe(200);
    };
    await saveLayout(elements);
    let mode = 'ambiguous';
    let savedOwner = '';
    // A separate stylesheet belonging to another entry must remain enabled.
    await page.route('**/prime-builder/css/999999999-0000000000000000.css', route => route.fulfill({
      status: 200, contentType: 'text/css', body: '[data-preview-foreign] h2 { color: rgb(12, 34, 56); }',
    }));
    // Simulate a different theme by replacing Aurora's content wrapper in a real
    // CMS preview. Foreign and copied Builder layouts precede the entry.
    await page.route(`**/admin/content/post/${entryId}/preview`, async route => {
      const response = await route.fetch();
      let html = await response.text();
      expect(html).toContain('class="aurora-prose"');
      html = html.replace('class="aurora-prose"', mode === 'legacy-optin' ? 'class="generic-entry" data-pb-editor-content' : 'class="generic-entry"');
      if (mode.startsWith('legacy-')) html = html.replace(` data-pb-document="${entryId}"`, '');
      const collision = mode === 'ambiguous' ? ` data-pb-document="${entryId}"` : '';
      const foreign = `<div class="pb-layout" data-preview-foreign${collision}><div class="pb-element pb-heading" data-pb-id="entry_heading" data-pb-owner="ffffffffffffffffffffffff"><h2>En-tête indépendant</h2></div></div>`;
      savedOwner = html.match(/data-pb-owner="([a-f0-9]{24})" data-pb-id="entry_heading"/)?.[1] || '';
      expect(savedOwner).toMatch(/^[a-f0-9]{24}$/);
      // This represents another copy of the saved entry: it shares the saved
      // owner's stylesheet but must not follow unsaved edits in the canvas.
      const copy = `<div data-pb-content-copy="pbc-111111111111111111111111"><div class="pb-layout" data-pb-document="${entryId}" data-preview-copy><div class="pb-element pb-heading" data-pb-owner="${savedOwner}" data-pb-id="entry_heading"><h2>Copie indépendante</h2></div></div></div>`;
      const decoy = mode === 'decoy-area' ? '<div class="aurora-prose" data-preview-decoy>Décor indépendant</div>' : '';
      const foreignStyle = '<link rel="stylesheet" href="/prime-builder/css/999999999-0000000000000000.css" data-preview-foreign-style>';
      expect(html).toMatch(/<body\b[^>]*>/i);
      expect(html).toContain('</head>');
      html = html.replace('</head>', `${foreignStyle}</head>`);
      await route.fulfill({ response, body: html.replace(/(<body\b[^>]*>)/i, `$1${decoy}${foreign}${copy}`) });
    });
    const editUrl = `/admin/prime-builder/edit/${entryId}`;
    const frame = page.frameLocator('[data-pb-frame]');
    const canvas = frame.locator('.generic-entry > .pb-layout');
    const foreign = frame.locator('[data-preview-foreign]');
    const copy = frame.locator('[data-preview-copy]');
    const savedStyle = frame.locator(`link[rel="stylesheet"][href*="/prime-builder/css/${entryId}-"]`);
    const foreignStyle = frame.locator('[data-preview-foreign-style]');
    const warning = page.locator('[data-pb-preview-warning]');

    await page.goto(editUrl);
    await expect(warning).toBeVisible();
    await expect(savedStyle).toHaveCount(1);
    expect(await savedStyle.evaluate(node => node.disabled)).toBe(false);
    expect(await foreignStyle.evaluate(node => node.disabled)).toBe(false);
    await expect(foreign.locator('h2')).toHaveCSS('color', 'rgb(12, 34, 56)');
    await expect(warning).toHaveAttribute('role', 'alert');
    await expect(canvas).toHaveAttribute('data-pb-document', String(entryId));
    await expect(canvas).not.toHaveAttribute('data-pb-live');
    await expect(frame.locator('[data-pb-editor-canvas]')).toHaveCount(0);
    await expect(foreign.locator('h2')).toHaveText('En-tête indépendant');
    await expect(copy.locator('h2')).toHaveText('Copie indépendante');
    await expect(canvas.locator('h2').first()).toHaveText('Titre du contenu');

    // A coincidental Aurora-like wrapper outside the marked entry must not be
    // treated as its content area when the two signals disagree.
    mode = 'decoy-area';
    await page.reload();
    await expect(warning).toBeVisible();
    await expect(frame.locator('[data-preview-decoy]')).toHaveText('Décor indépendant');
    await expect(frame.locator('[data-pb-editor-canvas]')).toHaveCount(0);
    await expect(canvas.locator('h2').first()).toHaveText('Titre du contenu');

    mode = 'legacy-unknown';
    await page.reload();
    await expect(warning).toBeVisible();
    await expect(frame.locator('[data-pb-editor-canvas]')).toHaveCount(0);
    await expect(foreign.locator('h2')).toHaveText('En-tête indépendant');
    await expect(canvas.locator('h2').first()).toHaveText('Titre du contenu');
    await page.locator('[data-pb-save]').click();
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');

    mode = 'legacy-optin';
    await page.reload();
    await expect(canvas).toHaveAttribute('data-pb-editor-canvas', '');
    await expect(warning).toBeHidden();
    await expect(foreign.locator('h2')).toHaveText('En-tête indépendant');
    await expect(copy.locator('h2')).toHaveText('Copie indépendante');

    // An older entry with a live URL can also be located without the new marker.
    await saveLayout([...elements, { id: 'identity', type: 'site_title', settings: {}, styles: {}, children: [] }]);
    mode = 'legacy-live';
    await page.reload();
    await expect(canvas).toHaveAttribute('data-pb-editor-canvas', '');
    await expect(warning).toBeHidden();
    await expect(foreign.locator('h2')).toHaveText('En-tête indépendant');
    await expect(copy.locator('h2')).toHaveText('Copie indépendante');

    // A newly saved, static layout needs neither Aurora nor a live widget.
    await saveLayout(elements);
    mode = 'marked';
    await page.reload();
    await expect(canvas).toHaveAttribute('data-pb-editor-canvas', '');
    await expect(warning).toBeHidden();
    expect(await savedStyle.evaluate(node => node.disabled)).toBe(false);
    expect(await foreignStyle.evaluate(node => node.disabled)).toBe(false);
    await expect(foreign.locator('h2')).toHaveCSS('color', 'rgb(12, 34, 56)');
    await expect(copy.locator('h2')).toHaveCSS('color', 'rgb(189, 27, 40)');
    const heading = canvas.locator(':scope > [data-pb-id="entry_heading"]');
    const sibling = canvas.locator(':scope > [data-pb-id="entry_sibling"]');
    const oldSibling = await sibling.elementHandle();
    const full = page.waitForResponse(response => response.url().endsWith('/render') && !response.request().postDataJSON()?.target);
    await heading.locator('h2').click();
    expect((await full).ok()).toBeTruthy();
    await expect.poll(() => oldSibling.evaluate(node => node.isConnected)).toBe(false);
    const text = page.getByLabel('Texte', { exact: true });
    await expect(text).toHaveValue('Titre du contenu');
    const textRender = page.waitForResponse(response => response.url().endsWith('/render') && response.request().postDataJSON()?.target === 'entry_heading');
    await text.fill('Titre modifié');
    expect((await textRender).ok()).toBeTruthy();
    await expect(heading.locator('h2')).toHaveText('Titre modifié');
    expect(await heading.getAttribute('data-pb-owner')).toBe(await sibling.getAttribute('data-pb-owner'));
    const previousOwner = await sibling.getAttribute('data-pb-owner');
    await page.getByRole('button', { name: 'Avancé', exact: true }).click();
    const styleRender = page.waitForResponse(response => response.url().endsWith('/render') && response.request().postDataJSON()?.target === 'entry_heading');
    await page.getByLabel('Marge intérieure', { exact: true }).fill('20');
    expect((await styleRender).ok()).toBeTruthy();
    await expect(heading).toHaveCSS('padding-left', '20px');
    await expect.poll(() => sibling.getAttribute('data-pb-owner')).not.toBe(previousOwner);
    expect(await heading.getAttribute('data-pb-owner')).toBe(await sibling.getAttribute('data-pb-owner'));
    await expect(foreign.locator('[data-pb-id]')).toHaveAttribute('data-pb-owner', 'ffffffffffffffffffffffff');
    await expect(copy.locator('[data-pb-id]')).toHaveAttribute('data-pb-owner', savedOwner);
    await expect(foreign.locator('h2')).toHaveText('En-tête indépendant');
    await expect(copy.locator('h2')).toHaveText('Copie indépendante');
    await expect(copy.locator('h2')).toHaveCSS('color', 'rgb(189, 27, 40)');

    // Clearing the saved color must affect only the preview, not a copy that
    // still relies on the same saved stylesheet and its original owner.
    await expect(heading.locator('h2')).toHaveCSS('color', 'rgb(189, 27, 40)');
    await page.getByRole('button', { name: 'Style', exact: true }).click();
    const clearRender = page.waitForResponse(response => response.url().endsWith('/render') && response.request().postDataJSON()?.target === 'entry_heading');
    await page.getByLabel('Couleur du texte', { exact: true }).fill('');
    expect((await clearRender).ok()).toBeTruthy();
    await expect(heading.locator('h2')).not.toHaveCSS('color', 'rgb(189, 27, 40)');
    await expect(copy.locator('h2')).toHaveCSS('color', 'rgb(189, 27, 40)');
    await expect(foreign.locator('h2')).toHaveCSS('color', 'rgb(12, 34, 56)');
    await page.screenshot({ path: test.info().outputPath('generic-editor-desktop.jpg'), fullPage: true, quality: 15 });
    await page.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    const oldHeading = await heading.elementHandle();
    const mobileRender = page.waitForResponse(response => response.url().endsWith('/render') && !response.request().postDataJSON()?.target);
    await page.locator('[data-pb-device="mobile"]').click();
    expect((await mobileRender).ok()).toBeTruthy();
    await expect.poll(() => oldHeading.evaluate(node => node.isConnected)).toBe(false);
    await heading.evaluate(node => {
      const view = node.ownerDocument.defaultView;
      view.scrollTo({ top: Math.max(0, node.getBoundingClientRect().top + view.scrollY - 110), behavior: 'instant' });
    });
    await page.evaluate(() => window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' }));
    await expect(heading.locator('h2')).toHaveText('Titre modifié');
    await expect(copy.locator('h2')).toHaveCSS('color', 'rgb(189, 27, 40)');
    await expect(warning).toBeHidden();
    await page.screenshot({ path: test.info().outputPath('generic-editor-mobile.jpg'), fullPage: true, quality: 15 });
  } finally {
    if (entryId !== null) {
      const trashed = await api(`/api/v1/content/post/${entryId}`, 'DELETE');
      expect(trashed.status, trashed.body).toBe(200);
      const deleted = await api(`/api/v1/content/post/${entryId}?force=1`, 'DELETE');
      expect(deleted.status, deleted.body).toBe(204);
    }
  }
});
