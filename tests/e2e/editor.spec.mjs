import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { readFileSync } from 'node:fs';

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
async function showMobilePreview(page, frame, selector) {
  const old = await frame.locator(selector).elementHandle();
  expect(old).not.toBeNull();
  const rendered = page.waitForResponse(response => response.url().endsWith('/render') && response.request().method() === 'POST');
  await page.locator('[data-pb-device="mobile"]').click();
  await rendered;
  await expect.poll(() => old.evaluate(node => node.isConnected)).toBe(false);
  await frame.locator(selector).evaluate(node => {
    const view = node.ownerDocument.defaultView;
    // Aurora enables smooth scrolling and has a sticky header; jump only after
    // the device change's preview replacement, leaving room for that header.
    view.scrollTo({ top: Math.max(0, node.getBoundingClientRect().top + view.scrollY - 110), behavior: 'instant' });
  });
  await expect.poll(() => frame.locator(selector).evaluate(node => {
    const top = node.getBoundingClientRect().top;
    return top >= 70 && top < node.ownerDocument.defaultView.innerHeight - 40;
  })).toBe(true);
  // The editor's sticky footer otherwise covers the middle of the iframe in a
  // full-page capture. A real user can scroll the outer page to reach its end.
  await page.evaluate(() => window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'instant' }));
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
  await page.getByRole('button', { name: 'Ajouter Titre', exact: true }).click();
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
  await page.getByRole('button', { name: 'Ajouter Titre', exact: true }).click();
  await page.getByLabel('Texte', { exact: true }).fill('Voisin conservé');
  await page.locator('[data-pb-panel="widgets"]').click();
  await page.getByRole('button', { name: 'Ajouter Titre', exact: true }).click();
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
  await page.getByRole('button', { name: 'Ajouter Titre', exact: true }).click();
  await page.getByLabel('Texte', { exact: true }).fill('Une page à la souris');
  await page.locator('[data-pb-panel="widgets"]').click();
  await page.getByRole('button', { name: 'Ajouter Texte' }).click();
  await page.getByLabel('Texte', { exact: true }).fill('<p>Texte composé à la souris.</p>');
  await page.locator('[data-pb-panel="widgets"]').click();
  await page.getByRole('button', { name: 'Ajouter Bouton', exact: true }).click();
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
  await page.getByRole('button', { name: 'Ajouter aux favoris : Titre', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Retirer des favoris : Titre', exact: true }).first()).toHaveAttribute('aria-pressed', 'true');
  await page.getByRole('button', { name: 'Ajouter Conteneur' }).click();
  await page.getByRole('button', { name: 'Éléments', exact: true }).last().click();
  await page.getByRole('button', { name: 'Ajouter Titre', exact: true }).first().click();
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
  await page.getByRole('button', { name: 'Ajouter Titre', exact: true }).click();
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

test('countdown uses a UTC deadline, announces expiry and keeps its date without JavaScript', async ({ page, browser }) => {
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
  await page.getByRole('button', { name: 'Ajouter Compte à rebours', exact: true }).click();
  await page.getByLabel('Titre', { exact: true }).fill('Ouverture des inscriptions');
  const deadline = page.getByLabel('Échéance (UTC)', { exact: true });
  await expect(deadline).toHaveAttribute('type', 'datetime-local');
  await deadline.fill('2035-01-02T00:00');
  await page.getByLabel('Message à l’échéance', { exact: true }).fill('Les inscriptions sont ouvertes.');
  const preview = page.frameLocator('[data-pb-frame]');
  await expect(preview.locator('link[href*="/css/widgets/countdown.css"]')).toHaveCount(1);
  await expect(preview.locator('.pb-countdown time')).toHaveAttribute('datetime', '2035-01-02T00:00:00Z');
  await page.getByRole('button', { name: 'Style', exact: true }).click();
  await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Avancé', exact: true }).click();
  await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');

  const visitor = await browser.newContext({ locale: 'fr-FR', timezoneId: 'Pacific/Honolulu' });
  try {
    const publicPage = await visitor.newPage();
    await publicPage.clock.install({ time: new Date('2035-01-01T23:59:55Z') });
    await publicPage.clock.pauseAt(new Date('2035-01-01T23:59:56Z'));
    await publicPage.goto('/test-prime-builder');
    const widget = publicPage.locator('.pb-countdown');
    const values = widget.getByRole('group', { name: 'Temps restant : Ouverture des inscriptions' });
    const seconds = values.locator('[data-pb-countdown-value="second"]');
    await expect(values).toBeVisible();
    await expect(seconds).toHaveText('4');
    await expect(widget.locator('time')).toHaveText('2035-01-02 00:00 UTC');
    await expect(publicPage.locator('link[href*="/css/widgets/countdown.css"]')).toHaveCount(1);
    await expect(publicPage.locator('script[src*="/js/countdown.js"]')).toHaveCount(1);
    await publicPage.clock.runFor(1000);
    await expect(seconds).toHaveText('3');
    await publicPage.clock.fastForward(3000);
    await expect(values).toBeHidden();
    await expect(widget.getByRole('status')).toHaveText('Les inscriptions sont ouvertes.');
    await publicPage.clock.resume();
    await expectAccessible(publicPage);
    await publicPage.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => publicPage.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await expectAccessible(publicPage);
  } finally { await visitor.close(); }

  const noJs = await browser.newContext({ javaScriptEnabled: false, timezoneId: 'Pacific/Honolulu' });
  try {
    const fallback = await noJs.newPage();
    await fallback.goto('/test-prime-builder');
    const widget = fallback.locator('.pb-countdown');
    await expect(widget.locator('time')).toHaveText('2035-01-02 00:00 UTC');
    await expect(widget.locator('[data-pb-countdown-values]')).toBeHidden();
    await expect(widget.locator('[data-pb-countdown-status]')).toBeEmpty();
  } finally { await noJs.close(); }
});

test('animated heading and highlighted code stay readable and safe without JavaScript', async ({ page, browser }) => {
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
  await page.getByRole('button', { name: 'Ajouter Titre animé', exact: true }).click();
  await page.getByLabel('Texte avant la mise en valeur').fill('Créons');
  await page.getByLabel('Texte mis en valeur').fill('ensemble');
  await page.getByLabel('Texte après la mise en valeur').fill('demain');
  await page.getByLabel('Effet du titre').selectOption('rise');
  const frame = page.frameLocator('[data-pb-frame]');
  await expect(frame.locator('.pb-animated_heading h2')).toHaveText('Créons ensemble demain');
  await expect(frame.locator('link[href*="/css/widgets/animated_heading.css"]')).toHaveCount(1);
  await page.getByRole('button', { name: 'Style', exact: true }).click();
  await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Avancé', exact: true }).click();
  await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
  await page.locator('[data-pb-panel="widgets"]').click();
  await page.getByRole('button', { name: 'Ajouter Code coloré', exact: true }).click();
  await page.getByLabel('Libellé du code').fill('Exemple sécurisé');
  await page.getByLabel('Langage du code').selectOption('javascript');
  const source = `const message = "</code><img src=x onerror=alert(1)>";\n// café & thé\nconst longLine = "${'x'.repeat(240)}";`;
  await page.getByLabel('Code source', { exact: true }).fill(source);
  await expect(frame.locator('.pb-code code')).toHaveText(source);
  await expect(frame.locator('.pb-code .pb-code-token--keyword').first()).toHaveText('const');
  await expect(frame.locator('link[href*="/css/widgets/code.css"]')).toHaveCount(1);
  await page.getByRole('button', { name: 'Style', exact: true }).click();
  await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Avancé', exact: true }).click();
  await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');

  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.goto('/test-prime-builder');
  await expect(page.getByRole('heading', { name: 'Créons ensemble demain', exact: true })).toHaveCount(1);
  await expect(page.locator('.pb-animated-highlight')).toHaveCSS('animation-name', 'none');
  const code = page.getByRole('region', { name: 'Exemple sécurisé' });
  await expect(code.locator('code')).toHaveText(source);
  await expect(code.locator('img, script')).toHaveCount(0);
  for (const type of ['animated_heading', 'code']) {
    await expect(page.locator(`link[href*="/css/widgets/${type}.css"]`)).toHaveCount(1);
    await expect(page.locator(`script[src*="/js/${type}.js"]`)).toHaveCount(0);
  }
  await expectAccessible(page);
  await page.setViewportSize({ width: 375, height: 812 });
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  expect(await code.evaluate(node => node.scrollWidth > node.clientWidth)).toBe(true);
  await code.focus();
  await page.keyboard.press('ArrowRight');
  await expect.poll(() => code.evaluate(node => node.scrollLeft)).toBeGreaterThan(0);
  await expectAccessible(page);

  const noJs = await browser.newContext({ javaScriptEnabled: false });
  try {
    const fallback = await noJs.newPage();
    await fallback.goto('/test-prime-builder');
    await expect(fallback.getByRole('heading', { name: 'Créons ensemble demain', exact: true })).toBeVisible();
    await expect(fallback.getByRole('region', { name: 'Exemple sécurisé' }).locator('code')).toHaveText(source);
    await expect(fallback.locator('.pb-code img, .pb-code script')).toHaveCount(0);
  } finally { await noJs.close(); }
});

test('review and flip box remain accessible with keyboard, reduced motion and no JavaScript', async ({ page, browser }) => {
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
  await page.getByRole('button', { name: 'Ajouter Avis', exact: true }).click();
  await page.getByLabel('Titre', { exact: true }).fill('Une expérience réussie');
  await page.getByLabel('Note (0 à 5 étoiles)', { exact: true }).fill('4');
  await page.getByLabel('Texte', { exact: true }).fill('<p>Un accompagnement attentif.</p>');
  await page.getByLabel('Auteur', { exact: true }).fill('Camille');
  await page.getByLabel('Fonction', { exact: true }).fill('Designer');
  await page.getByRole('button', { name: 'Style', exact: true }).click();
  await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Avancé', exact: true }).click();
  await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
  await page.locator('[data-pb-panel="widgets"]').click();
  await page.getByRole('button', { name: 'Ajouter Boîte retournable', exact: true }).click();
  await page.getByLabel('Titre du recto').fill('Nos services');
  await page.getByLabel('Texte du recto').fill('<p>Une présentation.</p>');
  await page.getByLabel('Titre du verso').fill('Les détails');
  await page.getByLabel('Texte du verso').fill('<p>Une suite accessible.</p>');
  await page.getByLabel('Adresse URL', { exact: true }).fill('/test-prime-builder#details');
  await page.getByLabel('Texte du bouton', { exact: true }).fill('Découvrir');
  const frame = page.frameLocator('[data-pb-frame]');
  await expect(frame.locator('link[href*="/css/widgets/review.css"]')).toHaveCount(1);
  await expect(frame.locator('link[href*="/css/widgets/flip_box.css"]')).toHaveCount(1);
  await expect(frame.locator('.pb-flip_box [data-pb-flip-back]').first()).toContainText('Une suite accessible.');
  await page.getByRole('button', { name: 'Style', exact: true }).click();
  await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Avancé', exact: true }).click();
  await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
  await page.locator('[data-pb-panel="widgets"]').click();
  await page.getByRole('button', { name: 'Ajouter Boîte retournable', exact: true }).click();
  await page.getByLabel('Titre du recto').fill('Deuxième carte');
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');

  await page.goto('/test-prime-builder');
  await page.emulateMedia({ reducedMotion: 'reduce' });
  const review = page.locator('.pb-review');
  await expect(review.getByRole('heading', { name: 'Une expérience réussie' })).toBeVisible();
  await expect(review.getByRole('img', { name: '4 sur 5 étoiles' })).toBeVisible();
  await expect(review).toContainText('Camille');
  for (const type of ['review', 'flip_box']) await expect(page.locator(`link[href*="/css/widgets/${type}.css"]`)).toHaveCount(1);
  await expect(page.locator('script[src*="/js/flip_box.js"]')).toHaveCount(1);
  const controls = page.locator('[data-pb-flip-control]');
  await expect(controls).toHaveCount(2);
  const ids = await controls.evaluateAll(nodes => nodes.map(node => node.getAttribute('aria-controls')));
  expect(new Set(ids).size).toBe(2);
  for (const panelId of ids) {
    expect(panelId).toBeTruthy();
    expect(await page.evaluate(id => document.querySelectorAll(`[id="${id}"]`).length, panelId)).toBe(1);
  }
  const flip = page.locator('.pb-flip_box').first();
  const button = flip.locator('[data-pb-flip-control]');
  const front = flip.locator('[data-pb-flip-front]');
  const back = flip.locator('[data-pb-flip-back]');
  await expect(button).toHaveAccessibleName('Voir le verso : Nos services');
  await expect(button).toHaveAttribute('aria-expanded', 'false');
  await expect(front).toBeVisible();
  await expect(back).toBeHidden();
  await button.focus();
  await page.keyboard.press('Enter');
  await expect(button).toHaveAccessibleName('Voir le recto : Nos services');
  await expect(button).toHaveAttribute('aria-expanded', 'true');
  await expect(front).toBeHidden();
  await expect(back).toBeVisible();
  await expect(back).toHaveCSS('animation-name', 'none');
  const link = back.getByRole('link', { name: 'Découvrir', exact: true });
  await page.keyboard.press('Tab');
  await expect(link).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(/#details$/);
  await expectAccessible(page);
  await button.focus();
  await page.keyboard.press('Space');
  await expect(front).toBeVisible();
  await expect(back).toBeHidden();
  await expect(button).toHaveAttribute('aria-expanded', 'false');
  await page.setViewportSize({ width: 375, height: 812 });
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expectAccessible(page);

  const noJs = await browser.newContext({ javaScriptEnabled: false });
  try {
    const fallback = await noJs.newPage();
    await fallback.goto('/test-prime-builder');
    const staticFlip = fallback.locator('.pb-flip_box').first();
    await expect(staticFlip.locator('[data-pb-flip-front]')).toBeVisible();
    await expect(staticFlip.locator('[data-pb-flip-back]')).toBeVisible();
    await expect(staticFlip.locator('[data-pb-flip-control]')).toHaveCount(0);
    await expect(staticFlip.getByRole('link', { name: 'Découvrir' })).toHaveAttribute('href', '/test-prime-builder#details');
    await expect(fallback.locator('.pb-review').getByRole('img', { name: '4 sur 5 étoiles' })).toBeVisible();
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

test('portfolio filters projects with the keyboard and shows all without JavaScript', async ({ page, browser }) => {
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
  await page.getByRole('button', { name: 'Ajouter Portfolio', exact: true }).click();
  await page.getByLabel('Une ligne : URL image | texte alternatif | titre | catégorie', { exact: false }).fill('/assets/plugins/prime-builder/images/landscape.svg | Paysage abstrait | Identité vivante | Identité | Une marque qui prend forme. | /projet-identite\n/assets/plugins/prime-builder/images/landscape.svg | Paysage stylisé | Site accessible | Sites web | Une expérience pour tous. | /projet-site\n/assets/plugins/prime-builder/images/landscape.svg | Paysage abstrait | Nouveau regard | Identité | Un projet collectif.');
  const frame = page.frameLocator('[data-pb-frame]');
  await expect(frame.locator('.pb-portfolio [data-pb-portfolio-item]')).toHaveCount(3);
  await expect(frame.locator('.pb-portfolio')).toContainText('Nouveau regard');
  await expect(frame.locator('link[href*="/css/widgets/portfolio.css"]')).toHaveCount(1);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('portfolio-screen-editor-desktop.jpg'), fullPage: true, quality: 15 });
  await page.setViewportSize({ width: 375, height: 812 });
  await showMobilePreview(page, frame, '.pb-portfolio');
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expect.poll(() => frame.locator('html').evaluate(node => node.scrollWidth - node.clientWidth)).toBeLessThanOrEqual(0);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('portfolio-screen-editor-mobile.jpg'), fullPage: true, quality: 15 });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.getByRole('button', { name: 'Style', exact: true }).click();
  await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Avancé', exact: true }).click();
  await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');

  await page.goto('/test-prime-builder');
  const portfolio = page.locator('.pb-portfolio');
  const items = portfolio.locator('[data-pb-portfolio-item]');
  const filters = portfolio.getByRole('group', { name: 'Filtrer les projets' });
  const all = filters.getByRole('button', { name: 'Tous les projets' });
  const identity = filters.getByRole('button', { name: 'Catégorie : Identité' });
  const web = filters.getByRole('button', { name: 'Catégorie : Sites web' });
  await expect(items).toHaveCount(3);
  await expect(all).toHaveAttribute('aria-pressed', 'true');
  await expect(portfolio.getByRole('status')).toHaveText('3 projets affichés');
  await expect(portfolio.getByRole('link', { name: 'Identité vivante' })).toHaveAttribute('href', '/projet-identite');
  await expect(page.locator('link[href*="/css/widgets/portfolio.css"]')).toHaveCount(1);
  await expect(page.locator('script[src*="/js/portfolio.js"]')).toHaveCount(1);
  await identity.focus();
  await page.keyboard.press('Enter');
  await expect(identity).toHaveAttribute('aria-pressed', 'true');
  await expect(items.nth(0)).toBeVisible();
  await expect(items.nth(1)).toBeHidden();
  await expect(items.nth(2)).toBeVisible();
  await expect(portfolio.getByRole('status')).toHaveText('2 projets affichés');
  await page.keyboard.press('Tab');
  await expect(web).toBeFocused();
  await page.keyboard.press('Space');
  await expect(items.nth(0)).toBeHidden();
  await expect(items.nth(1)).toBeVisible();
  await expect(portfolio.getByRole('status')).toHaveText('1 projet affiché');
  await all.focus();
  await page.keyboard.press('Enter');
  await expect(all).toHaveAttribute('aria-pressed', 'true');
  await expect(items.nth(0)).toBeVisible();
  await expect(items.nth(1)).toBeVisible();
  await expect(items.nth(2)).toBeVisible();
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('portfolio-screen-public-desktop.jpg'), fullPage: true, quality: 15 });
  await page.setViewportSize({ width: 375, height: 812 });
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('portfolio-screen-public-mobile.jpg'), fullPage: true, quality: 15 });

  const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
  try {
    const fallback = await noJs.newPage();
    await fallback.goto('/test-prime-builder');
    const visible = fallback.locator('.pb-portfolio [data-pb-portfolio-item]');
    await expect(visible).toHaveCount(3);
    for (let index = 0; index < 3; index++) await expect(visible.nth(index)).toBeVisible();
    await expect(fallback.locator('.pb-portfolio-filters')).toHaveCount(0);
    await expect(fallback.getByRole('link', { name: 'Identité vivante' })).toHaveAttribute('href', '/projet-identite');
  } finally { await noJs.close(); }
});

