import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const id = process.env.E2E_ENTRY_ID || '1';
async function login(page) {
  await page.goto('/admin/login');
  await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/), page.locator('form button[type=submit]').click()]);
}
async function expectAccessible(page) {
  const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
  const failures = result.violations.filter(v => ['serious', 'critical'].includes(v.impact));
  expect(failures.map(v => `${v.id}: ${v.nodes.map(n => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('dashboard exposes the current features and system status', async ({ page }) => {
  await login(page);
  await page.goto('/admin/prime-builder');
  await expect(page.getByRole('heading', { name: 'Commencer' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'État de l’installation' })).toBeVisible();
  await expect(page.getByText('Cette version est partielle', { exact: false })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Consulter le diagnostic système' })).toBeVisible();
  await expectAccessible(page);
});

test('compose and publish a page using the CMS theme', async ({ page }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Ajouter Conteneur' }).click();
  await page.getByRole('button', { name: 'Éléments', exact: true }).last().click();
  await page.getByRole('button', { name: 'Ajouter Titre' }).click();
  await page.getByLabel('Texte', { exact: true }).fill('Une page composée dans Prime Builder');
  await expect(page.frameLocator('[data-pb-frame]').locator('.pb-heading h2')).toHaveText('Une page composée dans Prime Builder');
  await page.locator('[data-pb-status]').selectOption('published');
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('editor-desktop.png'), fullPage: true });
  await page.goto('/test-prime-builder');
  await expect(page.getByRole('heading', { name: 'Une page composée dans Prime Builder' })).toBeVisible();
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('public-desktop.png'), fullPage: true });
});

test('library templates are available and the editor fits a mobile viewport', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Modèles', exact: true }).click();
  await expect(page.locator('.pb-template-card')).toHaveCount(9);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('editor-mobile.png'), fullPage: true });
});

test('keyboard reparenting, favorites, commands and scheduling', async ({ page }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Ajouter aux favoris : Titre' }).click();
  await expect(page.getByRole('button', { name: 'Retirer des favoris : Titre' }).first()).toHaveAttribute('aria-pressed', 'true');
  await page.getByRole('button', { name: 'Ajouter Conteneur' }).click();
  await page.getByRole('button', { name: 'Éléments', exact: true }).last().click();
  await page.getByRole('button', { name: 'Ajouter Titre' }).first().click();
  await page.getByLabel('Texte', { exact: true }).fill('Déplacé sans souris');
  const destination = page.getByLabel('Déplacer vers');
  await destination.selectOption('');
  await expect(page.frameLocator('[data-pb-frame]').locator('.pb-layout > .pb-heading h2')).toHaveText('Déplacé sans souris');
  const target = await destination.locator('option').last().getAttribute('value');
  await destination.selectOption(target);
  await expect(page.frameLocator('[data-pb-frame]').locator('.pb-container .pb-heading h2').last()).toHaveText('Déplacé sans souris');
  await page.keyboard.press('Control+k');
  await page.getByLabel('Rechercher une commande').fill('séparateur');
  await page.getByRole('dialog').getByRole('button', { name: 'Ajouter Séparateur' }).click();
  await expect(page.frameLocator('[data-pb-frame]').locator('.pb-separator').last()).toBeVisible();
  await page.locator('[data-pb-status]').selectOption('scheduled');
  const future = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString().slice(0, 16);
  await page.locator('[data-pb-schedule]').fill(future);
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  await expect(page.locator('[data-pb-status]')).toHaveValue('scheduled');
});

test('template library supports insertion and JSON export/import', async ({ page }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Modèles', exact: true }).click();
  await page.getByRole('button', { name: 'Insérer Présentation' }).click();
  await expect(page.frameLocator('[data-pb-frame]').getByText('Des idées qui prennent forme')).toBeVisible();
  await page.getByRole('button', { name: 'Modèles', exact: true }).click();
  const downloaded = page.waitForEvent('download');
  await page.getByRole('button', { name: 'Exporter JSON' }).click();
  const file = await downloaded;
  expect(file.suggestedFilename()).toMatch(/^prime-builder-\d+\.json$/);
  await page.locator('[data-pb-import]').setInputFiles(await file.path());
  await page.getByRole('dialog', { name: 'Confirmer' }).getByRole('button', { name: 'Continuer' }).click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Modèle importé.');
});

test('tabs work with keyboard and remain readable without JavaScript', async ({ page, browser }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Ajouter Onglets' }).click();
  await page.getByLabel('Nom du groupe').fill('Choisir une vue');
  await page.getByLabel('Titre du premier onglet').fill('Rouge');
  await page.getByLabel('Contenu du premier onglet').fill('<p>Contenu rouge</p>');
  await page.getByLabel('Titre du deuxième onglet').fill('Bleu');
  await page.getByLabel('Contenu du deuxième onglet').fill('<p>Contenu bleu</p>');
  await page.locator('[data-pb-status]').selectOption('published');
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  await page.goto('/test-prime-builder');
  const tabs = page.getByRole('tablist', { name: 'Choisir une vue' });
  const first = tabs.getByRole('tab', { name: 'Rouge' });
  const second = tabs.getByRole('tab', { name: 'Bleu' });
  await first.focus();
  await page.keyboard.press('ArrowRight');
  await expect(second).toBeFocused();
  await expect(second).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByRole('tabpanel', { name: 'Bleu' })).toContainText('Contenu bleu');
  await expectAccessible(page);

  const withoutJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
  const fallback = await withoutJs.newPage();
  await fallback.goto('/test-prime-builder');
  await expect(fallback.getByText('Contenu rouge')).toBeVisible();
  await expect(fallback.getByText('Contenu bleu')).toBeVisible();
  await withoutJs.close();
});

test('image selection uses the Prime media picker', async ({ page }) => {
  await login(page);
  await page.route('**/admin/media/api/items?*', route => route.fulfill({ json: { items: [{
    id: 42, kind: 'image', url: '/assets/plugins/prime-builder/images/landscape.svg',
    thumbnail_url: '/assets/plugins/prime-builder/images/landscape.svg', display_title: 'Paysage',
    alt: 'Montagnes', width: 1200, height: 800, human_size: '10 KB',
  }], has_more: false } }));
  await page.route('**/admin/media/api/folders', route => route.fulfill({ json: { flat_folders: [], counts: { all: 1, unclassified: 1 } } }));
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Ajouter Image' }).click();
  await page.getByRole('button', { name: 'Choisir dans la médiathèque' }).click();
  const modal = page.getByRole('dialog', { name: 'Choisir dans la médiathèque' });
  await expect(modal).toBeVisible();
  await expectAccessible(page);
  await modal.getByRole('option', { name: /Paysage/ }).click();
  await modal.locator('[data-prime-picker-confirm]').click();
  await expect(page.getByLabel('Adresse URL')).toHaveValue('/assets/plugins/prime-builder/images/landscape.svg');
  await expect(page.getByLabel('Texte alternatif')).toHaveValue('Montagnes');
  await expect(page.getByLabel('Largeur')).toHaveValue('1200');
});


test('responsive container controls and unsaved style reset update the preview', async ({ page }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Ajouter Conteneur' }).click();
  await page.getByRole('button', { name: 'Avancé', exact: true }).click();
  await page.getByLabel('Disposition responsive').selectOption('grid');
  await page.getByLabel('Colonnes responsive').fill('3');
  await expect(page.frameLocator('[data-pb-frame]').locator('.pb-container').last()).toHaveCSS('display', 'grid');
  await page.getByRole('button', { name: 'Mobile', exact: true }).click();
  await page.getByLabel('Disposition responsive').selectOption('flex');
  await page.getByLabel('Direction responsive').selectOption('column');
  await expect(page.frameLocator('[data-pb-frame]').locator('.pb-container').last()).toHaveCSS('display', 'flex');
  await expect(page.frameLocator('[data-pb-frame]').locator('.pb-container').last()).toHaveCSS('flex-direction', 'column');
  await page.getByRole('button', { name: 'Ordinateur', exact: true }).click();
  await page.getByRole('button', { name: 'Style', exact: true }).click();
  await page.getByLabel('Couleur du texte').fill('#123456');
  const container = page.frameLocator('[data-pb-frame]').locator('.pb-container').last();
  await expect(container).toHaveCSS('color', 'rgb(18, 52, 86)');
  await page.locator('[data-pb-status]').selectOption('published');
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  await expect(container).toHaveCSS('color', 'rgb(18, 52, 86)');
  await page.getByLabel('Couleur du texte').fill('');
  await expect(container).not.toHaveCSS('color', 'rgb(18, 52, 86)');
  await expectAccessible(page);
});

test('recent posts and current content refresh safely with a no-JS fallback', async ({ page, browser }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Ajouter Articles récents' }).click();
  await page.getByRole('button', { name: 'Éléments', exact: true }).last().click();
  await page.getByRole('button', { name: 'Ajouter Contenu actuel' }).click();
  await page.locator('[data-pb-status]').selectOption('published');
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  await page.goto('/test-prime-builder');
  await expect(page.locator('.pb-posts').getByRole('link', { name: 'Actualité publique' })).toBeVisible();
  await expect(page.locator('.pb-posts')).not.toContainText('Brouillon confidentiel');
  await expect(page.locator('.pb-current_content')).toHaveText('Test Prime Builder');
  await expectAccessible(page);
  const withoutJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
  const fallback = await withoutJs.newPage();
  await fallback.goto('/test-prime-builder');
  await expect(fallback.locator('.pb-posts').getByRole('link', { name: 'Voir les articles récents' })).toBeVisible();
  await expect(fallback.locator('.pb-posts')).not.toContainText('Brouillon confidentiel');
  await expect(fallback.locator('.pb-current_content')).toHaveText('Test Prime Builder');
  await withoutJs.close();
});

test('a non-privileged Prime block is previewed and persisted', async ({ page }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Ajouter Bloc Prime' }).click();
  await page.getByLabel('Données du bloc (objet JSON)').fill('{"text":"Bonjour <strong>Prime</strong>"}');
  await expect(page.frameLocator('[data-pb-frame]').locator('.pb-prime_block .pc-block--paragraph').last()).toHaveText('Bonjour Prime');
  await page.locator('[data-pb-status]').selectOption('published');
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  await page.goto('/test-prime-builder');
  await expect(page.locator('.pb-prime_block .pc-block--paragraph').last()).toHaveText('Bonjour Prime');
});

test('fingerprinted styles survive a public edit and protect drafts', async ({ page, browser }) => {
  await login(page);
  async function saveColor(color, status = 'published') {
    await page.goto(`/admin/prime-builder/edit/${id}`);
    return page.evaluate(async ({ entryId, color, status }) => {
      const csrf = document.querySelector('meta[name="csrf-token"]').content;
      const data = { version: 1, elements: [{ id: 'historical', type: 'heading', settings: { text: 'Couleur', level: 2 }, styles: { desktop: { color } }, children: [] }] };
      const response = await fetch(`/api/prime-builder/v1/documents/${entryId}`, {
        method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf },
        body: JSON.stringify({ content: { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data }] }, status, published_at: '' }),
      });
      return response.status;
    }, { entryId: id, color, status });
  }
  expect(await saveColor('#123456')).toBe(200);
  await page.goto('/test-prime-builder');
  const oldUrl = await page.locator('link[href^="/prime-builder/css/"]').getAttribute('href');
  const anonymous = await browser.newContext({ baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
  const original = await anonymous.request.get(oldUrl);
  expect(original.status()).toBe(200);
  expect(original.headers()['cache-control']).toContain('immutable');
  const originalCss = await original.text();
  expect(originalCss).toContain('color:#123456');
  expect(await saveColor('#654321')).toBe(200);
  const old = await anonymous.request.get(oldUrl);
  expect(old.status()).toBe(200);
  expect(await old.text()).toBe(originalCss);
  expect(await saveColor('#654321', 'draft')).toBe(200);
  expect((await anonymous.request.get(oldUrl)).status()).toBe(404);
  await anonymous.close();
});

test('built-in default widgets save and remain accessible together', async ({ page }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  const response = await page.evaluate(async entryId => {
    const config = JSON.parse(document.querySelector('[data-pb-config]').value);
    const { createElement } = await import('/assets/plugins/prime-builder/js/model.js');
    const elements = Object.keys(config.widgets).filter(type => !config.widgets[type].hidden).map(type => {
      const element = createElement(type);
      element.settings = { ...element.settings, ...(config.widgetDefaults[type] || {}) };
      return element;
    });
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const result = await fetch(`/api/prime-builder/v1/documents/${entryId}`, {
      method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf },
      body: JSON.stringify({ content: { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements } }] }, status: 'published', published_at: '' }),
    });
    return result.status;
  }, id);
  expect(response).toBe(200);
  await page.goto('/test-prime-builder');
  await expect(page.locator('.pb-element')).toHaveCount(23);
  await expectAccessible(page);
});

test('navigator renames, folds and hides elements with persisted accessible controls', async ({ page }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Ajouter Conteneur' }).click();
  await page.locator('[data-pb-panel="widgets"]').click();
  await page.getByRole('button', { name: 'Ajouter Titre' }).click();
  await page.getByLabel('Texte', { exact: true }).fill('Titre à masquer');
  await expect(page.frameLocator('[data-pb-frame]').getByRole('heading', { name: 'Titre à masquer' })).toBeVisible();
  await page.getByRole('button', { name: 'Structure', exact: true }).click();
  await page.getByRole('button', { name: 'Renommer : Conteneur' }).last().click();
  await page.getByRole('textbox', { name: 'Renommer : Conteneur' }).fill('Section test');
  await page.getByRole('textbox', { name: 'Renommer : Conteneur' }).press('Enter');
  const collapse = page.getByRole('button', { name: 'Replier : Section test' });
  await collapse.click();
  await expect(page.getByRole('button', { name: 'Déplier : Section test' })).toHaveAttribute('aria-expanded', 'false');
  await page.getByRole('button', { name: 'Déplier : Section test' }).click();
  await page.getByRole('button', { name: 'Renommer : Titre' }).last().click();
  await page.getByRole('textbox', { name: 'Renommer : Titre' }).fill('Sous-titre spécial');
  await page.getByRole('textbox', { name: 'Renommer : Titre' }).press('Enter');
  await page.getByRole('button', { name: 'Masquer : Sous-titre spécial' }).click();
  await expect(page.getByRole('button', { name: 'Afficher : Sous-titre spécial' })).toBeFocused();
  await expect(page.frameLocator('[data-pb-frame]').locator('.pb-heading').filter({ hasText: 'Titre à masquer' })).toHaveAttribute('hidden', '');
  await expectAccessible(page);
  await page.locator('[data-pb-status]').selectOption('published');
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  await page.goto('/test-prime-builder');
  await expect(page.locator('.pb-heading').filter({ hasText: 'Titre à masquer' })).toHaveAttribute('hidden', '');
  await expect(page.locator('.pb-heading').filter({ hasText: 'Titre à masquer' })).toBeHidden();
  await expectAccessible(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Structure', exact: true }).click();
  await page.getByRole('button', { name: 'Afficher : Sous-titre spécial' }).click();
  await expect(page.frameLocator('[data-pb-frame]').getByRole('heading', { name: 'Titre à masquer' })).toBeVisible();
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  await page.goto('/test-prime-builder');
  await expect(page.getByRole('heading', { name: 'Titre à masquer' })).toBeVisible();
});

test('context menu copies elements and styles with keyboard and restores focus', async ({ page }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Ajouter Titre' }).click();
  await page.getByLabel('Texte', { exact: true }).fill('Source de copie');
  await page.getByRole('button', { name: 'Style', exact: true }).click();
  await page.getByLabel('Couleur du texte', { exact: true }).fill('#123456');
  const frame = page.frameLocator('[data-pb-frame]');
  await expect(frame.getByRole('heading', { name: 'Source de copie' })).toBeVisible();
  const opener = page.locator('[data-pb-context-open]');
  const menu = page.getByRole('menu', { name: 'Actions de l’élément' });
  await opener.click();
  await expect(menu.getByRole('menuitem', { name: 'Copier', exact: true })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.locator('[data-pb-live]')).toHaveText('Élément copié dans le constructeur.');
  await page.locator('[data-pb-panel="widgets"]').click();
  await page.getByRole('button', { name: 'Ajouter Titre' }).click();
  await page.getByLabel('Texte', { exact: true }).fill('Cible de copie');
  await opener.click();
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('ArrowDown');
  await expect(menu.getByRole('menuitem', { name: 'Coller le style' })).toBeFocused();
  await page.keyboard.press('Enter');
  const target = frame.getByRole('heading', { name: 'Cible de copie' });
  await expect(target).toHaveCSS('color', 'rgb(18, 52, 86)');
  await opener.click();
  await menu.getByRole('menuitem', { name: 'Coller', exact: true }).click();
  await expect(frame.getByRole('heading', { name: 'Source de copie' })).toHaveCount(2);
  await target.click({ button: 'right' });
  await expect(menu).toBeVisible();
  await expectAccessible(page);
  await menu.getByRole('menuitem', { name: 'Réinitialiser le style' }).click();
  await expect(target).not.toHaveCSS('color', 'rgb(18, 52, 86)');
  await opener.click();
  await menu.getByRole('menuitem', { name: 'Dupliquer' }).click();
  await expect(target).toHaveCount(2);
  await opener.click();
  await menu.getByRole('menuitem', { name: 'Supprimer' }).click();
  await expect(target).toHaveCount(1);
  await target.click({ button: 'right' });
  await page.keyboard.press('Escape');
  await expect(menu).toBeHidden();
  await expect(opener).toBeFocused();
});

test('existing canvas elements can be dragged into a container', async ({ page }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Ajouter Conteneur' }).click();
  await page.getByRole('button', { name: 'Avancé', exact: true }).click();
  await page.getByLabel('Marge intérieure', { exact: true }).fill('40');
  await page.locator('[data-pb-panel="widgets"]').click();
  await page.getByRole('button', { name: 'Ajouter Titre' }).click();
  await page.getByLabel('Texte', { exact: true }).fill('Déplacé dans le canevas');
  await page.getByLabel('Déplacer vers').selectOption('');
  const frame = page.frameLocator('[data-pb-frame]');
  const source = frame.locator('.pb-heading').filter({ hasText: 'Déplacé dans le canevas' });
  const container = frame.locator('.pb-container').last();
  await expect(source).toHaveAttribute('draggable', 'true');
  await expect(container).toBeVisible();
  await source.dragTo(container, { targetPosition: { x: 5, y: 5 } });
  await expect(frame.locator('.pb-container .pb-heading').filter({ hasText: 'Déplacé dans le canevas' })).toBeVisible();
});

test('color picker uses kit references and spacing has numeric units', async ({ page }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Ajouter Titre' }).click();
  await page.getByLabel('Texte', { exact: true }).fill('Styles référencés');
  await page.getByRole('button', { name: 'Style', exact: true }).click();
  await expect(page.getByLabel('Sélecteur : texte')).toHaveAttribute('type', 'color');
  await page.getByLabel('Couleur globale du texte').selectOption('kit:primary');
  const heading = page.frameLocator('[data-pb-frame]').locator('.pb-heading').last();
  await expect(heading).toHaveCSS('color', 'rgb(201, 68, 31)');
  await expect(heading.locator('h2')).toHaveCSS('color', 'rgb(201, 68, 31)');
  await page.getByLabel('Unité : Taille du texte', { exact: true }).selectOption('rem');
  await page.getByLabel('Taille du texte', { exact: true }).fill('2');
  await expect(heading.locator('h2')).toHaveCSS('font-size', '32px');
  await page.getByRole('button', { name: 'Avancé', exact: true }).click();
  await page.getByLabel('Unité : Marge intérieure', { exact: true }).selectOption('rem');
  await page.getByLabel('Marge intérieure', { exact: true }).fill('1.25');
  await expect(heading).toHaveCSS('padding-top', '20px');
  const response = page.waitForResponse(result => result.request().method() === 'PATCH' && result.url().includes(`/documents/${id}`));
  await page.locator('[data-pb-save]').click();
  const saved = await (await response).json();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  const nodes = saved.data.content.blocks[0].data.elements;
  const node = nodes.find(element => element.settings.text === 'Styles référencés');
  expect(node.styles.desktop.color).toBe('kit:primary');
  expect(node.styles.desktop.font_size).toBe('2rem');
  expect(node.styles.desktop.padding).toBe('1.25rem');
  await expectAccessible(page);
});

test('plugin settings save and remain usable on desktop and mobile', async ({ page }) => {
  await login(page);
  await page.goto('/admin/prime-builder/settings');
  await expect(page.getByRole('heading', { name: 'Réglages Prime Builder' })).toBeVisible();
  const primary = page.getByLabel('Couleur principale', { exact: true });
  const tablet = page.getByLabel('Point de rupture tablette (px)');
  const originalPrimary = await primary.inputValue();
  const originalTablet = await tablet.inputValue();
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('settings-desktop.png'), fullPage: true });
  await page.setViewportSize({ width: 375, height: 812 });
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('settings-mobile.png'), fullPage: true });
  async function submit() {
    const response = page.waitForResponse(result => result.request().method() === 'POST' && result.url().endsWith('/admin/prime-builder/settings'));
    await page.locator('form[action="/admin/prime-builder/settings"] button[type="submit"]').click();
    expect((await response).status()).toBe(302);
  }
  await primary.fill('#0a6380');
  await tablet.fill('1200');
  await submit();
  await expect(primary).toHaveValue('#0a6380');
  await expect(tablet).toHaveValue('1200');
  await primary.fill(originalPrimary);
  await tablet.fill(originalTablet);
  await submit();
  await expect(primary).toHaveValue(originalPrimary);
  await expect(tablet).toHaveValue(originalTablet);
});

test('existing three-block page is converted without losing its saved HTML', async ({ page }) => {
  const legacyId = process.env.E2E_LEGACY_ENTRY_ID;
  expect(legacyId).toBeTruthy();
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${legacyId}`);
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Non enregistré');
  const frame = page.frameLocator('[data-pb-frame]');
  await expect(frame.getByText('Premier contenu')).toBeVisible();
  await expect(frame.getByText('Contenu original conservé')).toBeVisible();
  await expect(frame.getByText('Troisième contenu')).toBeVisible();
  const types = await page.evaluate(() => {
    const config = JSON.parse(document.querySelector('[data-pb-config]').value);
    return config.document.blocks[0].data.elements[0].children.map(node => node.type);
  });
  expect(types).toEqual(['prime_block', 'original_content', 'prime_block']);
  await page.getByRole('button', { name: 'Structure', exact: true }).click();
  await page.getByRole('button', { name: 'Contenu d’origine', exact: true }).click();
  await expect(page.locator('[data-pb-fields]').getByText('Ce contenu d’origine ne peut pas être modifié ici.', { exact: false })).toBeVisible();
  await expect(page.locator('[data-pb-fields] input')).toHaveCount(0);
  await expect(page.getByLabel('Déplacer vers').locator('option').last()).toHaveText('Conteneur 1');
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  await page.goto('/contenu-existant');
  const content = page.locator('.pb-layout');
  await expect(content).toContainText('Premier contenu');
  await expect(content.locator('[data-legacy="original"]')).toHaveText('Contenu original conservé');
  await expect(content).toContainText('Troisième contenu');
  await expect(content.locator('#troisieme-contenu')).toHaveText('Troisième contenu');
  const text = await content.innerText();
  expect(text.indexOf('Premier contenu')).toBeLessThan(text.indexOf('Contenu original conservé'));
  expect(text.indexOf('Contenu original conservé')).toBeLessThan(text.indexOf('Troisième contenu'));
  await expectAccessible(page);
});

