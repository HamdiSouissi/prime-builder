import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const id = process.env.E2E_ENTRY_ID || '1';
const mouseId = process.env.E2E_MOUSE_ENTRY_ID || '';
const keyboardId = process.env.E2E_KEYBOARD_ENTRY_ID || '';
const dragId = process.env.E2E_DRAG_ENTRY_ID || '';
const htmlId = process.env.E2E_HTML_ENTRY_ID || '';
const formId = process.env.E2E_FORM_ENTRY_ID || '';
const menuId = process.env.E2E_MENU_ENTRY_ID || '';
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

test('the core content screen embeds the alternative editor on desktop and mobile', async ({ page }) => {
  await login(page);
  await page.goto(`/admin/content/page/${id}/edit`);
  await expect(page.getByRole('link', { name: 'Modifier avec Prime Builder' })).toHaveAttribute('href', `/admin/prime-builder/edit/${id}`);
  await expect(page.locator('textarea[name="content_json"][data-prime-custom-editor-input]')).toBeHidden();
  await expect(page.locator('.pb-alternate-preview')).toBeVisible();
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('core-editor-desktop.png'), fullPage: true });
  await page.setViewportSize({ width: 375, height: 812 });
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('core-editor-mobile.png'), fullPage: true });
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

test('partial previews preserve siblings, ignore stale responses, and recover a missing node', async ({ page }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Ajouter Titre' }).click();
  await page.getByLabel('Texte', { exact: true }).fill('Voisin conservé');
  await page.locator('[data-pb-panel="widgets"]').click();
  await page.getByRole('button', { name: 'Ajouter Titre' }).click();
  const field = page.getByLabel('Texte', { exact: true });
  await field.fill('Élément modifié');
  const frame = page.frameLocator('[data-pb-frame]');
  const sibling = frame.locator('.pb-heading').filter({ hasText: 'Voisin conservé' });
  const selected = frame.locator('.pb-heading').filter({ hasText: 'Élément modifié' });
  await expect(sibling).toBeVisible();
  await expect(selected).toBeVisible();
  const elementId = await selected.getAttribute('data-pb-id');
  expect(elementId).toBeTruthy();
  const edited = frame.locator(`[data-pb-id="${elementId}"] h2`);
  await sibling.evaluate(node => { node.ownerDocument.defaultView.__pbSibling = node; });

  const partial = page.waitForResponse(response => response.url().endsWith('/render') && response.request().postDataJSON()?.target === elementId);
  await field.fill('Rendu partiel');
  const partialResponse = await partial;
  expect(partialResponse.ok()).toBeTruthy();
  const body = (await partialResponse.json()).data;
  expect(body.target).toBe(elementId);
  expect(body.html).toContain('Rendu partiel');
  expect(body.html).not.toContain('Voisin conservé');
  expect(body).not.toHaveProperty('layout');
  await expect(edited).toHaveText('Rendu partiel');
  expect(await sibling.evaluate(node => node === node.ownerDocument.defaultView.__pbSibling)).toBe(true);

  let release;
  let signal;
  const blocked = new Promise(resolve => { release = resolve; });
  const requestStarted = new Promise(resolve => { signal = resolve; });
  await page.route('**/render', async route => {
    if (route.request().postDataJSON()?.target === elementId && route.request().postData()?.includes('Valeur retardée')) {
      signal();
      await blocked;
    }
    await route.continue();
  });
  try {
    const delayedResponse = page.waitForResponse(response => response.url().endsWith('/render') && response.request().postData()?.includes('Valeur retardée'));
    await field.fill('Valeur retardée');
    await requestStarted;
    await field.fill('Valeur finale');
    await expect(edited).toHaveText('Valeur finale');
    release();
    await delayedResponse;
    await page.waitForTimeout(250);
    await expect(edited).toHaveText('Valeur finale');
    expect(await sibling.evaluate(node => node === node.ownerDocument.defaultView.__pbSibling)).toBe(true);
  } finally {
    release();
    await page.unroute('**/render');
  }

  // If an element disappeared from the iframe, the next change must trigger a full reconciliation.
  await frame.locator(`[data-pb-id="${elementId}"]`).evaluate(node => node.remove());
  await field.fill('Élément restauré');
  await expect(edited).toHaveText('Élément restauré');
  await expect(sibling).toBeVisible();
  expect(await sibling.evaluate(node => node === node.ownerDocument.defaultView.__pbSibling)).toBe(false);
  await expectAccessible(page);
});