test('slideshow exposes safe slides and keyboard navigation with a no-script fallback', async ({ page, browser }) => {
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
  await page.getByRole('button', { name: 'Ajouter Diaporama', exact: true }).click();
  await page.getByLabel('Nom du diaporama', { exact: true }).fill('Nos projets');
  await page.getByLabel('Une ligne : URL image', { exact: false }).fill('/assets/plugins/prime-builder/images/landscape.svg | Paysage abstrait | Horizons créatifs | Nos idées prennent forme. | Voir les projets | /projets\n/assets/plugins/prime-builder/images/landscape.svg | Paysage stylisé | Construire ensemble | Un second projet.');
  const frame = page.frameLocator('[data-pb-frame]');
  await expect(frame.locator('.pb-slideshow [data-pb-slide]')).toHaveCount(2);
  await expect(frame.locator('.pb-slideshow')).toContainText('Construire ensemble');
  await expect(frame.locator('link[href*="/css/widgets/slideshow.css"]')).toHaveCount(1);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('slideshow-screen-editor-desktop.jpg'), fullPage: true, quality: 20 });
  await page.setViewportSize({ width: 375, height: 812 });
  await showMobilePreview(page, frame, '.pb-slideshow');
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expect.poll(() => frame.locator('html').evaluate(node => node.scrollWidth - node.clientWidth)).toBeLessThanOrEqual(0);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('slideshow-screen-editor-mobile.jpg'), fullPage: true, quality: 20 });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.getByRole('button', { name: 'Style', exact: true }).click();
  await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Avancé', exact: true }).click();
  await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');

  await page.goto('/test-prime-builder');
  const slideshow = page.getByRole('region', { name: 'Nos projets' });
  const slides = slideshow.locator('[data-pb-slide]');
  await expect(slides).toHaveCount(2);
  await expect(slides.nth(0)).toBeVisible();
  await expect(slides.nth(1)).toBeHidden();
  const link = slideshow.getByRole('link', { name: 'Voir les projets' });
  await expect(link).toHaveAttribute('href', '/projets');
  await expect(slideshow.getByRole('heading', { name: 'Horizons créatifs' })).toBeVisible();
  await expect(page.locator('link[href*="/css/widgets/slideshow.css"]')).toHaveCount(1);
  await expect(page.locator('script[src*="/js/image_carousel.js"]')).toHaveCount(1);
  await link.focus();
  await page.keyboard.press('ArrowRight');
  await expect(slides.nth(0)).toBeVisible();
  const next = slideshow.getByRole('button', { name: 'Diapositive suivante' });
  await next.focus();
  await page.keyboard.press('Enter');
  await expect(slides.nth(1)).toBeVisible();
  await expect(slideshow.getByRole('status')).toHaveText('Diapositive 2 sur 2');
  await page.keyboard.press('ArrowLeft');
  await expect(slides.nth(0)).toBeVisible();
  await expect(slideshow.getByRole('status')).toHaveText('Diapositive 1 sur 2');
  await expect(next).toBeFocused();
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('slideshow-screen-public-desktop.jpg'), fullPage: true, quality: 20 });
  await page.setViewportSize({ width: 375, height: 812 });
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('slideshow-screen-public-mobile.jpg'), fullPage: true, quality: 20 });

  const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
  try {
    const fallback = await noJs.newPage();
    await fallback.goto('/test-prime-builder');
    const visibleSlides = fallback.locator('.pb-slideshow [data-pb-slide]');
    await expect(visibleSlides).toHaveCount(2);
    await expect(visibleSlides.nth(0)).toBeVisible();
    await expect(visibleSlides.nth(1)).toBeVisible();
    await expect(fallback.locator('.pb-slideshow .pb-carousel-controls')).toHaveCount(0);
    await expect(fallback.getByRole('link', { name: 'Voir les projets' })).toHaveAttribute('href', '/projets');
  } finally { await noJs.close(); }
});

