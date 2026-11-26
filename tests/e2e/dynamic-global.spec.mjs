import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

async function login(page) {
  await page.goto('/admin/login');
  await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/), page.locator('form button[type=submit]').click()]);
  return page.locator('meta[name="csrf-token"]').getAttribute('content');
}
function api(page, csrf, url, method, body) {
  return page.evaluate(async ({ url, method, body, csrf }) => {
    const response = await fetch(url, {
      method, headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf, Accept: 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    return { status: response.status, body: await response.text() };
  }, { url, method, body, csrf });
}
async function audit(page) {
  const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
  expect(result.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => `${v.id}: ${v.nodes.map(n => n.target.join(' ')).join(', ')}`)).toEqual([]);
}
async function removeEntry(page, csrf, id) {
  const removed = await api(page, csrf, `/api/v1/content/page/${id}`, 'DELETE');
  expect(removed.status, removed.body).toBe(200);
  const deleted = await api(page, csrf, `/api/v1/content/page/${id}?force=1`, 'DELETE');
  expect(deleted.status, deleted.body).toBe(204);
}

test('dynamic tags, validated conditions and all three responsive previews', async ({ page, browser }) => {
  test.setTimeout(180_000);
  page.setDefaultTimeout(25_000);
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const csrf = await login(page);
  const suffix = `${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;
  const title = `Dynamique ${suffix}`;
  const slug = `pb-dynamique-${suffix}`;
  let id = null;
  try {
    const created = await api(page, csrf, '/api/v1/content/page', 'POST', {
      title, slug, status: 'draft', content: { version: 1, blocks: [] },
    });
    expect(created.status, created.body).toBe(201);
    id = JSON.parse(created.body).data.id;
    await page.goto(`/admin/prime-builder/edit/${id}`);
    await page.getByRole('button', { name: 'Ajouter Titre', exact: true }).click();
    const heading = page.locator('[data-pb-setting="text"]');
    await heading.fill('Bonjour ');
    await page.locator('[data-pb-dynamic-for="text"]').selectOption('{{entry.title}}');
    await page.locator('[data-pb-dynamic-insert="text"]').click();
    await expect(heading).toHaveValue('Bonjour {{entry.title}}');
    const frame = page.frameLocator('[data-pb-frame]');
    const canvasHeading = frame.locator('[data-pb-editor-canvas] .pb-heading h2');
    await expect(canvasHeading).toHaveText(`Bonjour ${title}`);

    // The rich-text control must retain its caret while the picker receives focus.
    await page.locator('[data-pb-panel="widgets"]').click();
    await page.getByRole('button', { name: 'Ajouter Texte', exact: true }).click();
    await page.getByRole('button', { name: 'Éditeur visuel', exact: true }).click();
    const visual = page.locator('[data-pb-rich-editable="text"]');
    await visual.fill('Texte riche ');
    await page.locator('[data-pb-dynamic-for="text"]').selectOption('{{entry.title}}');
    await page.locator('[data-pb-dynamic-insert="text"]').click();
    await expect(visual).toContainText('{{entry.title}}');
    await expect(frame.locator('[data-pb-editor-canvas] .pb-text')).toContainText(`Texte riche ${title}`);

    // A URL tag replaces the whole address and is resolved and filtered server-side.
    await page.locator('[data-pb-panel="widgets"]').click();
    await page.getByRole('button', { name: 'Ajouter Bouton', exact: true }).click();
    await page.locator('[data-pb-setting="text"]').fill('Voir le contenu');
    await page.locator('[data-pb-dynamic-for="url"]').selectOption('{{entry.url}}');
    await page.locator('[data-pb-dynamic-insert="url"]').click();
    await expect(page.locator('[data-pb-setting="url"]')).toHaveValue('{{entry.url}}');
    await expect(frame.locator('[data-pb-editor-canvas] .pb-button a')).toHaveAttribute('href', `/${slug}`);

    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select]').first().click();
    await page.locator('[data-pb-tab="advanced"]').click();
    await page.locator('[data-pb-condition="device"]').selectOption('mobile');
    await page.locator('[data-pb-condition="role"]').selectOption('administrator');
    await page.locator('[data-pb-condition="start"]').fill('2001-01-01T00:00');
    await page.locator('[data-pb-condition="end"]').fill('2000-01-01T00:00');
    await expect(page.locator('.pb-condition-error')).toContainText('Vérifiez les conditions');
    await page.locator('[data-pb-save]').click();
    await expect(page.locator('[data-pb-save-state]')).toContainText('Vérifiez les conditions');
    await expect(page.locator('[data-pb-condition="auth"]')).toBeFocused();
    await page.locator('[data-pb-condition="end"]').fill('2999-01-01T00:00');
    await expect(page.locator('.pb-condition-error')).toBeEmpty();
    await page.locator('[data-pb-device="desktop"]').click();
    await expect(canvasHeading).toBeHidden();
    await page.locator('[data-pb-device="tablet"]').click();
    await expect(canvasHeading).toBeHidden();
    await page.locator('[data-pb-device="mobile"]').click();
    await expect(canvasHeading).toBeVisible();
    await audit(page);
    await page.locator('[data-pb-status]').selectOption('published');
    await page.locator('[data-pb-save]').click();
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');

    await page.setViewportSize({ width: 390, height: 844 });
    const published = await page.goto(`/${slug}`);
    expect(published.status()).toBe(200);
    expect(published.headers()['cache-control']).toContain('no-store');
    const publishedHeading = page.getByRole('heading', { name: `Bonjour ${title}` });
    await expect(publishedHeading).toBeVisible();
    await expect(page.locator('.pb-text')).toContainText(`Texte riche ${title}`);
    await page.setViewportSize({ width: 900, height: 844 });
    await expect(page.locator('.pb-heading h2', { hasText: `Bonjour ${title}` })).toBeHidden();
    await page.setViewportSize({ width: 1440, height: 900 });
    await expect(page.locator('.pb-heading h2', { hasText: `Bonjour ${title}` })).toBeHidden();
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(publishedHeading).toBeVisible();
    const anonymous = await browser.newContext({ baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080', viewport: { width: 390, height: 844 }, javaScriptEnabled: false });
    try {
      const guest = await anonymous.newPage();
      const response = await guest.goto(`/${slug}`);
      expect(response.status()).toBe(200);
      expect(response.headers()['cache-control']).toContain('no-store');
      await expect(guest.getByRole('heading', { name: `Bonjour ${title}` })).toHaveCount(0);
      await expect(guest.locator('.pb-text')).toContainText(`Texte riche ${title}`);
    } finally { await anonymous.close(); }
    // Axe injects JavaScript, so audit in a separate normal browser context.
    const auditContext = await browser.newContext({ baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080', viewport: { width: 390, height: 844 } });
    try {
      const auditPage = await auditContext.newPage();
      await auditPage.goto(`/${slug}`);
      await audit(auditPage);
    } finally { await auditContext.close(); }
    expect(errors).toEqual([]);
  } finally {
    if (id !== null) {
      await page.goto('/admin/prime-builder');
      await removeEntry(page, csrf, id);
    }
  }
});

test('a visually edited global widget updates all page references', async ({ page }) => {
  test.setTimeout(180_000);
  page.setDefaultTimeout(25_000);
  const csrf = await login(page);
  const suffix = `${Date.now()}-${Math.random().toString(16).slice(2, 8)}`;
  const name = `Widget partagé ${suffix}`;
  const ids = [];
  let global = null;
  try {
    await page.goto('/admin/prime-builder/theme');
    const form = page.locator('form[action="/admin/prime-builder/theme/create"]');
    await form.getByLabel('Nom du modèle').fill(name);
    await form.getByLabel('Type de modèle').selectOption('global');
    await form.getByRole('button', { name: 'Créer un modèle' }).click();
    await expect(page).toHaveURL(/\/admin\/prime-builder\/theme\/[a-f0-9]{32}$/);
    global = page.url().split('/').pop();
    await page.goto(`/admin/prime-builder/theme/${global}/edit`);
    await page.getByRole('button', { name: 'Ajouter Titre', exact: true }).click();
    await page.locator('[data-pb-setting="text"]').fill('Version partagée initiale');
    await page.locator('[data-pb-save]').click();
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await page.goto(`/admin/prime-builder/theme/${global}`);
    await page.getByLabel('Activer ce modèle sur le site').check();
    await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    for (let number = 1; number <= 2; number++) {
      const slug = `pb-global-${suffix}-${number}`;
      const created = await api(page, csrf, '/api/v1/content/page', 'POST', {
        title: `Page globale ${number} ${suffix}`, slug, status: 'draft', content: { version: 1, blocks: [] },
      });
      expect(created.status, created.body).toBe(201);
      const id = JSON.parse(created.body).data.id; ids.push({ id, slug });
      await page.goto(`/admin/prime-builder/edit/${id}`);
      await page.getByRole('button', { name: 'Ajouter Widget global', exact: true }).click();
      await page.locator('[data-pb-setting="global_id"]').selectOption(global);
      await expect(page.frameLocator('[data-pb-frame]').locator('[data-pb-editor-canvas] .pb-heading h2')).toHaveText('Version partagée initiale');
      await page.locator('[data-pb-status]').selectOption('published');
      await page.locator('[data-pb-save]').click();
      await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    }
    await page.goto(`/admin/prime-builder/theme/${global}/edit`);
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select]').first().click();
    await page.locator('[data-pb-setting="text"]').fill('Version partagée modifiée');
    await page.locator('[data-pb-save]').click();
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    for (const { slug } of ids) {
      await page.goto(`/${slug}`);
      await expect(page.getByRole('heading', { name: 'Version partagée modifiée' })).toBeVisible();
      await expect(page.getByRole('heading', { name: 'Version partagée initiale' })).toHaveCount(0);
    }
  } finally {
    await page.goto('/admin/prime-builder/theme');
    if (global !== null) {
      const form = page.locator(`form[action="/admin/prime-builder/theme/${global}/delete"]`);
      if (await form.count()) await form.locator('button').click();
    }
    for (const { id } of ids) await removeEntry(page, csrf, id);
  }
});
