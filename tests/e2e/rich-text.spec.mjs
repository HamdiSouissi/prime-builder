import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const accessible = async page => {
  const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
  expect(result.violations.filter(v => ['serious', 'critical'].includes(v.impact)).map(v => ({ id: v.id, targets: v.nodes.map(node => node.target) }))).toEqual([]);
};

async function setup(page, slug, settings, type = 'text') {
  await page.goto('/admin/login');
  await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/), page.locator('form button[type=submit]').click()]);
  await page.goto(`/admin/prime-builder/edit/${process.env.E2E_ENTRY_ID || '1'}`);
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  const api = (url, method, body) => page.evaluate(async ({ url, method, body, csrf }) => {
    const response = await fetch(url, {
      method, headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf, Accept: 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    return { status: response.status, body: await response.text() };
  }, { url, method, body, csrf });
  const created = await api('/api/v1/content/page', 'POST', {
    title: 'Édition riche', slug, status: 'draft', content: { version: 1, blocks: [] },
  });
  expect(created.status, created.body).toBe(201);
  const entryId = JSON.parse(created.body).data.id;
  const content = { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: [
    { id: 'rich_text', type, settings, styles: {}, children: [] },
  ] } }] };
  const prepared = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content, status: 'published', published_at: '' });
  expect(prepared.status, prepared.body).toBe(200);
  const original = JSON.parse(prepared.body).data.content.blocks[0].data.elements[0].settings.text;
  await page.goto(`/admin/prime-builder/edit/${entryId}`);
  await page.locator('[data-pb-panel="navigator"]').click();
  await page.locator('[data-pb-tree-select="rich_text"]').click();
  return { api, entryId, original };
}

async function cleanup(page, api, entryId) {
  const leave = dialog => dialog.accept();
  page.on('dialog', leave);
  try {
    await api(`/api/v1/content/page/${entryId}`, 'DELETE');
    await api(`/api/v1/content/page/${entryId}?force=1`, 'DELETE');
  } finally { page.off('dialog', leave); }
}