test('media carousel keeps native video controls and stops hidden playback', async ({ page, browser }) => {
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
  await page.getByRole('button', { name: 'Ajouter Carrousel de médias', exact: true }).click();
  await page.getByLabel('Nom du carrousel', { exact: true }).fill('Notre travail');
  await page.getByLabel('Une ligne : image', { exact: false }).fill('image | /assets/plugins/prime-builder/images/landscape.svg | Paysage abstrait | Première image\nvideo | /media-demo.webm | Vidéo de démonstration | /captions.vtt | Transcription de la vidéo');
  const frame = page.frameLocator('[data-pb-frame]');
  await expect(frame.locator('.pb-media_carousel [data-pb-slide]')).toHaveCount(2);
  await expect(frame.locator('.pb-media_carousel')).toContainText('Transcription de la vidéo');
  await expect(frame.locator('link[href*="/css/widgets/media_carousel.css"]')).toHaveCount(1);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('media-screen-editor-desktop.jpg'), fullPage: true, quality: 20 });
  await page.setViewportSize({ width: 375, height: 812 });
  await showMobilePreview(page, frame, '.pb-media_carousel');
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expect.poll(() => frame.locator('html').evaluate(node => node.scrollWidth - node.clientWidth)).toBeLessThanOrEqual(0);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('media-screen-editor-mobile.jpg'), fullPage: true, quality: 20 });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.getByRole('button', { name: 'Style', exact: true }).click();
  await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Avancé', exact: true }).click();
  await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');

  let mediaRequests = 0;
  const demoVideo = readFileSync(new URL('./fixtures/media-demo.webm', import.meta.url));
  await page.route('**/media-demo.webm', route => {
    mediaRequests++;
    return route.fulfill({ status: 200, contentType: 'video/webm', body: demoVideo });
  });
  await page.route('**/captions.vtt', route => route.fulfill({
    status: 200, contentType: 'text/vtt', body: 'WEBVTT\n\n00:00:00.000 --> 00:00:06.000\nUne démonstration.\n',
  }));
  await page.goto('/test-prime-builder');
  const carousel = page.getByRole('region', { name: 'Notre travail' });
  const slides = carousel.locator('[data-pb-slide]');
  await expect(slides).toHaveCount(2);
  await expect(slides.nth(0)).toBeVisible();
  await expect(slides.nth(1)).toBeHidden();
  await expect(carousel.getByRole('img', { name: 'Paysage abstrait' })).toBeVisible();
  await expect(page.locator('link[href*="/css/widgets/media_carousel.css"]')).toHaveCount(1);
  await expect(page.locator('script[src*="/js/image_carousel.js"]')).toHaveCount(1);
  expect(mediaRequests).toBe(0);
  const next = carousel.getByRole('button', { name: 'Média suivant' });
  await next.focus();
  await page.keyboard.press('Enter');
  await expect(slides.nth(1)).toBeVisible();
  await expect(carousel.getByRole('status')).toHaveText('Média 2 sur 2');
  const video = carousel.locator('video[aria-label="Vidéo de démonstration"]');
  await expect(video).toHaveAttribute('preload', 'none');
  await expect(video.locator('track[kind="captions"]')).toHaveAttribute('src', '/captions.vtt');
  await expect(carousel).toContainText('Transcription de la vidéo');
  await video.evaluate(node => { node.textTracks[0].mode = 'hidden'; });
  await expect.poll(() => video.locator('track').evaluate(node => node.readyState)).toBe(2);
  await expect.poll(() => video.evaluate(node => node.textTracks[0].cues.length)).toBe(1);
  await video.evaluate(node => { node.muted = true; return node.play(); });
  await expect.poll(() => video.evaluate(node => node.paused)).toBe(false);
  expect(mediaRequests).toBeGreaterThan(0);
  await video.focus();
  await page.keyboard.press('ArrowLeft');
  await expect(slides.nth(1)).toBeVisible();
  await expect.poll(() => video.evaluate(node => node.paused)).toBe(false);
  const previous = carousel.getByRole('button', { name: 'Média précédent' });
  await previous.focus();
  await page.keyboard.press('Enter');
  await expect(slides.nth(0)).toBeVisible();
  await expect.poll(() => video.evaluate(node => node.paused)).toBe(true);
  await expect(previous).toBeFocused();
  await next.click();
  await expect(slides.nth(1)).toBeVisible();
  await expect.poll(() => video.evaluate(node => node.paused)).toBe(true);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('media-screen-public-desktop.jpg'), fullPage: true, quality: 20 });
  await previous.click();
  await page.setViewportSize({ width: 375, height: 812 });
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('media-screen-public-mobile.jpg'), fullPage: true, quality: 20 });

  const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
  try {
    const fallback = await noJs.newPage();
    await fallback.goto('/test-prime-builder');
    const media = fallback.locator('.pb-media_carousel [data-pb-slide]');
    await expect(media).toHaveCount(2);
    await expect(media.nth(0)).toBeVisible();
    await expect(media.nth(1)).toBeVisible();
    await expect(fallback.locator('.pb-media_carousel .pb-carousel-controls')).toHaveCount(0);
  } finally { await noJs.close(); }
});

test('testimonial carousel works with keyboard and keeps every quote without JavaScript', async ({ page, browser }) => {
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
  await page.getByRole('button', { name: 'Ajouter Carrousel de témoignages', exact: true }).click();
  await page.getByLabel('Nom du carrousel', { exact: true }).fill('Retours de nos clients');
  await page.getByLabel('Une ligne : texte', { exact: false }).fill('Un accompagnement attentif. | Camille | Designer\nUne collaboration précieuse. | Alex | Entrepreneur');
  const frame = page.frameLocator('[data-pb-frame]');
  await expect(frame.locator('.pb-testimonial_carousel [data-pb-slide]')).toHaveCount(2);
  await expect(frame.locator('.pb-testimonial_carousel')).toContainText('Une collaboration précieuse.');
  await expect(frame.locator('link[href*="/css/widgets/testimonial_carousel.css"]')).toHaveCount(1);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('testimonial-screen-editor-desktop.jpg'), fullPage: true, quality: 45 });
  await page.setViewportSize({ width: 375, height: 812 });
  await showMobilePreview(page, frame, '.pb-testimonial_carousel');
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expect.poll(() => frame.locator('html').evaluate(node => node.scrollWidth - node.clientWidth)).toBeLessThanOrEqual(0);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('testimonial-screen-editor-mobile.jpg'), fullPage: true, quality: 20 });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.getByRole('button', { name: 'Style', exact: true }).click();
  await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Avancé', exact: true }).click();
  await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');

  await page.goto('/test-prime-builder');
  const carousel = page.getByRole('region', { name: 'Retours de nos clients' });
  const slides = carousel.locator('[data-pb-slide]');
  await expect(slides).toHaveCount(2);
  await expect(slides.nth(0)).toBeVisible();
  await expect(slides.nth(1)).toBeHidden();
  await expect(carousel.getByRole('group', { name: 'Témoignage 1 sur 2' })).toContainText('Camille');
  await expect(page.locator('link[href*="/css/widgets/testimonial_carousel.css"]')).toHaveCount(1);
  await expect(page.locator('script[src*="/js/image_carousel.js"]')).toHaveCount(1);
  const next = carousel.getByRole('button', { name: 'Témoignage suivant' });
  await next.focus();
  await page.keyboard.press('Enter');
  await expect(slides.nth(0)).toBeHidden();
  await expect(slides.nth(1)).toBeVisible();
  await expect(carousel.getByRole('status')).toHaveText('Témoignage 2 sur 2');
  await page.keyboard.press('ArrowLeft');
  await expect(slides.nth(0)).toBeVisible();
  await expect(carousel.getByRole('status')).toHaveText('Témoignage 1 sur 2');
  await expect(next).toBeFocused();
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('testimonial-screen-public-desktop.jpg'), fullPage: true, quality: 45 });
  await page.setViewportSize({ width: 375, height: 812 });
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('testimonial-screen-public-mobile.jpg'), fullPage: true, quality: 45 });

  const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
  try {
    const fallback = await noJs.newPage();
    await fallback.goto('/test-prime-builder');
    const quotes = fallback.locator('.pb-testimonial_carousel [data-pb-slide]');
    await expect(quotes).toHaveCount(2);
    await expect(quotes.nth(0)).toBeVisible();
    await expect(quotes.nth(1)).toBeVisible();
    await expect(fallback.locator('.pb-testimonial_carousel .pb-carousel-controls')).toHaveCount(0);
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
  await page.getByRole('button', { name: 'Ajouter Image', exact: true }).click();
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
  await page.getByRole('button', { name: 'Ajouter Grille d’articles' }).click();
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

test('posts grid configures public filters and offers keyboard pagination with a safe fallback', async ({ page, browser }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  const termsResponse = await page.request.get('/api/v1/terms/category');
  expect(termsResponse.status()).toBe(200);
  const terms = (await termsResponse.json()).data;
  const category = terms.find(term => term.slug === 'non-classe');
  expect(category?.id).toBeTruthy();
  const reset = await page.evaluate(async entryId => {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const content = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: [] } }] };
    return (await fetch(`/api/prime-builder/v1/documents/${entryId}`, {
      method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf },
      body: JSON.stringify({ content, status: 'published', published_at: '' }),
    })).status;
  }, id);
  expect(reset).toBe(200);
  await page.reload();
  await page.getByRole('button', { name: 'Ajouter Grille d’articles', exact: true }).click();
  await page.getByLabel('Type de contenu public').selectOption('post');
  await page.getByLabel('Taxonomie publique (facultative)').selectOption('category');
  await page.getByLabel('Identifiants de termes séparés par des virgules (10 maximum)').fill(String(category.id));
  await page.getByLabel('Identifiant de l’auteur (facultatif)').fill('1');
  await page.getByLabel('Trier par').selectOption('title');
  await page.getByLabel('Ordre de tri').selectOption('asc');
  await page.getByLabel('Nombre d’articles').fill('1');
  await page.getByRole('combobox', { name: 'Pagination', exact: true }).selectOption('show');
  await page.getByLabel('Présentation').selectOption('list');
  const frame = page.frameLocator('[data-pb-frame]');
  await expect(frame.locator('.pb-posts .pb-post-list').last()).toBeVisible();
  await expect(frame.locator('.pb-posts .pb-post-list').last()).not.toHaveClass(/pb-post-list--cards/);
  await page.getByLabel('Présentation').selectOption('cards');
  await page.getByLabel('Colonnes des cartes (1 à 4)').fill('2');
  await expect(frame.locator('.pb-post-list--cards.pb-post-columns-2').last()).toBeVisible();
  const gridId = await frame.locator('.pb-posts').last().getAttribute('data-pb-id');
  expect(gridId).toBeTruthy();
  await page.getByRole('button', { name: 'Style', exact: true }).click();
  await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Avancé', exact: true }).click();
  await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
  await expectAccessible(page);
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
  const liveRequest = page.waitForRequest(request => request.url().includes(`/documents/${id}/live`));
  await page.goto('/test-prime-builder?campaign=ete&pb_page_other=5#top');
  const initialUrl = new URL((await liveRequest).url());
  expect(initialUrl.searchParams.has('campaign')).toBe(false);
  expect(initialUrl.searchParams.has('pb_page_other')).toBe(false);
  const grid = page.locator(`[data-pb-id="${gridId}"]`);
  await expect(grid.getByRole('link', { name: 'Actualité publique' })).toBeVisible();
  await expect(grid).not.toContainText('Brouillon confidentiel');
  await expect(page.locator('link[href*="/css/widgets/posts.css"]')).toHaveCount(1);
  const next = grid.getByRole('link', { name: 'Suivant' });
  await expect(next).toHaveAttribute('href', new RegExp(`pb_page_${gridId}=2`));
  await next.focus();
  await page.keyboard.press('Enter');
  await expect.poll(() => new URL(page.url()).searchParams.get(`pb_page_${gridId}`)).toBe('2');
  expect(new URL(page.url()).searchParams.get('campaign')).toBe('ete');
  expect(new URL(page.url()).searchParams.get('pb_page_other')).toBe('5');
  await expect(grid.getByRole('link', { name: 'Grille Alpha' })).toBeVisible();
  await expect(grid.locator('[aria-current="page"]')).toHaveText('Page 2 sur 3');
  const previous = grid.getByRole('link', { name: 'Précédent' });
  await previous.focus();
  await page.keyboard.press('Enter');
  await expect.poll(() => new URL(page.url()).searchParams.has(`pb_page_${gridId}`)).toBe(false);
  await expect(grid.getByRole('link', { name: 'Actualité publique' })).toBeVisible();
  await page.setViewportSize({ width: 375, height: 812 });
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expectAccessible(page);
  const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
  try {
    const fallback = await noJs.newPage();
    await fallback.goto('/test-prime-builder');
    await expect(fallback.locator('.pb-posts').getByRole('link', { name: 'Voir les articles récents' })).toBeVisible();
    await expect(fallback.locator('.pb-posts')).not.toContainText('Actualité publique');
    await expect(fallback.locator('.pb-posts nav')).toHaveCount(0);
  } finally { await noJs.close(); }
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
  const { status, count } = await page.evaluate(async entryId => {
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
    return { status: result.status, count: elements.length };
  }, id);
  expect(status).toBe(200);
  expect(count).toBe(60);
  await page.goto('/test-prime-builder');
  await expect(page.locator('.pb-element')).toHaveCount(count);
  await expectAccessible(page);
});