test('build and publish four widgets with the mouse', async ({ page }) => {
  expect(mouseId).toMatch(/^[1-9][0-9]*$/);
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${mouseId}`);
  await page.getByRole('button', { name: 'Ajouter Titre' }).click();
  await page.getByLabel('Texte', { exact: true }).fill('Une page à la souris');
  await page.locator('[data-pb-panel="widgets"]').click();
  await page.getByRole('button', { name: 'Ajouter Texte' }).click();
  await page.getByLabel('Texte', { exact: true }).fill('<p>Texte composé à la souris.</p>');
  await page.locator('[data-pb-panel="widgets"]').click();
  await page.getByRole('button', { name: 'Ajouter Bouton' }).click();
  await page.getByLabel('Texte', { exact: true }).fill('Lien souris');
  await page.getByLabel('Adresse URL').fill('/');
  await page.locator('[data-pb-panel="widgets"]').click();
  await page.getByRole('button', { name: 'Ajouter Séparateur' }).click();
  await expect(page.frameLocator('[data-pb-frame]').locator('.pb-element')).toHaveCount(4);
  await expectAccessible(page);
  await page.locator('[data-pb-status]').selectOption('published');
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  await page.goto('/parcours-souris');
  await expect(page.getByRole('heading', { name: 'Une page à la souris' })).toBeVisible();
  await expect(page.getByText('Texte composé à la souris.')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Lien souris' })).toHaveAttribute('href', '/');
  await expect(page.locator('.pb-separator hr')).toBeVisible();
  await expect(page.locator('.pb-element')).toHaveCount(4);
  await expectAccessible(page);
});

test('build and publish four widgets using keyboard controls', async ({ page }) => {
  expect(keyboardId).toMatch(/^[1-9][0-9]*$/);
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${keyboardId}`);
  async function addWithKeyboard(name) {
    await page.keyboard.press('Control+k');
    const search = page.getByLabel('Rechercher une commande');
    await expect(search).toBeFocused();
    await page.keyboard.insertText(`Ajouter ${name}`);
    await page.keyboard.press('Tab');
    await expect(page.getByRole('dialog').getByRole('button', { name: `Ajouter ${name}`, exact: true })).toBeFocused();
    await page.keyboard.press('Enter');
  }
  await addWithKeyboard('Titre');
  await expect(page.getByLabel('Texte', { exact: true })).toBeFocused();
  await page.keyboard.press('Control+a'); await page.keyboard.insertText('Une page au clavier');
  await addWithKeyboard('Texte');
  await expect(page.getByLabel('Texte', { exact: true })).toBeFocused();
  await page.keyboard.press('Control+a'); await page.keyboard.insertText('Texte composé au clavier.');
  await addWithKeyboard('Bouton');
  await expect(page.getByLabel('Texte', { exact: true })).toBeFocused();
  await page.keyboard.press('Control+a'); await page.keyboard.insertText('Lien clavier');
  await page.keyboard.press('Tab');
  await expect(page.getByLabel('Adresse URL')).toBeFocused();
  await page.keyboard.insertText('/');
  await addWithKeyboard('Séparateur');
  await expect(page.getByLabel('Déplacer vers')).toBeFocused();
  await expect(page.frameLocator('[data-pb-frame]').locator('.pb-element')).toHaveCount(4);
  await expectAccessible(page);
  const status = page.locator('[data-pb-status]');
  for (let i = 0; i < 40 && !await status.evaluate(el => el === document.activeElement); i++) await page.keyboard.press('Tab');
  await expect(status).toBeFocused();
  await page.keyboard.press('ArrowDown');
  await expect(status).toHaveValue('published');
  await page.keyboard.press('Control+s');
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  await page.goto('/parcours-clavier');
  await expect(page.getByRole('heading', { name: 'Une page au clavier' })).toBeVisible();
  await expect(page.getByText('Texte composé au clavier.')).toBeVisible();
  await expect(page.getByRole('link', { name: 'Lien clavier' })).toHaveAttribute('href', '/');
  await expect(page.locator('.pb-separator hr')).toBeVisible();
  await expect(page.locator('.pb-element')).toHaveCount(4);
  await expectAccessible(page);
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

test('context menu saves a reusable template with keyboard, reload and deletion', async ({ page }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Ajouter Titre' }).click();
  await page.getByLabel('Texte', { exact: true }).fill('Témoin du modèle enregistré');
  const opener = page.locator('[data-pb-context-open]');
  const menu = page.getByRole('menu', { name: 'Actions de l’élément' });
  const dialog = page.getByRole('dialog', { name: 'Enregistrer comme modèle' });

  await opener.focus();
  await page.keyboard.press('Shift+F10');
  await expect(menu.getByRole('menuitem', { name: 'Copier', exact: true })).toBeFocused();
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('ArrowDown');
  await expect(menu.getByRole('menuitem', { name: 'Enregistrer comme modèle' })).toBeFocused();
  await page.keyboard.press('Enter');
  const name = dialog.getByLabel('Nom du modèle');
  await expect(name).toBeFocused();
  await page.keyboard.press('Shift+Tab');
  await expect(dialog.getByRole('button', { name: 'Enregistrer', exact: true })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(name).toBeFocused();
  await expectAccessible(page);
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(opener).toBeFocused();

  await page.keyboard.press('Shift+F10');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Enter');
  await name.fill('Modèle clavier partagé');
  const saved = page.waitForResponse(response => response.url().endsWith('/api/prime-builder/v1/templates') && response.request().method() === 'POST');
  await page.keyboard.press('Enter');
  expect((await saved).status()).toBe(201);
  await expect(dialog).toBeHidden();
  await expect(opener).toBeFocused();
  await expect(page.locator('[data-pb-live]')).toHaveText('Modèle enregistré dans la bibliothèque.');

  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  await page.reload();
  await page.getByRole('button', { name: 'Modèles', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Insérer Modèle clavier partagé' })).toBeVisible();
  await page.getByRole('button', { name: 'Insérer Modèle clavier partagé' }).click();
  await expect(page.frameLocator('[data-pb-frame]').getByRole('heading', { name: 'Témoin du modèle enregistré' })).toHaveCount(2);
  await page.getByRole('button', { name: 'Modèles', exact: true }).click();
  await page.getByRole('button', { name: 'Supprimer le modèle Modèle clavier partagé' }).click();
  await page.getByRole('dialog', { name: 'Confirmer' }).getByRole('button', { name: 'Continuer' }).click();
  await expect(page.getByRole('button', { name: 'Insérer Modèle clavier partagé' })).toHaveCount(0);
  await expect(page.locator('[data-pb-live]')).toHaveText('Modèle supprimé.');
  await expectAccessible(page);
});

test('new basic widgets each expose content, style and advanced controls', async ({ page }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  for (const name of ['Icône', 'Liste d’icônes', 'Boîte image', 'Témoignage', 'Bascule', 'Réseaux sociaux', 'Note par étoiles', 'Carrousel d’images', 'Carte OpenStreetMap', 'Zone du thème']) {
    await page.locator('[data-pb-panel="widgets"]').click();
    await page.getByRole('button', { name: `Ajouter ${name}`, exact: true }).click();
    await expect(page.locator('[data-pb-fields] input, [data-pb-fields] select, [data-pb-fields] textarea').first()).toBeVisible();
    if (name === 'Boîte image') {
      await page.getByLabel('Adresse URL', { exact: true }).fill('/suite');
      const frame = page.frameLocator('[data-pb-frame]');
      await expect(frame.locator('link[href*="/css/widgets/image_box.css"]')).toHaveCount(1);
      await expect(frame.locator('.pb-image_box .pb-button-link').last()).toHaveCSS('background-color', 'rgb(201, 68, 31)');
    }
    await page.getByRole('button', { name: 'Style', exact: true }).click();
    await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Avancé', exact: true }).click();
    await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Contenu', exact: true }).click();
    await expect(page.locator('[data-pb-fields] input, [data-pb-fields] select, [data-pb-fields] textarea').first()).toBeVisible();
  }
});

test('call to action and pricing widgets can be edited and remain usable without JavaScript', async ({ page, browser }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  const reset = await page.evaluate(async entryId => {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const content = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: [] } }] };
    const response = await fetch(`/api/prime-builder/v1/documents/${entryId}`, {
      method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf },
      body: JSON.stringify({ content, status: 'published', published_at: '' }),
    });
    return response.status;
  }, id);
  expect(reset).toBe(200);
  await page.reload();
  const widgets = [
    ['Appel à l’action', 'call_to_action'],
    ['Table de prix', 'price_table'],
    ['Liste de prix', 'price_list'],
  ];
  for (const [name, type] of widgets) {
    await page.locator('[data-pb-panel="widgets"]').click();
    await page.getByRole('button', { name: `Ajouter ${name}`, exact: true }).click();
    await expect(page.locator('[data-pb-fields] input, [data-pb-fields] textarea').first()).toBeVisible();
    if (type === 'call_to_action') {
      await page.getByLabel('Titre', { exact: true }).fill('Un projet ensemble');
      await page.getByLabel('Texte', { exact: true }).fill('<p>Parlons de votre idée.</p>');
      await page.getByLabel('Adresse URL', { exact: true }).fill('/test-prime-builder#contact');
      await page.getByLabel('Texte du bouton', { exact: true }).fill('Nous contacter');
    } else if (type === 'price_table') {
      await page.getByLabel('Titre', { exact: true }).fill('Offre mensuelle');
      await page.getByLabel('Prix (texte et devise)').fill('29 €');
      await page.getByLabel('Période (facultative)').fill('par mois');
      await page.getByLabel('Avantages (un par ligne)').fill('Conseil inclus\nSuivi personnalisé');
      await page.getByLabel('Adresse URL', { exact: true }).fill('/test-prime-builder#tarifs');
      await page.getByLabel('Texte du bouton', { exact: true }).fill('Choisir cette offre');
    } else {
      await page.getByLabel('Titre', { exact: true }).fill('Prestations');
      await page.getByLabel('Articles : nom | prix | description facultative (30 lignes maximum)').fill('Conseil | 25 € | Une heure\nAtelier | Sur devis');
    }
    const frame = page.frameLocator('[data-pb-frame]');
    await expect(frame.locator(`link[href*="/css/widgets/${type}.css"]`)).toHaveCount(1);
    await page.getByRole('button', { name: 'Style', exact: true }).click();
    await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Avancé', exact: true }).click();
    await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Contenu', exact: true }).click();
  }
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  await page.goto('/test-prime-builder');
  await expect(page.getByRole('heading', { name: 'Un projet ensemble' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Offre mensuelle' })).toBeVisible();
  await expect(page.locator('.pb-price_list')).toContainText('Conseil');
  await expect(page.locator('.pb-price_list')).toContainText('Sur devis');
  for (const [, type] of widgets) await expect(page.locator(`link[href*="/css/widgets/${type}.css"]`)).toHaveCount(1);
  const contact = page.locator('.pb-call_to_action').getByRole('link', { name: 'Nous contacter', exact: true });
  await contact.focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(/#contact$/);
  const choose = page.locator('.pb-price_table').getByRole('link', { name: 'Choisir cette offre', exact: true });
  await choose.focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(/#tarifs$/);
  await expectAccessible(page);
  const noJs = await browser.newContext({ javaScriptEnabled: false });
  try {
    const fallback = await noJs.newPage();
    await fallback.goto('/test-prime-builder');
    await expect(fallback.locator('.pb-call_to_action').getByRole('link', { name: 'Nous contacter', exact: true })).toHaveAttribute('href', '/test-prime-builder#contact');
    await expect(fallback.locator('.pb-price_table').getByRole('link', { name: 'Choisir cette offre', exact: true })).toHaveAttribute('href', '/test-prime-builder#tarifs');
    await expect(fallback.locator('.pb-price_list')).toContainText('Une heure');
  } finally { await noJs.close(); }
});

test('search form uses the CMS search and refreshes its URL without disturbing typing', async ({ page, browser }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  const reset = await page.evaluate(async entryId => {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const response = await fetch(`/api/prime-builder/v1/documents/${entryId}`, {
      method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf },
      body: JSON.stringify({ content: { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: [] } }] }, status: 'published', published_at: '' }),
    });
    return response.status;
  }, id);
  expect(reset).toBe(200);
  await page.reload();
  await page.getByRole('button', { name: 'Ajouter Formulaire de recherche', exact: true }).click();
  await page.getByLabel('Libellé du champ de recherche').fill('Chercher une actualité');
  await page.getByLabel('Texte indicatif (facultatif)').fill('Mots clés');
  await page.getByLabel('Texte du bouton', { exact: true }).fill('Trouver');
  const frame = page.frameLocator('[data-pb-frame]');
  await expect(frame.locator('link[href*="/css/widgets/search_form.css"]')).toHaveCount(1);
  await expect(frame.getByRole('searchbox', { name: 'Chercher une actualité' })).toBeVisible();
  await page.getByRole('button', { name: 'Style', exact: true }).click();
  await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Avancé', exact: true }).click();
  await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  const original = await page.evaluate(async () => {
    const response = await fetch('/api/v1/settings', { headers: { Accept: 'application/json' } });
    return (await response.json()).data['permalinks.search_base'];
  });
  expect(original).toBe('recherche');

  // The persisted GET form works even when JS is disabled, before a setting changes.
  const noJs = await browser.newContext({ javaScriptEnabled: false });
  try {
    const fallback = await noJs.newPage();
    await fallback.goto('/test-prime-builder');
    const form = fallback.locator('.pb-search_form');
    await expect(form.locator('form')).toHaveAttribute('action', '/recherche');
    const input = form.getByRole('searchbox', { name: 'Chercher une actualité' });
    await input.fill('Actualité');
    await input.press('Enter');
    await fallback.waitForURL(url => url.pathname === '/recherche' && url.searchParams.get('q') === 'Actualité');
    await expect(fallback.getByText('Actualité publique', { exact: true }).first()).toBeVisible();
  } finally { await noJs.close(); }

  const setBase = value => page.evaluate(async ({ value, token }) => {
    const response = await fetch('/api/v1/settings', {
      method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': token },
      body: JSON.stringify({ 'permalinks.search_base': value }),
    });
    return response.status;
  }, { value, token: csrf });
  const pattern = `**/api/prime-builder/v1/documents/${id}/live`;
  let releaseLive;
  let notifyLive;
  const gate = new Promise(resolve => { releaseLive = resolve; });
  const requested = new Promise(resolve => { notifyLive = resolve; });
  try {
    expect(await setBase('trouver-exemple')).toBe(200);
    await page.route(pattern, async route => { notifyLive(); await gate; await route.continue(); });
    await page.goto('/test-prime-builder', { waitUntil: 'domcontentloaded' });
    await requested;
    const widget = page.locator('.pb-search_form');
    const form = widget.locator('form');
    const input = widget.getByRole('searchbox', { name: 'Chercher une actualité' });
    await expect(form).toHaveAttribute('action', '/recherche');
    await expect(page.locator('link[href*="/css/widgets/search_form.css"]')).toHaveCount(1);
    await input.fill('Actualité');
    await input.focus();
    releaseLive();
    await expect(form).toHaveAttribute('action', '/trouver-exemple');
    await expect(input).toHaveValue('Actualité');
    await expect(input).toBeFocused();
    await expectAccessible(page);
    await page.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await expectAccessible(page);
    await page.unroute(pattern);
    await input.press('Enter');
    await page.waitForURL(url => url.pathname === '/trouver-exemple' && url.searchParams.get('q') === 'Actualité');
    await expect(page.getByText('Actualité publique', { exact: true }).first()).toBeVisible();
  } finally {
    releaseLive();
    await page.unroute(pattern);
    expect(await setBase(original)).toBe(200);
  }
});

