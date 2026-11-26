import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { execFileSync } from 'node:child_process';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';

const formId = process.env.E2E_FORM_ENTRY_ID || '';
const cmsRoot = process.env.E2E_CMS_ROOT || '';
const php = process.env.E2E_PHP || 'php';

async function audit(page) {
  const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
  expect(result.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => v.id)).toEqual([]);
}

function mailLogs() {
  const dir = path.join(cmsRoot, 'storage', 'logs');
  return new Map(readdirSync(dir).filter(name => /^mail-\d{4}-\d{2}-\d{2}\.log$/.test(name)).map(name => [name, statSync(path.join(dir, name)).size]));
}

test('form email actions are configured privately and sent by the real queue worker', async ({ page, browser }) => {
  test.setTimeout(120_000);
  expect(formId).toMatch(/^[1-9][0-9]*$/);
  expect(cmsRoot, 'E2E_CMS_ROOT must point to the disposable installed CMS').not.toBe('');
  let originalAddress;
  let submissionCreated = false;
  await page.goto('/admin/login');
  await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/), page.locator('form button[type=submit]').click()]);
  try {
    await page.goto('/admin/prime-builder/settings');
    const address = page.getByLabel('Adresse de notification des formulaires');
    originalAddress = await address.inputValue();
    await audit(page);
    await page.screenshot({ path: test.info().outputPath('notification-settings-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await audit(page);
    await page.screenshot({ path: test.info().outputPath('notification-settings-mobile.png'), fullPage: true });
    await address.fill('pb-notify-admin@example.test');
    const settingsSaved = page.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith('/admin/prime-builder/settings'));
    await page.locator('form[action="/admin/prime-builder/settings"] button[type="submit"]').click();
    expect((await settingsSaved).status()).toBe(302);
    await expect(address).toHaveValue('pb-notify-admin@example.test');
    await page.setViewportSize({ width: 1440, height: 900 });

    await page.goto(`/admin/prime-builder/edit/${formId}`);
    const reset = await page.evaluate(async entryId => {
      const csrf = document.querySelector('meta[name="csrf-token"]').content;
      const response = await fetch(`/api/prime-builder/v1/documents/${entryId}`, {
        method: 'PATCH', headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf },
        body: JSON.stringify({ content: { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: [] } }] }, status: 'published', published_at: '' }),
      });
      return response.status;
    }, formId);
    expect(reset).toBe(200);
    await page.reload();
    await page.getByRole('button', { name: 'Ajouter Formulaire de contact', exact: true }).click();
    await page.getByLabel('Notifier l’administrateur (adresse dans Réglages Prime Builder)').selectOption('send');
    await page.getByLabel('Accusé de réception au premier e-mail renseigné').selectOption('send');
    await audit(page);
    await page.getByLabel('Accusé de réception au premier e-mail renseigné').scrollIntoViewIfNeeded();
    await page.screenshot({ path: test.info().outputPath('notification-editor-desktop.png'), fullPage: true });
    await page.locator('[data-pb-save]').click();
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');

    const anonymous = await browser.newContext();
    try {
      const visitor = await anonymous.newPage();
      await visitor.goto('/formulaire-test?notification=1');
      const form = visitor.locator('[data-pb-form]');
      await expect(form).toHaveCount(1);
      await expect(form).toBeVisible();
      expect(await visitor.content()).not.toContain('pb-notify-admin@example.test');
      const resource = await visitor.request.get(`/api/v1/content/page/${formId}`);
      expect(resource.status()).toBe(200);
      expect(await resource.text()).not.toContain('pb-notify-admin@example.test');
      await form.getByLabel('Nom', { exact: true }).fill('Test notifications');
      await form.getByLabel('Adresse e-mail', { exact: true }).fill('pb-notify-sender@example.test');
      await form.getByLabel('Message', { exact: true }).fill('Corps confidentiel de test navigateur.');
      await expect(form.getByRole('button', { name: 'Envoyer', exact: true })).toBeEnabled();
      const responsePromise = visitor.waitForResponse(r => r.url().endsWith(`/api/prime-builder/v1/forms/${formId}/submit`) && r.request().method() === 'POST');
      await form.getByRole('button', { name: 'Envoyer', exact: true }).click();
      const response = await responsePromise;
      expect(response.status()).toBe(201);
      submissionCreated = true;
      const headers = await response.request().allHeaders();
      expect(headers.cookie).toBeUndefined();
      expect(headers['x-prime-csrf']).toBeUndefined();
      await expect(form.locator('[data-pb-form-status]')).toHaveText('Votre message a été enregistré.');
      await audit(visitor);
    } finally {
      await anonymous.close();
    }

    await page.goto('/admin/tools/queue');
    const jobs = page.locator('tr').filter({ hasText: 'prime-builder.form-notification' });
    await expect(jobs).toHaveCount(2);
    expect(await page.content()).not.toContain('pb-notify-sender@example.test');
    const before = mailLogs();
    const output = execFileSync(php, ['bin/prime', 'queue:work', '--max=100', '--verbose'], { cwd: cmsRoot, encoding: 'utf8', timeout: 60_000 });
    expect(output.match(/completed prime-builder\.form-notification/g) || []).toHaveLength(2);
    const after = mailLogs();
    let appended = '';
    for (const [name] of after) {
      appended += readFileSync(path.join(cmsRoot, 'storage', 'logs', name)).subarray(before.get(name) || 0).toString('utf8');
    }
    expect(appended).toContain('To: pb-notify-admin@example.test');
    expect(appended).toContain('To: pb-notify-sender@example.test');
    expect(appended).toContain('Nouvel envoi de formulaire');
    expect(appended).toContain('Votre formulaire a été reçu');
    expect(appended).not.toContain('Corps confidentiel de test navigateur.');
    await page.reload();
    await expect(jobs).toHaveCount(0);
    await page.goto('/admin/prime-builder/submissions?q=pb-notify-sender%40example.test');
    const row = page.locator('[data-pb-submission-id]').filter({ hasText: 'pb-notify-sender@example.test' });
    await expect(row).toHaveCount(1);
    await expect(row).toContainText('Corps confidentiel de test navigateur.');
    await row.getByRole('button', { name: /Supprimer l’envoi/ }).click();
    await expect(row).toHaveCount(0);
    submissionCreated = false;
  } finally {
    if (submissionCreated) {
      await page.goto('/admin/prime-builder/submissions?q=pb-notify-sender%40example.test');
      const row = page.locator('[data-pb-submission-id]').filter({ hasText: 'pb-notify-sender@example.test' });
      if (await row.count()) await row.first().getByRole('button', { name: /Supprimer l’envoi/ }).click();
    }
    if (originalAddress !== undefined) {
      await page.goto('/admin/prime-builder/settings');
      await page.getByLabel('Adresse de notification des formulaires').fill(originalAddress);
      const saved = page.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith('/admin/prime-builder/settings'));
      await page.locator('form[action="/admin/prime-builder/settings"] button[type="submit"]').click();
      expect((await saved).status()).toBe(302);
    }
  }
});
