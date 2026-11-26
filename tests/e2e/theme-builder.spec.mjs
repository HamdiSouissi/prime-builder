import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('theme models are visually edited, conditionally applied and disabled with the plugin', async ({ page }) => {
  test.setTimeout(180_000);
  const modelIds = [];
  const entries = [];
  let oldTheme = null;
  let disabled = false;
  const suffix = Date.now().toString(36);
  const excludedTitle = `Page exclue ${suffix}`;
  const postTitle = `Article du thème ${suffix}`;
  const excludedPath = `/pb-exclue-${suffix}`;
  const postPath = `/blog/pb-article-${suffix}`;
  const missingPath = `/pb-introuvable-${suffix}`;

  async function audit() {
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => `${v.id}: ${v.nodes.map(n => `${n.target.join(' ')} ${n.failureSummary}`).join(', ')}`)).toEqual([]);
  }
  async function api(url, method, body) {
    return page.evaluate(async ({ url, method, body }) => {
      const csrf = document.querySelector('meta[name="csrf-token"]').content;
      const response = await fetch(url, { method, headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf }, ...(body ? { body: JSON.stringify(body) } : {}) });
      return { status: response.status, body: await response.text() };
    }, { url, method, body });
  }
  async function createModel(name, kind) {
    await page.goto('/admin/prime-builder/theme');
    const form = page.locator('form[action="/admin/prime-builder/theme/create"]');
    await form.getByLabel('Nom du modèle').fill(name);
    await form.getByLabel('Type de modèle').selectOption(kind);
    await form.getByRole('button', { name: 'Créer un modèle' }).click();
    await expect(page).toHaveURL(/\/admin\/prime-builder\/theme\/[a-f0-9]{32}$/);
    const id = page.url().split('/').pop(); modelIds.push(id);
    return id;
  }
  async function visualHeading(id, text, contentWidget = false, archiveWidgets = false, pagination = false) {
    await page.goto(`/admin/prime-builder/theme/${id}/edit`);
    const config = JSON.parse(await page.locator('[data-pb-config]').inputValue());
    await page.getByRole('button', { name: 'Ajouter Titre', exact: true }).click();
    await page.getByLabel('Texte', { exact: true }).fill(text);
    const frame = page.frameLocator('[data-pb-frame]');
    const target = frame.locator(['header', 'footer'].includes(config.themeKind) ? '[data-pb-theme-preview-target]' : '[data-pb-editor-content]');
    await expect(target.locator('.pb-heading h2')).toHaveText(text);
    await expect(frame.locator('link[href*="aurora"]')).not.toHaveCount(0);
    if (config.themeKind === 'header') {
      await page.screenshot({ path: test.info().outputPath('theme-editor-desktop.png'), fullPage: true });
      await page.setViewportSize({ width: 375, height: 812 });
      await page.screenshot({ path: test.info().outputPath('theme-editor-mobile.png'), fullPage: true });
      await page.setViewportSize({ width: 1440, height: 900 });
    }
    if (contentWidget) {
      await page.locator('[data-pb-panel="widgets"]').click();
      await page.getByRole('button', { name: 'Ajouter Titre du contenu', exact: true }).click();
      await page.locator('[data-pb-panel="widgets"]').click();
      await page.getByRole('button', { name: 'Ajouter Contenu de l’entrée', exact: true }).click();
    }
    if (archiveWidgets) {
      await page.locator('[data-pb-panel="widgets"]').click();
      await page.getByRole('button', { name: 'Ajouter Titre d’archive', exact: true }).click();
      await page.locator('[data-pb-panel="widgets"]').click();
      await page.getByRole('button', { name: 'Ajouter Liste d’archive', exact: true }).click();
    }
    if (pagination) {
      await page.locator('[data-pb-panel="widgets"]').click();
      await page.getByRole('button', { name: 'Ajouter Grille d’articles', exact: true }).click();
      await page.getByLabel('Nombre d’articles').fill('1');
      await page.getByRole('combobox', { name: 'Pagination', exact: true }).selectOption('show');
    }
    await page.locator('[data-pb-save]').click();
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await page.goto(`/admin/prime-builder/theme/${id}`);
  }
  async function activate() {
    await page.getByLabel('Activer ce modèle sur le site').check();
    await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(page.getByLabel('Activer ce modèle sur le site')).toBeChecked();
  }
  async function togglePlugin(action) {
    await page.goto('/admin/plugins');
    const form = page.locator(`form[action="/admin/plugins/prime-builder/${action}"]`);
    await expect(form).toHaveCount(1);
    await form.locator('button[type="submit"]').click();
    await expect(page.locator(`form[action="/admin/plugins/prime-builder/${action === 'deactivate' ? 'activate' : 'deactivate'}"]`)).toHaveCount(1);
  }
  await page.goto('/admin/login');
  await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/), page.locator('form button[type=submit]').click()]);
  try {
    await page.goto('/admin/prime-builder/theme');
    oldTheme = {
      header: await page.getByLabel('En-tête', { exact: true }).inputValue(),
      footer: await page.getByLabel('Pied de page', { exact: true }).inputValue(),
      exclude: await page.getByLabel('Contenus exclus', { exact: true }).inputValue(),
    };
    await page.getByLabel('En-tête', { exact: true }).selectOption('');
    await page.getByLabel('Pied de page', { exact: true }).selectOption('');
    await page.getByLabel('Contenus exclus', { exact: true }).fill('');
    await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    for (const [type, title, slug] of [['page', excludedTitle, excludedPath.slice(1)], ['post', postTitle, postPath.split('/').pop()]]) {
      const created = await api(`/api/v1/content/${type}`, 'POST', {
        title, slug, status: 'published', published_at: '2021-01-01 10:00:00',
        content: { version: 1, blocks: [{ id: 'intro', type: 'paragraph', data: { text: `Texte original ${title}` } }] },
      });
      expect(created.status, created.body).toBe(201);
      entries.push({ type, id: JSON.parse(created.body).data.id });
    }

    const header = await createModel(`En-tête ${suffix}`, 'header');
    await visualHeading(header, 'En-tête conditionnel', false, false, true);
    const excluded = page.locator('[data-pb-rule-group="exclude"]');
    await excluded.getByRole('button', { name: 'Ajouter une exclusion' }).click();
    await excluded.getByLabel('Condition').selectOption('entry');
    const search = excluded.getByLabel('Rechercher un contenu par son titre');
    await search.fill(excludedTitle);
    await expect(excluded.getByRole('button', { name: new RegExp(excludedTitle) })).toBeVisible();
    await search.press('Enter');
    let tag = excluded.getByRole('button', { name: new RegExp(`Retirer le contenu.*${excludedTitle}`) });
    await expect(tag).toBeFocused();
    await tag.press('Enter');
    await expect(search).toBeFocused();
    await search.fill(excludedTitle);
    await expect(excluded.getByRole('button', { name: new RegExp(excludedTitle) })).toBeVisible();
    await search.press('Enter');
    tag = excluded.getByRole('button', { name: new RegExp(`Retirer le contenu.*${excludedTitle}`) });
    await expect(tag).toBeVisible();
    await audit();
    await page.screenshot({ path: test.info().outputPath('theme-conditions-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await audit();
    await page.screenshot({ path: test.info().outputPath('theme-conditions-mobile.png'), fullPage: true });
    await page.setViewportSize({ width: 1440, height: 900 });
    await activate();

    const single = await createModel(`Article seul ${suffix}`, 'single');
    await visualHeading(single, 'Gabarit article construit', true);
    const included = page.locator('[data-pb-rule-group="include"]');
    await included.getByLabel('Condition').selectOption('content_type');
    await included.locator('[data-pb-rule-controls] select').selectOption('post');
    await activate();

    const missing = await createModel(`Erreur ${suffix}`, 'not_found');
    await visualHeading(missing, 'Cette page n’existe pas');
    await page.locator('[data-pb-rule-group="include"]').getByLabel('Condition').selectOption('not_found');
    await activate();

    const archive = await createModel(`Archive ${suffix}`, 'archive');
    await visualHeading(archive, 'Archive construite', false, true);
    await page.locator('[data-pb-rule-group="include"]').getByLabel('Condition').selectOption('archive');
    await activate();
    const searchModel = await createModel(`Recherche ${suffix}`, 'search');
    await visualHeading(searchModel, 'Recherche construite', false, true);
    await page.locator('[data-pb-rule-group="include"]').getByLabel('Condition').selectOption('search');
    await activate();

    await page.goto('/?pb-theme-d=1');
    await expect(page.locator('header.pb-theme-header')).toContainText('En-tête conditionnel');
    await expect(page.getByRole('heading', { name: 'Archive construite' })).toBeVisible();
    await expect(page.locator('main')).toContainText(postTitle);
    const headerGrid = page.locator('header.pb-theme-header .pb-posts');
    const firstTitle = await headerGrid.locator('.pb-post-list h3').textContent();
    const nextPage = headerGrid.getByRole('link', { name: 'Suivant' });
    await expect(nextPage).toHaveAttribute('href', /pb_page_pbt_header_/);
    expect(await nextPage.getAttribute('href')).not.toContain('pb-theme-d');
    await nextPage.focus();
    await page.keyboard.press('Enter');
    await expect(headerGrid.locator('[aria-current="page"]')).toContainText('Page 2');
    await expect(headerGrid.locator('.pb-post-list h3')).not.toHaveText(firstTitle);
    await page.goto(`/recherche?q=${encodeURIComponent(postTitle)}`);
    await expect(page.getByRole('heading', { name: 'Recherche construite' })).toBeVisible();
    await expect(page.locator('main')).toContainText(postTitle);
    await audit();
    await page.goto(`${excludedPath}?pb-theme-d=1`);
    await expect(page.locator('header.site-header')).toBeVisible();
    await expect(page.locator('header.pb-theme-header')).toHaveCount(0);
    await page.goto(`${postPath}?pb-theme-d=1`);
    await expect(page.locator('header.pb-theme-header')).toContainText('En-tête conditionnel');
    await expect(page.getByRole('heading', { name: 'Gabarit article construit' })).toBeVisible();
    await expect(page.locator('main')).toContainText(postTitle);
    await expect(page.locator('main')).toContainText(`Texte original ${postTitle}`);
    expect(await page.locator('html').count()).toBe(1);
    expect(await page.locator('head').count()).toBe(1);
    const notFound = await page.goto(`${missingPath}?pb-theme-d=1`);
    expect(notFound.status()).toBe(404);
    await expect(page.getByRole('heading', { name: 'Cette page n’existe pas' })).toBeVisible();
    await audit();

    await togglePlugin('deactivate'); disabled = true;
    await page.goto(`${postPath}?pb-theme-disabled=1`);
    await expect(page.locator('header.site-header')).toBeVisible();
    await expect(page.locator('header.pb-theme-header')).toHaveCount(0);
    await expect(page.getByText('Gabarit article construit')).toHaveCount(0);
    await expect(page.locator('main')).toContainText(`Texte original ${postTitle}`);
    const restored404 = await page.goto(`${missingPath}?pb-theme-disabled=1`);
    expect(restored404.status()).toBe(404);
    await expect(page.getByText('Cette page n’existe pas')).toHaveCount(0);
    await togglePlugin('activate'); disabled = false;
    await page.goto(`${missingPath}?pb-theme-reactivated=1`);
    await expect(page.getByRole('heading', { name: 'Cette page n’existe pas' })).toBeVisible();
  } finally {
    if (disabled) { await togglePlugin('activate'); disabled = false; }
    await page.goto('/admin/prime-builder/theme');
    for (const id of modelIds) {
      const form = page.locator(`form[action="/admin/prime-builder/theme/${id}/delete"]`);
      if (await form.count()) { await form.locator('button').click(); await expect(page).toHaveURL(/\/admin\/prime-builder\/theme$/); }
    }
    if (oldTheme) {
      await page.getByLabel('En-tête', { exact: true }).selectOption(oldTheme.header);
      await page.getByLabel('Pied de page', { exact: true }).selectOption(oldTheme.footer);
      await page.getByLabel('Contenus exclus', { exact: true }).fill(oldTheme.exclude);
      await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    }
    for (const entry of entries) {
      await api(`/api/v1/content/${entry.type}/${entry.id}`, 'DELETE');
      await api(`/api/v1/content/${entry.type}/${entry.id}?force=1`, 'DELETE');
    }
  }
});
