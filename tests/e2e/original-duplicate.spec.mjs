import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('a core duplicate with an entry-bound original is flagged instead of silently edited', async ({ page, browser }) => {
  test.setTimeout(120_000);
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
  let sourceId = null;
  let copyId = null;
  try {
    const created = await api('/api/v1/content/page', 'POST', {
      title: 'Source à dupliquer', slug: 'pb-duplicate-original-guard', status: 'published',
      content: { version: 1, blocks: [{ id: 'old', type: 'html', data: { html: '<p data-legacy="duplicate">Fragment original conservé</p>' } }] },
    });
    expect(created.status, created.body).toBe(201);
    sourceId = JSON.parse(created.body).data.id;
    await page.goto(`/admin/prime-builder/edit/${sourceId}`);
    const sourceConfig = await page.locator('[data-pb-config]').inputValue();
    expect(JSON.parse(sourceConfig).conversionFailed).toBe(false);
    expect(JSON.parse(sourceConfig).document.blocks[0].data.elements[0].children[0].type).toBe('original_content');
    await page.locator('[data-pb-save]').click();
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await page.goto('/pb-duplicate-original-guard');
    await expect(page.locator('[data-legacy="duplicate"]')).toHaveText('Fragment original conservé');

    // Exercise the actual core admin route, which succeeds but does not rebind
    // original fragments to the new entry. Never reveal or reauthenticate it.
    const duplicate = await page.request.post(`/admin/content/page/${sourceId}/duplicate`, {
      form: { _csrf_token: csrf }, maxRedirects: 0,
    });
    expect(duplicate.status()).toBe(302);
    const match = duplicate.headers().location?.match(/\/admin\/content\/page\/(\d+)\/edit$/);
    expect(match).toBeTruthy();
    copyId = Number(match[1]);
    expect(copyId).not.toBe(sourceId);
    const before = await api(`/api/v1/content/page/${copyId}`, 'GET');
    expect(before.status, before.body).toBe(200);
    // A stale client cannot bypass the UI with a valid empty Builder document.
    const replacement = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: [] } }] };
    const refused = await api(`/api/prime-builder/v1/documents/${copyId}`, 'PATCH', { content: replacement });
    expect(refused.status, refused.body).toBe(422);
    expect(refused.body).toContain('L’enregistrement dans Prime Builder est désactivé');
    expect((await api(`/api/v1/content/page/${copyId}`, 'GET')).body).toBe(before.body);

    // The warning and disabled controls are in the server response, even if
    // the editor script fails to load. The mobile escape link remains visible.
    const noJs = await browser.newContext({
      baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080', locale: 'fr-FR',
      javaScriptEnabled: false, storageState: await page.context().storageState(), viewport: { width: 320, height: 700 },
    });
    try {
      const fallback = await noJs.newPage();
      await fallback.goto(`/admin/prime-builder/edit/${copyId}`);
      await expect(fallback.locator('[data-pb-conversion-warning]')).toBeVisible();
      await expect(fallback.locator('[data-pb-save]')).toBeDisabled();
      await expect(fallback.locator('[data-pb-status]')).toBeDisabled();
      await expect(fallback.locator('[data-pb-schedule]')).toBeDisabled();
      await expect(fallback.locator('[data-pb-import]')).toBeDisabled();
      await expect(fallback.locator('[data-pb-export]')).toBeDisabled();
      await expect(fallback.getByRole('link', { name: 'Retour au contenu' })).toBeVisible();
      await expect.poll(() => fallback.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    } finally { await noJs.close(); }

    await page.goto(`/admin/prime-builder/edit/${copyId}`);
    const copyConfig = JSON.parse(await page.locator('[data-pb-config]').inputValue());
    expect(copyConfig.conversionFailed).toBe(true);
    expect(copyConfig.converted).toBe(false);
    const warning = page.locator('[data-pb-conversion-warning]');
    await expect(warning).toBeVisible();
    await expect(warning).toHaveAttribute('role', 'alert');
    await expect(warning).toContainText('L’enregistrement dans Prime Builder est désactivé');
    await expect(page.locator('[data-pb-save]')).toBeDisabled();
    await expect(page.locator('[data-pb-save]')).toHaveCSS('background-color', 'rgb(225, 226, 229)');
    await expect(page.locator('[data-pb-status]')).toBeDisabled();
    await expect(page.locator('[data-pb-schedule]')).toBeDisabled();
    const headingWidget = page.getByRole('button', { name: 'Ajouter Titre', exact: true });
    await expect(headingWidget).toBeDisabled();
    await expect(headingWidget).toHaveAttribute('draggable', 'false');
    await expect(page.frameLocator('[data-pb-frame]').locator('body')).toBeVisible();
    await expect(page.frameLocator('[data-pb-frame]').locator('[data-legacy="duplicate"]')).toHaveCount(0);

    const writes = [];
    page.on('request', request => {
      if (request.method() === 'PATCH' && request.url().includes(`/api/prime-builder/v1/documents/${copyId}`)) writes.push(request.url());
    });
    await page.keyboard.press('Control+s');
    // Even a synthetic click cannot insert a widget when the UI is blocked.
    await headingWidget.dispatchEvent('click');
    await page.locator('[data-pb-panel="navigator"]').click();
    await expect(page.locator('[data-pb-tree] [data-pb-tree-id]')).toHaveCount(0);
    await page.locator('[data-pb-panel="library"]').click();
    await expect(page.locator('[data-pb-export]')).toBeDisabled();
    await expect(page.locator('[data-pb-import]')).toBeDisabled();
    await expect(page.locator('[data-pb-library-list]').getByRole('button', { name: /^Insérer/ }).first()).toBeDisabled();
    await page.locator('[data-pb-command-open]').click();
    await page.locator('[data-pb-command-search]').fill('Enregistrer');
    await expect(page.locator('[data-pb-command-list]').getByRole('button', { name: 'Enregistrer', exact: true })).toBeDisabled();
    await page.locator('[data-pb-command-close]').click();
    await page.getByRole('link', { name: 'Retour au contenu' }).focus();
    await page.keyboard.press('Tab');
    await expect(page.locator('[data-pb-save]')).not.toBeFocused();
    await expect(page.locator('[data-pb-save]')).toBeDisabled();
    expect(writes).toEqual([]);
    const after = await api(`/api/v1/content/page/${copyId}`, 'GET');
    expect(after.status, after.body).toBe(200);
    expect(after.body).toBe(before.body);

    const accessible = async () => {
      const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
      expect(result.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => ({ id: v.id, targets: v.nodes.map(node => node.target) }))).toEqual([]);
    };
    await accessible();
    await page.screenshot({ path: test.info().outputPath('duplicate-warning-desktop.jpg'), fullPage: true, quality: 15 });
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-navigator-panel]').focus();
    await expect(page.locator('[data-pb-navigator-panel]')).toBeFocused();
    await accessible();
    await page.locator('[data-pb-panel="widgets"]').click();
    await page.setViewportSize({ width: 375, height: 812 });
    await expect(warning).toBeVisible();
    await expect(page.getByRole('link', { name: 'Retour au contenu' })).toBeVisible();
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await accessible();
    await page.screenshot({ path: test.info().outputPath('duplicate-warning-mobile.jpg'), fullPage: true, quality: 15 });
    await page.setViewportSize({ width: 320, height: 700 });
    await expect(page.getByRole('link', { name: 'Retour au contenu' })).toBeVisible();
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);

    // Simulate an invalid saved layout returned by the server. Integration
    // tests cover its detection; here the client must not traverse malformed
    // elements or offer controls which could overwrite that stored data.
    let malformedElements = 'not-an-array';
    await page.route(`**/admin/prime-builder/edit/${copyId}`, async route => {
      const response = await route.fetch();
      const original = await response.text();
      const malformed = structuredClone(copyConfig);
      malformed.status = 'scheduled';
      malformed.publishedAt = '2030-01-01 12:00:00';
      malformed.document.blocks[0].data.elements = malformedElements;
      const escaped = JSON.stringify(malformed).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
      const body = original.replace(/(<textarea hidden data-pb-config>)[\s\S]*?(<\/textarea>)/, (_, start, end) => `${start}${escaped}${end}`);
      expect(body).not.toBe(original);
      await route.fulfill({ response, body });
    });
    for (const value of ['not-an-array', [{ id: 'broken', type: 'container', children: 'not-an-array' }]]) {
      malformedElements = value;
      await page.goto(`/admin/prime-builder/edit/${copyId}`);
      await expect(warning).toBeVisible();
      await expect(page.locator('[data-pb-save]')).toBeDisabled();
      await expect(page.locator('[data-pb-schedule]')).toBeDisabled();
      await page.locator('[data-pb-panel="navigator"]').click();
      await expect(page.locator('[data-pb-tree] [data-pb-tree-id]')).toHaveCount(0);
    }
    expect(writes).toEqual([]);
    await page.goto('/pb-duplicate-original-guard');
    await expect(page.locator('[data-legacy="duplicate"]')).toHaveText('Fragment original conservé');

    // Integration tests show that valid old layouts may omit optional node
    // properties. Simulate the same server config and exercise the editor tree.
    await page.route(`**/admin/prime-builder/edit/${sourceId}`, async route => {
      const response = await route.fetch();
      const original = await response.text();
      const minimal = JSON.parse(sourceConfig);
      minimal.converted = false;
      minimal.document.blocks[0].data.elements = [
        { id: 'empty', type: 'container', settings: null, styles: null, children: null },
        { id: 'parent', type: 'container', children: [{ id: 'minimal', type: 'heading' }] },
      ];
      const escaped = JSON.stringify(minimal).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;').replaceAll("'", '&#039;');
      const body = original.replace(/(<textarea hidden data-pb-config>)[\s\S]*?(<\/textarea>)/, (_, start, end) => `${start}${escaped}${end}`);
      expect(body).not.toBe(original);
      await route.fulfill({ response, body });
    });
    await page.goto(`/admin/prime-builder/edit/${sourceId}`);
    await expect(page.locator('[data-pb-conversion-warning]')).toBeHidden();
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="minimal"]').click();
    await expect(page.getByLabel('Texte', { exact: true })).toBeVisible();
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="empty"]').click();
    await expect(page.locator('[data-pb-fields] select').first()).toBeVisible();
    await page.locator('[data-pb-panel="widgets"]').click();
    await page.getByRole('button', { name: 'Ajouter Titre', exact: true }).click();
    await page.locator('[data-pb-panel="navigator"]').click();
    await expect(page.locator('[data-pb-tree-id="empty"] + ul [data-pb-tree-id]')).toHaveCount(1);
    expect(pageErrors).toEqual([]);
  } finally {
    for (const entryId of [copyId, sourceId]) {
      if (entryId === null) continue;
      const trashed = await api(`/api/v1/content/page/${entryId}`, 'DELETE');
      expect(trashed.status, trashed.body).toBe(200);
      const deleted = await api(`/api/v1/content/page/${entryId}?force=1`, 'DELETE');
      expect(deleted.status, deleted.body).toBe(204);
    }
  }
});
