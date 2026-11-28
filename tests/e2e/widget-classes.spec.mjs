import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('widget CSS classes are editable, validated and retained across preview and publication', async ({ page, browser }) => {
  test.setTimeout(180_000);
  page.setDefaultTimeout(25_000);
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
  const accessible = async () => {
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => ({ id: v.id, targets: v.nodes.map(node => node.target) }))).toEqual([]);
  };
  const slug = `pb-widget-classes-${Date.now()}`;
  let entryId = null;
  try {
    const created = await api('/api/v1/content/page', 'POST', {
      title: 'Classes des widgets', slug, status: 'draft', content: { version: 1, blocks: [] },
    });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;
    const content = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: [{
      id: 'section', type: 'container', settings: { layout: 'flex', direction: 'column' }, styles: {}, children: [
        { id: 'title', type: 'heading', settings: { text: 'Titre personnalisé', level: 2 }, styles: {}, classes: 'seed', children: [] },
        { id: 'button', type: 'button', settings: { text: 'Découvrir', url: '/' }, styles: {}, children: [] },
      ],
    }] } }] };
    const prepared = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content, status: 'published', published_at: '' });
    expect(prepared.status, prepared.body).toBe(200);

    await page.goto(`/admin/prime-builder/edit/${entryId}`);
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="title"]').click();
    await page.getByRole('button', { name: 'Avancé', exact: true }).click();
    const control = page.getByRole('textbox', { name: 'Classes CSS', exact: true });
    const frame = page.frameLocator('[data-pb-frame]');
    const title = frame.locator('[data-pb-id="title"]');
    await expect(control).toHaveValue('seed');
    await expect(control).toHaveAccessibleDescription(/Classes de l’enveloppe.*communes à tous les écrans/);
    await control.focus();
    await page.keyboard.press('Control+A');
    await control.fill('theme-hero wide theme-hero');
    await expect(control).toBeFocused();
    await expect(control).toHaveAttribute('aria-invalid', 'false');
    await expect(title).toHaveClass('pb-element pb-heading theme-hero wide');
    await expect(frame.locator('[data-pb-id="button"]')).not.toHaveClass(/theme-hero/);

    await page.locator('[data-pb-undo]').focus();
    await page.keyboard.press('Control+z');
    await expect(control).toHaveValue('seed');
    await expect(title).toHaveClass('pb-element pb-heading seed');
    await page.keyboard.press('Control+Shift+z');
    await expect(control).toHaveValue('theme-hero wide');
    await expect(title).toHaveClass('pb-element pb-heading theme-hero wide');
    await page.getByRole('button', { name: 'Mobile', exact: true }).click();
    await expect(control).toHaveValue('theme-hero wide');
    await page.locator('[data-pb-action="duplicate"]').click();
    await expect(frame.locator('.pb-heading.theme-hero.wide')).toHaveCount(2);

    await control.fill('theme-hero pb-element');
    await expect(control).toHaveAttribute('aria-invalid', 'true');
    await expect(control).toHaveAccessibleDescription(/Classes CSS invalides/);
    await accessible();
    await page.locator('[data-pb-settings-panel]').screenshot({ path: test.info().outputPath('classes-invalid-desktop.jpg'), quality: 15 });
    // The invalid draft survives navigation; saving from another node returns
    // focus to the offending field rather than silently saving its old value.
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="button"]').click();
    await page.keyboard.press('Control+s');
    await expect(control).toBeFocused();
    await expect(control).toHaveValue('theme-hero pb-element');
    await expect(control).toHaveAttribute('aria-invalid', 'true');
    await expect(page.locator('[data-pb-save-state]')).toContainText('Classes CSS invalides');
    const untouched = await api(`/api/v1/content/page/${entryId}`, 'GET');
    expect(untouched.status, untouched.body).toBe(200);
    const before = JSON.parse(untouched.body).data.content.blocks[0].data.elements[0].children;
    expect(before).toHaveLength(2);
    expect(before[0].classes).toBe('seed');

    await control.fill('theme-hero wide');
    await expect(control).toHaveAttribute('aria-invalid', 'false');
    await expect(title).toHaveClass('pb-element pb-heading theme-hero wide');
    await page.setViewportSize({ width: 375, height: 812 });
    await control.scrollIntoViewIfNeeded();
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await accessible();
    await page.screenshot({ path: test.info().outputPath('classes-editor-mobile.jpg'), quality: 15, fullPage: true });
    await page.setViewportSize({ width: 1440, height: 900 });
    const saving = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click();
    const saved = await saving;
    expect(saved.status()).toBe(200);
    const nodes = (await saved.json()).data.content.blocks[0].data.elements[0].children;
    expect(nodes).toHaveLength(3);
    expect(nodes[0].classes).toBe('theme-hero wide');
    expect(nodes[1].classes).toBe('theme-hero wide');
    expect(nodes[1].id).not.toBe(nodes[0].id);
    expect(nodes[2]).not.toHaveProperty('classes');
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await page.reload();
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="title"]').click();
    await page.getByRole('button', { name: 'Avancé', exact: true }).click();
    await expect(control).toHaveValue('theme-hero wide');
    await expect(title).toHaveClass('pb-element pb-heading theme-hero wide');

    await page.goto(`/${slug}`);
    await expect(page.locator('.pb-heading.theme-hero.wide')).toHaveCount(2);
    await expect(page.locator('[data-pb-id="button"]')).not.toHaveClass(/theme-hero/);
    await accessible();
    await page.screenshot({ path: test.info().outputPath('classes-public-desktop.jpg'), quality: 15, fullPage: true });
    const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080', viewport: { width: 375, height: 812 } });
    try {
      const fallback = await noJs.newPage();
      await fallback.goto(`/${slug}`);
      await expect(fallback.locator('.pb-heading.theme-hero.wide')).toHaveCount(2);
      const link = fallback.getByRole('link', { name: 'Découvrir' });
      await link.focus();
      await expect(link).toBeFocused();
      await fallback.screenshot({ path: test.info().outputPath('classes-public-mobile-nojs.jpg'), quality: 15, fullPage: true });
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
