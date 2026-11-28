import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('table of contents follows visible rich fields in other widgets', async ({ page, browser }) => {
  test.setTimeout(180_000);
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
  const element = (id, type, settings = {}, children = [], extra = {}) => ({ id, type, settings, styles: {}, children, ...extra });
  const slug = `pb-toc-widget-rich-${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;
  let entryId = null;
  try {
    const created = await api('/api/v1/content/page', 'POST', {
      title: 'Plan des champs de widgets', slug, status: 'draft', content: { version: 1, blocks: [] },
    });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;
    const elements = [
      element('toc', 'table_of_contents', { label: 'Plan du jardin', min_level: 2, max_level: 4 }),
      element('reserved', 'anchor', { anchor: 'pb-toc-card-rich-1' }),
      element('card', 'card', { title: 'Carte du jardin', text: '<h2>Histoire du <em>jardin</em></h2><p>Un jardin vivant.</p>' }),
      element('alert', 'alert', { text: '<h3>Conseil pratique</h3><p>Arroser le matin.</p>' }),
      element('audio', 'audio', { transcript: '<h4>Résumé audio</h4><p>Transcription complète.</p>' }),
      element('disclosure', 'accordion', { title: 'Détails à ouvrir', text: '<h3>Titre replié</h3><p>Information masquable.</p>' }),
      element('hidden', 'container', {}, [element('hidden-alert', 'alert', { text: '<h3>Titre invisible</h3>' })], { hidden: true }),
    ];
    const content = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements } }] };
    const saved = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content, status: 'published', published_at: '' });
    expect(saved.status, saved.body).toBe(200);
    expect(saved.body).not.toContain('pb-toc-card-rich-1-2');
    // Direct subtree previews use the same collision-aware outline as the full document.
    const targetedToc = await api(`/api/prime-builder/v1/documents/${entryId}/render`, 'POST', { data: content.blocks[0].data, target: 'toc' });
    expect(targetedToc.status, targetedToc.body).toBe(200);
    expect(JSON.parse(targetedToc.body).data.html).toContain('href="#pb-toc-card-rich-1-2"');
    const targetedCard = await api(`/api/prime-builder/v1/documents/${entryId}/render`, 'POST', { data: content.blocks[0].data, target: 'card' });
    expect(targetedCard.status, targetedCard.body).toBe(200);
    expect(JSON.parse(targetedCard.body).data.html).toContain('id="pb-toc-card-rich-1-2"');

    await page.goto(`/admin/prime-builder/edit/${entryId}`);
    const frame = page.frameLocator('[data-pb-frame]');
    const toc = frame.getByRole('navigation', { name: 'Plan du jardin' });
    await expect(toc.getByRole('link')).toHaveText(['Carte du jardin', 'Histoire du jardin', 'Conseil pratique', 'Résumé audio']);
    await expect(toc.getByRole('link', { name: 'Histoire du jardin' })).toHaveAttribute('href', '#pb-toc-card-rich-1-2');
    await expect(frame.locator('#pb-toc-card-rich-1-2')).toHaveText('Histoire du jardin');
    await expect(toc.getByRole('link', { name: 'Titre replié' })).toHaveCount(0);
    await expect(toc.getByRole('link', { name: 'Titre invisible' })).toHaveCount(0);

    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="card"]').click();
    const text = page.getByLabel('Texte', { exact: true });
    const updatedPreview = page.waitForResponse(response => response.url().endsWith('/render') && response.request().postData()?.includes('Culture renouvelée'));
    await text.fill('<h2>Culture renouvelée</h2><p>Un jardin vivant.</p>');
    const response = await updatedPreview;
    expect(response.status()).toBe(200);
    expect(response.request().postDataJSON()).not.toHaveProperty('target');
    await expect(text).toBeFocused();
    await expect(toc.getByRole('link', { name: 'Culture renouvelée' })).toHaveAttribute('href', '#pb-toc-card-rich-1-2');
    await expect(toc.getByRole('link', { name: 'Histoire du jardin' })).toHaveCount(0);
    await expect(frame.locator('#pb-toc-card-rich-1-2')).toHaveText('Culture renouvelée');
    await accessible(page);
    await page.screenshot({ path: test.info().outputPath('toc-widget-editor-desktop.jpg'), quality: 15, fullPage: true });
    await page.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await accessible(page);
    await page.screenshot({ path: test.info().outputPath('toc-widget-editor-mobile.jpg'), quality: 15, fullPage: true });
    await page.setViewportSize({ width: 1440, height: 900 });
    const saving = page.waitForResponse(result => result.url().endsWith(`/documents/${entryId}`) && result.request().method() === 'PATCH');
    await page.locator('[data-pb-save]').click();
    expect((await saving).status()).toBe(200);
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await page.reload();
    await expect(frame.getByRole('navigation', { name: 'Plan du jardin' }).getByRole('link', { name: 'Culture renouvelée' })).toBeVisible();
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="card"]').click();
    await expect(page.getByLabel('Texte', { exact: true })).not.toHaveValue(/pb-toc-/);

    await page.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
    const publicToc = page.getByRole('navigation', { name: 'Plan du jardin' });
    await expect(publicToc.getByRole('link')).toHaveText(['Carte du jardin', 'Culture renouvelée', 'Conseil pratique', 'Résumé audio']);
    const link = publicToc.getByRole('link', { name: 'Culture renouvelée' });
    await expect(link).toHaveAttribute('href', '#pb-toc-card-rich-1-2');
    await expect(page.locator('link[href*="/css/widgets/table_of_contents.css"]')).toHaveCount(1);
    await expect(page.locator('script[src*="/js/table_of_contents.js"]')).toHaveCount(0);
    await link.focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/#pb-toc-card-rich-1-2$/);
    const target = page.locator('#pb-toc-card-rich-1-2');
    await expect(target).toBeFocused();
    await expect.poll(() => target.evaluate(node => parseFloat(getComputedStyle(node).outlineWidth))).toBeGreaterThanOrEqual(3);
    await expect(target).toHaveCSS('outline-style', 'solid');
    await accessible(page);
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: test.info().outputPath('toc-widget-public-desktop.jpg'), quality: 15, fullPage: true });
    await page.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await accessible(page);
    await page.screenshot({ path: test.info().outputPath('toc-widget-public-mobile.jpg'), quality: 15, fullPage: true });

    const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080', viewport: { width: 375, height: 812 } });
    try {
      const fallback = await noJs.newPage();
      await fallback.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
      const fallbackToc = fallback.getByRole('navigation', { name: 'Plan du jardin' });
      await expect(fallbackToc.getByRole('link')).toHaveText(['Carte du jardin', 'Culture renouvelée', 'Conseil pratique', 'Résumé audio']);
      await fallbackToc.getByRole('link', { name: 'Culture renouvelée' }).focus();
      await fallback.keyboard.press('Enter');
      await expect(fallback).toHaveURL(/#pb-toc-card-rich-1-2$/);
      await expect(fallback.locator('#pb-toc-card-rich-1-2')).toBeFocused();
      await expect.poll(() => fallback.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    } finally { await noJs.close(); }
    expect(errors).toEqual([]);
  } finally {
    if (entryId !== null) {
      const trashed = await api(`/api/v1/content/page/${entryId}`, 'DELETE');
      expect(trashed.status, trashed.body).toBe(200);
      const deleted = await api(`/api/v1/content/page/${entryId}?force=1`, 'DELETE');
      expect(deleted.status, deleted.body).toBe(204);
    }
  }
});
