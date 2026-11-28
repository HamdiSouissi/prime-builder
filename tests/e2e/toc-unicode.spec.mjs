import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('table of contents ignores invisible-only headings while retaining private-use characters', async ({ page, browser }) => {
  test.setTimeout(90_000);
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
      title: 'Sommaire Unicode', slug: 'pb-sommaire-unicode', status: 'draft', content: { version: 1, blocks: [] },
    });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;
    const invisible = '\u200d\u034f\ufe0f';
    const privateUse = '\ue000';
    const element = (id, type, settings) => ({ id, type, settings, styles: {}, children: [] });
    const content = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: [
      element('toc', 'table_of_contents', { label: 'Sommaire Unicode' }),
      element('invisible', 'heading', { text: invisible }),
      element('private', 'heading', { text: privateUse }),
      element('article', 'text', { text: `<h2>${invisible}</h2><h2>${privateUse}</h2><h2>Neige ❄️</h2>` }),
    ] } }] };
    const saved = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content, status: 'published', published_at: '' });
    expect(saved.status, saved.body).toBe(200);
    await page.goto('/pb-sommaire-unicode');
    const toc = page.getByRole('navigation', { name: 'Sommaire Unicode' });
    await expect(toc.getByRole('link')).toHaveCount(3);
    await expect(toc.locator('a[href="#pb-toc-private"]')).toHaveText(privateUse);
    await expect(toc.locator('a[href="#pb-toc-article-rich-2"]')).toHaveText(privateUse);
    await expect(toc.locator('a[href="#pb-toc-invisible"]')).toHaveCount(0);
    await expect(toc.locator('a[href="#pb-toc-article-rich-1"]')).toHaveCount(0);
    await expect(page.locator('[data-pb-id="article"] h2').first()).toHaveText(invisible);
    const link = toc.getByRole('link', { name: 'Neige ❄️' });
    await link.focus(); await page.keyboard.press('Enter');
    await expect(page.locator('#pb-toc-article-rich-3')).toBeFocused();
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(results.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => v.id)).toEqual([]);
    const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
    try {
      const fallback = await noJs.newPage();
      await fallback.goto('/pb-sommaire-unicode');
      const fallbackToc = fallback.getByRole('navigation', { name: 'Sommaire Unicode' });
      await expect(fallbackToc.getByRole('link')).toHaveCount(3);
      await fallbackToc.getByRole('link', { name: 'Neige ❄️' }).focus();
      await fallback.keyboard.press('Enter');
      await expect(fallback.locator('#pb-toc-article-rich-3')).toBeFocused();
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
