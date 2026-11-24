import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('shadow presets inherit and render consistently in the editor and public page', async ({ page, browser }) => {
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
  const shadows = {
    soft: 'rgba(0, 0, 0, 0.15) 0px 2px 8px 0px',
    medium: 'rgba(0, 0, 0, 0.2) 0px 6px 20px 0px',
    strong: 'rgba(0, 0, 0, 0.28) 0px 12px 32px 0px',
  };
  const accessible = async () => {
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => ({ id: v.id, targets: v.nodes.map(node => node.target) }))).toEqual([]);
  };
  let entryId = null;
  try {
    const created = await api('/api/v1/content/page', 'POST', {
      title: 'Ombres adaptatives', slug: 'pb-responsive-shadows', status: 'draft', content: { version: 1, blocks: [] },
    });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;
    const section = element('section', 'container', { layout: 'flex', direction: 'column' }, { desktop: { padding: 24, background: '#ffffff' } }, [
      element('title', 'heading', { text: 'Une ombre pour chaque écran', level: 2 }),
      element('button', 'button', { text: 'Découvrir', url: '/' }),
    ]);
    const content = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: [section] } }] };
    const saved = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content, status: 'published', published_at: '' });
    expect(saved.status, saved.body).toBe(200);

    await page.goto(`/admin/prime-builder/edit/${entryId}`);
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="section"]').click();
    await page.getByRole('button', { name: 'Style', exact: true }).click();
    const shadow = page.getByRole('combobox', { name: 'Ombre', exact: true });
    const frame = page.frameLocator('[data-pb-frame]');
    const target = frame.locator('[data-pb-id="section"]');
    await expect(shadow).toHaveAccessibleDescription(/Ombre prédéfinie autour du widget/);
    await shadow.focus();
    await page.keyboard.press('ArrowDown'); // explicit none
    await page.keyboard.press('ArrowDown'); // soft
    await expect(shadow).toHaveValue('soft');
    await expect(target).toHaveCSS('box-shadow', shadows.soft);
    await expect(frame.locator('[data-pb-id="title"]')).toHaveCSS('box-shadow', 'none');
    await shadow.scrollIntoViewIfNeeded();
    await accessible();
    await page.locator('[data-pb-settings-panel]').screenshot({ path: test.info().outputPath('shadow-editor-desktop.jpg'), quality: 15 });

    await page.getByRole('button', { name: 'Tablette', exact: true }).click();
    await expect(shadow).toHaveValue('');
    await expect(shadow).toHaveAccessibleDescription(/Hérité de Ordinateur : Légère/);
    await shadow.selectOption('medium');
    await expect(target).toHaveCSS('box-shadow', shadows.medium);
    await shadow.selectOption('none');
    await expect(target).toHaveCSS('box-shadow', 'none');
    await page.getByRole('button', { name: 'Mobile', exact: true }).click();
    await expect(shadow).toHaveAccessibleDescription(/Hérité de Tablette : Aucune/);
    await expect(target).toHaveCSS('box-shadow', 'none');
    await shadow.selectOption('strong');
    await expect(target).toHaveCSS('box-shadow', shadows.strong);
    await page.locator('[data-pb-undo]').focus();
    await page.keyboard.press('Control+z');
    await expect(shadow).toHaveValue('');
    await expect(target).toHaveCSS('box-shadow', 'none');
    await page.keyboard.press('Control+Shift+z');
    await expect(shadow).toHaveValue('strong');
    await expect(target).toHaveCSS('box-shadow', shadows.strong);
    await shadow.selectOption('');
    await expect(target).toHaveCSS('box-shadow', 'none');
    await shadow.selectOption('strong');
    await expect(target).toHaveCSS('box-shadow', shadows.strong);
    const update = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click();
    const savedBody = await (await update).json();
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    const node = savedBody.data.content.blocks[0].data.elements[0];
    expect(node.styles.desktop).toMatchObject({ box_shadow: 'soft', background: '#ffffff', padding: 24 });
    expect(node.styles.tablet).toMatchObject({ box_shadow: 'none' });
    expect(node.styles.mobile).toMatchObject({ box_shadow: 'strong' });
    await page.reload();
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="section"]').click();
    await page.getByRole('button', { name: 'Style', exact: true }).click();
    await expect(shadow).toHaveValue('soft');
    await expect(target).toHaveCSS('box-shadow', shadows.soft);
    await page.setViewportSize({ width: 375, height: 812 });
    await page.getByRole('button', { name: 'Mobile', exact: true }).click();
    await expect(shadow).toHaveValue('strong');
    await expect(target).toHaveCSS('box-shadow', shadows.strong);
    await shadow.scrollIntoViewIfNeeded();
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await accessible();
    await page.locator('[data-pb-settings-panel]').screenshot({ path: test.info().outputPath('shadow-editor-mobile.jpg'), quality: 15 });
    await page.setViewportSize({ width: 1440, height: 900 });

    await page.goto('/pb-responsive-shadows');
    const publicTarget = page.locator('[data-pb-id="section"]');
    await expect(publicTarget).toHaveCSS('box-shadow', shadows.soft);
    await expect(publicTarget).not.toHaveAttribute('style');
    await expect(page.locator('[data-pb-id="title"]')).toHaveCSS('box-shadow', 'none');
    await accessible();
    await page.screenshot({ path: test.info().outputPath('shadow-public-desktop.jpg'), fullPage: true, quality: 15 });
    await page.setViewportSize({ width: 800, height: 900 });
    await expect(publicTarget).toHaveCSS('box-shadow', 'none');
    await page.setViewportSize({ width: 375, height: 812 });
    await expect(publicTarget).toHaveCSS('box-shadow', shadows.strong);
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await accessible();
    await page.screenshot({ path: test.info().outputPath('shadow-public-mobile.jpg'), fullPage: true, quality: 15 });

    const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080', viewport: { width: 375, height: 812 } });
    try {
      const fallback = await noJs.newPage();
      await fallback.goto('/pb-responsive-shadows');
      const box = fallback.locator('[data-pb-id="section"]');
      await expect(box).toHaveCSS('box-shadow', shadows.strong);
      await fallback.setViewportSize({ width: 800, height: 900 });
      await expect(box).toHaveCSS('box-shadow', 'none');
      await fallback.setViewportSize({ width: 1440, height: 900 });
      await expect(box).toHaveCSS('box-shadow', shadows.soft);
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