test('navigator renames, folds and hides elements with persisted accessible controls', async ({ page }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  await page.getByRole('button', { name: 'Ajouter Conteneur' }).click();
  await page.locator('[data-pb-panel="widgets"]').click();
  await page.getByRole('button', { name: 'Ajouter Titre', exact: true }).click();
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
  await page.getByRole('button', { name: 'Ajouter Titre', exact: true }).click();
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
  await page.getByRole('button', { name: 'Ajouter Titre', exact: true }).click();
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
  await page.getByRole('button', { name: 'Ajouter Titre', exact: true }).click();
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
  await page.getByRole('button', { name: 'Ajouter Titre', exact: true }).click();
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
    await page.getByRole('button', { name: 'Ajouter Titre', exact: true }).click();
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
  await page.getByRole('button', { name: 'Ajouter Titre', exact: true }).click();
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
  await page.getByRole('button', { name: 'Ajouter Titre', exact: true }).click();
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
  await page.getByRole('button', { name: 'Ajouter Titre', exact: true }).click();
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

test('table of contents follows document headings and works without JavaScript', async ({ page, browser }) => {
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  const status = await page.evaluate(async entryId => {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const element = (id, type, settings, children = [], extra = {}) => ({ id, type, settings, styles: {}, children, ...extra });
    const elements = [
      element('toc', 'table_of_contents', { label: 'Sur cette page', min_level: 2, max_level: 4 }),
      element('manual', 'anchor', { anchor: 'pb-toc-intro' }),
      element('intro', 'heading', { text: 'Bienvenue', level: 2 }),
      element('nested', 'container', {}, [
        element('detail', 'heading', { text: 'Les détails', level: 3 }),
        element('animated', 'animated_heading', { before: 'Créer', highlight: 'ensemble', level: 4 }),
        element('secret', 'heading', { text: 'Titre masqué', level: 2 }, [], { hidden: true }),
      ]),
      element('end', 'heading', { text: 'Conclusion', level: 2 }),
    ];
    const response = await fetch(`/api/prime-builder/v1/documents/${entryId}`, {
      method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf },
      body: JSON.stringify({ content: { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements } }] }, status: 'published', published_at: '' }),
    });
    return response.status;
  }, id);
  expect(status).toBe(200);
  await page.reload();
  const frame = page.frameLocator('[data-pb-frame]');
  const preview = frame.getByRole('navigation', { name: 'Sur cette page' });
  await expect(preview.getByRole('link', { name: 'Bienvenue' })).toHaveAttribute('href', '#pb-toc-intro-2');
  await expect(preview.getByRole('link', { name: 'Les détails' })).toBeVisible();
  await expect(preview.getByRole('link', { name: 'Créer ensemble' })).toBeVisible();
  await expect(preview.getByRole('link', { name: 'Titre masqué' })).toHaveCount(0);
  await expect(frame.locator('link[href*="/css/widgets/table_of_contents.css"]')).toHaveCount(1);

  await page.locator('[data-pb-panel="navigator"]').click();
  await page.locator('[data-pb-tree-select="intro"]').click();
  const fullPreview = page.waitForResponse(response => response.url().endsWith('/render') && response.request().postData()?.includes('Notre introduction'));
  await page.getByLabel('Texte', { exact: true }).fill('Notre introduction');
  const rendered = await fullPreview;
  expect(rendered.ok()).toBeTruthy();
  expect(rendered.request().postDataJSON()).not.toHaveProperty('target');
  await expect(preview.getByRole('link', { name: 'Notre introduction' })).toBeVisible();

  await page.locator('[data-pb-panel="navigator"]').click();
  await page.locator('[data-pb-tree-select="toc"]').click();
  await expect(page.getByLabel('Nom du sommaire', { exact: true })).toHaveValue('Sur cette page');
  await page.getByRole('button', { name: 'Style', exact: true }).click();
  await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Avancé', exact: true }).click();
  await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('toc-screen-editor-desktop.jpg'), fullPage: true, quality: 20 });
  await page.setViewportSize({ width: 375, height: 812 });
  await showMobilePreview(page, frame, '.pb-table_of_contents');
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('toc-screen-editor-mobile.jpg'), fullPage: true, quality: 20 });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');

  await page.goto('/test-prime-builder');
  const toc = page.getByRole('navigation', { name: 'Sur cette page' });
  const link = toc.getByRole('link', { name: 'Notre introduction' });
  await expect(link).toHaveAttribute('href', '#pb-toc-intro-2');
  await expect(page.locator('link[href*="/css/widgets/table_of_contents.css"]')).toHaveCount(1);
  await expect(page.locator('script[src*="/js/table_of_contents.js"]')).toHaveCount(0);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('toc-screen-public-desktop.jpg'), fullPage: true, quality: 20 });
  await link.focus();
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(/#pb-toc-intro-2$/);
  await expect(page.locator('#pb-toc-intro-2')).toBeFocused();
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto('/test-prime-builder');
  await expect(toc).toBeVisible();
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('toc-screen-public-mobile.jpg'), fullPage: true, quality: 20 });
  const longTextWidth = await link.evaluate(node => {
    const original = node.textContent;
    node.textContent = 'W'.repeat(160);
    const width = document.documentElement.scrollWidth;
    node.textContent = original;
    return width;
  });
  expect(longTextWidth).toBeLessThanOrEqual(375);

  const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
  try {
    const fallback = await noJs.newPage();
    await fallback.goto('/test-prime-builder');
    const fallbackLink = fallback.getByRole('navigation', { name: 'Sur cette page' }).getByRole('link', { name: 'Notre introduction' });
    await fallbackLink.focus();
    await fallback.keyboard.press('Enter');
    await expect(fallback).toHaveURL(/#pb-toc-intro-2$/);
    await expect(fallback.locator('#pb-toc-intro-2')).toBeFocused();
  } finally { await noJs.close(); }
});

test('breadcrumbs use the core trail and preserve focus while refreshing', async ({ page, browser }) => {
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
  await page.getByRole('button', { name: 'Ajouter Fil d’Ariane', exact: true }).click();
  await page.getByLabel('Nom accessible du fil d’Ariane', { exact: true }).fill('Vous êtes ici');
  const frame = page.frameLocator('[data-pb-frame]');
  const preview = frame.getByRole('navigation', { name: 'Vous êtes ici' });
  await expect(preview.getByRole('link', { name: 'Accueil' })).toHaveAttribute('href', '/');
  await expect(preview.locator('[aria-current="page"]')).toHaveText('Test Prime Builder');
  await expect(frame.locator('link[href*="/css/widgets/breadcrumbs.css"]')).toHaveCount(1);
  await page.getByRole('button', { name: 'Style', exact: true }).click();
  await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Avancé', exact: true }).click();
  await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('breadcrumbs-screen-editor-desktop.jpg'), fullPage: true, quality: 20 });
  await page.setViewportSize({ width: 375, height: 812 });
  await showMobilePreview(page, frame, '.pb-breadcrumbs');
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('breadcrumbs-screen-editor-mobile.jpg'), fullPage: true, quality: 20 });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');

  let releaseLive;
  const gate = new Promise(resolve => { releaseLive = resolve; });
  let intercepted = false;
  const livePattern = `**/api/prime-builder/v1/documents/${id}/live`;
  await page.route(livePattern, async route => { intercepted = true; await gate; await route.continue(); });
  await page.goto('/test-prime-builder');
  await expect.poll(() => intercepted).toBe(true);
  const trail = page.getByRole('navigation', { name: 'Vous êtes ici' });
  const home = trail.getByRole('link', { name: 'Accueil' });
  await home.focus();
  const response = page.waitForResponse(result => result.url().endsWith(`/api/prime-builder/v1/documents/${id}/live`));
  releaseLive();
  const live = await response;
  expect(live.ok()).toBeTruthy();
  await live.finished();
  await page.evaluate(() => new Promise(requestAnimationFrame));
  await expect(home).toBeFocused();
  await expect(trail.locator('[aria-current="page"]')).toHaveCount(0);
  await page.keyboard.press('Tab');
  await expect(trail.locator('[aria-current="page"]')).toHaveText('Test Prime Builder');
  await expect(page.locator('link[href*="/css/widgets/breadcrumbs.css"]')).toHaveCount(1);
  await expect(page.locator('script[src*="/js/content.js"]')).toHaveCount(1);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('breadcrumbs-screen-public-desktop.jpg'), fullPage: true, quality: 20 });
  await page.unroute(livePattern);
  await page.setViewportSize({ width: 375, height: 812 });
  await page.goto('/test-prime-builder');
  await expect(trail.locator('[aria-current="page"]')).toHaveText('Test Prime Builder');
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  const longTextWidth = await trail.locator('[aria-current="page"]').evaluate(node => {
    const original = node.textContent;
    node.textContent = 'W'.repeat(160);
    const width = document.documentElement.scrollWidth;
    node.textContent = original;
    return width;
  });
  expect(longTextWidth).toBeLessThanOrEqual(375);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('breadcrumbs-screen-public-mobile.jpg'), fullPage: true, quality: 20 });

  const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
  try {
    const fallback = await noJs.newPage();
    await fallback.goto('/test-prime-builder');
    const nav = fallback.getByRole('navigation', { name: 'Vous êtes ici' });
    await expect(nav.locator('[aria-current="page"]')).toHaveCount(0);
    const link = nav.getByRole('link', { name: 'Accueil' });
    await link.focus();
    await fallback.keyboard.press('Enter');
    await expect(fallback).toHaveURL(/\/$/);
  } finally { await noJs.close(); }
});