test('toggle works with keyboard on the public page, including without JavaScript', async ({ page, browser }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Ajouter Bascule' }).click();
  await page.getByLabel('Titre', { exact: true }).fill('Pourquoi cette option ?');
  await page.getByLabel('Texte', { exact: true }).fill('<p>Une réponse accessible.</p>');
  await page.getByLabel('État initial').selectOption('closed');
  await page.getByRole('button', { name: 'Style', exact: true }).click();
  await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Avancé', exact: true }).click();
  await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
  await page.locator('[data-pb-status]').selectOption('published');
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  await page.goto('/test-prime-builder');
  const details = page.locator('.pb-toggle details').last();
  const summary = details.locator('summary');
  await expect(summary).toHaveText('Pourquoi cette option ?');
  await expect(details).not.toHaveAttribute('open', '');
  await summary.focus();
  await page.keyboard.press('Enter');
  await expect(details).toHaveAttribute('open', '');
  await expect(details.getByText('Une réponse accessible.')).toBeVisible();
  await expectAccessible(page);
  await page.keyboard.press('Space');
  await expect(details).not.toHaveAttribute('open', '');

  const noJs = await browser.newContext({ javaScriptEnabled: false });
  try {
    const fallback = await noJs.newPage();
    await fallback.goto('/test-prime-builder');
    const native = fallback.locator('.pb-toggle details').last();
    await native.locator('summary').focus();
    await fallback.keyboard.press('Enter');
    await expect(native).toHaveAttribute('open', '');
    await expect(native.getByText('Une réponse accessible.')).toBeVisible();
  } finally { await noJs.close(); }
});

test('image carousel works with keyboard and leaves all slides readable without JavaScript', async ({ page, browser }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  const status = await page.evaluate(async entryId => {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const element = { id: 'carousel_demo', type: 'image_carousel', settings: { label: 'Images de vacances', images: '/assets/plugins/prime-builder/icon.svg|Montagne\n/assets/plugins/prime-builder/icon.svg?second=1|Océan' }, styles: {}, children: [] };
    const result = await fetch(`/api/prime-builder/v1/documents/${entryId}`, {
      method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf },
      body: JSON.stringify({ content: { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: [element] } }] }, status: 'published', published_at: '' }),
    });
    return result.status;
  }, id);
  expect(status).toBe(200);
  await page.goto('/test-prime-builder');
  const carousel = page.getByRole('region', { name: 'Images de vacances' });
  const slides = carousel.locator('[data-pb-slide]');
  await expect(slides).toHaveCount(2);
  await expect(slides.nth(0)).toBeVisible();
  await expect(slides.nth(1)).toBeHidden();
  const next = carousel.getByRole('button', { name: 'Image suivante' });
  await next.focus();
  await page.keyboard.press('Enter');
  await expect(slides.nth(0)).toBeHidden();
  await expect(slides.nth(1)).toBeVisible();
  await expect(carousel.getByRole('status')).toHaveText('Image 2 sur 2');
  await page.keyboard.press('ArrowLeft');
  await expect(slides.nth(0)).toBeVisible();
  await expect(carousel.getByRole('status')).toHaveText('Image 1 sur 2');
  await expect(next).toBeFocused();
  await expectAccessible(page);

  const noJs = await browser.newContext({ javaScriptEnabled: false });
  try {
    const fallback = await noJs.newPage();
    await fallback.goto('/test-prime-builder');
    const images = fallback.locator('[data-pb-carousel] [data-pb-slide]');
    await expect(images).toHaveCount(2);
    await expect(images.nth(0)).toBeVisible();
    await expect(images.nth(1)).toBeVisible();
    await expect(fallback.locator('.pb-carousel-controls')).toHaveCount(0);
  } finally { await noJs.close(); }
});

