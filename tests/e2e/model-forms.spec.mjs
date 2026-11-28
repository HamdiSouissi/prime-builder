import { test as base, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { isolateLoopback } from './isolated-loopback.mjs';

const test = base.extend({
  baseURL: async ({ baseURL }, use) => {
    const proxy = await isolateLoopback(baseURL || 'http://127.0.0.1:8080');
    try { await use(proxy.baseURL); } finally { await proxy.close(); }
  },
});

test('model and popup forms submit independently for visitors and signed-in users', async ({ page, browser, baseURL }) => {
  test.setTimeout(180_000);
  // Do not let an individual locator or navigation consume the entire test
  // budget: a failure must leave time for cleanup and report its real cause.
  page.setDefaultTimeout(20_000);
  page.setDefaultNavigationTimeout(30_000);
  const suffix = Date.now().toString(36);
  const models = [];
  let entryId = null;
  let visitorContext;
  let primaryError = null;
  const modelForm = { id: 'contact', type: 'form', settings: {}, styles: {}, children: [] };
  const docx = readFileSync(new URL('./fixtures/contact-sample.docx', import.meta.url));
  const field = (id, type, label) => ({ id, type, label, required: true, step: 1, options: [], value: '' });
  const popupModelForm = { ...modelForm, settings: { form_fields: JSON.stringify([
    field('f_name', 'text', 'Nom'), field('f_email', 'email', 'Adresse e-mail'),
    field('f_message', 'textarea', 'Message'), field('f_file', 'file', 'Pièce jointe'),
  ]) } };
  const document = elements => ({ version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements } }] });
  async function api(url, method, body) {
    return page.evaluate(async ({ url, method, body }) => {
      const csrf = document.querySelector('meta[name="csrf-token"]').content;
      const response = await fetch(url, { method, signal: AbortSignal.timeout(20_000), headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf, Accept: 'application/json' }, ...(body === undefined ? {} : { body: JSON.stringify(body) }) });
      return { status: response.status, body: await response.text() };
    }, { url, method, body });
  }
  async function createModel(kind) {
    await page.goto('/admin/prime-builder/theme');
    const form = page.locator('form[action="/admin/prime-builder/theme/create"]');
    await form.getByLabel('Nom du modèle').fill(`Form ${kind} ${suffix}`);
    await form.getByLabel('Type de modèle').selectOption(kind);
    await form.getByRole('button', { name: 'Créer un modèle' }).click();
    await expect(page).toHaveURL(/\/admin\/prime-builder\/theme\/[a-f0-9]{32}$/);
    const id = page.url().split('/').pop();
    models.push(id);
    await page.goto(`/admin/prime-builder/theme/${id}/edit`);
    const config = JSON.parse(await page.locator('[data-pb-config]').inputValue());
    expect(config.widgets.form.hidden).toBe(false);
    const saved = await api(`/api/prime-builder/v1/theme-models/${id}`, 'PATCH', { content: document([kind === 'popup' ? popupModelForm : modelForm]), source_hash: config.sourceHash });
    expect(saved.status, saved.body).toBe(200);
    await page.goto(`/admin/prime-builder/theme/${id}`);
    // Target only this temporary page so unrelated models never affect the run.
    const include = page.locator('[data-pb-rule-group="include"]');
    await include.getByLabel('Condition').selectOption('entry');
    await include.getByLabel('Rechercher un contenu par son titre').fill(`Form ${suffix}`);
    await expect(include.getByRole('button', { name: new RegExp(`Form ${suffix}`) })).toBeVisible();
    await include.getByLabel('Rechercher un contenu par son titre').press('Enter');
    await page.getByLabel('Priorité', { exact: true }).fill('1000');
    await page.getByLabel('Activer ce modèle sur le site').check();
    if (kind === 'popup') {
      await page.locator('[name="popup_trigger"]').selectOption('load');
      await page.locator('[name="popup_delay"]').fill('0');
      await page.locator('[name="popup_frequency"]').selectOption('always');
    }
    await page.getByRole('button', { name: 'Enregistrer', exact: true }).click();
    await expect(page.getByLabel('Activer ce modèle sur le site')).toBeChecked();
    return id;
  }
  async function sendForm(currentPage, form, routeId, email) {
    await expect(form).toBeVisible();
    const button = form.getByRole('button', { name: 'Envoyer', exact: true });
    await expect(button).toBeEnabled();
    await form.getByLabel('Nom', { exact: false }).fill('Test');
    await form.getByLabel('Adresse e-mail', { exact: false }).fill(email);
    await form.getByLabel('Message', { exact: false }).fill(`Message ${suffix}`);
    const upload = form.getByLabel('Pièce jointe', { exact: false });
    const hasFile = await upload.count() > 0;
    if (hasFile) {
      await upload.setInputFiles({ name: 'trop-grand.txt', mimeType: 'text/plain', buffer: Buffer.alloc(512 * 1024 + 1, 65) });
      await button.click();
      await expect(upload).toBeFocused();
      await upload.setInputFiles({ name: 'demande.docx', mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', buffer: docx });
    }
    const endpoint = hasFile ? 'submit-multipart' : 'submit';
    const [response] = await Promise.all([
      currentPage.waitForResponse(response => response.url().endsWith(`/api/prime-builder/v1/forms/${routeId}/${endpoint}`) && response.request().method() === 'POST', { timeout: 30_000 }),
      button.click(),
    ]);
    expect(new URL(response.url()).origin).toBe(new URL(baseURL).origin);
    expect(response.status(), `Unexpected ${endpoint} status for ${routeId}`).toBe(201);
    if (hasFile) expect((await response.request().allHeaders())['content-type']).toMatch(/^multipart\/form-data; boundary=/);
    // The UI only announces success after parsing accepted=true. Reading the
    // same body again through Playwright can hang even after response headers.
    await expect(form.locator('[data-pb-form-status]')).toHaveText('Votre message a été enregistré.');
    return response;
  }

  await page.goto('/admin/login');
  await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/, { timeout: 15_000 }), page.locator('form button[type=submit]').click()]);
  try {
    const conditionalForm = { ...modelForm, id: 'private_form', conditions: { auth: 'authenticated' } };
    const created = await api('/api/v1/content/page', 'POST', { title: `Form ${suffix}`, slug: `pb-model-form-${suffix}`, status: 'published', content: document([conditionalForm]) });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;
    const header = await createModel('header');
    const popup = await createModel('popup');
    const path = `/pb-model-form-${suffix}`;
    visitorContext = await browser.newContext({ baseURL });
    visitorContext.setDefaultTimeout(20_000);
    visitorContext.setDefaultNavigationTimeout(30_000);
    const visitor = await visitorContext.newPage();
    await visitor.goto(path);
    const popupForm = visitor.locator(`[data-pb-popup="${popup}"] [data-pb-form]`);
    await expect(visitor.locator('main [data-pb-form]')).toHaveCount(0);
    const visitorPopup = await sendForm(visitor, popupForm, `models/${popup}`, `popup-${suffix}@example.test`);
    expect((await visitorPopup.request().allHeaders()).cookie).toBeUndefined();
    await visitor.locator(`[data-pb-popup="${popup}"] [data-pb-popup-close]`).click();
    const headerForm = visitor.locator('header.pb-theme-header [data-pb-form]');
    const visitorHeader = await sendForm(visitor, headerForm, `models/${header}`, `header-${suffix}@example.test`);
    expect((await visitorHeader.request().allHeaders()).cookie).toBeUndefined();
    expect((await visitorContext.cookies()).some(cookie => cookie.name === 'prime_session')).toBe(false);

    // A model may be visible only to signed-in users; its start supplies the
    // CMS CSRF header privately rather than embedding a token in cached HTML.
    const signedInPage = await page.goto(path);
    expect(signedInPage.headers()['cache-control']).toContain('private');
    await page.locator(`[data-pb-popup="${popup}"] [data-pb-popup-close]`).click();
    const authenticated = await sendForm(page, page.locator('header.pb-theme-header [data-pb-form]'), `models/${header}`, `user-${suffix}@example.test`);
    const headers = await authenticated.request().allHeaders();
    expect(headers.cookie).toBeTruthy();
    expect(headers['x-prime-csrf']).toMatch(/^[a-f0-9]{64}$/);
    const conditional = await sendForm(page, page.locator('main [data-pb-form]'), String(entryId), `member-${suffix}@example.test`);
    const conditionalHeaders = await conditional.request().allHeaders();
    expect(conditionalHeaders.cookie).toBeTruthy();
    expect(conditionalHeaders['x-prime-csrf']).toMatch(/^[a-f0-9]{64}$/);

    await page.goto('/admin/prime-builder/submissions');
    await page.getByLabel('Rechercher dans les envois').fill(suffix);
    await page.locator('form[role="search"]').getByRole('button', { name: 'Rechercher', exact: true }).click();
    const submissions = page.locator('[data-pb-submission-id]');
    await expect(submissions).toHaveCount(4);
    await expect(submissions.filter({ hasText: `header-${suffix}@example.test` })).toContainText(`${header} / contact`);
    const popupSubmission = submissions.filter({ hasText: `popup-${suffix}@example.test` });
    await expect(popupSubmission).toContainText(`${popup} / contact`);
    const [download] = await Promise.all([
      page.waitForEvent('download', { timeout: 20_000 }),
      popupSubmission.getByRole('link', { name: 'demande.docx' }).click(),
    ]);
    expect(download.suggestedFilename()).toBe('demande.docx');
    expect(readFileSync(await download.path())).toEqual(docx);
  } catch (error) {
    primaryError = error;
    throw error;
  } finally {
    try {
      if (visitorContext) await visitorContext.close();
      const search = `/admin/prime-builder/submissions?q=${encodeURIComponent(suffix)}`;
      await page.goto(search);
      let rows = page.locator('[data-pb-submission-id]').filter({ hasText: suffix });
      // This scenario creates at most four submissions. Bound cleanup, and
      // prove each selected row disappears before attempting the next one.
      for (let attempt = 0; attempt < 4 && await rows.count(); attempt++) {
        const id = await rows.first().getAttribute('data-pb-submission-id');
        expect(id).toMatch(/^[1-9][0-9]*$/);
        await rows.first().getByRole('button', { name: /Supprimer l’envoi/ }).click();
        await page.goto(search);
        await expect(page.locator(`[data-pb-submission-id="${id}"]`)).toHaveCount(0);
        rows = page.locator('[data-pb-submission-id]').filter({ hasText: suffix });
      }
      await expect(rows).toHaveCount(0);
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
    } catch (cleanupError) {
      // Keep the original assertion/timeout visible instead of replacing it
      // with a secondary error after Playwright closes the browser.
      if (primaryError) console.error('Model form cleanup failed after the primary error:', cleanupError);
      else throw cleanupError;
    }
  }
});