test('rich text can be formatted with keyboard without losing supported existing HTML', async ({ page, browser }) => {
  test.setTimeout(180_000);
  const errors = []; page.on('pageerror', error => errors.push(error.message));
  const initial = '<p>Bonjour monde</p><h3>Un sous-titre</h3><table><tbody><tr><th>Nom</th><td>Valeur</td></tr></tbody></table><p><a href="/guide" target="_blank" rel="noopener noreferrer">Guide</a></p>';
  const { api, entryId, original } = await setup(page, 'pb-texte-riche', { text: initial });
  try {
    const source = page.getByLabel('Texte', { exact: true });
    await expect(source).toHaveValue(original);
    const toggle = page.getByRole('button', { name: 'Éditeur visuel' });
    await expect(toggle).toBeVisible();
    await toggle.focus(); await page.keyboard.press('Enter');
    const editable = page.getByRole('textbox', { name: 'Texte', exact: true });
    await expect(editable).toHaveAttribute('contenteditable', 'true');
    await expect(editable.locator('h3')).toHaveText('Un sous-titre');
    await expect(editable.locator('table th')).toHaveText('Nom');
    await expect(editable.locator('table td')).toHaveText('Valeur');
    await expect(editable.locator('a')).toHaveAttribute('href', '/guide');
    await expect(editable.locator('a')).toHaveAttribute('target', '_blank');
    await accessible(page);
    await page.screenshot({ path: test.info().outputPath('rich-editor-desktop.jpg'), quality: 15 });
    await page.getByRole('button', { name: 'Modifier le HTML' }).focus(); await page.keyboard.press('Enter');
    await expect(source).toHaveValue(original);
    await page.getByRole('button', { name: 'Éditeur visuel' }).focus(); await page.keyboard.press('Enter');
    await editable.focus();
    await page.keyboard.press('Control+Home');
    for (let i = 0; i < 7; i++) await page.keyboard.press('Shift+ArrowRight');
    await expect.poll(() => page.evaluate(() => window.getSelection().toString())).toBe('Bonjour');
    const bold = page.getByRole('button', { name: 'Gras', exact: true });
    for (let i = 0; i < 12 && !await bold.evaluate(el => el === document.activeElement); i++) await page.keyboard.press('Shift+Tab');
    await expect(bold).toBeFocused(); await expect(bold).toHaveAttribute('aria-pressed', 'false'); await page.keyboard.press('Enter');
    await expect(editable.locator('b, strong').first()).toHaveText('Bonjour');
    await expect(bold).toHaveAttribute('aria-pressed', 'true');
    await page.keyboard.press('Control+u');
    await expect(editable.locator('u')).toHaveCount(0);
    await expect(page.locator('.pb-rich-field [role="status"]')).toHaveText('Cette mise en forme n’est pas prise en charge. Utilisez les commandes proposées.');
    const previewBold = page.frameLocator('[data-pb-frame]').locator('[data-pb-id="rich_text"] b, [data-pb-id="rich_text"] strong');
    await expect(previewBold.first()).toHaveText('Bonjour');
    // Native editing undo and the Builder's own history must both reach the model.
    await expect(editable).toBeFocused();
    await page.keyboard.press('Control+z');
    await expect(editable.locator('b, strong')).toHaveCount(0);
    await expect(bold).toHaveAttribute('aria-pressed', 'false');
    await expect(previewBold).toHaveCount(0);
    await page.keyboard.press('Control+Shift+z');
    await expect(editable.locator('b, strong').first()).toHaveText('Bonjour');
    await expect(bold).toHaveAttribute('aria-pressed', 'true');
    await page.locator('[data-pb-undo]').click();
    await expect(source).not.toHaveValue(/<b>Bonjour<\/b>/);
    await page.locator('[data-pb-redo]').click();
    await expect(source).toHaveValue(/<b>Bonjour<\/b>/);
    await page.getByRole('button', { name: 'Éditeur visuel' }).click();
    await expect(editable.locator('b, strong').first()).toHaveText('Bonjour');
    await page.setViewportSize({ width: 375, height: 812 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
    await accessible(page);
    await page.screenshot({ path: test.info().outputPath('rich-editor-mobile.jpg'), quality: 15 });
    await page.setViewportSize({ width: 320, height: 400 });
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(320);
    await editable.evaluate(node => node.scrollIntoView({ block: 'center', inline: 'nearest' }));
    const visibleBounds = await editable.evaluate(node => ({ field: node.getBoundingClientRect().toJSON(), panel: node.closest('.pb-panel-content').getBoundingClientRect().toJSON(), footer: document.querySelector('.pb-bottombar').getBoundingClientRect().toJSON() }));
    expect(visibleBounds.field.top).toBeGreaterThanOrEqual(Math.max(0, visibleBounds.panel.top));
    expect(visibleBounds.field.bottom).toBeLessThanOrEqual(Math.min(visibleBounds.footer.top, visibleBounds.panel.bottom));
    await accessible(page);
    await page.screenshot({ path: test.info().outputPath('rich-editor-short.jpg'), quality: 15 });
    await page.setViewportSize({ width: 1440, height: 900 });
    const savedResponse = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click();
    expect((await savedResponse).status()).toBe(200);
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await page.reload();
    await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="rich_text"]').click();
    await page.getByRole('button', { name: 'Éditeur visuel' }).click();
    await expect(editable.locator('b, strong').first()).toHaveText('Bonjour');
    await expect(editable.locator('h3')).toHaveText('Un sous-titre');
    await expect(editable.locator('table td')).toHaveText('Valeur');
    await expect(editable.locator('a')).toHaveAttribute('href', '/guide');
    const publicPage = await page.context().newPage();
    try {
      await publicPage.goto('/pb-texte-riche');
      const rendered = publicPage.locator('[data-pb-id="rich_text"]');
      await expect(rendered.locator('b, strong').first()).toHaveText('Bonjour');
      await expect(rendered.locator('h3')).toHaveText('Un sous-titre');
      await expect(rendered.locator('table td')).toHaveText('Valeur');
      await expect(rendered.locator('a')).toHaveAttribute('href', '/guide');
      await accessible(publicPage);
    } finally { await publicPage.close(); }
    const noJs = await browser.newContext({ javaScriptEnabled: false });
    try {
      const fallback = await noJs.newPage(); await fallback.goto('/pb-texte-riche');
      await expect(fallback.locator('[data-pb-id="rich_text"] table td')).toHaveText('Valeur');
      await expect(fallback.locator('[data-pb-id="rich_text"] b, [data-pb-id="rich_text"] strong').first()).toHaveText('Bonjour');
    } finally { await noJs.close(); }
    expect(errors).toEqual([]);
  } finally { await cleanup(page, api, entryId); }
});

test('rich links and plain-text paste use safe URLs, keyboard, and undo', async ({ page }) => {
  test.setTimeout(180_000);
  const errors = [], dialogs = [];
  page.on('pageerror', error => errors.push(error.message));
  const onDialog = dialog => { dialogs.push(dialog.type()); dialog.dismiss(); };
  page.on('dialog', onDialog);
  const initial = '<p>Bonjour monde</p><p><a href="/old" target="_blank" rel="noopener noreferrer">Guide</a></p>';
  const { api, entryId } = await setup(page, 'pb-texte-liens', { text: initial });
  try {
    await page.getByRole('button', { name: 'Éditeur visuel' }).click();
    const editable = page.getByRole('textbox', { name: 'Texte', exact: true });
    const linkButton = page.getByRole('button', { name: 'Lien', exact: true });
    const address = page.getByRole('textbox', { name: 'Adresse du lien' });
    const pagesBeforeClick = page.context().pages().length;
    await editable.locator('a').last().click();
    expect(page.context().pages()).toHaveLength(pagesBeforeClick);
    await expect(page).toHaveURL(new RegExp(`/admin/prime-builder/edit/${entryId}$`));
    await editable.focus(); await page.keyboard.press('Control+Home');
    await page.keyboard.press('Shift+Tab'); await expect(linkButton).toBeFocused(); await page.keyboard.press('Enter');
    await address.fill('/guide'); await address.press('Enter');
    await expect(page.getByRole('alert')).toHaveText('Sélectionnez du texte avant de créer un lien.');
    await address.press('Escape'); await expect(editable).toBeFocused();
    await page.keyboard.press('Control+Home');
    for (let i = 0; i < 7; i++) await page.keyboard.press('Shift+ArrowRight');
    await expect.poll(() => page.evaluate(() => window.getSelection().toString())).toBe('Bonjour');
    await page.keyboard.press('Shift+Tab'); await expect(linkButton).toBeFocused(); await page.keyboard.press('Enter');
    await expect(address).toBeFocused();
    await accessible(page);
    for (const invalid of ['javascript:alert(1)', 'jav&#x61;script:alert(1)', 'java\tscript:alert(1)', 'data:text/html,test', '//evil.example/path']) {
      await address.fill(invalid); await address.press('Enter');
      await expect(page.getByRole('alert')).toHaveText('Saisissez une adresse de lien autorisée.');
      await expect(address).toHaveAttribute('aria-invalid', 'true');
      await expect(editable.locator('a')).toHaveCount(1);
    }
    await address.fill('/guide?x=1#detail'); await address.press('Enter');
    await expect(editable.locator('a').first()).toHaveAttribute('href', '/guide?x=1#detail');
    await expect(editable.locator('a').first()).toHaveText('Bonjour');
    await expect(editable).toBeFocused();
    await page.keyboard.press('Control+z'); await expect(editable.locator('a')).toHaveCount(1);
    await page.keyboard.press('Control+Shift+z'); await expect(editable.locator('a')).toHaveCount(2);

    // Change and remove an existing link, preserving its target and browser undo history.
    await editable.focus(); await page.keyboard.press('Control+End'); await page.keyboard.press('ArrowLeft');
    await linkButton.click(); await expect(address).toHaveValue('/old');
    await address.fill('/nouveau'); await address.press('Enter');
    const oldLink = editable.locator('a').last();
    await expect(oldLink).toHaveAttribute('href', '/nouveau');
    await expect(oldLink).toHaveAttribute('target', '_blank');
    await expect(oldLink).toHaveAttribute('rel', 'noopener noreferrer');
    await page.keyboard.press('Control+z'); await expect(oldLink).toHaveAttribute('href', '/old');
    await page.keyboard.press('Control+Shift+z'); await expect(oldLink).toHaveAttribute('href', '/nouveau');
    await expect(oldLink).toHaveAttribute('target', '_blank');
    await linkButton.click(); await page.getByRole('button', { name: 'Retirer le lien' }).click();
    await expect(editable.locator('a')).toHaveCount(1);
    await page.keyboard.press('Control+z'); await expect(editable.locator('a')).toHaveCount(2);

    await editable.focus(); await page.keyboard.press('Control+End');
    const prevented = await editable.evaluate(element => {
      const data = new DataTransfer();
      data.setData('text/plain', ' Collé <script>window.richPasteAttack=1</script>');
      data.setData('text/html', '<img src="/pb-evil-pixel.png" onerror="window.richPasteAttack=1">');
      const event = new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true });
      return !element.dispatchEvent(event);
    });
    expect(prevented).toBe(true);
    await expect(editable).toContainText('Collé <script>window.richPasteAttack=1</script>');
    await expect(editable.locator('script, img')).toHaveCount(0);
    expect(await page.evaluate(() => window.richPasteAttack)).toBeUndefined();
    const savedResponse = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click(); expect((await savedResponse).status()).toBe(200);
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await page.reload(); await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="rich_text"]').click();
    await page.getByRole('button', { name: 'Éditeur visuel' }).click();
    await expect(editable.locator('a').first()).toHaveAttribute('href', '/guide?x=1#detail');
    await expect(editable.locator('a').last()).toHaveAttribute('href', '/nouveau');
    await expect(editable.locator('a').last()).toHaveAttribute('target', '_blank');
    await expect(editable).toContainText('Collé <script>window.richPasteAttack=1</script>');
    const publicPage = await page.context().newPage();
    try {
      await publicPage.goto('/pb-texte-liens');
      const rendered = publicPage.locator('[data-pb-id="rich_text"]');
      await expect(rendered.locator('a').first()).toHaveAttribute('href', '/guide?x=1#detail');
      await expect(rendered.locator('a').last()).toHaveAttribute('target', '_blank');
      await expect(rendered).toContainText('Collé <script>window.richPasteAttack=1</script>');
      await expect(rendered.locator('script, img')).toHaveCount(0);
      await accessible(publicPage);
    } finally { await publicPage.close(); }
    expect(errors).toEqual([]); expect(dialogs).toEqual([]);
  } finally { page.off('dialog', onDialog); await cleanup(page, api, entryId); }
});