test('departure dialog traps and restores focus and closes on Escape', async ({ page }) => {
  let nativeDialogs = 0;
  page.on('dialog', dialog => { nativeDialogs += 1; dialog.dismiss(); });
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Ajouter Titre' }).click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Non enregistré');
  const back = page.getByRole('link', { name: 'Retour au contenu' });
  await back.click();
  const dialog = page.getByRole('dialog', { name: 'Confirmer' });
  await expect(dialog).toContainText('Des modifications ne sont pas enregistrées. Quitter la page ?');
  const cancel = dialog.getByRole('button', { name: 'Annuler' });
  const proceed = dialog.getByRole('button', { name: 'Continuer' });
  await expect(cancel).toBeFocused();
  await expectAccessible(page);
  await page.keyboard.press('Tab');
  await expect(proceed).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(cancel).toBeFocused();
  await page.keyboard.press('Shift+Tab');
  await expect(proceed).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(dialog).not.toBeVisible();
  await expect(back).toBeFocused();
  expect(nativeDialogs).toBe(0);
});

// Keep this last: the CI workflow next disables the plugin and makes a fresh uncached HTTP request.
test('save a page for the deactivation check', async ({ page }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  const code = await page.evaluate(async entryId => {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const element = { id: 'lifecycle', type: 'heading', settings: { text: 'Contenu conservé', level: 2 }, styles: {}, children: [] };
    const response = await fetch(`/api/prime-builder/v1/documents/${entryId}`, {
      method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf },
      body: JSON.stringify({ content: { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: [element] } }] }, status: 'published', published_at: '' }),
    });
    return response.status;
  }, id);
  expect(code).toBe(200);
  await page.goto('/test-prime-builder');
  await expect(page.locator('.pb-heading h2')).toHaveText('Contenu conservé');
});
