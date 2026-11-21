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
  page.once('dialog', dialog => dialog.accept());
  await page.locator('[data-pb-import]').setInputFiles(await file.path());
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


test('built-in default widgets save and remain accessible together', async ({ page }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  const response = await page.evaluate(async entryId => {
    const config = JSON.parse(document.querySelector('[data-pb-config]').value);
    const { createElement } = await import('/assets/plugins/prime-builder/js/model.js');
    const elements = Object.keys(config.widgets).map(type => {
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
  await expect(page.locator('.pb-element')).toHaveCount(20);
  await expectAccessible(page);
});