test('gallery lightbox works with keyboard, restores focus and keeps image links without JS', async ({ page, browser }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  const status = await page.evaluate(async entryId => {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const element = { id: 'gallery_demo', type: 'gallery', settings: { images: '/assets/plugins/prime-builder/icon.svg|Couronne\n/assets/plugins/prime-builder/icon.svg?second=1|Deuxième image' }, styles: {}, children: [] };
    const result = await fetch(`/api/prime-builder/v1/documents/${entryId}`, {
      method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf },
      body: JSON.stringify({ content: { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: [element] } }] }, status: 'published', published_at: '' }),
    });
    return result.status;
  }, id);
  expect(status).toBe(200);
  await page.goto('/test-prime-builder');
  const first = page.getByRole('link', { name: 'Agrandir l’image 1 : Couronne' });
  await first.focus();
  await page.keyboard.press('Enter');
  const dialog = page.getByRole('dialog', { name: 'Image agrandie' });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Fermer l’image' })).toBeFocused();
  await page.keyboard.press('Shift+Tab');
  await expect(dialog.getByRole('button', { name: 'Image suivante' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(dialog.getByRole('button', { name: 'Fermer l’image' })).toBeFocused();
  await expect(dialog.locator('img')).toHaveAttribute('alt', 'Couronne');
  await expect(dialog.getByRole('status')).toHaveText('Image 1 sur 2 — Couronne');
  await page.keyboard.press('ArrowRight');
  await expect(dialog.locator('img')).toHaveAttribute('alt', 'Deuxième image');
  await expect(dialog.getByRole('status')).toHaveText('Image 2 sur 2 — Deuxième image');
  await expectAccessible(page);
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(first).toBeFocused();
  await first.press('Enter');
  await dialog.getByRole('button', { name: 'Image précédente' }).click();
  await expect(dialog.locator('img')).toHaveAttribute('alt', 'Deuxième image');
  await dialog.getByRole('button', { name: 'Fermer l’image' }).click();
  await expect(first).toBeFocused();

  const noJs = await browser.newContext({ javaScriptEnabled: false });
  try {
    const fallback = await noJs.newPage();
    await fallback.goto('/test-prime-builder');
    await expect(fallback.getByRole('link', { name: 'Agrandir l’image 1 : Couronne' })).toHaveAttribute('href', '/assets/plugins/prime-builder/icon.svg');
    await expect(fallback.locator('dialog')).toHaveCount(0);
  } finally { await noJs.close(); }
});

test('OpenStreetMap is requested only after activation and remains a link without JavaScript', async ({ page, browser }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Ajouter Carte OpenStreetMap' }).click();
  await page.getByLabel('Titre de la carte').fill('Carte de Créteil');
  await page.getByLabel('Latitude (de −85 à 85, point décimal)').fill('48.790000');
  await page.getByLabel('Longitude (de −180 à 180, point décimal)').fill('2.450000');
  await page.getByLabel('Zoom (3 à 18)').fill('14');
  await page.locator('[data-pb-status]').selectOption('published');
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  const requests = [];
  page.on('request', request => {
    if (/openstreetmap\.org$/i.test(new URL(request.url()).hostname)) requests.push(request.url());
  });
  await page.route('https://www.openstreetmap.org/**', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<!doctype html><html lang="fr"><head><title>Carte simulée</title></head><body>Carte simulée</body></html>' }));
  const response = await page.goto('/test-prime-builder');
  expect(response.headers()['content-security-policy']).toContain('https://www.openstreetmap.org');
  const map = page.locator('.pb-map');
  const button = map.getByRole('button', { name: 'Charger la carte OpenStreetMap' });
  await expect(button).toBeVisible();
  await expect(map.locator('iframe')).toHaveCount(0);
  await expect(map.getByRole('link', { name: 'Ouvrir la carte sur OpenStreetMap' })).toHaveAttribute('href', 'https://www.openstreetmap.org/?mlat=48.790000&mlon=2.450000#map=14/48.790000/2.450000');
  await expectAccessible(page);
  expect(requests).toEqual([]);
  await button.focus();
  await page.keyboard.press('Enter');
  const frame = map.locator('iframe');
  await expect(frame).toHaveAttribute('title', 'Carte de Créteil');
  await expect(frame).toHaveAttribute('referrerpolicy', 'no-referrer');
  await expect(frame).toHaveAttribute('src', /https:\/\/www\.openstreetmap\.org\/export\/embed\.html\?/);
  await expect.poll(() => requests.length).toBe(1);
  await expectAccessible(page);

  const noJs = await browser.newContext({ javaScriptEnabled: false });
  try {
    const fallback = await noJs.newPage();
    await fallback.goto('/test-prime-builder');
    await expect(fallback.locator('.pb-map iframe')).toHaveCount(0);
    await expect(fallback.getByRole('link', { name: 'Ouvrir la carte sur OpenStreetMap' })).toBeVisible();
    await expect(fallback.locator('[data-pb-map-load]')).toBeHidden();
  } finally { await noJs.close(); }
});

test('theme zone follows the core zone and leaves a safe no-JavaScript fallback', async ({ page, browser }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  const saveZone = text => page.request.post('/admin/appearance/zones/sidebar/save', {
    headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf },
    data: { content: { version: 1, blocks: [{ id: 'zone_text', type: 'paragraph', data: { text } }, { id: 'zone_search', type: 'dynamic', data: { name: 'search_form', attrs: {} } }] } },
  });
  expect((await saveZone('Contenu de zone initial')).status()).toBe(200);
  await page.getByRole('button', { name: 'Ajouter Zone du thème' }).click();
  await page.getByLabel('Choisir une zone du thème').selectOption('sidebar');
  await page.locator('[data-pb-status]').selectOption('published');
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  await page.goto('/test-prime-builder');
  const zone = page.locator('.pb-theme_zone');
  await expect(zone).toContainText('Contenu de zone initial');
  const searchInput = zone.getByRole('search').locator('input[type="search"]');
  const searchId = await searchInput.getAttribute('id');
  expect(searchId).toMatch(/^pb-zone-/);
  await expect(zone.getByRole('search').locator('label')).toHaveAttribute('for', searchId);
  expect(await page.locator('[id]').evaluateAll(nodes => nodes.map(node => node.id))).toEqual(expect.arrayContaining([searchId]));
  expect(await page.locator('[id]').evaluateAll((nodes, id) => nodes.filter(node => node.id === id).length, searchId)).toBe(1);
  await expectAccessible(page);
  expect((await saveZone('Contenu de zone actualisé')).status()).toBe(200);
  await page.reload();
  await expect(zone).toContainText('Contenu de zone actualisé');
  await expect(zone).not.toContainText('Contenu de zone initial');
  const noJs = await browser.newContext({ javaScriptEnabled: false });
  try {
    const fallback = await noJs.newPage();
    await fallback.goto('/test-prime-builder');
    await expect(fallback.locator('.pb-theme_zone')).toContainText('La zone du thème est disponible avec JavaScript');
    await expect(fallback.locator('.pb-theme_zone')).not.toContainText('Contenu de zone initial');
    await expect(fallback.locator('.pb-theme_zone')).not.toContainText('Contenu de zone actualisé');
  } finally { await noJs.close(); }
  const reset = await page.request.post('/admin/appearance/zones/sidebar/save', {
    headers: { 'Accept': 'application/json', 'X-CSRF-Token': csrf },
    data: { content: { version: 1, blocks: [] } },
  });
  expect(reset.status()).toBe(200);
});

test('external videos contact their providers only after keyboard activation', async ({ page, browser }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  const status = await page.evaluate(async entryId => {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const elements = [
      { id: 'youtube_demo', type: 'video', settings: { url: 'https://www.youtube.com/watch?v=M7lc1UVf-VE', video_title: 'Présentation vidéo' }, styles: {}, children: [] },
      { id: 'vimeo_demo', type: 'video', settings: { url: 'https://vimeo.com/12345678', video_title: 'Entretien vidéo' }, styles: {}, children: [] },
    ];
    const result = await fetch(`/api/prime-builder/v1/documents/${entryId}`, {
      method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf },
      body: JSON.stringify({ content: { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements } }] }, status: 'published', published_at: '' }),
    });
    return result.status;
  }, id);
  expect(status).toBe(200);

  const providerRequests = [];
  page.on('request', request => {
    if (/youtube|youtu\.be|ytimg|googlevideo|vimeo|vimeocdn|doubleclick/i.test(new URL(request.url()).hostname)) providerRequests.push(request.url());
  });
  const response = '<!doctype html><html lang="fr"><head><title>Lecteur de test</title></head><body>Lecteur</body></html>';
  await page.route('https://www.youtube-nocookie.com/**', route => route.fulfill({ status: 200, contentType: 'text/html', body: response }));
  await page.route('https://player.vimeo.com/**', route => route.fulfill({ status: 200, contentType: 'text/html', body: response }));
  await page.goto('/test-prime-builder');
  const youtube = page.locator('[data-pb-id="youtube_demo"]');
  const vimeo = page.locator('[data-pb-id="vimeo_demo"]');
  const ytButton = youtube.getByRole('button', { name: 'Charger la vidéo YouTube' });
  const vimeoButton = vimeo.getByRole('button', { name: 'Charger la vidéo Vimeo' });
  await expect(ytButton).toBeVisible();
  await expect(vimeoButton).toBeVisible();
  await expect(page.locator('.pb-video iframe')).toHaveCount(0);
  await expectAccessible(page);
  expect(providerRequests).toEqual([]);
  await ytButton.focus();
  await page.keyboard.press('Enter');
  const ytFrame = youtube.locator('iframe');
  await expect(ytFrame).toHaveAttribute('title', 'Présentation vidéo');
  await expect(ytFrame).toHaveAttribute('src', 'https://www.youtube-nocookie.com/embed/M7lc1UVf-VE?autoplay=1');
  await expect.poll(() => providerRequests.length).toBe(1);
  await vimeoButton.focus();
  await page.keyboard.press('Space');
  await expect(vimeo.locator('iframe')).toHaveAttribute('src', 'https://player.vimeo.com/video/12345678?dnt=1&autoplay=1');
  await expect.poll(() => providerRequests.length).toBe(2);

  const noJs = await browser.newContext({ javaScriptEnabled: false });
  try {
    const fallback = await noJs.newPage();
    await fallback.goto('/test-prime-builder');
    await expect(fallback.locator('.pb-video iframe')).toHaveCount(0);
    await expect(fallback.getByRole('link', { name: 'Ouvrir la vidéo sur YouTube' })).toHaveAttribute('href', 'https://www.youtube.com/watch?v=M7lc1UVf-VE');
    await expect(fallback.getByRole('link', { name: 'Ouvrir la vidéo sur Vimeo' })).toHaveAttribute('href', 'https://vimeo.com/12345678');
    await expect(fallback.locator('[data-pb-video-load]')).toHaveCount(2);
    await expect(fallback.locator('[data-pb-video-load]').first()).toBeHidden();
  } finally { await noJs.close(); }
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
  await expect(page.locator('.pb-element')).toHaveCount(39);
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

