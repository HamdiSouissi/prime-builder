import { test, expect } from '@playwright/test';
import { spawn } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { readFileSync } from 'node:fs';
import http from 'node:http';
import net from 'node:net';

// This creates and deletes a page and submissions in the installed CMS. Never
// run it against a real site. Linux loopback aliases give each scenario its own
// address without changing the plugin's real per-IP submission quota.
const cmsRoot = process.env.E2E_CMS_ROOT || '';
const php = process.env.E2E_PHP || 'php';
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

async function unusedPort() {
  const listener = net.createServer();
  await new Promise((resolve, reject) => listener.once('error', reject).listen(0, '127.0.0.1', resolve));
  const address = listener.address();
  if (!address || typeof address === 'string') throw new Error('Cannot allocate a test port');
  await new Promise((resolve, reject) => listener.close(error => error ? reject(error) : resolve()));
  return address.port;
}

async function startPhp(settings) {
  const port = await unusedPort();
  const args = ['-d', 'display_errors=0', '-d', 'log_errors=1'];
  for (const [name, value] of Object.entries(settings)) args.push('-d', `${name}=${value}`);
  args.push('-S', `127.0.0.1:${port}`, '-t', 'public', 'public/router.php');
  const child = spawn(php, args, { cwd: cmsRoot, stdio: ['ignore', 'ignore', 'pipe'] });
  let logs = '';
  try {
    await new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error(`PHP server did not start: ${logs}`)), 15_000);
      child.once('error', error => { clearTimeout(timer); reject(error); });
      child.once('exit', code => { clearTimeout(timer); reject(new Error(`PHP server exited (${code}): ${logs}`)); });
      child.stderr.on('data', chunk => {
        logs = (logs + chunk.toString()).slice(-16_000);
        if (/Development Server .* started/.test(logs)) { clearTimeout(timer); resolve(); }
      });
    });
    return { child, port, logs: () => logs };
  } catch (error) {
    child.kill();
    throw error;
  }
}

async function stopPhp(server) {
  if (!server || server.child.exitCode !== null || server.child.signalCode !== null) return;
  await new Promise(resolve => { server.child.once('exit', resolve); server.child.kill(); });
}

function multipart(payload, parts) {
  const boundary = `pb-test-${randomBytes(12).toString('hex')}`;
  const buffers = [Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="payload"\r\n\r\n${JSON.stringify(payload)}\r\n`)];
  for (const [name, filename, bytes] of parts) {
    buffers.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="${name}"; filename="${filename}"\r\nContent-Type: text/plain\r\n\r\n`));
    buffers.push(bytes, Buffer.from('\r\n'));
  }
  buffers.push(Buffer.from(`--${boundary}--\r\n`));
  return { body: Buffer.concat(buffers), type: `multipart/form-data; boundary=${boundary}` };
}

function httpRequest(server, source, method, path, data) {
  const headers = { Accept: 'application/json', Connection: 'close' };
  if (data) {
    headers['Content-Type'] = data.type;
    headers['Content-Length'] = String(data.body.length);
  }
  return new Promise((resolve, reject) => {
    const request = http.request({ host: '127.0.0.1', port: server.port, localAddress: source, method, path, headers }, response => {
      const chunks = [];
      response.on('data', chunk => chunks.push(chunk));
      response.on('end', () => resolve({ status: response.statusCode, body: Buffer.concat(chunks).toString() }));
      response.on('error', reject);
    });
    request.setTimeout(15_000, () => request.destroy(new Error('HTTP request timed out')));
    request.on('error', reject);
    request.end(data?.body);
  });
}