test('visual mode does not activate unsafe source HTML and leaves it untouched until edited', async ({ page }) => {
  test.setTimeout(180_000);
  const requests = [], errors = [];
  page.on('pageerror', error => errors.push(error.message));
  await page.route('**/pb-evil-pixel.png', route => { requests.push(route.request().url()); return route.abort(); });
  const { api, entryId } = await setup(page, 'pb-texte-filtre', { text: '<p>Initial</p>' });
  try {
    const values = await page.evaluate(async () => {
      const { safeRichHref } = await import('/assets/plugins/prime-builder/js/rich-text.js');
      return ['#ancre', '/guide?x=1', 'https://example.org/path', 'mailto:a@example.org', 'tel:+33123456789', 'javascript:alert(1)', 'jav&#x61;script:alert(1)', '&amp;#106;avascript:alert(1)', 'data:text/html,a', '//evil.example', '/\\evil.example'].map(value => safeRichHref(value));
    });
    expect(values).toEqual(['#ancre', '/guide?x=1', 'https://example.org/path', 'mailto:a@example.org', 'tel:+33123456789', null, null, null, null, null, null]);
    const source = page.getByLabel('Texte', { exact: true });
    const unsafe = '<p onclick="window.richSourceAttack=1">Texte <a href="jav&#x61;script:alert(1)">dangereux</a> <a href="/safe" onclick="window.richSourceAttack=1">sûr</a></p><img src="/pb-evil-pixel.png" onerror="window.richSourceAttack=1"><script>window.richSourceAttack=1</script><svg onload="window.richSourceAttack=1"><circle></circle></svg>';
    await source.fill(unsafe);
    await page.getByRole('button', { name: 'Éditeur visuel' }).click();
    const editable = page.getByRole('textbox', { name: 'Texte', exact: true });
    await expect(editable).toContainText('Texte dangereux sûr');
    await expect(editable.locator('script, img, svg, [onclick], [onerror], [onload]')).toHaveCount(0);
    await expect(editable.locator('a')).toHaveCount(1);
    await expect(editable.locator('a')).toHaveAttribute('href', '/safe');
    expect(await page.evaluate(() => window.richSourceAttack)).toBeUndefined();
    expect(requests).toEqual([]);
    await page.getByRole('button', { name: 'Modifier le HTML' }).click();
    await expect(source).toHaveValue(unsafe);
    await page.getByRole('button', { name: 'Éditeur visuel' }).click();
    await editable.focus(); await page.keyboard.press('Control+End'); await page.keyboard.insertText('!');
    await page.getByRole('button', { name: 'Modifier le HTML' }).click();
    const filtered = await source.inputValue();
    expect(filtered).not.toMatch(/<(?:script|img|svg)\b|onerror|onclick|onload|javascript:/i);
    expect(filtered).toContain('dangereux'); expect(filtered).toContain('href="/safe"');
    const savedResponse = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click(); expect((await savedResponse).status()).toBe(200);
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    const publicPage = await page.context().newPage();
    try {
      await publicPage.goto('/pb-texte-filtre');
      const rendered = publicPage.locator('[data-pb-id="rich_text"]');
      await expect(rendered).toContainText('dangereux');
      await expect(rendered.locator('script, img, svg, [onclick], [onerror], [onload]')).toHaveCount(0);
      await expect(rendered.locator('a')).toHaveAttribute('href', '/safe');
      expect(await publicPage.evaluate(() => window.richSourceAttack)).toBeUndefined();
    } finally { await publicPage.close(); }
    expect(requests).toEqual([]); expect(errors).toEqual([]);
  } finally { await cleanup(page, api, entryId); }
});

