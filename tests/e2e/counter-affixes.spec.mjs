import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('counter affixes keep their spaces in the editor and on the public page', async ({ page, browser }) => {
  test.setTimeout(150_000);
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
  const slug = `pb-counter-affixes-${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;
  let entryId = null;
  try {
    const created = await api('/api/v1/content/page', 'POST', {
      title: 'Compteur et unités', slug, status: 'draft', content: { version: 1, blocks: [] },
    });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;
    await page.goto(`/admin/prime-builder/edit/${entryId}`);
    await page.locator('[data-pb-panel="widgets"]').click();
    await page.getByRole('button', { name: 'Ajouter Compteur', exact: true }).click();
    const prefix = page.getByLabel('Préfixe', { exact: true });
    const suffix = page.getByLabel('Suffixe', { exact: true });
    await page.getByLabel('Valeur', { exact: true }).fill('42');
    await prefix.fill('€ ');
    await suffix.fill(' kg');
    await page.getByLabel('Titre', { exact: true }).fill('Charge utile');
    const preview = page.frameLocator('[data-pb-frame]').locator('.pb-counter-value');
    await expect(preview).toHaveText('€ 42 kg');
    await expect.poll(() => preview.textContent()).toBe('€ 42 kg');
    await accessible(page);

    // Undo and redo the affix edit without losing the intentionally typed space.
    await suffix.fill(' tonnes');
    await expect(preview).toHaveText('€ 42 tonnes');
    await page.locator('[data-pb-undo]').focus();
    await page.keyboard.press('Control+z');
    await expect(suffix).toHaveValue(' kg');
    await expect(preview).toHaveText('€ 42 kg');
    await page.keyboard.press('Control+Shift+z');
    await expect(suffix).toHaveValue(' tonnes');
    await expect(preview).toHaveText('€ 42 tonnes');
    await page.keyboard.press('Control+z');
    await expect(suffix).toHaveValue(' kg');

    // Even an affix consisting only of a space survives rendering and storage.
    await prefix.fill(' ');
    await suffix.fill(' ');
    await expect.poll(() => preview.textContent()).toBe(' 42 ');
    const draftUpdate = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click();
    const draft = await draftUpdate;
    expect(draft.status()).toBe(200);
    const draftCounter = (await draft.json()).data.content.blocks[0].data.elements.find(element => element.type === 'counter');
    expect(draftCounter.settings).toMatchObject({ value: 42, prefix: ' ', suffix: ' ' });
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await page.reload();
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator(`[data-pb-tree-select="${draftCounter.id}"]`).click();
    await expect(prefix).toHaveValue(' ');
    await expect(suffix).toHaveValue(' ');
    await expect.poll(() => preview.textContent()).toBe(' 42 ');
    await prefix.fill('€ ');
    await suffix.fill(' kg');
    await expect.poll(() => preview.textContent()).toBe('€ 42 kg');

    await page.locator('[data-pb-status]').selectOption('published');
    const updated = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click();
    const response = await updated;
    expect(response.status()).toBe(200);
    const saved = await response.json();
    const counter = saved.data.content.blocks[0].data.elements.find(element => element.type === 'counter');
    expect(counter.settings).toMatchObject({ value: 42, prefix: '€ ', suffix: ' kg', title: 'Charge utile' });
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await page.reload();
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator(`[data-pb-tree-select="${counter.id}"]`).click();
    await expect(prefix).toHaveValue('€ ');
    await expect(suffix).toHaveValue(' kg');
    await expect(preview).toHaveText('€ 42 kg');
    await page.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await accessible(page);

    const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080', viewport: { width: 1440, height: 900 } });
    try {
      const publicPage = await noJs.newPage();
      await publicPage.goto(`/${slug}`, { waitUntil: 'domcontentloaded' });
      const value = publicPage.locator('.pb-counter-value');
      await expect(value).toHaveText('€ 42 kg');
      await expect.poll(() => value.textContent()).toBe('€ 42 kg');
      await expect(publicPage.getByText('Charge utile', { exact: true })).toBeVisible();
      await publicPage.setViewportSize({ width: 375, height: 812 });
      await expect(value).toBeVisible();
      await expect.poll(() => publicPage.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
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
