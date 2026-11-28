import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const accessible = async page => {
  const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
  expect(result.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => ({ id: v.id, targets: v.nodes.map(node => node.target) }))).toEqual([]);
};

test('table of contents follows rich text headings through visual editing and publication', async ({ page, browser }) => {
  test.setTimeout(180_000);
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
  const element = (id, type, settings = {}, children = [], extra = {}) => ({ id, type, settings, styles: {}, children, ...extra });
  let entryId = null;
  try {
    const created = await api('/api/v1/content/page', 'POST', {
      title: 'Plan du texte riche', slug: 'pb-sommaire-texte-riche', status: 'draft', content: { version: 1, blocks: [] },
    });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;
    const elements = [
      element('toc', 'table_of_contents', { label: 'Plan du texte', min_level: 2, max_level: 4 }),
      element('manual', 'anchor', { anchor: 'pb-toc-article-rich-1' }),
      element('article', 'text', { text: '<h2>Notre <em>histoire</em></h2><p>Début du texte.</p><h3>Étape &amp; suite</h3><h4>Le détail</h4><table><tbody><tr><th>Nom</th><td>Valeur</td></tr></tbody></table>' }),
      element('second', 'text', { text: '<h2>Une autre section</h2><p>Une suite.</p>' }),
      element('hidden', 'container', {}, [element('hidden-text', 'text', { text: '<h2>Titre masqué</h2>' })], { hidden: true }),
    ];
    const content = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements } }] };
    const saved = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content, status: 'published', published_at: '' });
    expect(saved.status, saved.body).toBe(200);
    expect(saved.body).not.toContain('pb-toc-article-rich-1-2');

    await page.goto(`/admin/prime-builder/edit/${entryId}`);
    const frame = page.frameLocator('[data-pb-frame]');
    const toc = frame.getByRole('navigation', { name: 'Plan du texte' });
    await expect(toc.getByRole('link', { name: 'Notre histoire' })).toHaveAttribute('href', '#pb-toc-article-rich-1-2');
    await expect(toc.getByRole('link', { name: 'Étape & suite' })).toHaveAttribute('href', '#pb-toc-article-rich-2');
    await expect(toc.getByRole('link', { name: 'Le détail' })).toHaveAttribute('href', '#pb-toc-article-rich-3');
    await expect(toc.getByRole('link', { name: 'Une autre section' })).toHaveAttribute('href', '#pb-toc-second-rich-1');
    await expect(toc.getByRole('link', { name: 'Titre masqué' })).toHaveCount(0);
    await expect(frame.locator('#pb-toc-article-rich-1-2')).toHaveText('Notre histoire');
    await expect(frame.locator('[data-pb-id="article"] table td')).toHaveText('Valeur');

    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="article"]').click();
    await page.getByRole('button', { name: 'Éditeur visuel' }).click();
    const editable = page.getByRole('textbox', { name: 'Texte', exact: true });
    await expect(editable.locator('h2')).toHaveText('Notre histoire');
    const fullPreview = page.waitForResponse(response => response.url().endsWith('/render') && response.request().postData()?.includes('Titre actualisé'));
    await editable.locator('h2').evaluate(node => {
      const selection = node.ownerDocument.getSelection();
      const range = node.ownerDocument.createRange();
      range.selectNodeContents(node);
      selection.removeAllRanges();
      selection.addRange(range);
      node.closest('[contenteditable]').focus();
    });
    await page.keyboard.insertText('Titre actualisé');
    const response = await fullPreview;
    expect(response.ok()).toBeTruthy();
    expect(response.request().postDataJSON()).not.toHaveProperty('target');
    await expect(toc.getByRole('link', { name: 'Titre actualisé' })).toHaveAttribute('href', '#pb-toc-article-rich-1-2');
    await expect(toc.getByRole('link', { name: 'Notre histoire' })).toHaveCount(0);
    await expect(frame.locator('#pb-toc-article-rich-1-2')).toHaveText('Titre actualisé');
    await accessible(page);
    await page.screenshot({ path: test.info().outputPath('toc-rich-editor-desktop.jpg'), quality: 15 });
    await page.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await accessible(page);
    await page.screenshot({ path: test.info().outputPath('toc-rich-editor-mobile.jpg'), quality: 15 });
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.locator('[data-pb-save]').click();
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await page.reload();
    await expect(frame.getByRole('navigation', { name: 'Plan du texte' }).getByRole('link', { name: 'Titre actualisé' })).toBeVisible();
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="article"]').click();
    await expect(page.getByLabel('Texte', { exact: true })).not.toHaveValue(/pb-toc-/);

    await page.goto('/pb-sommaire-texte-riche');
    const publicToc = page.getByRole('navigation', { name: 'Plan du texte' });
    const link = publicToc.getByRole('link', { name: 'Titre actualisé' });
    await expect(link).toHaveAttribute('href', '#pb-toc-article-rich-1-2');
    await expect(page.locator('link[href*="/css/widgets/table_of_contents.css"]')).toHaveCount(1);
    await expect(page.locator('script[src*="/js/table_of_contents.js"]')).toHaveCount(0);
    await link.focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/#pb-toc-article-rich-1-2$/);
    const target = page.locator('#pb-toc-article-rich-1-2');
    await expect(target).toBeFocused();
    await expect.poll(() => target.evaluate(node => parseFloat(getComputedStyle(node).outlineWidth))).toBeGreaterThanOrEqual(3);
    await expect(target).toHaveCSS('outline-style', 'solid');
    await expect(page.locator('[data-pb-id="article"] table td')).toHaveText('Valeur');
    await accessible(page);
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: test.info().outputPath('toc-rich-public-desktop.jpg'), fullPage: true, quality: 15 });
    await page.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await accessible(page);
    await page.screenshot({ path: test.info().outputPath('toc-rich-public-mobile.jpg'), fullPage: true, quality: 15 });

    const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
    try {
      const fallback = await noJs.newPage();
      await fallback.goto('/pb-sommaire-texte-riche');
      const fallbackLink = fallback.getByRole('navigation', { name: 'Plan du texte' }).getByRole('link', { name: 'Titre actualisé' });
      await fallbackLink.focus(); await fallback.keyboard.press('Enter');
      await expect(fallback).toHaveURL(/#pb-toc-article-rich-1-2$/);
      await expect(fallback.locator('#pb-toc-article-rich-1-2')).toBeFocused();
      await expect(fallback.locator('[data-pb-id="article"] table td')).toHaveText('Valeur');
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

test('large tables of contents report their limit without removing page headings', async ({ page, browser }) => {
  test.setTimeout(120_000);
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
  let entryId = null;
  try {
    const created = await api('/api/v1/content/page', 'POST', {
      title: 'Long sommaire', slug: 'pb-sommaire-limite', status: 'draft', content: { version: 1, blocks: [] },
    });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;
    const headings = Array.from({ length: 201 }, (_, index) => `<h2>Titre ${index + 1}</h2>`).join('') + '<h4>Détail tardif</h4>';
    const element = (id, type, settings) => ({ id, type, settings, styles: {}, children: [] });
    const content = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: [
      element('toc', 'table_of_contents', { label: 'Sommaire borné', min_level: 2, max_level: 4 }),
      element('deep', 'table_of_contents', { label: 'Détails', min_level: 4, max_level: 4 }),
      element('article', 'text', { text: headings }),
    ] } }] };
    const saved = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content, status: 'published', published_at: '' });
    expect(saved.status, saved.body).toBe(200);
    await page.goto('/pb-sommaire-limite');
    const toc = page.getByRole('navigation', { name: 'Sommaire borné' });
    await expect(toc.getByRole('link')).toHaveCount(200);
    await expect(toc.getByText('Sommaire limité aux 200 premiers titres reconnus du document.')).toBeVisible();
    await expect(page.locator('.pb-toc__limit')).toHaveCount(2);
    await expect(page.locator('.pb-toc-empty')).toHaveText('Aucun titre à afficher dans ce sommaire.');
    await expect(page.locator('[data-pb-id="article"] h2')).toHaveCount(201);
    await expect(page.locator('#pb-toc-article-rich-200')).toHaveText('Titre 200');
    await expect(page.locator('#pb-toc-article-rich-201')).toHaveCount(0);
    await expect(page.locator('[data-pb-id="article"] h2').last()).toHaveText('Titre 201');
    await expect(page.getByRole('heading', { name: 'Détail tardif' })).toBeVisible();
    await accessible(page);
    const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
    try {
      const fallback = await noJs.newPage();
      await fallback.goto('/pb-sommaire-limite');
      await expect(fallback.getByRole('navigation', { name: 'Sommaire borné' }).getByRole('link')).toHaveCount(200);
      await expect(fallback.locator('.pb-toc__limit')).toHaveCount(2);
      await expect(fallback.getByRole('heading', { name: 'Titre 201', exact: true })).toBeVisible();
    } finally { await noJs.close(); }
  } finally {
    if (entryId !== null) {
      const trashed = await api(`/api/v1/content/page/${entryId}`, 'DELETE');
      expect(trashed.status, trashed.body).toBe(200);
      const deleted = await api(`/api/v1/content/page/${entryId}?force=1`, 'DELETE');
      expect(deleted.status, deleted.body).toBe(204);
    }
  }
});
