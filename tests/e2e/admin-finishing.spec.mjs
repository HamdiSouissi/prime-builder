import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const entryId = process.env.E2E_ENTRY_ID || '1';

async function login(page) {
  await page.goto('/admin/login');
  await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/), page.locator('form button[type=submit]').click()]);
}

async function expectAccessible(page) {
  const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
  expect(result.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => v.id)).toEqual([]);
}

test('admin menu exposes filtered models, submissions, kit and settings alongside system status', async ({ page }) => {
  await login(page);
  await page.goto('/admin/prime-builder');
  const navigation = page.locator('#pc-submenu-plugin-prime-builder');
  await expect(navigation.getByRole('link', { name: 'Modèles', exact: true })).toBeVisible();
  await expect(navigation.getByRole('link', { name: 'Envois de formulaires', exact: true })).toBeVisible();
  await expect(navigation.getByRole('link', { name: 'Kit du site', exact: true })).toBeVisible();
  await expect(navigation.getByRole('link', { name: 'Réglages', exact: true })).toBeVisible();
  await navigation.getByRole('link', { name: 'Modèles', exact: true }).click();
  await expect(page).toHaveURL(/\/admin\/prime-builder\/theme$/);
  await expect(page.getByRole('heading', { name: 'Theme Builder', exact: true })).toBeVisible();
  await page.getByLabel('Filtrer par type').selectOption('popup');
  await page.getByRole('button', { name: 'Filtrer', exact: true }).click();
  await expect(page).toHaveURL(/kind=popup/);
  await expect(page.getByLabel('Filtrer par type')).toHaveValue('popup');
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('theme-models-desktop.png'), fullPage: true });
  await navigation.getByRole('link', { name: 'Kit du site', exact: true }).click();
  await expect(page).toHaveURL(/\/admin\/prime-builder\/kit#pb-kit-title$/);
  await expect(page.locator('#pb-kit-title')).toBeVisible();
  await expect(navigation.getByRole('link', { name: 'Kit du site', exact: true })).toHaveAttribute('aria-current', 'page');
  await expect(navigation.getByRole('link', { name: 'Réglages', exact: true })).not.toHaveAttribute('aria-current', 'page');
  const kitSave = page.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith('/admin/prime-builder/kit'));
  await page.locator('form[action="/admin/prime-builder/kit"] button[type=submit]').click();
  expect((await kitSave).status()).toBe(302);
  await expect(page).toHaveURL(/\/admin\/prime-builder\/kit#pb-kit-title$/);
  await expect(navigation.getByRole('link', { name: 'Kit du site', exact: true })).toHaveAttribute('aria-current', 'page');
  await expectAccessible(page);
  await navigation.getByRole('link', { name: 'Envois de formulaires', exact: true }).click();
  await expect(page).toHaveURL(/\/admin\/prime-builder\/submissions$/);
  await navigation.getByRole('link', { name: 'Réglages', exact: true }).click();
  await expect(page).toHaveURL(/\/admin\/prime-builder\/settings$/);
  await expect(navigation.getByRole('link', { name: 'Réglages', exact: true })).toHaveAttribute('aria-current', 'page');
  await expect(navigation.getByRole('link', { name: 'Kit du site', exact: true })).not.toHaveAttribute('aria-current', 'page');
  await page.goto('/admin/tools/system');
  await expect(page.locator('[data-prime-check="prime-builder.status"]')).toHaveAttribute('data-status', 'ok');
  await expect(page.locator('[data-prime-check="prime-builder.assets"]')).toHaveAttribute('data-status', 'ok');
});

test('font loading and the filtered editor CSS are saved, applied and restored', async ({ page, context }) => {
  await login(page);
  await page.goto('/admin/prime-builder/settings');
  const font = page.getByLabel('Chargement des polices du site');
  const css = page.getByLabel('CSS de l’éditeur', { exact: true });
  const original = { font: await font.inputValue(), css: await css.inputValue() };
  const submit = async () => {
    const response = page.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith('/admin/prime-builder/settings'));
    await page.locator('form[action="/admin/prime-builder/settings"] button[type=submit]').click();
    return (await response).status();
  };
  let editor;
  try {
    await font.selectOption('inter_local');
    await css.fill('--pb-brand: #123456;\n--pb-accent: #abc;');
    expect(await submit()).toBe(302);
    await expect(font).toHaveValue('inter_local');
    await expect(css).toHaveValue('--pb-brand: #123456;\n--pb-accent: #abc;');
    await expectAccessible(page);
    await page.screenshot({ path: test.info().outputPath('settings-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await expectAccessible(page);
    await page.screenshot({ path: test.info().outputPath('settings-mobile.png'), fullPage: true });
    editor = await context.newPage();
    await editor.goto(`/admin/prime-builder/edit/${entryId}`);
    await expect(editor.locator('[data-pb-editor-custom-css]')).toHaveCount(1);
    expect(await editor.locator('[data-pb-editor-custom-css]').textContent()).toBe(':root{--pb-brand: #123456;--pb-accent: #abc;}');
    await expect(editor.locator('[data-pb-save]')).toHaveCSS('background-color', 'rgb(18, 52, 86)');
    const config = JSON.parse(await editor.locator('[data-pb-config]').inputValue());
    const stylesheet = await editor.request.get(config.kitCssUrl);
    expect(stylesheet.status()).toBe(200);
    expect(await stylesheet.text()).toContain("src:url('/assets/fonts/inter/InterVariable.woff2')");
    expect((await editor.request.get('/assets/fonts/inter/InterVariable.woff2')).status()).toBe(200);
    await editor.getByRole('button', { name: 'Ajouter Titre', exact: true }).click();
    const layout = editor.frameLocator('[data-pb-frame]').locator('.pb-layout').first();
    await expect(layout).toBeVisible();
    await expect.poll(() => layout.evaluate(node => getComputedStyle(node).fontFamily)).toContain('PrimeBuilderInter');
    const fontLoaded = await layout.evaluate(async node => (await node.ownerDocument.fonts.load('16px PrimeBuilderInter')).length > 0);
    expect(fontLoaded).toBe(true);
    await editor.screenshot({ path: test.info().outputPath('builder-editor-desktop.png'), fullPage: true });
    await editor.close();
    editor = undefined;
    await css.fill('--pb-brand: url(https://example.test/font);');
    expect(await submit()).toBe(422);
    await expect(page.getByLabel('CSS de l’éditeur', { exact: true })).toHaveAttribute('aria-invalid', 'true');
  } finally {
    if (editor) await editor.close();
    await page.goto('/admin/prime-builder/settings');
    await font.selectOption(original.font);
    await css.fill(original.css);
    expect(await submit()).toBe(302);
    await expect(font).toHaveValue(original.font);
    await expect(css).toHaveValue(original.css);
  }
});