test('rich text reports the UTF-8 byte limit without silently discarding the draft', async ({ page }) => {
  test.setTimeout(180_000);
  const { api, entryId } = await setup(page, 'pb-texte-limite', { text: '<p>Initial</p>' });
  try {
    const source = page.getByLabel('Texte', { exact: true });
    const oversized = 'é'.repeat(10001); // 10,001 characters but 20,002 UTF-8 bytes.
    await source.fill(oversized);
    await expect(source).toHaveValue(oversized);
    await expect(source).toHaveAttribute('aria-invalid', 'true');
    await expect(page.getByRole('alert')).toContainText('20000 octets');
    const rejected = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click(); expect((await rejected).status()).toBe(422);
    await expect(source).toHaveValue(oversized);
    await page.getByRole('button', { name: 'Éditeur visuel' }).click();
    const editable = page.getByRole('textbox', { name: 'Texte', exact: true });
    await expect(editable).toHaveAttribute('aria-invalid', 'true');
    await editable.focus(); await page.keyboard.press('Control+End'); await page.keyboard.press('Backspace');
    await expect(editable).toHaveAttribute('aria-invalid', 'false');
    await expect(page.getByRole('alert')).toHaveCount(0);
    await page.getByRole('button', { name: 'Modifier le HTML' }).click();
    await expect(source).toHaveValue('é'.repeat(10000));
    const saved = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click(); expect((await saved).status()).toBe(200);
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await page.reload(); await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="rich_text"]').click();
    await expect(source).toHaveValue('é'.repeat(10000));
  } finally { await cleanup(page, api, entryId); }
});