test('site title and logo use core identity and refresh without losing focus', async ({ page, browser }) => {
  test.setTimeout(120_000);
  await login(page);
  await page.goto(`/admin/prime-builder/edit/${id}`);
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  const original = await page.evaluate(async () => {
    const response = await fetch('/api/v1/settings', { headers: { Accept: 'application/json' } });
    const settings = (await response.json()).data;
    return { name: settings['site.name'], logo: settings['site.logo_id'] };
  });
  let mediaId = null, templateId = null, previousTheme = null, releaseLive = null;
  const livePattern = `**/api/prime-builder/v1/documents/${id}/live`;
  const setIdentity = (name, logo) => page.evaluate(async ({ name, logo, csrf }) => {
    const response = await fetch('/api/v1/settings', {
      method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf, Accept: 'application/json' },
      body: JSON.stringify({ 'site.name': name, 'site.logo_id': logo }),
    });
    return { status: response.status, body: await response.text() };
  }, { name, logo, csrf });
  try {
    // Draw a fictional logo inside the browser; upload it through the real CMS media API.
    const upload = await page.evaluate(async csrf => {
      const canvas = document.createElement('canvas'); canvas.width = 480; canvas.height = 144;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#193c3b'; ctx.fillRect(0, 0, 480, 144);
      ctx.fillStyle = '#f4c975'; ctx.beginPath(); ctx.arc(76, 72, 38, 0, Math.PI * 2); ctx.fill();
      ctx.fillStyle = '#193c3b'; ctx.fillRect(73, 42, 6, 60); ctx.fillRect(46, 69, 60, 6);
      ctx.fillStyle = '#ffffff'; ctx.font = 'bold 40px sans-serif'; ctx.fillText('ATELIER', 140, 86);
      const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
      const form = new FormData(); form.append('file', blob, 'atelier-logo.png');
      const response = await fetch('/api/v1/media', { method: 'POST', headers: { 'X-Prime-CSRF': csrf, Accept: 'application/json' }, body: form });
      return { status: response.status, body: await response.text() };
    }, csrf);
    expect(upload.status, upload.body).toBe(201);
    mediaId = JSON.parse(upload.body).data.id;
    expect(mediaId).toBeGreaterThan(0);
    let setting = await setIdentity('Atelier Boréal', mediaId);
    expect(setting.status, setting.body).toBe(200);
    const reset = await page.evaluate(async ({ entryId, csrf }) => {
      const response = await fetch(`/api/prime-builder/v1/documents/${entryId}`, {
        method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf },
        body: JSON.stringify({ content: { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: [] } }] }, status: 'published', published_at: '' }),
      });
      return response.status;
    }, { entryId: id, csrf });
    expect(reset).toBe(200);
    await page.reload();
    await page.getByRole('button', { name: 'Ajouter Logo du site', exact: true }).click();
    const frame = page.frameLocator('[data-pb-frame]');
    const logo = frame.locator('.pb-site_logo');
    await expect(logo.getByRole('img', { name: 'Atelier Boréal' })).toBeVisible();
    await expect(logo.getByRole('link', { name: 'Atelier Boréal' })).toHaveAttribute('href', '/');
    await page.getByRole('combobox', { name: 'Lien vers l’accueil', exact: true }).selectOption('hide');
    await expect(logo.locator('a')).toHaveCount(0);
    await page.getByRole('combobox', { name: 'Lien vers l’accueil', exact: true }).selectOption('show');
    await page.getByRole('button', { name: 'Style', exact: true }).click();
    await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Avancé', exact: true }).click();
    await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
    await page.locator('[data-pb-panel="widgets"]').click();
    await page.getByRole('button', { name: 'Ajouter Titre du site', exact: true }).click();
    await page.getByRole('combobox', { name: 'Balise du titre du site', exact: true }).selectOption('h3');
    await expect(frame.locator('.pb-site_title h3')).toHaveText('Atelier Boréal');
    await expect(frame.locator('link[href*="/css/widgets/site_title.css"]')).toHaveCount(1);
    await expect(frame.locator('link[href*="/css/widgets/site_logo.css"]')).toHaveCount(1);
    await page.getByRole('button', { name: 'Style', exact: true }).click();
    await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Avancé', exact: true }).click();
    await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
    await expectAccessible(page);
    await page.screenshot({ path: test.info().outputPath('identity-screen-editor-desktop.jpg'), fullPage: true, quality: 20 });
    await page.setViewportSize({ width: 375, height: 812 });
    await showMobilePreview(page, frame, '.pb-site_title');
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await expectAccessible(page);
    await page.screenshot({ path: test.info().outputPath('identity-screen-editor-mobile.jpg'), fullPage: true, quality: 20 });
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.locator('[data-pb-save]').click();
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    setting = await setIdentity('Atelier actualisé', mediaId);
    expect(setting.status, setting.body).toBe(200);

    const gate = new Promise(resolve => { releaseLive = resolve; });
    let intercepted = false;
    await page.route(livePattern, async route => { intercepted = true; await gate; await route.continue(); });
    await page.goto('/test-prime-builder');
    await expect.poll(() => intercepted).toBe(true);
    const title = page.locator('.pb-site_title a');
    await expect(title).toHaveText('Atelier Boréal');
    await title.focus();
    const response = page.waitForResponse(result => result.url().endsWith(`/api/prime-builder/v1/documents/${id}/live`));
    releaseLive(); releaseLive = null;
    const live = await response;
    expect(live.ok()).toBeTruthy();
    await live.finished();
    await page.evaluate(() => new Promise(requestAnimationFrame));
    await expect(title).toBeFocused();
    await expect(title).toHaveText('Atelier Boréal');
    await page.keyboard.press('Tab');
    await expect(page.locator('.pb-site_title a')).toHaveText('Atelier actualisé');
    await expect(page.locator('.pb-site_logo img')).toHaveAttribute('alt', 'Atelier actualisé');
    await expect(page.locator('script[src*="/js/content.js"]')).toHaveCount(1);
    await expectAccessible(page);
    await page.screenshot({ path: test.info().outputPath('identity-screen-public-desktop.jpg'), fullPage: true, quality: 20 });
    await page.unroute(livePattern);
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/test-prime-builder');
    await expect(page.locator('.pb-site_title a')).toHaveText('Atelier actualisé');
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    const longTextWidth = await page.locator('.pb-site_title a').evaluate(node => {
      const original = node.textContent; node.textContent = 'W'.repeat(160);
      const width = document.documentElement.scrollWidth; node.textContent = original; return width;
    });
    expect(longTextWidth).toBeLessThanOrEqual(375);
    await expectAccessible(page);
    await page.screenshot({ path: test.info().outputPath('identity-screen-public-mobile.jpg'), fullPage: true, quality: 20 });

    const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
    try {
      const fallback = await noJs.newPage();
      await fallback.goto('/test-prime-builder');
      // Entry HTML is saved by the core: its no-JS identity may be stale.
      await expect(fallback.locator('.pb-site_title a')).toHaveText('Atelier Boréal');
      const fallbackLogo = fallback.locator('.pb-site_logo a');
      await expect(fallbackLogo.getByRole('img', { name: 'Atelier Boréal' })).toBeVisible();
      await fallbackLogo.focus(); await fallback.keyboard.press('Enter');
      await expect(fallback).toHaveURL(/\/$/);

      // The same widgets also work server-side in a theme header on pages with no entry.
      await page.setViewportSize({ width: 1440, height: 900 });
      await page.goto(`/admin/prime-builder/edit/${id}`);
      const created = await page.evaluate(async csrf => {
        const elements = [
          { id: 'brand-logo', type: 'site_logo', settings: {}, styles: {}, children: [] },
          { id: 'brand-title', type: 'site_title', settings: {}, styles: {}, children: [] },
        ];
        const response = await fetch('/api/prime-builder/v1/templates', {
          method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf },
          body: JSON.stringify({ name: 'Identité du site', kind: 'section', data: { version: 1, elements } }),
        });
        return { status: response.status, body: await response.text() };
      }, csrf);
      expect(created.status, created.body).toBe(201);
      templateId = JSON.parse(created.body).data.id;
      await page.goto('/admin/prime-builder/theme');
      previousTheme = {
        header: await page.getByLabel('En-tête', { exact: true }).inputValue(),
        footer: await page.getByLabel('Pied de page', { exact: true }).inputValue(),
        exclude: await page.getByLabel('Contenus exclus', { exact: true }).inputValue(),
      };
      await page.getByLabel('En-tête', { exact: true }).selectOption(templateId);
      await page.getByLabel('Contenus exclus', { exact: true }).fill('');
      await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();
      await expect(page.getByLabel('En-tête', { exact: true })).toHaveValue(templateId);
      await fallback.goto('/blog?identity-test=1');
      const header = fallback.locator('header.pb-theme-header');
      await expect(header.locator('.pb-site_logo img')).toHaveAttribute('alt', 'Atelier actualisé');
      await expect(header.locator('.pb-site_title a')).toHaveText('Atelier actualisé');
      await expect(header.locator('[data-pb-live]')).toHaveCount(0);
      const edited = await page.evaluate(async ({ mediaId, csrf }) => {
        const response = await fetch(`/api/v1/media/${mediaId}`, {
          method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf, Accept: 'application/json' },
          body: JSON.stringify({ title: 'Logo modifié' }),
        });
        return { status: response.status, body: await response.text() };
      }, { mediaId, csrf });
      expect(edited.status, edited.body).toBe(200);
      const deleted = await page.evaluate(async ({ mediaId, csrf }) => {
        const response = await fetch(`/api/v1/media/${mediaId}?force=1`, { method: 'DELETE', headers: { 'X-Prime-CSRF': csrf, Accept: 'application/json' } });
        return { status: response.status, body: await response.text() };
      }, { mediaId, csrf });
      expect(deleted.status, deleted.body).toBe(204);
      mediaId = null;
      await fallback.goto('/blog?identity-test=1');
      await expect(header.locator('.pb-site_logo a')).toHaveText('Atelier actualisé');
      await expect(header.locator('.pb-site_logo img')).toHaveCount(0);
      setting = await setIdentity('Atelier sans logo', 0);
      expect(setting.status, setting.body).toBe(200);
      await fallback.goto('/blog?identity-test=2');
      await expect(header.locator('.pb-site_logo a')).toHaveText('Atelier sans logo');
      await expect(header.locator('.pb-site_logo img')).toHaveCount(0);
      await expect(header.locator('.pb-site_title a')).toHaveText('Atelier sans logo');
    } finally { await noJs.close(); }
  } finally {
    if (releaseLive) releaseLive();
    await page.unroute(livePattern);
    if (previousTheme) {
      await page.goto('/admin/prime-builder/theme');
      await page.getByLabel('En-tête', { exact: true }).selectOption(previousTheme.header);
      await page.getByLabel('Pied de page', { exact: true }).selectOption(previousTheme.footer);
      await page.getByLabel('Contenus exclus', { exact: true }).fill(previousTheme.exclude);
      await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    }
    await page.goto(`/admin/prime-builder/edit/${id}`);
    const restored = await setIdentity(original.name, original.logo || 0);
    expect(restored.status, restored.body).toBe(200);
    if (mediaId) {
      const removed = await page.evaluate(async ({ mediaId, csrf }) => (await fetch(`/api/v1/media/${mediaId}`, { method: 'DELETE', headers: { 'X-Prime-CSRF': csrf, Accept: 'application/json' } })).status, { mediaId, csrf });
      expect(removed).toBe(204);
    }
    if (templateId) {
      const removed = await page.evaluate(async ({ templateId, csrf }) => (await fetch(`/api/prime-builder/v1/templates/${templateId}`, { method: 'DELETE', headers: { 'X-Prime-CSRF': csrf, Accept: 'application/json' } })).status, { templateId, csrf });
      expect(removed).toBe(200);
    }
  }
});