test('history lists actions and can jump backward and forward with focus restored', async ({ page }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Ajouter Titre' }).click();
  await page.getByLabel('Texte', { exact: true }).fill('Étape de l’historique');
  const frame = page.frameLocator('[data-pb-frame]');
  const heading = frame.locator('.pb-heading').filter({ hasText: 'Étape de l’historique' });
  await expect(heading).toBeVisible();
  const elementId = await heading.getAttribute('data-pb-id');
  const opener = page.getByRole('button', { name: 'Historique', exact: true });
  await opener.click();
  const dialog = page.getByRole('dialog', { name: 'Historique' });
  await expect(dialog.getByRole('button', { name: /Contenu : Texte/ })).toHaveAttribute('aria-current', 'step');
  await expectAccessible(page);
  await dialog.getByRole('button', { name: 'État de départ' }).click();
  await expect(frame.locator(`[data-pb-id="${elementId}"]`)).toHaveCount(0);
  await dialog.getByRole('button', { name: 'Ajouter : Titre' }).click();
  await expect(frame.locator(`[data-pb-id="${elementId}"]`)).toHaveCount(1);
  await dialog.getByRole('button', { name: /Contenu : Texte/ }).click();
  await expect(heading).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(opener).toBeFocused();
  await page.keyboard.press('Control+z');
  await expect(heading).toHaveCount(0);
  await page.keyboard.press('Control+Shift+z');
  await expect(heading).toBeVisible();
  await page.setViewportSize({ width: 375, height: 812 });
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await opener.click();
  await expectAccessible(page);
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

test('context menu supports every action using only the keyboard', async ({ page }) => {
  expect(menuId).toMatch(/^[1-9][0-9]*$/);
  // No mouse, locator.focus, fill, click, or API setup: login and editing use keys.
  await page.goto('/admin/login');
  await expect(page.locator('input[name="email"]')).toBeFocused();
  await page.keyboard.insertText(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.keyboard.press('Tab');
  await expect(page.locator('input[name="password"]')).toBeFocused();
  await page.keyboard.insertText(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/), page.keyboard.press('Enter')]);
  await page.goto(`/admin/prime-builder/edit/${menuId}`);

  async function addHeading(value) {
    await page.keyboard.press('Control+k');
    await expect(page.getByLabel('Rechercher une commande')).toBeFocused();
    await page.keyboard.insertText('Ajouter Titre');
    await page.keyboard.press('Tab');
    await expect(page.getByRole('dialog').getByRole('button', { name: 'Ajouter Titre', exact: true })).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.getByLabel('Texte', { exact: true })).toBeFocused();
    await page.keyboard.press('Control+a');
    await page.keyboard.insertText(value);
  }
  const menu = page.getByRole('menu', { name: 'Actions de l’élément' });
  const opener = page.locator('[data-pb-context-open]');
  const frame = page.frameLocator('[data-pb-frame]');
  await addHeading('Source clavier intégral');
  await page.keyboard.press('Shift+Tab');
  await page.keyboard.press('Shift+Tab');
  await expect(page.getByRole('button', { name: 'Style', exact: true })).toBeFocused();
  await page.keyboard.press('Enter');
  await page.keyboard.press('Tab');
  await page.keyboard.press('Tab');
  await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeFocused();
  await page.keyboard.insertText('#123456');
  await page.keyboard.press('Shift+Tab');
  await page.keyboard.press('Shift+F10');
  await expect(menu.getByRole('menuitem', { name: 'Copier', exact: true })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(opener).toBeFocused();
  await expect(page.locator('[data-pb-live]')).toHaveText('Élément copié dans le constructeur.');

  await addHeading('Cible clavier intégral');
  await page.keyboard.press('Shift+Tab');
  await page.keyboard.press('Shift+F10');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('ArrowDown');
  await expect(menu.getByRole('menuitem', { name: 'Coller le style' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(frame.getByRole('heading', { name: 'Cible clavier intégral' })).toHaveCSS('color', 'rgb(18, 52, 86)');
  await page.keyboard.press('Shift+F10');
  await page.keyboard.press('ArrowDown');
  await expect(menu.getByRole('menuitem', { name: 'Coller', exact: true })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(frame.getByRole('heading', { name: 'Source clavier intégral' })).toHaveCount(2);

  await page.keyboard.press('Shift+F10');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('ArrowDown');
  await expect(menu.getByRole('menuitem', { name: 'Dupliquer', exact: true })).toBeFocused();
  await page.keyboard.press('Enter');
  const sources = frame.getByRole('heading', { name: 'Source clavier intégral' });
  await expect(sources).toHaveCount(3);
  await expect(sources.nth(1)).toHaveCSS('color', 'rgb(18, 52, 86)');

  await page.keyboard.press('Shift+F10');
  for (let i = 0; i < 4; i++) await page.keyboard.press('ArrowDown');
  await expect(menu.getByRole('menuitem', { name: 'Enregistrer comme modèle' })).toBeFocused();
  await page.keyboard.press('Enter');
  const dialog = page.getByRole('dialog', { name: 'Enregistrer comme modèle' });
  await expect(dialog.getByLabel('Nom du modèle')).toBeFocused();
  await page.keyboard.press('Control+a');
  await page.keyboard.insertText('Modèle sans souris');
  const saved = page.waitForResponse(response => response.url().endsWith('/api/prime-builder/v1/templates') && response.request().method() === 'POST');
  await page.keyboard.press('Enter');
  expect((await saved).status()).toBe(201);
  await expect(dialog).toBeHidden();
  await expect(opener).toBeFocused();
  await page.keyboard.press('Shift+F10');
  await page.keyboard.press('Escape');
  await expect(menu).toBeHidden();
  await expect(opener).toBeFocused();

  await page.keyboard.press('Shift+F10');
  await page.keyboard.press('End');
  await page.keyboard.press('ArrowUp');
  await expect(menu.getByRole('menuitem', { name: 'Réinitialiser le style' })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(sources.nth(1)).not.toHaveCSS('color', 'rgb(18, 52, 86)');
  await page.keyboard.press('Shift+F10');
  await page.keyboard.press('End');
  await expect(menu.getByRole('menuitem', { name: 'Supprimer', exact: true })).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(sources).toHaveCount(2);
  await expectAccessible(page);
  await page.keyboard.press('Control+s');
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  await page.reload();
  await expect(frame.getByRole('heading', { name: 'Source clavier intégral' })).toHaveCount(2);
  await expect(frame.getByRole('heading', { name: 'Cible clavier intégral' })).toHaveCSS('color', 'rgb(18, 52, 86)');
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

test('drag ordering, nesting and keyboard alternatives persist in canvas and navigator', async ({ page }) => {
  expect(dragId).toMatch(/^[1-9][0-9]*$/);
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${dragId}`);
  async function addHeading(name, first = false) {
    if (!first) await page.locator('[data-pb-panel="widgets"]').click();
    await page.getByRole('button', { name: 'Ajouter Titre' }).click();
    await page.getByLabel('Texte', { exact: true }).fill(name);
  }
  await addHeading('Premier', true);
  await addHeading('Deuxième');
  await addHeading('Troisième');
  const frame = page.frameLocator('[data-pb-frame]');
  for (const name of ['Premier', 'Deuxième', 'Troisième']) await expect(frame.locator('.pb-heading').filter({ hasText: name })).toBeVisible();
  const first = await frame.locator('.pb-heading').filter({ hasText: 'Premier' }).getAttribute('data-pb-id');
  const second = await frame.locator('.pb-heading').filter({ hasText: 'Deuxième' }).getAttribute('data-pb-id');
  const third = await frame.locator('.pb-heading').filter({ hasText: 'Troisième' }).getAttribute('data-pb-id');
  await page.locator('[data-pb-panel="widgets"]').click();
  await page.getByRole('button', { name: 'Ajouter Conteneur' }).click();
  await page.getByRole('button', { name: 'Avancé', exact: true }).click();
  await page.getByLabel('Marge intérieure', { exact: true }).fill('50');
  const container = frame.locator('.pb-container');
  await expect(container).toBeVisible();
  const group = await container.getAttribute('data-pb-id');
  const one = frame.locator(`[data-pb-id="${first}"]`);
  const three = frame.locator(`[data-pb-id="${third}"]`);
  const rootOrder = () => frame.locator('.pb-layout > .pb-element').evaluateAll(nodes => nodes.map(node => node.dataset.pbId));
  const groupOrder = () => frame.locator(`[data-pb-id="${group}"] > .pb-element`).evaluateAll(nodes => nodes.map(node => node.dataset.pbId));
  await expect.poll(rootOrder).toEqual([first, second, third, group]);

  await three.dragTo(one, { targetPosition: { x: 100, y: 2 } });
  await expect.poll(rootOrder).toEqual([third, first, second, group]);
  await expect(page.locator('[data-pb-live]')).toContainText('avant');
  await page.locator('[data-pb-panel="navigator"]').click();
  const row = elementId => page.locator(`[data-pb-tree-id="${elementId}"]`);
  const secondBox = await row(second).boundingBox();
  await row(first).dragTo(row(second), { targetPosition: { x: 100, y: secondBox.height - 2 } });
  await expect.poll(rootOrder).toEqual([third, second, first, group]);
  await expect(page.locator('[data-pb-live]')).toContainText('après');
  await row(first).dragTo(row(group), { targetPosition: { x: 5, y: 5 } });
  await expect.poll(groupOrder).toEqual([first]);
  await row(group).dragTo(row(first), { targetPosition: { x: 100, y: 2 } });
  await expect(page.locator('[data-pb-live]')).toHaveText('Ce déplacement n’est pas possible.');
  await expect.poll(groupOrder).toEqual([first]);

  // The same nesting and ordering are available without dragging.
  await row(third).locator('[data-pb-tree-select]').focus();
  await page.keyboard.press('Enter');
  const parent = page.getByLabel('Déplacer vers');
  await parent.focus();
  await page.keyboard.press('End');
  await expect(parent).toHaveValue(group);
  await expect(parent).toBeFocused();
  await expect.poll(groupOrder).toEqual([first, third]);
  await expect(page.locator('[data-pb-live]')).toContainText('Déplacer vers');
  await page.keyboard.press('Tab');
  const up = page.locator('[data-pb-action="move_up"]');
  const down = page.locator('[data-pb-action="move_down"]');
  await expect(up).toBeFocused();
  await page.keyboard.press('Enter');
  await expect.poll(groupOrder).toEqual([third, first]);
  await expect(up).toBeFocused();
  await expect(page.locator('[data-pb-live]')).toContainText('Monter');
  await page.keyboard.press('Tab');
  await expect(down).toBeFocused();
  await page.keyboard.press('Enter');
  await expect.poll(groupOrder).toEqual([first, third]);
  await expect(down).toBeFocused();
  await expect(page.locator('[data-pb-live]')).toContainText('Descendre');
  await page.keyboard.press('Enter');
  await expect.poll(groupOrder).toEqual([first, third]);
  await expect(down).toBeFocused();
  await expect(page.locator('[data-pb-live]')).toHaveText('Ce déplacement n’est pas possible.');

  // Drag a widget from the parent document into the iframe, before a sibling.
  await page.locator('[data-pb-panel="widgets"]').click();
  const card = page.getByRole('button', { name: 'Ajouter Séparateur' });
  await card.scrollIntoViewIfNeeded();
  const sourceBox = await card.boundingBox();
  const targetBox = await one.boundingBox();
  await page.mouse.move(sourceBox.x + sourceBox.width / 2, sourceBox.y + sourceBox.height / 2);
  await page.mouse.down();
  await page.mouse.move(targetBox.x + 100, targetBox.y + 2, { steps: 20 });
  await expect(one).toHaveAttribute('data-pb-drop', 'before');
  await page.mouse.up();
  await expect.poll(async () => (await groupOrder()).length).toBe(3);
  const inserted = await groupOrder();
  expect(inserted.slice(1)).toEqual([first, third]);
  await expect(frame.locator(`[data-pb-id="${inserted[0]}"]`)).toHaveClass('pb-element pb-separator');

  // Horizontal flex containers use left/right placement instead of top/bottom.
  await page.locator('[data-pb-panel="navigator"]').click();
  await row(group).locator('[data-pb-tree-select]').click();
  await page.getByLabel('Direction').selectOption('row');
  await expect(container).toHaveCSS('flex-direction', 'row');
  const horizontalBox = await one.boundingBox();
  await three.dragTo(one, { targetPosition: { x: 2, y: horizontalBox.height / 2 } });
  await expect.poll(groupOrder).toEqual([inserted[0], third, first]);
  await page.locator('[data-pb-panel="navigator"]').click();
  await row(group).locator('[data-pb-tree-select]').click();
  await page.getByLabel('Disposition').selectOption('grid');
  await page.getByLabel('Colonnes').fill('2');
  await expect(container).toHaveCSS('display', 'grid');
  const gridTarget = await three.boundingBox();
  await one.dragTo(three, { targetPosition: { x: 2, y: gridTarget.height / 2 } });
  await expect.poll(groupOrder).toEqual([inserted[0], first, third]);
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  await page.goto(`/admin/prime-builder/edit/${dragId}`);
  await expect.poll(groupOrder).toEqual([inserted[0], first, third]);
  await page.locator('[data-pb-panel="navigator"]').click();
  await expectAccessible(page);
  await page.setViewportSize({ width: 375, height: 812 });
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expectAccessible(page);
});

test('responsive controls show inherited values and their source', async ({ page }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Ajouter Titre' }).click();
  await page.getByLabel('Texte', { exact: true }).fill('Héritage responsive');
  await page.getByRole('button', { name: 'Style', exact: true }).click();
  const font = page.getByLabel('Taille du texte', { exact: true });
  const heading = page.frameLocator('[data-pb-frame]').getByRole('heading', { name: 'Héritage responsive' });
  await expect(font).toHaveAccessibleDescription('Valeur héritée du parent ou valeur par défaut.');
  await font.fill('32');
  await expect(font).toHaveAccessibleDescription('Défini pour Ordinateur.');
  await page.getByLabel('Couleur globale du texte').selectOption('kit:primary');
  await page.getByRole('button', { name: 'Tablette', exact: true }).click();
  await expect(font).toHaveValue('');
  await expect(font).toHaveAccessibleDescription('Hérité de Ordinateur : 32px');
  await expect(page.getByLabel('Couleur du texte', { exact: true })).toHaveAccessibleDescription('Hérité de Ordinateur : Couleur principale du kit');
  await expect(heading).toHaveCSS('font-size', '32px');
  await font.fill('24');
  await expect(font).toHaveAccessibleDescription('Défini pour Tablette.');
  await page.getByRole('button', { name: 'Mobile', exact: true }).click();
  await expect(font).toHaveAccessibleDescription('Hérité de Tablette : 24px');
  await expect(heading).toHaveCSS('font-size', '24px');
  await font.fill('18');
  await expect(font).toHaveAccessibleDescription('Défini pour Mobile.');
  await expect(heading).toHaveCSS('font-size', '18px');
  await font.fill('');
  await expect(font).toHaveAccessibleDescription('Hérité de Tablette : 24px');
  await expect(heading).toHaveCSS('font-size', '24px');
  await expectAccessible(page);
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

test('unavailable block between existing blocks remains read-only and survives saving', async ({ page }) => {
  const missingId = process.env.E2E_MISSING_ENTRY_ID;
  expect(missingId).toMatch(/^[1-9][0-9]*$/);
  let nativeDialogs = 0;
  page.on('dialog', dialog => { nativeDialogs += 1; dialog.dismiss(); });
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${missingId}`);
  await expect(page.locator('[data-pb-save]')).toBeEnabled();
  const types = await page.evaluate(() => {
    const config = JSON.parse(document.querySelector('[data-pb-config]').value);
    return config.document.blocks[0].data.elements[0].children.map(node => node.type);
  });
  expect(types).toEqual(['prime_block', 'original_content', 'prime_block']);
  const frame = page.frameLocator('[data-pb-frame]');
  await expect(frame.getByText('Premier contenu disponible')).toBeVisible();
  await expect(frame.getByText('Bloc indisponible conservé')).toBeVisible();
  await expect(frame.getByText('Dernier contenu disponible')).toBeVisible();
  await page.getByRole('button', { name: 'Structure', exact: true }).click();
  await page.getByRole('button', { name: 'Contenu d’origine', exact: true }).click();
  await expect(page.locator('[data-pb-fields]').getByText('Ce contenu d’origine ne peut pas être modifié ici.', { exact: false })).toBeVisible();
  await expect(page.locator('[data-pb-fields] input')).toHaveCount(0);
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  await page.goto('/bloc-indisponible');
  const content = page.locator('.pb-layout');
  await expect(content.locator('[data-legacy="missing"]')).toHaveText('Bloc indisponible conservé');
  await expect(content.locator('#dernier-contenu-disponible')).toHaveText('Dernier contenu disponible');
  const text = await content.innerText();
  expect(text.indexOf('Premier contenu disponible')).toBeGreaterThanOrEqual(0);
  expect(text.indexOf('Premier contenu disponible')).toBeLessThan(text.indexOf('Bloc indisponible conservé'));
  expect(text.indexOf('Bloc indisponible conservé')).toBeLessThan(text.indexOf('Dernier contenu disponible'));
  expect(nativeDialogs).toBe(0);
  await expectAccessible(page);
});

test('unrecoverable block boundaries remain one read-only group after saving and reopening', async ({ page }) => {
  const groupId = process.env.E2E_GROUP_ENTRY_ID;
  expect(groupId).toMatch(/^[1-9][0-9]*$/);
  await page.goto('/blocs-groupes?before-grouping=1');
  const before = (await page.locator('.aurora-prose').innerHTML()).trim();
  expect(before).toContain('Premier bloc d’origine');
  expect(before).toContain('Milieu conservé');
  expect(before).toContain('Dernier bloc d’origine');
  let nativeDialogs = 0;
  page.on('dialog', dialog => { nativeDialogs += 1; dialog.dismiss(); });
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${groupId}`);
  const group = await page.evaluate(() => JSON.parse(document.querySelector('[data-pb-config]').value).document.blocks[0].data.elements);
  expect(group.map(node => node.type)).toEqual(['original_group']);
  expect(group[0].children).toEqual([]);
  const warning = page.locator('[data-pb-group-warning]');
  await expect(warning).toBeVisible();
  await expect(warning).toHaveAttribute('role', 'status');
  await expect(warning).toContainText('ne peuvent pas être modifiés, déplacés ou supprimés séparément');
  await expect(warning).toContainText('contenu dynamique reste figé');
  const frame = page.frameLocator('[data-pb-frame]');
  await expect(frame.locator('.pb-original_group')).toHaveCount(1);
  await expect(frame.locator('.pb-original_group')).toContainText('Milieu conservé');
  await page.getByRole('button', { name: 'Structure', exact: true }).click();
  await page.getByRole('button', { name: 'Contenus d’origine groupés', exact: true }).click();
  await expect(page.locator('[data-pb-fields] input, [data-pb-fields] textarea, [data-pb-fields] select')).toHaveCount(0);
  await expect(page.locator('[data-pb-fields]')).toContainText('supprimer cet élément affecte tout le groupe');
  await expectAccessible(page);
  // The group can be removed as a whole, never as separate original blocks.
  await page.locator('[data-pb-action="remove"]').click();
  await expect(warning).toBeHidden();
  await page.locator('[data-pb-undo]').click();
  await expect(warning).toBeVisible();
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  await page.reload();
  await expect(warning).toBeVisible();
  await expect(page.frameLocator('[data-pb-frame]').locator('.pb-original_group')).toContainText('Dernier bloc d’origine');
  await page.goto('/blocs-groupes?after-grouping=1');
  await expect(page.locator('.pb-original_group')).toHaveCount(1);
  expect((await page.locator('.pb-original_group').innerHTML()).trim()).toBe(before);
  expect(nativeDialogs).toBe(0);
  await expectAccessible(page);
});

test('filtered HTML is previewed, sanitized, saved and reopened', async ({ page }) => {
  expect(htmlId).toMatch(/^[1-9][0-9]*$/);
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${htmlId}`);
  await page.getByRole('button', { name: 'Ajouter HTML filtré', exact: true }).click();
  const field = page.getByLabel('Code HTML filtré', { exact: true });
  await field.fill('<p>Un <strong>fragment HTML</strong><script>alert(1)</script><a href="javascript:alert(1)" onclick="alert(2)">lien</a></p>');
  const frame = page.frameLocator('[data-pb-frame]');
  await expect(frame.locator('.pb-html strong')).toHaveText('fragment HTML');
  await expect(frame.locator('.pb-html script')).toHaveCount(0);
  await page.locator('[data-pb-status]').selectOption('published');
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  await page.reload();
  await expect(page.frameLocator('[data-pb-frame]').locator('.pb-html strong')).toHaveText('fragment HTML');
  await page.goto('/html-filtre?filtered-html=1');
  await expect(page.locator('.pb-html strong')).toHaveText('fragment HTML');
  await expect(page.locator('.pb-html script, .pb-html [onclick]')).toHaveCount(0);
  await expect(page.locator('.pb-html [href^="javascript:"]')).toHaveCount(0);
  await expectAccessible(page);
});

test('simple contact form sends anonymously and the protected inbox receives it', async ({ page }) => {
  expect(formId).toMatch(/^[1-9][0-9]*$/);
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${formId}`);
  await page.getByRole('button', { name: 'Ajouter Formulaire de contact', exact: true }).click();
  await expect(page.frameLocator('[data-pb-frame]').getByLabel('Adresse e-mail')).toBeVisible();
  await page.locator('[data-pb-status]').selectOption('published');
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');

  await page.goto('/formulaire-test?contact=1');
  const form = page.locator('[data-pb-form]');
  await expect(form).toBeVisible();
  await expect(form.locator('input[name="_csrf_token"], input[name="token"]')).toHaveCount(0);
  await expect(form.getByRole('button', { name: 'Envoyer', exact: true })).toBeEnabled();
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('contact-public-desktop.png'), fullPage: true });
  await page.setViewportSize({ width: 375, height: 812 });
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('contact-public-mobile.png'), fullPage: true });
  await form.getByLabel('Nom', { exact: true }).fill('Camille Exemple');
  await form.getByLabel('Adresse e-mail', { exact: true }).fill('camille@example.test');
  await form.getByLabel('Message', { exact: true }).fill('Bonjour <img src=x onerror=alert(1)> depuis le navigateur.');
  const sent = page.waitForResponse(response => response.url().endsWith(`/api/prime-builder/v1/forms/${formId}/submit`) && response.request().method() === 'POST');
  await form.getByRole('button', { name: 'Envoyer', exact: true }).click();
  const response = await sent;
  expect(response.status()).toBe(201);
  const headers = await response.request().allHeaders();
  expect(headers.cookie).toBeUndefined();
  expect(headers['x-prime-csrf']).toBeUndefined();
  await expect(form.locator('[data-pb-form-status]')).toHaveText('Votre message a été enregistré.');

  await page.goto('/admin/prime-builder/submissions');
  const submission = page.locator('[data-pb-submission-id]').filter({ hasText: 'camille@example.test' });
  await expect(submission).toHaveCount(1);
  await expect(submission).toContainText('Bonjour <img src=x onerror=alert(1)> depuis le navigateur.');
  await expect(submission.locator('img, script')).toHaveCount(0);
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('contact-inbox-mobile.png'), fullPage: true });
  await page.setViewportSize({ width: 1440, height: 900 });
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('contact-inbox-desktop.png'), fullPage: true });
  await submission.getByRole('button', { name: /Supprimer l’envoi/ }).click();
  await expect(page.locator('[data-pb-submission-id]').filter({ hasText: 'camille@example.test' })).toHaveCount(0);
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

// Keep the saved page intact: the CI workflow disables the plugin after the suite.
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

// Leave these theme settings active for the CI's subsequent deactivation check.
test('static theme header and footer replace Aurora except on excluded content', async ({ page }) => {
  expect(htmlId).toMatch(/^[1-9][0-9]*$/);
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  const created = await page.evaluate(async () => {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const element = { id: 'theme_title', type: 'heading', settings: { text: 'En-tête construit', level: 2 }, styles: { desktop: { color: '#123456' } }, children: [] };
    const response = await fetch('/api/prime-builder/v1/templates', {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf },
      body: JSON.stringify({ name: 'Modèle de thème', kind: 'section', data: { version: 1, elements: [element] } }),
    });
    return { status: response.status, body: await response.json() };
  });
  expect(created.status).toBe(201);
  const templateId = created.body.data.id;
  await page.goto('/admin/prime-builder/theme');
  await page.getByLabel('En-tête', { exact: true }).selectOption(templateId);
  await page.getByLabel('Pied de page', { exact: true }).selectOption(templateId);
  await page.getByLabel('Contenus exclus', { exact: true }).fill(htmlId);
  await expectAccessible(page);
  await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();
  await expect(page).toHaveURL(/\/admin\/prime-builder\/theme$/);
  await expect(page.getByLabel('En-tête', { exact: true })).toHaveValue(templateId);

  await page.goto('/parcours-souris?theme-preview=1');
  const header = page.locator('header.pb-theme-header');
  const footer = page.locator('footer.pb-theme-footer');
  await expect(header.getByRole('heading', { name: 'En-tête construit' })).toBeVisible();
  await expect(footer.getByRole('heading', { name: 'En-tête construit' })).toBeVisible();
  await expect(page.locator('header.site-header')).toHaveCount(0);
  await expect(header.locator('.pb-heading')).toHaveCSS('color', 'rgb(18, 52, 86)');
  expect(await header.locator('.pb-heading').getAttribute('data-pb-id')).not.toBe(await footer.locator('.pb-heading').getAttribute('data-pb-id'));
  await expectAccessible(page);
  await page.goto('/html-filtre?theme-excluded=1');
  await expect(page.locator('header.site-header')).toBeVisible();
  await expect(page.locator('header.pb-theme-header, footer.pb-theme-footer')).toHaveCount(0);
  await expectAccessible(page);
});