test('rich controls edit separate fields and produce headings, lists, and emphasis', async ({ page }) => {
  test.setTimeout(180_000);
  const { api, entryId } = await setup(page, 'pb-texte-deux-champs', {
    front_text: '<p>Recto simple</p>', back_text: '<p>Verso intact</p>',
  }, 'flip_box');
  try {
    const front = page.locator('.pb-rich-field').filter({ has: page.getByText('Texte du recto', { exact: true }) });
    const back = page.locator('.pb-rich-field').filter({ has: page.getByText('Texte du verso', { exact: true }) });
    await expect(front).toHaveCount(1); await expect(back).toHaveCount(1);
    await front.getByRole('button', { name: 'Éditeur visuel' }).click();
    const frontText = front.getByRole('textbox', { name: 'Texte du recto' });
    await frontText.focus(); await page.keyboard.press('Control+Home');
    await front.getByRole('combobox', { name: 'Type de paragraphe' }).selectOption('h2');
    await expect(frontText.locator('h2')).toHaveText('Recto simple');
    await frontText.focus(); await page.keyboard.press('Control+End'); await page.keyboard.press('Enter');
    await page.keyboard.insertText('Liste');
    await front.getByRole('button', { name: 'Liste à puces' }).click();
    await expect(frontText.locator('ul li')).toHaveText('Liste');
    await expect(front.getByRole('button', { name: 'Liste à puces' })).toHaveAttribute('aria-pressed', 'true');
    await front.getByRole('button', { name: 'Liste numérotée' }).click();
    await expect(frontText.locator('ol li')).toHaveText('Liste');
    await expect(front.getByRole('button', { name: 'Liste numérotée' })).toHaveAttribute('aria-pressed', 'true');
    await frontText.focus(); await page.keyboard.press('Control+End');
    for (let i = 0; i < 5; i++) await page.keyboard.press('Shift+ArrowLeft');
    await front.getByRole('button', { name: 'Italique' }).click();
    await expect(frontText.locator('ol li em, ol li i')).toHaveText('Liste');
    await expect(back.locator('textarea')).toHaveValue('<p>Verso intact</p>');
    await back.getByRole('button', { name: 'Éditeur visuel' }).click();
    const backText = back.getByRole('textbox', { name: 'Texte du verso' });
    await backText.focus(); await page.keyboard.press('Control+End'); await page.keyboard.insertText(' !');
    await expect(backText).toHaveText('Verso intact !');
    await expect(frontText.locator('h2')).toHaveText('Recto simple');
    expect(await frontText.getAttribute('id')).not.toBe(await backText.getAttribute('id'));
    await accessible(page);
    const saved = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click(); expect((await saved).status()).toBe(200);
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await page.reload(); await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="rich_text"]').click();
    await expect(front.locator('textarea')).toHaveValue(/<h2>Recto simple<\/h2>/);
    await expect(front.locator('textarea')).toHaveValue(/<ol><li><(?:i|em)>Liste<\/(?:i|em)><\/li><\/ol>/);
    await expect(back.locator('textarea')).toHaveValue(/Verso intact !/);
    const publicPage = await page.context().newPage();
    try {
      await publicPage.goto('/pb-texte-deux-champs');
      const rendered = publicPage.locator('[data-pb-id="rich_text"]');
      await expect(rendered.getByRole('heading', { name: 'Recto simple', exact: true })).toBeVisible();
      await expect(rendered.locator('ol li i, ol li em')).toHaveText('Liste');
      await expect(rendered).toContainText('Verso intact !');
    } finally { await publicPage.close(); }
  } finally { await cleanup(page, api, entryId); }
});

