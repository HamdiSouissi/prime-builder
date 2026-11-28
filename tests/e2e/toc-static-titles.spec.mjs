import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('the table of contents follows supported static widget titles in preview and public output', async ({ page, browser }) => {
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
  const element = (id, type, settings = {}, children = [], extra = {}) => ({ id, type, settings, styles: {}, children, ...extra });
  let entryId = null;
  try {
    const created = await api('/api/v1/content/page', 'POST', {
      title: 'Sommaire des widgets', slug: 'pb-static-widget-toc', status: 'draft', content: { version: 1, blocks: [] },
    });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;
    const elements = [
      element('toc', 'table_of_contents', { label: 'Plan de la page', min_level: 2, max_level: 4 }),
      element('manual', 'anchor', { anchor: 'pb-toc-cta' }),
      element('intro', 'heading', { text: 'Bienvenue', level: 2 }),
      element('cta', 'call_to_action', { title: 'Découvrir ensemble', level: 3, text: '<p>Un projet commun.</p>' }),
      element('review', 'review', { title: 'Notre avis', level: 4, text: '<p>Une belle expérience.</p>' }),
      element('prices', 'price_list', { title: 'Nos tarifs', level: 3, items: 'Conseil | 25 €' }),
      element('later', 'countdown', { title: 'Prochaine ouverture', level: 4, deadline: '2030-01-02T03:04' }),
      element('no-date', 'countdown', { title: 'Sans date', deadline: '' }),
      element('hidden', 'container', {}, [element('hidden-review', 'review', { title: 'Avis masqué' })], { hidden: true }),
    ];
    const content = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements } }] };
    const saved = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content, status: 'published', published_at: '' });
    expect(saved.status, saved.body).toBe(200);

    await page.goto(`/admin/prime-builder/edit/${entryId}`);
    const frame = page.frameLocator('[data-pb-frame]');
    const preview = frame.getByRole('navigation', { name: 'Plan de la page' });
    await expect(preview.getByRole('link', { name: 'Découvrir ensemble' })).toHaveAttribute('href', '#pb-toc-cta-2');
    await expect(preview.getByRole('link', { name: 'Notre avis' })).toHaveAttribute('href', '#pb-toc-review');
    await expect(preview.getByRole('link', { name: 'Nos tarifs' })).toBeVisible();
    await expect(preview.getByRole('link', { name: 'Prochaine ouverture' })).toBeVisible();
    await expect(preview.getByRole('link', { name: 'Sans date' })).toHaveCount(0);
    await expect(preview.getByRole('link', { name: 'Avis masqué' })).toHaveCount(0);

    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="cta"]').click();
    const title = page.getByLabel('Titre', { exact: true });
    await title.fill('');
    await expect(preview.getByRole('link', { name: 'Découvrir ensemble' })).toHaveCount(0);
    await expect(frame.locator('[data-pb-id="cta"] h3')).toHaveCount(0);
    const fullPreview = page.waitForResponse(response => response.url().endsWith('/render') && response.request().postData()?.includes('Titre actualisé'));
    await title.fill('Titre actualisé');
    const rendered = await fullPreview;
    expect(rendered.ok()).toBeTruthy();
    expect(rendered.request().postDataJSON()).not.toHaveProperty('target');
    await expect(preview.getByRole('link', { name: 'Titre actualisé' })).toHaveAttribute('href', '#pb-toc-cta-2');
    await expect(preview.getByRole('link', { name: 'Découvrir ensemble' })).toHaveCount(0);
    await page.locator('[data-pb-save]').click();
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await page.reload();
    await expect(frame.getByRole('navigation', { name: 'Plan de la page' }).getByRole('link', { name: 'Titre actualisé' })).toBeVisible();

    await page.goto('/pb-static-widget-toc');
    const toc = page.getByRole('navigation', { name: 'Plan de la page' });
    const link = toc.getByRole('link', { name: 'Titre actualisé' });
    await expect(link).toHaveAttribute('href', '#pb-toc-cta-2');
    await expect(page.locator('link[href*="/css/widgets/table_of_contents.css"]')).toHaveCount(1);
    await expect(page.locator('script[src*="/js/table_of_contents.js"]')).toHaveCount(0);
    await link.focus();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/#pb-toc-cta-2$/);
    await expect(page.locator('#pb-toc-cta-2')).toBeFocused();
    const accessible = async () => {
      const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
      expect(result.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => ({ id: v.id, targets: v.nodes.map(node => node.target) }))).toEqual([]);
    };
    await accessible();
    await page.goto('/pb-static-widget-toc');
    await page.screenshot({ path: test.info().outputPath('toc-widget-public-desktop.jpg'), fullPage: true, quality: 15 });
    await page.setViewportSize({ width: 375, height: 812 });
    await expect(toc).toBeVisible();
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await accessible();
    await page.screenshot({ path: test.info().outputPath('toc-widget-public-mobile.jpg'), fullPage: true, quality: 15 });

    const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
    try {
      const fallback = await noJs.newPage();
      await fallback.goto('/pb-static-widget-toc');
      const fallbackLink = fallback.getByRole('navigation', { name: 'Plan de la page' }).getByRole('link', { name: 'Titre actualisé' });
      await fallbackLink.focus();
      await fallback.keyboard.press('Enter');
      await expect(fallback).toHaveURL(/#pb-toc-cta-2$/);
      await expect(fallback.locator('#pb-toc-cta-2')).toBeFocused();
      await expect(fallback.getByRole('navigation', { name: 'Plan de la page' }).getByRole('link', { name: 'Prochaine ouverture' })).toBeVisible();
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
