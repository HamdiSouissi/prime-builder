import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

async function audit(page) {
  const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
  expect(result.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => `${v.id}: ${v.nodes.map(n => n.target.join(' ')).join(', ')}`)).toEqual([]);
}

test('popup models use explicit, accessible activation, conditions, all triggers and frequency caps', async ({ page, browser }) => {
  test.setTimeout(240_000);
  const suffix = Date.now().toString(36);
  const title = `Fenêtre ${suffix}`;
  const includedTitle = `Page popup ${suffix}`;
  const excludedTitle = `Page exclue popup ${suffix}`;
  const includedPath = `/pb-popup-test-${suffix}`;
  const excludedPath = `/pb-popup-exclue-${suffix}`;
  const entries = [];
  let id = '';
  let otherId = '';
  let visitorContext;
  let disabled = false;

  async function api(url, method, body) {
    return page.evaluate(async ({ url, method, body }) => {
      const csrf = document.querySelector('meta[name="csrf-token"]').content;
      const response = await fetch(url, { method, headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf }, ...(body ? { body: JSON.stringify(body) } : {}) });
      return { status: response.status, body: await response.text() };
    }, { url, method, body });
  }
  async function configure(trigger, frequency, delay = '0') {
    await page.goto(`/admin/prime-builder/theme/${id}`);
    await page.getByLabel('Déclencheur', { exact: true }).selectOption(trigger);
    await page.getByLabel('Délai après chargement (secondes, 0 à 120)').fill(delay);
    await page.getByLabel('Défilement requis (%, 1 à 100)').fill('25');
    await page.getByLabel('Délai d’inactivité (secondes, 5 à 1800)').fill('5');
    await page.getByLabel('Fréquence', { exact: true }).selectOption(frequency);
    await page.getByLabel('Durée de la période (jours, 1 à 365)').fill('2');
    await page.getByLabel('Activer ce modèle sur le site').check();
    await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(page.getByLabel('Activer ce modèle sur le site')).toBeChecked();
  }
  async function toggle(action) {
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
    const create = page.locator('form[action="/admin/prime-builder/theme/create"]');
    await create.getByLabel('Nom du modèle').fill(title);
    await create.getByLabel('Type de modèle').selectOption('popup');
    await create.getByRole('button', { name: 'Créer un modèle' }).click();
    await expect(page).toHaveURL(/\/admin\/prime-builder\/theme\/[a-f0-9]{32}$/);
    id = page.url().split('/').pop();
    await expect(page.getByText(`#pb-popup-${id}`)).toBeVisible();
    await audit(page);
    await page.screenshot({ path: test.info().outputPath('popup-settings-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await audit(page);
    await page.screenshot({ path: test.info().outputPath('popup-settings-mobile.png'), fullPage: true });
    await page.setViewportSize({ width: 1440, height: 900 });

    await page.getByRole('link', { name: 'Modifier visuellement' }).click();
    await expect(page).toHaveURL(new RegExp(`/theme/${id}/edit$`));
    const frame = page.frameLocator('[data-pb-frame]');
    await page.getByRole('button', { name: 'Ajouter Titre', exact: true }).click();
    await page.getByLabel('Texte', { exact: true }).fill('Contenu visuel de la fenêtre');
    await expect(frame.locator('[data-pb-editor-content] .pb-heading')).toHaveText('Contenu visuel de la fenêtre');
    await page.locator('[data-pb-panel="widgets"]').click();
    await page.getByRole('button', { name: 'Ajouter Bouton', exact: true }).click();
    await page.getByLabel('Texte', { exact: true }).fill('Lien dans la fenêtre');
    await page.getByLabel('Adresse URL').fill('/');
    await page.locator('[data-pb-save]').click();
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');

    const layout = { version: 1, elements: [
      { id: 'open-popup', type: 'button', settings: { text: 'Ouvrir la fenêtre', url: `#pb-popup-${id}` }, styles: {}, children: [] },
      { id: 'space', type: 'spacer', settings: {}, styles: { desktop: { min_height: 1800 } }, children: [] },
    ] };
    for (const [titleValue, slug, data] of [[includedTitle, includedPath.slice(1), layout], [excludedTitle, excludedPath.slice(1), { version: 1, elements: [] }]]) {
      const response = await api('/api/v1/content/page', 'POST', {
        title: titleValue, slug, status: 'published', published_at: '2021-01-01 10:00:00',
        content: { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data }] },
      });
      expect(response.status, response.body).toBe(201);
      entries.push(JSON.parse(response.body).data.id);
    }
    await page.goto(`/admin/prime-builder/theme/${id}`);
    const exclude = page.locator('[data-pb-rule-group="exclude"]');
    await exclude.getByRole('button', { name: 'Ajouter une exclusion' }).click();
    await exclude.getByLabel('Condition').selectOption('entry');
    await exclude.getByLabel('Rechercher un contenu par son titre').fill(excludedTitle);
    await expect(exclude.getByRole('button', { name: new RegExp(excludedTitle) })).toBeVisible();
    await exclude.getByLabel('Rechercher un contenu par son titre').press('Enter');
    await page.getByLabel('Activer ce modèle sur le site').check();
    await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(page.getByLabel('Activer ce modèle sur le site')).toBeChecked();
    await page.goto('/admin/prime-builder/theme?kind=popup');
    await expect(page.getByText(title, { exact: true })).toBeVisible();
    await page.goto('/admin/prime-builder/theme?kind=header');
    await expect(page.getByText(title, { exact: true })).toHaveCount(0);

    visitorContext = await browser.newContext({ baseURL: test.info().project.use.baseURL, reducedMotion: 'reduce' });
    const visitor = await visitorContext.newPage();
    const errors = [];
    visitor.on('pageerror', error => errors.push(error.message));
    await visitor.goto(`${excludedPath}?popup=excluded`);
    await expect(visitor.locator('[data-pb-popup]')).toHaveCount(0);
    await expect(visitor.locator('script[src*="popup.js"]')).toHaveCount(0);

    await visitor.goto(`${includedPath}?popup=click`);
    const dialog = visitor.locator(`#pb-popup-${id}`);
    const opener = visitor.getByRole('link', { name: 'Ouvrir la fenêtre' });
    await expect(opener).toHaveAttribute('aria-haspopup', 'dialog');
    await expect(dialog).toBeHidden();
    await opener.focus();
    await visitor.keyboard.press('Enter');
    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAttribute('aria-modal', 'true');
    await expect(dialog.getByRole('heading', { name: title })).toBeVisible();
    await expect(dialog.getByRole('heading', { name: 'Contenu visuel de la fenêtre' })).toBeVisible();
    const close = dialog.getByRole('button', { name: `Fermer : ${title}` });
    const inside = dialog.getByRole('link', { name: 'Lien dans la fenêtre' });
    await expect(close).toBeFocused();
    await visitor.keyboard.press('Shift+Tab');
    await expect(inside).toBeFocused();
    await visitor.keyboard.press('Tab');
    await expect(close).toBeFocused();
    await audit(visitor);
    await visitor.screenshot({ path: test.info().outputPath('popup-dialog-desktop.png') });
    await visitor.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => visitor.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await audit(visitor);
    await visitor.screenshot({ path: test.info().outputPath('popup-dialog-mobile.png') });
    await visitor.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
    await expect(opener).toBeFocused();
    await expect(opener).toHaveAttribute('aria-disabled', 'true');
    await expect(opener).toHaveAccessibleDescription('Déjà affichée pour cette période.');
    await opener.press('Enter');
    await expect(dialog).toBeHidden();
    await visitor.reload();
    await expect(opener).toHaveAttribute('aria-disabled', 'true');
    await opener.press('Enter');
    await expect(dialog).toBeHidden();

    // A time trigger offers a button but cannot interrupt a screen reader or move focus.
    await configure('load', 'always', '1');
    await visitor.setViewportSize({ width: 1440, height: 900 });
    await visitor.goto(`${includedPath}?popup=load`);
    const offer = visitor.locator('[data-pb-popup-offer]');
    await expect(offer).toBeHidden();
    await expect(offer).toBeVisible({ timeout: 5000 });
    await expect(dialog).toBeHidden();
    expect(await visitor.evaluate(() => document.activeElement === document.body)).toBe(true);
    const launch = offer.getByRole('button', { name: `Ouvrir : ${title}` });
    await launch.focus();
    await visitor.keyboard.press('Enter');
    await expect(dialog).toBeVisible();
    await visitor.keyboard.press('Escape');
    await expect(launch).toBeFocused();
    const autoLabel = 'Autoriser l’ouverture automatique des fenêtres proposées dans cet onglet';
    await offer.getByLabel(autoLabel).check();
    await offer.getByRole('button', { name: `Masquer : ${title}` }).click();
    await expect(offer).toBeHidden();
    await visitor.reload();
    await expect(dialog).toBeVisible({ timeout: 5000 });
    await expect(close).toBeFocused();
    await expect(dialog.getByLabel(autoLabel)).toBeChecked();
    await dialog.getByLabel(autoLabel).uncheck();
    await visitor.keyboard.press('Escape');
    await visitor.reload();
    await expect(offer).toBeVisible({ timeout: 5000 });
    await expect(dialog).toBeHidden();
    await offer.getByRole('button', { name: `Masquer : ${title}` }).click();

    // A separately opened native dialog is not covered by an automatic offer.
    await configure('load', 'always', '2');
    await visitor.goto(`${includedPath}?popup=other-dialog`);
    await visitor.evaluate(() => {
      const other = document.createElement('dialog');
      other.id = 'pb-e2e-other-dialog';
      other.setAttribute('aria-label', 'Autre dialogue');
      other.innerHTML = '<button type="button">Autre</button>';
      document.body.append(other);
      other.showModal();
    });
    await visitor.waitForTimeout(2500);
    await expect(offer).toBeHidden();
    await expect(dialog).toBeHidden();
    await visitor.evaluate(() => document.getElementById('pb-e2e-other-dialog').close());
    await expect(offer).toBeVisible();
    await visitor.evaluate(() => document.getElementById('pb-e2e-other-dialog').remove());
    await offer.getByRole('button', { name: `Masquer : ${title}` }).click();

    await configure('scroll', 'always');
    await visitor.goto(`${includedPath}?popup=scroll`);
    await expect(offer).toBeHidden();
    await visitor.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await expect(offer).toBeVisible();
    await expect(dialog).toBeHidden();
    await offer.getByRole('button', { name: `Ouvrir : ${title}` }).click();
    await expect(dialog).toBeVisible();
    await visitor.keyboard.press('Escape');

    await configure('exit', 'always');
    await visitor.goto(`${includedPath}?popup=exit`);
    await expect(offer).toBeHidden();
    await visitor.mouse.move(400, 300);
    await visitor.mouse.move(400, -1);
    await expect(offer).toBeVisible();
    await expect(dialog).toBeHidden();
    await offer.getByRole('button', { name: `Ouvrir : ${title}` }).click();
    await expect(dialog).toBeVisible();
    await visitor.keyboard.press('Escape');

    await configure('idle', 'always');
    await visitor.goto(`${includedPath}?popup=idle`);
    await expect(offer).toBeHidden();
    await visitor.keyboard.press('Tab');
    await expect(offer).toBeVisible({ timeout: 8000 });
    await expect(dialog).toBeHidden();
    await offer.getByRole('button', { name: `Ouvrir : ${title}` }).click();
    await expect(dialog).toBeVisible();
    await visitor.keyboard.press('Escape');

    await configure('load', 'days');
    await visitor.goto(`${includedPath}?popup=days`);
    await expect(offer).toBeVisible();
    await offer.getByRole('button', { name: `Masquer : ${title}` }).click();
    await visitor.reload();
    await expect(offer).toBeHidden();
    await expect(opener).toHaveAttribute('aria-disabled', 'true');
    const key = `prime-builder:popup:v1:${id}`;
    expect(Number(await visitor.evaluate(key => localStorage.getItem(key), key))).toBeGreaterThan(Date.now());
    await visitor.evaluate(key => localStorage.setItem(key, String(Date.now() - 1)), key);
    await visitor.reload();
    await expect(offer).toBeVisible();

    const noStorage = await browser.newContext({ baseURL: test.info().project.use.baseURL });
    try {
      await noStorage.addInitScript(() => Object.defineProperty(window, 'localStorage', { get() { throw new DOMException('Denied', 'SecurityError'); } }));
      const restricted = await noStorage.newPage();
      const restrictedErrors = [];
      restricted.on('pageerror', error => restrictedErrors.push(error.message));
      await restricted.goto(`${includedPath}?popup=storage-blocked`);
      const restrictedOffer = restricted.locator('[data-pb-popup-offer]');
      await expect(restrictedOffer).toBeVisible();
      await restrictedOffer.getByRole('button', { name: `Masquer : ${title}` }).click();
      await expect(restricted.getByRole('link', { name: 'Ouvrir la fenêtre' })).toHaveAttribute('aria-disabled', 'true');
      await restricted.reload();
      await expect(restrictedOffer).toBeVisible();
      expect(restrictedErrors).toEqual([]);
    } finally {
      await noStorage.close();
    }

    // Simultaneous offers are queued by priority instead of covering each other.
    await configure('load', 'always');
    await page.goto('/admin/prime-builder/theme');
    await create.getByLabel('Nom du modèle').fill(`Autre fenêtre ${suffix}`);
    await create.getByLabel('Type de modèle').selectOption('popup');
    await create.getByRole('button', { name: 'Créer un modèle' }).click();
    await expect(page).toHaveURL(/\/admin\/prime-builder\/theme\/[a-f0-9]{32}$/);
    otherId = page.url().split('/').pop();
    await page.goto(`/admin/prime-builder/theme/${otherId}/edit`);
    const otherConfig = JSON.parse(await page.locator('[data-pb-config]').inputValue());
    const otherLayout = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: [
      { id: 'title', type: 'heading', settings: { text: 'Seconde fenêtre', level: 2 }, styles: {}, children: [] },
    ] } }] };
    const otherSaved = await api(`/api/prime-builder/v1/theme-models/${otherId}`, 'PATCH', { content: otherLayout, source_hash: otherConfig.sourceHash });
    expect(otherSaved.status, otherSaved.body).toBe(200);
    await page.goto(`/admin/prime-builder/theme/${otherId}`);
    await page.getByLabel('Déclencheur', { exact: true }).selectOption('load');
    await page.getByLabel('Délai après chargement (secondes, 0 à 120)').fill('0');
    await page.getByLabel('Fréquence', { exact: true }).selectOption('always');
    await page.getByLabel('Priorité', { exact: true }).fill('-10');
    await page.getByLabel('Activer ce modèle sur le site').check();
    await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    const queueContext = await browser.newContext({ baseURL: test.info().project.use.baseURL });
    try {
      const queued = await queueContext.newPage();
      await queued.goto(`${includedPath}?popup=queue`);
      const firstOffer = queued.locator(`[data-pb-popup="${id}"] [data-pb-popup-offer]`);
      const secondOffer = queued.locator(`[data-pb-popup="${otherId}"] [data-pb-popup-offer]`);
      await expect(firstOffer).toBeVisible();
      await expect(secondOffer).toBeHidden();
      await firstOffer.getByRole('button', { name: `Ouvrir : ${title}` }).click();
      await expect(queued.locator(`#pb-popup-${id}`)).toBeVisible();
      await expect(secondOffer).toBeHidden();
      await queued.keyboard.press('Escape');
      await firstOffer.getByLabel(autoLabel).check();
      await firstOffer.getByRole('button', { name: `Masquer : ${title}` }).click();
      const secondDialog = queued.locator(`#pb-popup-${otherId}`);
      await expect(secondDialog).toBeVisible();
      await expect(secondOffer).toBeHidden();
      await expect(secondDialog.getByLabel(autoLabel)).toBeChecked();
      await secondDialog.getByLabel(autoLabel).uncheck();
      await queued.keyboard.press('Escape');
      await expect(secondDialog).toBeHidden();
    } finally {
      await queueContext.close();
    }

    await toggle('deactivate'); disabled = true;
    await visitor.goto(`${includedPath}?popup=disabled`);
    await expect(visitor.locator('[data-pb-popup]')).toHaveCount(0);
    await expect(visitor.locator('script[src*="popup.js"]')).toHaveCount(0);
    await toggle('activate'); disabled = false;
    expect(errors).toEqual([]);
  } finally {
    await visitorContext?.close();
    if (disabled) await toggle('activate');
    if (id || otherId) {
      await page.goto('/admin/prime-builder/theme');
      for (const modelId of [id, otherId].filter(Boolean)) {
        const form = page.locator(`form[action="/admin/prime-builder/theme/${modelId}/delete"]`);
        if (await form.count()) await form.locator('button').click();
      }
    }
    for (const entry of entries) {
      await api(`/api/v1/content/page/${entry}`, 'DELETE');
      await api(`/api/v1/content/page/${entry}?force=1`, 'DELETE');
    }
  }
});