test('block controls retain supported headings, quotes, and preformatted text after saving', async ({ page, browser }) => {
  test.setTimeout(180_000);
  const initial = '<p>Alpha</p><p>Bravo</p><p>Charlie</p><p>Delta</p><p>Echo</p>';
  const { api, entryId } = await setup(page, 'pb-texte-blocs', { text: initial });
  try {
    await page.getByRole('button', { name: 'Éditeur visuel' }).click();
    const editable = page.getByRole('textbox', { name: 'Texte', exact: true });
    const block = page.getByRole('combobox', { name: 'Type de paragraphe' });
    for (const [content, tag] of [['Alpha', 'h2'], ['Bravo', 'h3'], ['Charlie', 'h4'], ['Delta', 'blockquote'], ['Echo', 'pre']]) {
      await editable.locator('p').filter({ hasText: content }).click();
      await block.selectOption(tag);
      await expect(editable.locator(tag).filter({ hasText: content })).toHaveCount(1);
    }
    const saved = page.waitForResponse(response => response.request().method() === 'PATCH' && response.url().endsWith(`/documents/${entryId}`));
    await page.locator('[data-pb-save]').click(); expect((await saved).status()).toBe(200);
    await expect(page.locator('[data-pb-save-state]')).toHaveText('Enregistré');
    await page.reload(); await page.locator('[data-pb-panel="navigator"]').click();
    await page.locator('[data-pb-tree-select="rich_text"]').click();
    await page.getByRole('button', { name: 'Éditeur visuel' }).click();
    for (const [content, tag] of [['Alpha', 'h2'], ['Bravo', 'h3'], ['Charlie', 'h4'], ['Delta', 'blockquote'], ['Echo', 'pre']]) {
      await expect(editable.locator(tag).filter({ hasText: content })).toHaveCount(1);
    }
    const noJs = await browser.newContext({ javaScriptEnabled: false });
    try {
      const publicPage = await noJs.newPage(); await publicPage.goto('/pb-texte-blocs');
      const rendered = publicPage.locator('[data-pb-id="rich_text"]');
      for (const [content, tag] of [['Alpha', 'h2'], ['Bravo', 'h3'], ['Charlie', 'h4'], ['Delta', 'blockquote'], ['Echo', 'pre']]) {
        await expect(rendered.locator(tag).filter({ hasText: content })).toHaveCount(1);
      }
    } finally { await noJs.close(); }
  } finally { await cleanup(page, api, entryId); }
});
