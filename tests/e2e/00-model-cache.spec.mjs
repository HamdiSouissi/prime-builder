import { test, expect } from '@playwright/test';

// Run on a fresh disposable CMS before 00-zone-cache: a saved declared zone
// intentionally disables the core cache because the CMS exposes no save hook.
test('real page cache refreshes theme and global models without sharing visitor-specific markup', async ({ page, browser }) => {
  test.setTimeout(180_000);
  const suffix = Date.now().toString(36);
  const models = [];
  let entryId = null;
  let menuId = null;
  let originalCache = null;
  let visitorContext;
  const heading = text => ({ id: 'heading', type: 'heading', settings: { text, level: 2 }, styles: {}, children: [] });
  const globalRef = id => ({ id: 'shared', type: 'global_widget', settings: { global_id: id }, styles: {}, children: [] });
  const layout = elements => ({ version: 1, elements });
  const document = elements => ({ version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: layout(elements) }] });
  async function api(url, method, body) {
    return page.evaluate(async ({ url, method, body }) => {
      const csrf = document.querySelector('meta[name="csrf-token"]').content;
      const response = await fetch(url, { method, headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf, Accept: 'application/json' }, ...(body === undefined ? {} : { body: JSON.stringify(body) }) });
      return { status: response.status, body: await response.text() };
    }, { url, method, body });
  }
  async function createModel(kind) {
    await page.goto('/admin/prime-builder/theme');
    const form = page.locator('form[action="/admin/prime-builder/theme/create"]');
    await form.getByLabel('Nom du modèle').fill(`Cache ${kind} ${suffix}`);
    await form.getByLabel('Type de modèle').selectOption(kind);
    await form.getByRole('button', { name: 'Créer un modèle' }).click();
    await expect(page).toHaveURL(/\/admin\/prime-builder\/theme\/[a-f0-9]{32}$/);
    const id = page.url().split('/').pop();
    models.push(id);
    return id;
  }
  async function saveModel(id, elements) {
    await page.goto(`/admin/prime-builder/theme/${id}/edit`);
    const config = JSON.parse(await page.locator('[data-pb-config]').inputValue());
    const response = await api(`/api/prime-builder/v1/theme-models/${id}`, 'PATCH', { content: document(elements), source_hash: config.sourceHash });
    expect(response.status, response.body).toBe(200);
  }
  async function enableModel(id, hideLoggedIn = false) {
    await page.goto(`/admin/prime-builder/theme/${id}`);
    await page.getByLabel('Activer ce modèle sur le site').check();
    if (hideLoggedIn) await page.getByLabel('Ne pas afficher aux visiteurs connectés').check();
    await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(page.getByLabel('Activer ce modèle sur le site')).toBeChecked();
  }
  async function saveMenu(label) {
    await page.goto(`/admin/appearance/menus?menu=${menuId}`);
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const response = await page.request.post(`/admin/appearance/menus/${menuId}/save`, {
      headers: { Accept: 'application/json', 'X-CSRF-Token': csrf },
      data: { name: `Menu cache ${suffix}`, items: [{ type: 'url', label, url: '/blog' }] },
    });
    expect(response.status(), await response.text()).toBe(200);
  }
  await page.goto('/admin/login');
  await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/, { timeout: 15_000 }), page.locator('form button[type=submit]').click()]);
  try {
    const settings = await api('/api/v1/settings', 'GET');
    expect(settings.status, settings.body).toBe(200);
    originalCache = JSON.parse(settings.body).data['cache.page_enabled'];
    const enabled = await api('/api/v1/settings', 'PATCH', { 'cache.page_enabled': true });
    expect(enabled.status, enabled.body).toBe(200);
    await page.goto('/admin/appearance/menus');
    const createMenu = page.locator('form[action="/admin/appearance/menus/create"]');
    await createMenu.locator('input[name=name]').fill(`Menu cache ${suffix}`);
    await createMenu.locator('button[type=submit]').click();
    menuId = new URL(page.url()).searchParams.get('menu');
    expect(menuId).toMatch(/^[1-9][0-9]*$/);
    await saveMenu('Menu avant');

    const global = await createModel('global');
    await saveModel(global, [heading('Widget global avant')]);
    await enableModel(global);
    const header = await createModel('header');
    const staticHeader = text => [heading(text), globalRef(global), { id: 'nav', type: 'navigation', settings: { menu_id: menuId, label: 'Navigation de test' }, styles: {}, children: [] }];
    await saveModel(header, staticHeader('En-tête avant'));
    await enableModel(header);
    const created = await api('/api/v1/content/page', 'POST', { title: `Cache ${suffix}`, slug: `pb-cache-${suffix}`, status: 'published', content: { version: 1, blocks: [] } });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;
    const saved = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content: document([globalRef(global)]) });
    expect(saved.status, saved.body).toBe(200);
    visitorContext = await browser.newContext({ baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080', javaScriptEnabled: false });
    const visitor = await visitorContext.newPage();
    const path = `/pb-cache-${suffix}`;
    let response = await visitor.goto(path);
    expect(response.headers()['x-prime-cache']).toBe('MISS');
    await expect(visitor.locator('header.pb-theme-header')).toContainText('En-tête avant');
    await expect(visitor.getByText('Widget global avant')).toHaveCount(2);
    await expect(visitor.getByText('Menu avant')).toHaveCount(1);
    response = await visitor.reload();
    expect(response.headers()['x-prime-cache']).toBe('HIT');

    await saveModel(global, [heading('Widget global après')]);
    response = await visitor.reload();
    expect(response.headers()['x-prime-cache']).toBe('MISS');
    await expect(visitor.getByText('Widget global avant')).toHaveCount(0);
    await expect(visitor.getByText('Widget global après')).toHaveCount(2);
    response = await visitor.reload();
    expect(response.headers()['x-prime-cache']).toBe('HIT');

    // A referenced global is evaluated in both the header and the entry. A
    // future start still prevents a cached page from outliving that boundary.
    await saveModel(global, [{ ...heading('Widget programmé'), conditions: { start: '2999-01-01T00:00' } }]);
    for (let attempt = 0; attempt < 2; attempt++) {
      response = await visitor.reload();
      expect(response.headers()['x-prime-cache']).toBe('BYPASS');
      await expect(visitor.getByText('Widget programmé')).toHaveCount(0);
    }
    await saveModel(global, [heading('Widget global après')]);
    response = await visitor.reload();
    expect(response.headers()['x-prime-cache']).toBe('MISS');
    response = await visitor.reload();
    expect(response.headers()['x-prime-cache']).toBe('HIT');
    await saveMenu('Menu après');
    response = await visitor.reload();
    expect(response.headers()['x-prime-cache']).toBe('MISS');
    await expect(visitor.getByText('Menu avant')).toHaveCount(0);
    await expect(visitor.getByText('Menu après')).toHaveCount(1);
    response = await visitor.reload();
    expect(response.headers()['x-prime-cache']).toBe('HIT');
    await saveModel(header, staticHeader('En-tête après'));
    response = await visitor.reload();
    expect(response.headers()['x-prime-cache']).toBe('MISS');
    await expect(visitor.locator('header.pb-theme-header')).toContainText('En-tête après');
    response = await visitor.reload();
    expect(response.headers()['x-prime-cache']).toBe('HIT');

    // The active header is part of this page, unlike an unrelated model.
    const currentHeader = { ...heading('En-tête daté'), id: 'dated', conditions: { end: '2999-01-01T00:00' } };
    await saveModel(header, [...staticHeader('En-tête après'), currentHeader]);
    for (let attempt = 0; attempt < 2; attempt++) {
      response = await visitor.reload();
      expect(response.headers()['x-prime-cache']).toBe('BYPASS');
      await expect(visitor.getByText('En-tête daté')).toBeVisible();
    }
    await saveModel(header, [...staticHeader('En-tête après'), { ...heading('En-tête {{today}}'), id: 'today' }]);
    response = await visitor.reload();
    expect(response.headers()['x-prime-cache']).toBe('BYPASS');
    await expect(visitor.getByText('En-tête', { exact: false }).first()).toBeVisible();
    await saveModel(header, staticHeader('En-tête après'));

    const privateHeading = { ...heading('Privé {{user.name}}'), id: 'private', conditions: { auth: 'authenticated' } };
    await saveModel(header, [...staticHeader('En-tête après'), privateHeading]);
    response = await visitor.reload();
    expect(response.headers()['x-prime-cache']).toBe('MISS');
    expect(response.headers()['cache-control'] || '').not.toContain('no-store');
    await expect(visitor.getByText('Privé', { exact: false })).toHaveCount(0);
    response = await visitor.reload();
    expect(response.headers()['x-prime-cache']).toBe('HIT');
    response = await page.goto(path);
    expect(response.headers()['x-prime-cache']).not.toBe('HIT');
    expect(response.headers()['cache-control']).toContain('no-store');
    await expect(page.getByText('Privé CI Admin')).toBeVisible();
    response = await visitor.reload();
    expect(response.headers()['x-prime-cache']).toBe('HIT');
    await expect(visitor.getByText('Privé', { exact: false })).toHaveCount(0);
    await saveModel(header, staticHeader('En-tête après'));

    const popup = await createModel('popup');
    await saveModel(popup, [heading('Offre visiteurs')]);
    await enableModel(popup, true);
    response = await visitor.reload();
    expect(response.headers()['x-prime-cache']).toBe('MISS');
    expect(response.headers()['cache-control'] || '').not.toContain('no-store');
    await expect(visitor.locator(`[data-pb-popup="${popup}"]`)).toHaveCount(1);
    response = await visitor.reload();
    expect(response.headers()['x-prime-cache']).toBe('HIT');
    expect(response.headers()['cache-control'] || '').not.toContain('no-store');
    response = await page.goto(path);
    expect(response.headers()['x-prime-cache']).toBeUndefined();
    expect(response.headers()['cache-control']).toContain('no-store');
    await expect(page.locator(`[data-pb-popup="${popup}"]`)).toHaveCount(0);

    await saveModel(popup, [{ ...heading('Popup à venir'), conditions: { start: '2999-01-01T00:00' } }]);
    for (let attempt = 0; attempt < 2; attempt++) {
      response = await visitor.reload();
      expect(response.headers()['x-prime-cache']).toBe('BYPASS');
      await expect(visitor.locator(`[data-pb-popup="${popup}"]`)).toHaveCount(1);
      await expect(visitor.getByText('Popup à venir')).toHaveCount(0);
    }
  } finally {
    if (visitorContext) await visitorContext.close();
    await page.goto('/admin/prime-builder/theme');
    for (const id of models) {
      const form = page.locator(`form[action="/admin/prime-builder/theme/${id}/delete"]`);
      if (await form.count()) { await form.locator('button').click(); await page.goto('/admin/prime-builder/theme'); }
    }
    if (entryId !== null) {
      const trashed = await api(`/api/v1/content/page/${entryId}`, 'DELETE');
      expect(trashed.status, trashed.body).toBe(200);
      const deleted = await api(`/api/v1/content/page/${entryId}?force=1`, 'DELETE');
      expect(deleted.status, deleted.body).toBe(204);
    }
    if (menuId !== null) {
      await page.goto(`/admin/appearance/menus?menu=${menuId}`);
      const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
      const removed = await page.request.post(`/admin/appearance/menus/${menuId}/delete`, { headers: { 'X-CSRF-Token': csrf } });
      expect(removed.ok()).toBeTruthy();
    }
    if (originalCache !== null) {
      const restored = await api('/api/v1/settings', 'PATCH', { 'cache.page_enabled': originalCache });
      expect(restored.status, restored.body).toBe(200);
    }
  }
});
