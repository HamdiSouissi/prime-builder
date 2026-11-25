import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('copied menu anchors remain distinct and their unchanged links are explained', async ({ page, browser }) => {
  test.setTimeout(240_000);
  page.setDefaultTimeout(30_000);
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
    await target.bringToFront();
    expect(result.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => ({ id: v.id, targets: v.nodes.map(node => node.target) }))).toEqual([]);
  };
  const name = `Ancrage ${Date.now()} ${Math.random().toString(16).slice(2, 8)}`;
  const slug = `pb-anchor-copy-${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;
  const base = 'pb-anchor-copy-target';
  const node = (id, type, settings = {}, children = []) => ({ id, type, settings, styles: {}, children });
  const section = node('source', 'container', { layout: 'flex', direction: 'column', columns: 1 }, [
    node('first', 'anchor', { anchor: base }),
    node('second', 'anchor', { anchor: `${base}-2` }),
    node('link', 'button', { text: 'Aller à la cible', url: `#${base}` }),
  ]);
  const layout = { version: 1, elements: [section] };
  const content = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: layout }] };
  let entryId = null;
  let templateId = null;
  try {
    const created = await api('/api/v1/content/page', 'POST', { title: 'Copie des ancres', slug, status: 'draft', content: { version: 1, blocks: [] } });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;
    const prepared = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content, status: 'draft' });
    expect(prepared.status, prepared.body).toBe(200);
    const template = await api('/api/prime-builder/v1/templates', 'POST', { name, kind: 'section', data: layout });
    expect(template.status, template.body).toBe(201);
    templateId = JSON.parse(template.body).data.id;
    expect(templateId).toBeTruthy();

    await page.goto(`/admin/prime-builder/edit/${entryId}`);
    const frame = page.frameLocator('[data-pb-frame]');
    const anchors = frame.locator('.pb-anchor span[id]');
    const warning = page.locator('[data-pb-anchor-warning]');
    const opener = page.locator('[data-pb-context-open]');
    const menu = page.getByRole('menu', { name: 'Actions de l’élément' });
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="source"]').click();
    await expect(anchors).toHaveCount(2);
    await opener.focus();
    await page.keyboard.press('Shift+F10');
    await menu.getByRole('menuitem', { name: 'Dupliquer', exact: true }).click();
    await expect(anchors).toHaveCount(4);
    await expect.poll(() => anchors.evaluateAll(items => items.map(item => item.id))).toEqual([base, `${base}-2`, `${base}-3`, `${base}-2-2`]);
    await expect(warning).toBeVisible();
    await expect(warning).toContainText('Les liens ne sont pas modifiés automatiquement');
    await accessible(page);
    await page.screenshot({ path: test.info().outputPath('anchor-copy-editor-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 375, height: 812 });
    await expect(warning).toBeVisible();
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await accessible(page);
    await page.screenshot({ path: test.info().outputPath('anchor-copy-editor-mobile.png'), fullPage: true });
    await page.setViewportSize({ width: 1440, height: 900 });

    await page.locator('[data-pb-undo]').click();
    await expect(anchors).toHaveCount(2);
    await page.locator('[data-pb-redo]').click();
    await expect(anchors).toHaveCount(4);
    await page.locator('[data-pb-anchor-warning-close]').click();
    await expect(warning).toBeHidden();
    await expect(page.locator('[data-pb-panel="navigator"]')).toBeFocused();

    // Pasting into the selected container must account for all existing anchors.
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="source"]').click();
    await opener.click();
    await menu.getByRole('menuitem', { name: 'Copier', exact: true }).click();
    await opener.click();
    await menu.getByRole('menuitem', { name: 'Coller', exact: true }).click();
    await expect(anchors).toHaveCount(6);
    await expect(warning).toBeVisible();
    await expect.poll(() => anchors.evaluateAll(items => items.map(item => item.id))).toEqual([base, `${base}-2`, `${base}-4`, `${base}-2-3`, `${base}-3`, `${base}-2-2`]);

    // Inserting the same saved section is another independent copy.
    await page.locator('[data-pb-panel="library"]').click();
    await page.getByRole('button', { name: `Insérer ${name}`, exact: true }).click();
    await expect(anchors).toHaveCount(8);
    const ids = await anchors.evaluateAll(items => items.map(item => item.id));
    expect(new Set(ids).size).toBe(8);
    expect(ids).toContain(`${base}-5`);
    const links = frame.locator('.pb-button-link');
    await expect(links).toHaveCount(4);
    expect(await links.evaluateAll(items => items.map(item => item.getAttribute('href')))).toEqual(Array(4).fill(`#${base}`));

    await page.locator('[data-pb-status]').selectOption('published');
    const updated = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click();
    expect((await updated).status()).toBe(200);
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await page.reload();
    await expect(anchors).toHaveCount(8);
    expect(new Set(await anchors.evaluateAll(items => items.map(item => item.id))).size).toBe(8);

    const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080', viewport: { width: 375, height: 812 } });
    try {
      const publicPage = await noJs.newPage();
      await publicPage.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
      const publicAnchors = publicPage.locator('.pb-layout .pb-anchor span[id]');
      await expect(publicAnchors).toHaveCount(8);
      expect(new Set(await publicAnchors.evaluateAll(items => items.map(item => item.id))).size).toBe(8);
      expect(await publicPage.locator('.pb-layout .pb-button-link').evaluateAll(items => items.map(item => item.getAttribute('href')))).toEqual(Array(4).fill(`#${base}`));
      await expect.poll(() => publicPage.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    } finally { await noJs.close(); }
    expect(errors).toEqual([]);
  } finally {
    if (templateId !== null) {
      const deleted = await api(`/api/prime-builder/v1/templates/${encodeURIComponent(templateId)}`, 'DELETE');
      expect(deleted.status, deleted.body).toBe(200);
    }
    if (entryId !== null) {
      const trashed = await api(`/api/v1/content/page/${entryId}`, 'DELETE');
      expect(trashed.status, trashed.body).toBe(200);
      const deleted = await api(`/api/v1/content/page/${entryId}?force=1`, 'DELETE');
      expect(deleted.status, deleted.body).toBe(204);
    }
  }
});