test('real PHP and CMS upload limits reject partial requests without consuming the token', async ({ page }) => {
  test.skip(process.platform !== 'linux', 'The isolated loopback source addresses require Linux');
  test.setTimeout(120_000);
  expect(cmsRoot, 'E2E_CMS_ROOT must point to the disposable installed CMS').not.toBe('');
  const suffix = randomBytes(6).toString('hex');
  const email = `upload-${suffix}@example.test`;
  const [a, b] = randomBytes(2);
  const sources = [21, 22, 23].map(n => `127.${a}.${b}.${n}`);
  const field = (id, type, label, required) => ({ id, type, label, required, step: 1, options: [], value: '' });
  const fields = [field('f_email', 'email', 'E-mail', true), field('f_message', 'textarea', 'Message', true),
    field('f_one', 'file', 'Premier fichier', false), field('f_two', 'file', 'Deuxième fichier', false)];
  const document = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: [
    { id: 'upload_probe', type: 'form', settings: { form_fields: JSON.stringify(fields) }, styles: {}, children: [] },
  ] } }] };
  let entryId = null;
  let server = null;
  let originalApiLimit;
  let apiLimitChanged = false;
  async function api(url, method, body) {
    return page.evaluate(async ({ url, method, body }) => {
      const csrf = document.querySelector('meta[name="csrf-token"]').content;
      const response = await fetch(url, { method, headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf, Accept: 'application/json' },
        ...(body === undefined ? {} : { body: JSON.stringify(body) }) });
      return { status: response.status, body: await response.text() };
    }, { url, method, body });
  }
  async function start(source) {
    const response = await httpRequest(server, source, 'GET', `/api/prime-builder/v1/forms/${entryId}/start?node=upload_probe`);
    expect(response.status, response.body).toBe(200);
    const token = JSON.parse(response.body).data.token;
    expect(token).toBeTruthy();
    await delay(3200);
    return { node: 'upload_probe', token, website: '', fields: { f_email: email, f_message: 'Probe', f_one: null, f_two: null }, upload_count: 1 };
  }
  async function submit(source, payload, parts, expected) {
    const result = await httpRequest(server, source, 'POST', `/api/prime-builder/v1/forms/${entryId}/submit-multipart`, multipart(payload, parts));
    expect(result.status, `${result.body}\n${server.logs()}`).toBe(expected);
    if (expected === 201) expect(JSON.parse(result.body).data.accepted).toBe(true);
  }

  await page.goto('/admin/login');
  await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/), page.locator('form button[type=submit]').click()]);
  try {
    const created = await api('/api/v1/content/page', 'POST', {
      title: `Upload limits ${suffix}`, slug: `pb-upload-limits-${suffix}`, status: 'published', published_at: '2021-01-01 00:00:00', content: document,
    });
    expect(created.status, created.body).toBe(201);
    entryId = JSON.parse(created.body).data.id;

    server = await startPhp({ upload_max_filesize: '64K', post_max_size: '2M', max_file_uploads: '1' });
    const current = await start(sources[0]);
    await submit(sources[0], { ...current, upload_count: 2 }, [
      ['upload_f_one', 'one.txt', Buffer.from('one')], ['upload_f_two', 'two.txt', Buffer.from('two')],
    ], 422);
    // PHP converts numeric part names into integer keys in $_FILES.
    await submit(sources[0], current, [['0', 'numeric.txt', Buffer.from('numeric')]], 422);
    await submit(sources[0], current, [['upload_f_one', 'large.txt', Buffer.alloc(65537, 97)]], 422);
    await submit(sources[0], current, [['upload_f_one', 'modern.txt', Buffer.from('valid modern upload')]], 201);
    const old = await start(sources[0]);
    delete old.upload_count;
    await submit(sources[0], old, [['upload_f_one', 'legacy.txt', Buffer.from('valid legacy upload')]], 201);
    await stopPhp(server);
    server = null;

    server = await startPhp({ upload_max_filesize: '1M', post_max_size: '32K', max_file_uploads: '4' });
    const postLimited = await start(sources[1]);
    await submit(sources[1], postLimited, [['upload_f_one', 'large.txt', Buffer.alloc(40 * 1024, 97)]], 422);
    await submit(sources[1], postLimited, [['upload_f_one', 'post.txt', Buffer.from('valid post retry')]], 201);
    await stopPhp(server);
    server = null;

    const settings = await api('/api/v1/settings', 'GET');
    expect(settings.status, settings.body).toBe(200);
    originalApiLimit = JSON.parse(settings.body).data['api.max_body_kb'];
    expect(originalApiLimit).toBeDefined();
    apiLimitChanged = true;
    const limited = await api('/api/v1/settings', 'PATCH', { 'api.max_body_kb': 16 });
    expect(limited.status, limited.body).toBe(200);
    server = await startPhp({ upload_max_filesize: '1M', post_max_size: '2M', max_file_uploads: '4' });
    const apiLimited = await start(sources[2]);
    await submit(sources[2], apiLimited, [['upload_f_one', 'large.txt', Buffer.alloc(40 * 1024, 97)]], 413);
    await submit(sources[2], apiLimited, [['upload_f_one', 'api.txt', Buffer.from('valid api retry')]], 201);
    await stopPhp(server);
    server = null;
    const restored = await api('/api/v1/settings', 'PATCH', { 'api.max_body_kb': originalApiLimit });
    expect(restored.status, restored.body).toBe(200);
    apiLimitChanged = false;

    await page.goto(`/admin/prime-builder/submissions?q=${encodeURIComponent(email)}`);
    await expect(page.locator('[data-pb-submission-id]').filter({ hasText: email })).toHaveCount(4);
    for (const [filename, content] of [['modern.txt', 'valid modern upload'], ['legacy.txt', 'valid legacy upload'],
      ['post.txt', 'valid post retry'], ['api.txt', 'valid api retry']]) {
      const downloadPromise = page.waitForEvent('download');
      await page.getByRole('link', { name: filename, exact: true }).click();
      const download = await downloadPromise;
      expect(download.suggestedFilename()).toBe(filename);
      expect(readFileSync(await download.path(), 'utf8')).toBe(content);
    }
  } finally {
    await stopPhp(server);
    if (apiLimitChanged) {
      const restored = await api('/api/v1/settings', 'PATCH', { 'api.max_body_kb': originalApiLimit });
      expect(restored.status, restored.body).toBe(200);
    }
    if (entryId !== null) {
      await page.goto(`/admin/prime-builder/submissions?q=${encodeURIComponent(email)}`);
      let rows = page.locator('[data-pb-submission-id]').filter({ hasText: email });
      while (await rows.count()) {
        await rows.first().getByRole('button', { name: /Supprimer l’envoi/ }).click();
        await page.goto(`/admin/prime-builder/submissions?q=${encodeURIComponent(email)}`);
        rows = page.locator('[data-pb-submission-id]').filter({ hasText: email });
      }
      const trashed = await api(`/api/v1/content/page/${entryId}`, 'DELETE');
      expect(trashed.status, trashed.body).toBe(200);
      const deleted = await api(`/api/v1/content/page/${entryId}?force=1`, 'DELETE');
      expect(deleted.status, deleted.body).toBe(204);
    }
  }
});