test('current entry navigation and author box use public CMS data', async ({ page, browser }) => {
  test.setTimeout(120_000);
  await login(page);
  let csrf = null, restorePosts = false, originalProfile = null, mediaId = null;
  const posts = [];
  const api = (url, method, body) => page.evaluate(async ({ url, method, body, csrf }) => {
    const response = await fetch(url, {
      method, headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf, Accept: 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    return { status: response.status, body: await response.text() };
  }, { url, method, body, csrf });
  const updateProfile = values => page.evaluate(async values => {
    const form = document.querySelector('form[action="/admin/profile/info"]');
    const fields = new FormData(form);
    fields.set('bio', values.bio);
    fields.set('avatar_media_id', values.avatar);
    const response = await fetch(form.action, { method: 'POST', body: fields });
    return { status: response.status, redirected: response.redirected };
  }, values);
  try {
    await page.goto('/admin/prime-builder/settings');
    if (!await page.locator('input[name="posts"]').isChecked()) {
      await page.locator('input[name="posts"]').check();
      const saved = page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith('/admin/prime-builder/settings'));
      await page.locator('form[action="/admin/prime-builder/settings"] button[type="submit"]').click();
      expect((await saved).status()).toBe(302);
      restorePosts = true;
    }
    await page.goto(`/admin/prime-builder/edit/${id}`);
    csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const upload = await page.evaluate(async csrf => {
      const canvas = document.createElement('canvas'); canvas.width = 160; canvas.height = 160;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#224a57'; ctx.fillRect(0, 0, 160, 160);
      ctx.fillStyle = '#f4c975'; ctx.beginPath(); ctx.arc(80, 67, 35, 0, Math.PI * 2); ctx.fill();
      ctx.fillRect(35, 115, 90, 45);
      const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
      const form = new FormData(); form.append('file', blob, 'portrait-builder.png');
      const response = await fetch('/api/v1/media', { method: 'POST', headers: { 'X-Prime-CSRF': csrf, Accept: 'application/json' }, body: form });
      return { status: response.status, body: await response.text() };
    }, csrf);
    expect(upload.status, upload.body).toBe(201);
    mediaId = JSON.parse(upload.body).data.id;
    await page.goto('/admin/profile');
    const profile = page.locator('form[action="/admin/profile/info"]');
    const name = await profile.locator('[name="display_name"]').inputValue();
    originalProfile = { bio: await profile.locator('[name="bio"]').inputValue(), avatar: await profile.locator('[name="avatar_media_id"]').inputValue() };
    expect(await updateProfile({ bio: 'Une biographie pour les lecteurs.', avatar: String(mediaId) })).toEqual({ status: 200, redirected: true });

    for (const [title, slug, published_at] of [
      ['Chapitre précédent', 'pb-chapitre-avant', '2023-04-01 10:00:00'],
      ['Chapitre présent', 'pb-chapitre-present', '2023-05-01 10:00:00'],
      ['Chapitre suivant', 'pb-chapitre-apres', '2023-06-01 10:00:00'],
    ]) {
      const result = await api('/api/v1/content/post', 'POST', { title, slug, status: 'published', published_at, content: { version: 1, blocks: [] } });
      expect(result.status, result.body).toBe(201);
      posts.push(JSON.parse(result.body).data);
    }
    const [older, current, newer] = posts;
    await page.goto(`/admin/prime-builder/edit/${current.id}`);
    const frame = page.frameLocator('[data-pb-frame]');
    await page.getByRole('button', { name: 'Ajouter Navigation entre contenus', exact: true }).click();
    await page.getByLabel('Nom accessible de la navigation entre contenus', { exact: true }).fill('À lire ensuite');
    const previewNav = frame.getByRole('navigation', { name: 'À lire ensuite' });
    await expect(previewNav.getByRole('link', { name: /Chapitre précédent/ })).toHaveAttribute('href', older.url);
    await expect(previewNav.getByRole('link', { name: /Chapitre suivant/ })).toHaveAttribute('href', newer.url);
    await page.getByRole('combobox', { name: 'Afficher le contenu précédent', exact: true }).selectOption('hide');
    await expect(previewNav.getByRole('link', { name: /Chapitre précédent/ })).toHaveCount(0);
    await page.getByRole('combobox', { name: 'Afficher le contenu précédent', exact: true }).selectOption('show');
    await page.getByRole('button', { name: 'Style', exact: true }).click();
    await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Avancé', exact: true }).click();
    await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
    await page.locator('[data-pb-panel="widgets"]').click();
    await page.getByRole('button', { name: 'Ajouter Boîte auteur', exact: true }).click();
    const previewAuthor = frame.locator('.pb-entry_author');
    await expect(previewAuthor.locator('.pb-entry-author__name')).toHaveText(name);
    await expect(previewAuthor.locator('.pb-entry-author__bio')).toHaveText('Une biographie pour les lecteurs.');
    await expect(previewAuthor.locator('img')).toHaveAttribute('src', /portrait-builder/);
    await page.getByRole('combobox', { name: 'Afficher la biographie de l’auteur', exact: true }).selectOption('hide');
    await expect(previewAuthor.locator('.pb-entry-author__bio')).toHaveCount(0);
    await page.getByRole('combobox', { name: 'Afficher la biographie de l’auteur', exact: true }).selectOption('show');
    await page.getByRole('combobox', { name: 'Afficher l’avatar de l’auteur', exact: true }).selectOption('hide');
    await expect(previewAuthor.locator('.pb-entry-author__avatar')).toHaveCount(0);
    await page.getByRole('combobox', { name: 'Afficher l’avatar de l’auteur', exact: true }).selectOption('show');
    await page.getByRole('combobox', { name: 'Lien vers les articles de l’auteur', exact: true }).selectOption('hide');
    await expect(previewAuthor.locator('.pb-entry-author__name a')).toHaveCount(0);
    await page.getByRole('combobox', { name: 'Lien vers les articles de l’auteur', exact: true }).selectOption('show');
    await page.getByRole('button', { name: 'Style', exact: true }).click();
    await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Avancé', exact: true }).click();
    await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
    for (const type of ['entry_navigation', 'entry_author']) {
      await expect(frame.locator(`link[href*="/css/widgets/${type}.css"]`)).toHaveCount(1);
    }
    await expectAccessible(page);
    await page.screenshot({ path: test.info().outputPath('context-screen-editor-desktop.jpg'), fullPage: true, quality: 15 });
    await page.setViewportSize({ width: 375, height: 812 });
    await showMobilePreview(page, frame, '.pb-entry_author');
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await expectAccessible(page);
    await page.screenshot({ path: test.info().outputPath('context-screen-editor-mobile.jpg'), fullPage: true, quality: 15 });
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.locator('[data-pb-save]').click();
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await page.goto(current.url);
    const nav = page.getByRole('navigation', { name: 'À lire ensuite' });
    const prevLink = nav.getByRole('link', { name: /Chapitre précédent/ });
    const nextLink = nav.getByRole('link', { name: /Chapitre suivant/ });
    const author = page.locator('.pb-entry_author');
    await expect(prevLink).toHaveAttribute('href', older.url);
    await expect(nextLink).toHaveAttribute('href', newer.url);
    const desktopRows = await nav.locator('li').evaluateAll(items => items.map(item => Math.round(item.getBoundingClientRect().top)));
    expect(desktopRows[0]).toBe(desktopRows[1]);
    await expect(author.locator('.pb-entry-author__bio')).toHaveText('Une biographie pour les lecteurs.');
    const authorLink = author.locator('.pb-entry-author__name a');
    await expect(authorLink).toHaveText(name);
    await expect(authorLink).toHaveAttribute('href', /^\/auteur\//);
    await expect(page.locator('script[src*="/js/content.js"]')).toHaveCount(1);
    await prevLink.focus(); await page.keyboard.press('Tab'); await expect(nextLink).toBeFocused();
    await page.keyboard.press('Tab'); await expect(authorLink).toBeFocused();
    await expectAccessible(page);
    await page.screenshot({ path: test.info().outputPath('context-screen-public-desktop.jpg'), fullPage: true, quality: 15 });
    await authorLink.focus(); await page.keyboard.press('Enter'); await expect(page).toHaveURL(/\/auteur\//);
    await page.goto(current.url);
    await nextLink.focus(); await page.keyboard.press('Enter'); await expect(page).toHaveURL(new RegExp(newer.url + '$'));
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto(current.url);
    await expect(nav.getByRole('link', { name: /Chapitre suivant/ })).toBeVisible();
    await expect(author.locator('.pb-entry-author__bio')).toHaveText('Une biographie pour les lecteurs.');
    const mobileRows = await nav.locator('li').evaluateAll(items => items.map(item => Math.round(item.getBoundingClientRect().top)));
    expect(mobileRows[1]).toBeGreaterThan(mobileRows[0]);
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    const longWidth = await author.locator('.pb-entry-author__name').evaluate(node => {
      const original = node.textContent; node.textContent = 'W'.repeat(160);
      const width = document.documentElement.scrollWidth; node.textContent = original; return width;
    });
    expect(longWidth).toBeLessThanOrEqual(375);
    const longNavigationWidth = await nav.locator('.pb-entry-navigation__title').first().evaluate(node => {
      const original = node.textContent; node.textContent = 'W'.repeat(160);
      const width = document.documentElement.scrollWidth; node.textContent = original; return width;
    });
    expect(longNavigationWidth).toBeLessThanOrEqual(375);
    await expectAccessible(page);
    await page.screenshot({ path: test.info().outputPath('context-screen-public-mobile.jpg'), fullPage: true, quality: 15 });
    const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
    try {
      const fallback = await noJs.newPage();
      await fallback.goto(current.url);
      // Other entries and profiles can become private; never persist their data.
      await expect(fallback.locator('.pb-entry_navigation')).toBeEmpty();
      await expect(fallback.locator('.pb-entry_author')).toBeEmpty();
    } finally { await noJs.close(); }
    const withdrawn = await api(`/api/v1/content/post/${older.id}`, 'PATCH', { status: 'draft', title: 'Titre privé retiré' });
    expect(withdrawn.status, withdrawn.body).toBe(200);
    await page.goto(current.url);
    await expect(nav).not.toContainText('Chapitre précédent');
    await expect(nav).not.toContainText('Titre privé retiré');
    await expect(nav.getByRole('link', { name: /Chapitre suivant/ })).toBeVisible();
    await page.goto('/admin/profile');
    expect(await updateProfile({ bio: 'Une biographie actualisée.', avatar: String(mediaId) })).toEqual({ status: 200, redirected: true });
    await page.goto(current.url);
    await expect(author.locator('.pb-entry-author__bio')).toHaveText('Une biographie actualisée.');
  } finally {
    const leave = dialog => dialog.accept();
    page.on('dialog', leave);
    try {
      if (originalProfile) {
        await page.goto('/admin/profile');
        expect(await updateProfile(originalProfile)).toEqual({ status: 200, redirected: true });
      }
      for (const post of posts) {
        const trashed = await api(`/api/v1/content/post/${post.id}`, 'DELETE');
        expect(trashed.status, trashed.body).toBe(200);
        const deleted = await api(`/api/v1/content/post/${post.id}?force=1`, 'DELETE');
        expect(deleted.status, deleted.body).toBe(204);
      }
      if (mediaId) {
        const deleted = await api(`/api/v1/media/${mediaId}`, 'DELETE');
        expect(deleted.status, deleted.body).toBe(204);
      }
    } finally {
      if (restorePosts) {
        await page.goto('/admin/prime-builder/settings');
        await page.locator('input[name="posts"]').uncheck();
        const saved = page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith('/admin/prime-builder/settings'));
        await page.locator('form[action="/admin/prime-builder/settings"] button[type="submit"]').click();
        expect((await saved).status()).toBe(302);
      }
      page.off('dialog', leave);
    }
  }
});

test('current entry title, excerpt, image and information use real CMS metadata', async ({ page, browser }) => {
  test.setTimeout(120_000);
  await login(page);
  let csrf = null, restorePosts = false;
  const api = (url, method, body) => page.evaluate(async ({ url, method, body, csrf }) => {
    const response = await fetch(url, {
      method, headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf, Accept: 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    return { status: response.status, body: await response.text() };
  }, { url, method, body, csrf });
  let mediaId = null, termId = null, postId = null, releaseLive = null;
  try {
    await page.goto('/admin/prime-builder/settings');
    if (!await page.locator('input[name="posts"]').isChecked()) {
      await page.locator('input[name="posts"]').check();
      const saved = page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith('/admin/prime-builder/settings'));
      await page.locator('form[action="/admin/prime-builder/settings"] button[type="submit"]').click();
      expect((await saved).status()).toBe(302);
      restorePosts = true;
    }
    await page.goto(`/admin/prime-builder/edit/${id}`);
    csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const upload = await page.evaluate(async csrf => {
      const canvas = document.createElement('canvas'); canvas.width = 600; canvas.height = 300;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#c8e2d7'; ctx.fillRect(0, 0, 600, 300);
      ctx.fillStyle = '#315f4f'; ctx.beginPath(); ctx.arc(200, 140, 90, 0, Math.PI * 2); ctx.fill();
      ctx.fillStyle = '#f5faf7'; ctx.font = 'bold 38px sans-serif'; ctx.fillText('JARDIN', 320, 155);
      const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
      const form = new FormData(); form.append('file', blob, 'jardin.png');
      const response = await fetch('/api/v1/media', { method: 'POST', headers: { 'X-Prime-CSRF': csrf, Accept: 'application/json' }, body: form });
      return { status: response.status, body: await response.text() };
    }, csrf);
    expect(upload.status, upload.body).toBe(201);
    mediaId = JSON.parse(upload.body).data.id;
    let result = await api(`/api/v1/media/${mediaId}`, 'PATCH', { alt: 'Feuillage initial' });
    expect(result.status, result.body).toBe(200);
    result = await api('/api/v1/terms/category', 'POST', { name: 'Jardin & nature', slug: 'jardin-nature-test' });
    expect(result.status, result.body).toBe(201);
    termId = JSON.parse(result.body).data.id;
    result = await api('/api/v1/content/post', 'POST', {
      title: 'Guide du jardin', slug: 'guide-du-jardin-test', status: 'published', published_at: '2024-03-02 10:00:00',
      excerpt: 'Un résumé initial pour le jardin.', featured_media_id: mediaId, terms: { category: [termId] },
      content: { version: 1, blocks: [] },
    });
    expect(result.status, result.body).toBe(201);
    const post = JSON.parse(result.body).data;
    postId = post.id;
    const publicUrl = post.url;
    expect(publicUrl).toMatch(/^\//);
    await page.goto(`/admin/prime-builder/edit/${postId}`);
    const frame = page.frameLocator('[data-pb-frame]');
    await page.getByRole('button', { name: 'Ajouter Titre du contenu', exact: true }).click();
    await page.getByRole('combobox', { name: 'Balise du titre du contenu', exact: true }).selectOption('h3');
    await page.getByRole('combobox', { name: 'Lien vers le contenu', exact: true }).selectOption('show');
    await expect(frame.locator('.pb-entry_title h3 a')).toHaveText('Guide du jardin');
    await expect(frame.locator('.pb-entry_title h3 a')).toHaveAttribute('href', publicUrl);
    await page.getByRole('button', { name: 'Style', exact: true }).click();
    await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Avancé', exact: true }).click();
    await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
    await page.locator('[data-pb-panel="widgets"]').click();
    await page.getByRole('button', { name: 'Ajouter Extrait du contenu', exact: true }).click();
    await page.getByLabel('Longueur maximale de l’extrait', { exact: true }).fill('100');
    await expect(frame.locator('.pb-entry_excerpt p')).toHaveText('Un résumé initial pour le jardin.');
    await page.locator('[data-pb-panel="widgets"]').click();
    await page.getByRole('button', { name: 'Ajouter Image à la une du contenu', exact: true }).click();
    const imageSize = page.getByRole('combobox', { name: 'Taille de l’image à la une', exact: true });
    await imageSize.selectOption('thumbnail');
    const thumbnail = frame.locator('.pb-entry_featured_image img');
    await expect(thumbnail).toHaveAttribute('width', '150');
    await expect(thumbnail).not.toHaveAttribute('srcset');
    await thumbnail.scrollIntoViewIfNeeded();
    await expect.poll(() => thumbnail.evaluate(image => [image.naturalWidth, image.naturalHeight])).toEqual([150, 150]);
    await expect.poll(() => thumbnail.evaluate(image => image.currentSrc === image.src)).toBe(true);
    await imageSize.selectOption('medium');
    await expect(frame.locator('.pb-entry_featured_image img')).toHaveAttribute('alt', 'Feuillage initial');
    await page.locator('[data-pb-panel="widgets"]').click();
    await page.getByRole('button', { name: 'Ajouter Informations du contenu', exact: true }).click();
    await expect(frame.locator('.pb-entry_info time')).toHaveAttribute('datetime', /2024-03-02/);
    await expect(frame.locator('.pb-entry_info a').filter({ hasText: 'Jardin & nature' })).toBeVisible();
    await page.getByRole('combobox', { name: 'Afficher les catégories', exact: true }).selectOption('hide');
    await expect(frame.locator('.pb-entry_info')).not.toContainText('Jardin & nature');
    await page.getByRole('combobox', { name: 'Afficher les catégories', exact: true }).selectOption('show');
    await expect(frame.locator('.pb-entry_info')).toContainText('Jardin & nature');
    for (const type of ['entry_title', 'entry_excerpt', 'entry_featured_image', 'entry_info']) {
      await expect(frame.locator(`link[href*="/css/widgets/${type}.css"]`)).toHaveCount(1);
    }
    await expectAccessible(page);
    await page.screenshot({ path: test.info().outputPath('entry-screen-editor-desktop.jpg'), fullPage: true, quality: 20 });
    await page.setViewportSize({ width: 375, height: 812 });
    await showMobilePreview(page, frame, '.pb-entry_info');
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await expectAccessible(page);
    await page.screenshot({ path: test.info().outputPath('entry-screen-editor-mobile.jpg'), fullPage: true, quality: 20 });
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.locator('[data-pb-save]').click();
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    result = await api(`/api/v1/content/post/${postId}`, 'PATCH', { title: 'Guide actualisé', excerpt: 'Un résumé actualisé pour le jardin.' });
    expect(result.status, result.body).toBe(200);
    result = await api(`/api/v1/media/${mediaId}`, 'PATCH', { alt: 'Feuillage actualisé' });
    expect(result.status, result.body).toBe(200);

    const livePattern = `**/api/prime-builder/v1/documents/${postId}/live`;
    const gate = new Promise(resolve => { releaseLive = resolve; });
    let intercepted = false;
    await page.route(livePattern, async route => { intercepted = true; await gate; await route.continue(); });
    await page.goto(publicUrl);
    await expect.poll(() => intercepted).toBe(true);
    const title = page.locator('.pb-entry_title a');
    await expect(title).toHaveText('Guide du jardin');
    await title.focus();
    const response = page.waitForResponse(result => result.url().endsWith(`/api/prime-builder/v1/documents/${postId}/live`));
    releaseLive(); releaseLive = null;
    const live = await response;
    expect(live.ok()).toBeTruthy(); await live.finished();
    await page.evaluate(() => new Promise(requestAnimationFrame));
    await expect(title).toBeFocused();
    await expect(title).toHaveText('Guide du jardin');
    await page.keyboard.press('Tab');
    await expect(page.locator('.pb-entry_title a')).toHaveText('Guide actualisé');
    await expect(page.locator('.pb-entry_excerpt')).toHaveText('Un résumé actualisé pour le jardin.');
    await expect(page.locator('.pb-entry_featured_image img')).toHaveAttribute('alt', 'Feuillage actualisé');
    await expect(page.locator('.pb-entry_info a').filter({ hasText: 'Jardin & nature' })).toBeVisible();
    await expect(page.locator('script[src*="/js/content.js"]')).toHaveCount(1);
    await expectAccessible(page);
    await page.screenshot({ path: test.info().outputPath('entry-screen-public-desktop.jpg'), fullPage: true, quality: 20 });
    await page.unroute(livePattern);
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto(publicUrl);
    await expect(page.locator('.pb-entry_title a')).toHaveText('Guide actualisé');
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    const longTextWidth = await page.locator('.pb-entry_title a').evaluate(node => {
      const original = node.textContent; node.textContent = 'W'.repeat(160);
      const width = document.documentElement.scrollWidth; node.textContent = original; return width;
    });
    expect(longTextWidth).toBeLessThanOrEqual(375);
    await expectAccessible(page);
    await page.screenshot({ path: test.info().outputPath('entry-screen-public-mobile.jpg'), fullPage: true, quality: 20 });
    const category = page.locator('.pb-entry_info a').filter({ hasText: 'Jardin & nature' });
    await category.focus(); await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/jardin-nature-test/);

    const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
    try {
      const fallback = await noJs.newPage();
      await fallback.goto(publicUrl);
      await expect(fallback.locator('.pb-entry_title a')).toHaveText('Guide du jardin');
      await expect(fallback.locator('.pb-entry_excerpt')).toHaveText('Un résumé initial pour le jardin.');
      await expect(fallback.locator('.pb-entry_featured_image img')).toHaveAttribute('alt', 'Feuillage initial');
      await expect(fallback.locator('.pb-entry_info time')).toHaveAttribute('datetime', /2024-03-02/);
      // Categories are never stored: they could become private after the save.
      await expect(fallback.locator('.pb-entry_info')).not.toContainText('Jardin & nature');
      await fallback.locator('.pb-entry_title a').focus();
      const navigation = fallback.waitForNavigation({ waitUntil: 'domcontentloaded' });
      await fallback.keyboard.press('Enter'); await navigation;
      await expect(fallback).toHaveURL(new RegExp(publicUrl.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$'));

      result = await api(`/api/v1/content/post/${postId}`, 'PATCH', { status: 'draft', title: 'Ancien titre privé', excerpt: 'Ancien résumé privé' });
      expect(result.status, result.body).toBe(200);
      expect((await noJs.request.get(`/api/prime-builder/v1/documents/${postId}/live`)).status()).toBe(404);
      expect((await noJs.request.get(publicUrl)).status()).toBe(404);
      result = await api(`/api/v1/content/post/${postId}`, 'PATCH', { status: 'published', title: 'Guide republié', excerpt: 'Résumé republié' });
      expect(result.status, result.body).toBe(200);
      const republished = await fallback.goto(publicUrl);
      expect(republished.status()).toBe(200);
      await expect(fallback.locator('.pb-entry_title')).toBeEmpty();
      await expect(fallback.locator('.pb-entry_excerpt')).toBeEmpty();
      await expect(fallback.locator('body')).not.toContainText('Ancien titre privé');
      const latest = await noJs.request.get(`/api/prime-builder/v1/documents/${postId}/live`);
      expect(latest.status()).toBe(200);
      expect(await latest.text()).toContain('Guide republié');
      expect(await latest.text()).not.toContain('Ancien titre privé');
    } finally { await noJs.close(); }
  } finally {
    if (releaseLive) releaseLive();
    const leave = dialog => dialog.accept();
    page.on('dialog', leave);
    try {
      if (postId) {
        await page.unroute(`**/api/prime-builder/v1/documents/${postId}/live`);
        const trashed = await api(`/api/v1/content/post/${postId}`, 'DELETE');
        expect(trashed.status, trashed.body).toBe(200);
        const deleted = await api(`/api/v1/content/post/${postId}?force=1`, 'DELETE');
        expect(deleted.status, deleted.body).toBe(204);
      }
      if (termId) {
        const deleted = await api(`/api/v1/terms/category/${termId}`, 'DELETE');
        expect(deleted.status, deleted.body).toBe(204);
      }
      if (mediaId) {
        const deleted = await api(`/api/v1/media/${mediaId}`, 'DELETE');
        expect(deleted.status, deleted.body).toBe(204);
      }
    } finally {
      if (restorePosts) {
        await page.goto('/admin/prime-builder/settings');
        await page.locator('input[name="posts"]').uncheck();
        const saved = page.waitForResponse(response => response.request().method() === 'POST' && response.url().endsWith('/admin/prime-builder/settings'));
        await page.locator('form[action="/admin/prime-builder/settings"] button[type="submit"]').click();
        expect((await saved).status()).toBe(302);
      }
      page.off('dialog', leave);
    }
  }
});

test('share buttons use ordinary private-by-default links on desktop and mobile', async ({ page, browser }) => {
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
  await page.getByRole('button', { name: 'Ajouter Boutons de partage', exact: true }).click();
  await page.getByLabel('Nom accessible du partage', { exact: true }).fill('Faire connaître cette page');
  await page.getByLabel('Texte partagé (X et e-mail)', { exact: true }).fill('Bonjour & merci');
  await page.getByRole('combobox', { name: 'Afficher LinkedIn', exact: true }).selectOption('hide');
  const frame = page.frameLocator('[data-pb-frame]');
  const preview = frame.getByRole('navigation', { name: 'Faire connaître cette page' });
  await expect(preview.getByRole('link', { name: 'Partager sur Facebook' })).toBeVisible();
  await expect(preview.getByRole('link', { name: 'Partager sur LinkedIn' })).toHaveCount(0);
  await expect(frame.locator('link[href*="/css/widgets/share_buttons.css"]')).toHaveCount(1);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('share-screen-editor-desktop.jpg'), fullPage: true, quality: 20 });
  await page.setViewportSize({ width: 375, height: 812 });
  await showMobilePreview(page, frame, '.pb-share_buttons');
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('share-screen-editor-mobile.jpg'), fullPage: true, quality: 20 });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.getByRole('button', { name: 'Style', exact: true }).click();
  await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Avancé', exact: true }).click();
  await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');

  const externalRequests = [];
  page.on('request', request => {
    if (/^https:\/\/(?:www\.)?(?:facebook\.com|linkedin\.com|twitter\.com)\//.test(request.url())) externalRequests.push(request.url());
  });
  await page.goto('/test-prime-builder?private_token=secret');
  const share = page.getByRole('navigation', { name: 'Faire connaître cette page' });
  const facebook = share.getByRole('link', { name: 'Partager sur Facebook' });
  const x = share.getByRole('link', { name: 'Partager sur X' });
  const email = share.getByRole('link', { name: 'Partager par e-mail' });
  await expect(facebook).toBeVisible();
  await expect(share.getByRole('link', { name: 'Partager sur LinkedIn' })).toHaveCount(0);
  await expect(page.locator('link[href*="/css/widgets/share_buttons.css"]')).toHaveCount(1);
  await expect(page.locator('script[src*="/js/share_buttons.js"]')).toHaveCount(1);
  const publicUrl = new URL('/test-prime-builder', page.url()).href;
  const facebookHref = await facebook.getAttribute('href');
  expect(new URL(facebookHref).searchParams.get('u')).toBe(publicUrl);
  expect(facebookHref).not.toContain('private_token');
  expect(new URL(await x.getAttribute('href')).searchParams.get('text')).toBe('Bonjour & merci');
  expect(await email.getAttribute('href')).toContain('subject=Bonjour%20%26%20merci');
  await expect(facebook).toHaveAttribute('rel', 'noreferrer');
  await facebook.focus();
  await page.keyboard.press('Tab');
  await expect(x).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(email).toBeFocused();
  await expectAccessible(page);
  expect(externalRequests).toEqual([]);
  await page.screenshot({ path: test.info().outputPath('share-screen-public-desktop.jpg'), fullPage: true, quality: 20 });
  await page.setViewportSize({ width: 375, height: 812 });
  await page.reload();
  await expect(facebook).toBeVisible();
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expectAccessible(page);
  expect(externalRequests).toEqual([]);
  await page.screenshot({ path: test.info().outputPath('share-screen-public-mobile.jpg'), fullPage: true, quality: 20 });

  const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
  try {
    const fallback = await noJs.newPage();
    await fallback.goto('/test-prime-builder');
    const fallbackLink = fallback.getByRole('navigation', { name: 'Faire connaître cette page' }).getByRole('link', { name: 'Partager sur Facebook' });
    expect(new URL(await fallbackLink.getAttribute('href')).searchParams.get('u')).toContain('/test-prime-builder');
  } finally { await noJs.close(); }
  await page.route('https://www.facebook.com/**', route => route.fulfill({ status: 200, contentType: 'text/html', body: 'Partage intercepté' }));
  await facebook.focus();
  await Promise.all([
    page.waitForURL(/^https:\/\/www\.facebook\.com\/sharer\//),
    page.keyboard.press('Enter'),
  ]);
  await expect.poll(() => externalRequests.length).toBe(1);
  expect(new URL(externalRequests[0]).searchParams.get('u')).toBe(publicUrl);

  // Both private preview routes must keep the generated public fallback, never
  // the admin URL or the preview query. No third party is contacted by previewing.
  await page.goto(`/admin/prime-builder/edit/${id}`);
  const draftStatus = await page.evaluate(async entryId => {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const element = { id: 'share1', type: 'share_buttons', settings: { label: 'Faire connaître cette page', message: 'Bonjour & merci', linkedin: 'hide' }, styles: {}, children: [] };
    const response = await fetch(`/api/prime-builder/v1/documents/${entryId}`, {
      method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf },
      body: JSON.stringify({ content: { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: [element] } }] }, status: 'draft', published_at: '' }),
    });
    return response.status;
  }, id);
  expect(draftStatus).toBe(200);
  for (const previewUrl of [`/admin/content/page/${id}/preview?private_token=secret`, `/?preview=1&entry_id=${id}&private_token=secret`]) {
    await page.goto(previewUrl);
    const link = page.getByRole('navigation', { name: 'Faire connaître cette page' }).getByRole('link', { name: 'Partager sur Facebook' });
    const href = await link.getAttribute('href');
    expect(new URL(href).searchParams.get('u')).toBe(publicUrl);
    expect(href).not.toMatch(/preview|private_token|secret|admin/);
  }
  expect(externalRequests).toHaveLength(1);
});

