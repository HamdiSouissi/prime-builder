import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('border controls inherit across devices and persist in the public stylesheet', async ({ page, browser }) => {
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
  const element = (id, type, settings = {}, styles = {}, children = []) => ({ id, type, settings, styles, children });
  const accessible = async () => {
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => ({ id: v.id, targets: v.nodes.map(node => node.target) }))).toEqual([]);
  };
  let entryId = null;
  try {
    const created = await api('/api/v1/content/page', 'POST', {
      title: 'Bordures adaptatives', slug: 'pb-responsive-borders', status: 'draft', content: { version: 1, blocks: [] },
    });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;
    const section = element('section', 'container', { layout: 'flex', direction: 'column' }, { desktop: { padding: 20 } }, [
      element('title', 'heading', { text: 'Un cadre pour chaque écran', level: 2 }),
      element('button', 'button', { text: 'Découvrir', url: '/' }),
    ]);
    const content = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: [section] } }] };
    const saved = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content, status: 'published', published_at: '' });
    expect(saved.status, saved.body).toBe(200);

    await page.goto(`/admin/prime-builder/edit/${entryId}`);
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="section"]').click();
    await page.getByRole('button', { name: 'Style', exact: true }).click();
    const style = page.getByRole('combobox', { name: 'Style de bordure', exact: true });
    const width = page.getByLabel('Épaisseur de bordure (px)', { exact: true });
    const color = page.getByLabel('Couleur de bordure', { exact: true });
    const frame = page.frameLocator('[data-pb-frame]');
    const target = frame.locator('[data-pb-id="section"]');
    await expect(style).toHaveAccessibleDescription(/Choisissez un style autre que Aucune/);
    await style.focus();
    await page.keyboard.press('ArrowDown'); // explicit none
    await page.keyboard.press('ArrowDown'); // solid
    await expect(style).toHaveValue('solid');
    await width.fill('4');
    await page.getByLabel('Couleur globale de la bordure', { exact: true }).selectOption('kit:primary');
    await expect(target).toHaveCSS('border-top-style', 'solid');
    await expect(target).toHaveCSS('border-top-width', '4px');
    await expect(target).toHaveCSS('border-top-color', 'rgb(201, 68, 31)');
    await expect(frame.locator('[data-pb-id="title"]')).toHaveCSS('border-top-width', '0px');
    const reference = page.getByLabel('Couleur globale de la bordure', { exact: true });
    await page.locator('[data-pb-undo]').focus();
    await page.keyboard.press('Control+z');
    await expect(reference).toHaveValue('');
    await expect(target).not.toHaveCSS('border-top-color', 'rgb(201, 68, 31)');
    await page.keyboard.press('Control+z');
    await expect(width).toHaveValue('');
    await expect(target).not.toHaveCSS('border-top-width', '4px');
    await page.keyboard.press('Control+Shift+z');
    await expect(width).toHaveValue('4');
    await expect(target).toHaveCSS('border-top-width', '4px');
    await page.keyboard.press('Control+Shift+z');
    await expect(reference).toHaveValue('kit:primary');
    await expect(target).toHaveCSS('border-top-color', 'rgb(201, 68, 31)');
    await color.scrollIntoViewIfNeeded();
    await accessible();
    await page.locator('[data-pb-settings-panel]').screenshot({ path: test.info().outputPath('border-editor-desktop.jpg'), quality: 15 });

    await page.getByRole('button', { name: 'Tablette', exact: true }).click();
    await expect(style).toHaveValue('');
    await expect(style).toHaveAccessibleDescription(/Hérité de Ordinateur : Continue/);
    await expect(width).toHaveAccessibleDescription('Hérité de Ordinateur : 4px');
    await expect(color).toHaveAccessibleDescription('Hérité de Ordinateur : Couleur principale du kit');
    await style.selectOption('dashed');
    await width.fill('8');
    await expect(target).toHaveCSS('border-top-style', 'dashed');
    await expect(target).toHaveCSS('border-top-width', '8px');
    await expect(target).toHaveCSS('border-top-color', 'rgb(201, 68, 31)');

    await page.getByRole('button', { name: 'Mobile', exact: true }).click();
    await expect(style).toHaveAccessibleDescription(/Hérité de Tablette : Tirets/);
    await expect(width).toHaveAccessibleDescription('Hérité de Tablette : 8px');
    await style.selectOption('none');
    await expect(target).toHaveCSS('border-top-style', 'none');
    await expect(target).toHaveCSS('border-top-width', '0px');
    await style.selectOption('');
    await expect(target).toHaveCSS('border-top-style', 'dashed');
    await width.fill('0');
    await expect(target).toHaveCSS('border-top-width', '0px');
    await width.fill('');
    await expect(target).toHaveCSS('border-top-width', '8px');
    await style.selectOption('dotted');
    await width.fill('2');
    await color.fill('#176533');
    await expect(target).toHaveCSS('border-top-style', 'dotted');
    await expect(target).toHaveCSS('border-top-width', '2px');
    await expect(target).toHaveCSS('border-top-color', 'rgb(23, 101, 51)');
    const update = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click();
    const savedBody = await (await update).json();
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    const node = savedBody.data.content.blocks[0].data.elements[0];
    expect(node.styles.desktop).toMatchObject({ border_style: 'solid', border_width: 4, border_color: 'kit:primary' });
    expect(node.styles.tablet).toMatchObject({ border_style: 'dashed', border_width: 8 });
    expect(node.styles.mobile).toMatchObject({ border_style: 'dotted', border_width: 2, border_color: '#176533' });
    await page.reload();
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="section"]').click();
    await page.getByRole('button', { name: 'Style', exact: true }).click();
    await expect(style).toHaveValue('solid');
    await expect(width).toHaveValue('4');
    await page.setViewportSize({ width: 375, height: 812 });
    await color.scrollIntoViewIfNeeded();
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await accessible();
    await page.locator('[data-pb-settings-panel]').screenshot({ path: test.info().outputPath('border-editor-mobile.jpg'), quality: 15 });
    await page.setViewportSize({ width: 1440, height: 900 });

    await page.goto('/pb-responsive-borders');
    const publicTarget = page.locator('[data-pb-id="section"]');
    await expect(publicTarget).toHaveCSS('border-top-style', 'solid');
    await expect(publicTarget).toHaveCSS('border-top-width', '4px');
    await expect(publicTarget).toHaveCSS('border-top-color', 'rgb(201, 68, 31)');
    await expect(publicTarget).not.toHaveAttribute('style');
    await expect(page.locator('[data-pb-id="title"]')).toHaveCSS('border-top-width', '0px');
    await accessible();
    await page.screenshot({ path: test.info().outputPath('border-public-desktop.jpg'), fullPage: true, quality: 15 });
    await page.setViewportSize({ width: 800, height: 900 });
    await expect(publicTarget).toHaveCSS('border-top-style', 'dashed');
    await expect(publicTarget).toHaveCSS('border-top-width', '8px');
    await expect(publicTarget).toHaveCSS('border-top-color', 'rgb(201, 68, 31)');
    await page.setViewportSize({ width: 375, height: 812 });
    await expect(publicTarget).toHaveCSS('border-top-style', 'dotted');
    await expect(publicTarget).toHaveCSS('border-top-width', '2px');
    await expect(publicTarget).toHaveCSS('border-top-color', 'rgb(23, 101, 51)');
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await accessible();
    await page.screenshot({ path: test.info().outputPath('border-public-mobile.jpg'), fullPage: true, quality: 15 });

    const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080', viewport: { width: 375, height: 812 } });
    try {
      const fallback = await noJs.newPage();
      await fallback.goto('/pb-responsive-borders');
      const border = fallback.locator('[data-pb-id="section"]');
      await expect(border).toHaveCSS('border-top-style', 'dotted');
      await expect(border).toHaveCSS('border-top-width', '2px');
      await expect(border).toHaveCSS('border-top-color', 'rgb(23, 101, 51)');
      await fallback.setViewportSize({ width: 800, height: 900 });
      await expect(border).toHaveCSS('border-top-style', 'dashed');
      await expect(border).toHaveCSS('border-top-width', '8px');
      await fallback.setViewportSize({ width: 1440, height: 900 });
      await expect(border).toHaveCSS('border-top-style', 'solid');
      await expect(border).toHaveCSS('border-top-width', '4px');
      const link = fallback.getByRole('link', { name: 'Découvrir' });
      await link.focus();
      await expect(link).toBeFocused();
    } finally { await noJs.close(); }
    expect(pageErrors).toEqual([]);
  } finally {
    if (entryId !== null) {
      const trashed = await api(`/api/v1/content/page/${entryId}`, 'DELETE');
      expect(trashed.status, trashed.body).toBe(200);
      const deleted = await api(`/api/v1/content/page/${entryId}?force=1`, 'DELETE');
      expect(deleted.status, deleted.body).toBe(204);
    }
  }
});