test('navigation uses CMS menus with keyboard disclosures on desktop and mobile', async ({ page, browser }) => {
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
  await page.getByRole('button', { name: 'Ajouter Menu de navigation', exact: true }).click();
  await page.getByRole('combobox', { name: 'Menu du site', exact: true }).selectOption({ label: 'Navigation démonstration' });
  await page.getByLabel('Nom accessible de la navigation', { exact: true }).fill('Explorer nos pages');
  const frame = page.frameLocator('[data-pb-frame]');
  const preview = frame.getByRole('navigation', { name: 'Explorer nos pages' });
  await expect(preview.getByRole('link', { name: 'Découvrir' })).toBeVisible();
  await expect(preview.getByRole('link', { name: 'Services' })).toBeVisible();
  await expect(preview.getByText('Brouillon confidentiel')).toHaveCount(0);
  await expect(frame.locator('link[href*="/css/widgets/navigation.css"]')).toHaveCount(1);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('navigation-screen-editor-desktop.jpg'), fullPage: true, quality: 20 });
  await page.setViewportSize({ width: 375, height: 812 });
  await showMobilePreview(page, frame, '.pb-navigation');
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('navigation-screen-editor-mobile.jpg'), fullPage: true, quality: 20 });
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.getByRole('button', { name: 'Style', exact: true }).click();
  await expect(page.getByLabel('Couleur du texte', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Avancé', exact: true }).click();
  await expect(page.getByLabel('Marge intérieure', { exact: true })).toBeVisible();
  await page.locator('[data-pb-save]').click();
  await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');

  await page.goto('/test-prime-builder');
  const nav = page.getByRole('navigation', { name: 'Explorer nos pages' });
  const discover = nav.getByRole('link', { name: 'Découvrir' });
  const discoverToggle = nav.getByRole('button', { name: 'Sous-menu : Découvrir' });
  await expect(discover).toBeVisible();
  await expect(discoverToggle).toHaveAttribute('aria-expanded', 'false');
  await expect(nav.getByText('Brouillon confidentiel')).toHaveCount(0);
  await expect(nav.getByRole('link', { name: 'Externe' })).toHaveAttribute('rel', 'noopener noreferrer');
  await expect(page.locator('link[href*="/css/widgets/navigation.css"]')).toHaveCount(1);
  await expect(page.locator('script[src*="/js/content.js"]')).toHaveCount(1);
  await discover.focus();
  await page.keyboard.press('Tab');
  await expect(discoverToggle).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(discoverToggle).toHaveAttribute('aria-expanded', 'true');
  await expect(nav.getByRole('link', { name: 'Page actuelle' })).toHaveAttribute('aria-current', 'page');
  await page.keyboard.press('Tab');
  await expect(nav.getByRole('link', { name: 'Services' })).toBeFocused();
  await page.keyboard.press('Tab');
  const servicesToggle = nav.getByRole('button', { name: 'Sous-menu : Services' });
  await expect(servicesToggle).toBeFocused();
  await page.keyboard.press('Space');
  await expect(servicesToggle).toHaveAttribute('aria-expanded', 'true');
  await page.keyboard.press('Tab');
  await expect(nav.getByRole('link', { name: 'Détails' })).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(servicesToggle).toBeFocused();
  await expect(servicesToggle).toHaveAttribute('aria-expanded', 'false');
  await page.keyboard.press('Escape');
  await expect(discoverToggle).toBeFocused();
  await expect(discoverToggle).toHaveAttribute('aria-expanded', 'false');
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('navigation-screen-public-desktop.jpg'), fullPage: true, quality: 20 });

  await page.setViewportSize({ width: 375, height: 812 });
  await page.reload();
  const mobileToggle = nav.getByRole('button', { name: 'Menu de navigation' });
  await expect(mobileToggle).toBeVisible();
  await expect(mobileToggle).toHaveAttribute('aria-expanded', 'false');
  await mobileToggle.focus();
  await page.keyboard.press('Enter');
  await expect(mobileToggle).toHaveAttribute('aria-expanded', 'true');
  await page.keyboard.press('Tab');
  await expect(nav.getByRole('link', { name: 'Accueil' })).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(discover).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(discoverToggle).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(nav.getByRole('link', { name: 'Services' })).toBeVisible();
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
  await expectAccessible(page);
  await page.screenshot({ path: test.info().outputPath('navigation-screen-public-mobile.jpg'), fullPage: true, quality: 20 });
  const longTextWidth = await discover.evaluate(link => {
    const original = link.textContent;
    link.textContent = 'W'.repeat(160);
    const width = document.documentElement.scrollWidth;
    link.textContent = original;
    return width;
  });
  expect(longTextWidth).toBeLessThanOrEqual(375);
  await page.keyboard.press('Escape');
  await expect(discoverToggle).toHaveAttribute('aria-expanded', 'false');
  await page.keyboard.press('Escape');
  await expect(mobileToggle).toBeFocused();
  await expect(mobileToggle).toHaveAttribute('aria-expanded', 'false');

  const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
  try {
    const fallback = await noJs.newPage();
    await fallback.goto('/test-prime-builder');
    const fallbackNav = fallback.getByRole('navigation', { name: 'Explorer nos pages' });
    await expect(fallbackNav.getByRole('link', { name: 'Accueil' })).toHaveAttribute('href', '/');
    await expect(fallbackNav.getByText('Brouillon confidentiel')).toHaveCount(0);
  } finally { await noJs.close(); }
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
