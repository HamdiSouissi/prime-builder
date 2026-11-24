import { test, expect } from '@playwright/test';

test('late zone copies initialize each supported interactive widget independently', async ({ page, browser }) => {
  test.setTimeout(120_000);
  await page.goto('/admin/login');
  await page.locator('input[name=email]').fill(process.env.E2E_ADMIN_EMAIL || 'builder@prime.test');
  await page.locator('input[name=password]').fill(process.env.E2E_ADMIN_PASSWORD || 'Cascade-Corail-900!Orbit');
  await Promise.all([page.waitForURL(/\/admin\/?$/), page.locator('form button[type=submit]').click()]);
  await page.goto('/admin/appearance/zones?zone=sidebar');
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  const originalZone = JSON.parse(await page.locator('[data-prime-editor-input]').inputValue());
  const saveZone = content => page.request.post('/admin/appearance/zones/sidebar/save', {
    headers: { Accept: 'application/json', 'X-CSRF-Token': csrf }, data: { content },
  });
  const api = (url, method, body) => page.evaluate(async ({ url, method, body, csrf }) => {
    const response = await fetch(url, { method, headers: { 'Content-Type': 'application/json', 'X-Prime-CSRF': csrf, Accept: 'application/json' }, body: body === undefined ? undefined : JSON.stringify(body) });
    return { status: response.status, body: await response.text() };
  }, { url, method, body, csrf });
  let entryId = null;
  let releaseLive = () => {};
  try {
    const image = '/assets/plugins/prime-builder/icon.svg';
    const elements = [
      { id: 'timer', type: 'countdown', settings: { title: 'Rendez-vous', deadline: '2099-01-01T00:00', finished_text: 'Terminé' }, styles: {}, children: [] },
      { id: 'card', type: 'flip_box', settings: { front_title: 'Recto zone', back_title: 'Verso zone' }, styles: {}, children: [] },
      { id: 'carousel', type: 'image_carousel', settings: { label: 'Images zone', images: `${image}|Première\n${image}?second=1|Seconde` }, styles: {}, children: [] },
      { id: 'gallery', type: 'gallery', settings: { images: `${image}|Image une\n${image}?second=1|Image deux` }, styles: {}, children: [] },
      { id: 'portfolio', type: 'portfolio', settings: { items: `${image} | Icône | Projet un | Alpha\n${image} | Icône | Projet deux | Bêta` }, styles: {}, children: [] },
      { id: 'video', type: 'video', settings: { url: 'https://www.youtube.com/watch?v=M7lc1UVf-VE', video_title: 'Vidéo zone' }, styles: {}, children: [] },
      { id: 'navigation', type: 'navigation', settings: { label: 'Menu zone' }, styles: {}, children: [] },
      { id: 'form', type: 'form', settings: { title: 'Contact zone' }, styles: {}, children: [] },
    ];
    const saved = await saveZone({ version: 1, blocks: [{ id: 'builder', type: 'prime-builder/layout', data: { version: 1, elements } }] });
    expect(saved.status(), await saved.text()).toBe(200);
    const created = await api('/api/v1/content/post', 'POST', { title: 'Interactions des zones', slug: 'pb-zone-interactions', status: 'published', content: { version: 1, blocks: [] } });
    expect(created.status, created.body).toBe(201);
    const entry = JSON.parse(created.body).data;
    entryId = entry.id;
    const copies = ['zone1', 'zone2'].map(id => ({ id, type: 'theme_zone', settings: { zone: 'sidebar' }, styles: {}, children: [] }));
    const updated = await api(`/api/prime-builder/v1/documents/${entryId}`, 'PATCH', { content: { version: 1, blocks: [{ id: 'pb_layout', type: 'prime-builder/layout', data: { version: 1, elements: copies } }] } });
    expect(updated.status, updated.body).toBe(200);
    const browserErrors = [];
    page.on('pageerror', error => browserErrors.push(String(error)));
    const providerRequests = [];
    page.on('request', request => { if (/youtube|youtu\.be|ytimg|googlevideo/i.test(new URL(request.url()).hostname)) providerRequests.push(request.url()); });
    await page.route('https://www.youtube-nocookie.com/**', route => route.fulfill({ status: 200, contentType: 'text/html', body: '<p>Lecteur simulé</p>' }));
    let liveStarted;
    const started = new Promise(resolve => { liveStarted = resolve; });
    const gate = new Promise(resolve => { releaseLive = resolve; });
    await page.route(`**/documents/${entryId}/live`, async route => { liveStarted(); await gate; await route.continue(); });
    await page.goto(entry.url);
    await started;
    const native = page.locator('.aurora-sidebar');
    const zones = page.locator('.pb-layout[data-pb-live] .pb-theme_zone');
    await expect(zones).toHaveCount(2);
    await expect(zones.locator('[data-pb-flip]')).toHaveCount(0);
    for (const name of ['countdown', 'flip_box', 'image_carousel', 'gallery', 'portfolio', 'video', 'content', 'forms']) {
      await expect(page.locator(`script[src*="/js/${name}.js"]`)).toHaveCount(1);
    }
    releaseLive();
    await expect(zones.locator('[data-pb-flip]')).toHaveCount(2);
    const all = [native, ...(await zones.all())];
    const state = await Promise.all(all.map(zone => zone.evaluate(root => ({
      countdown: !root.querySelector('[data-pb-countdown-values]')?.hidden,
      flips: root.querySelectorAll('[data-pb-flip-control]').length,
      carousels: root.querySelectorAll('.pb-carousel-controls').length,
      galleries: root.querySelectorAll('dialog.pb-gallery-dialog').length,
      filters: root.querySelectorAll('.pb-portfolio-filters').length,
      video: !root.querySelector('[data-pb-video-load]')?.hidden,
      navigation: root.querySelectorAll('.pb-nav__mobile-toggle').length,
      // Zones have no owning entry; a contact form must not submit for ID 0.
      formDisabled: root.querySelector('[data-pb-form] button[type=submit]')?.disabled,
    }))));
    expect(state).toEqual(Array(3).fill({ countdown: true, flips: 1, carousels: 1, galleries: 1, filters: 1, video: true, navigation: 1, formDisabled: true }));
    const first = zones.first();
    // A repeated event must not create a second set of controls or reset state.
    await first.locator('[data-pb-flip-control]').click();
    await expect(first.locator('[data-pb-flip-back]')).toBeVisible();
    await first.locator('.pb-carousel-controls button').last().click();
    await expect(first.locator('[data-pb-slide]').last()).toBeVisible();
    await first.locator('.pb-portfolio-filters button').last().click();
    await expect(first.locator('[data-pb-portfolio-item]').first()).toBeHidden();
    await first.evaluate(node => node.dispatchEvent(new CustomEvent('prime-builder:fragment', { bubbles: true })));
    await expect(first.locator('[data-pb-flip-control]')).toHaveCount(1);
    await expect(first.locator('.pb-carousel-controls')).toHaveCount(1);
    await expect(first.locator('dialog.pb-gallery-dialog')).toHaveCount(1);
    await expect(first.locator('.pb-portfolio-filters')).toHaveCount(1);
    await expect(first.locator('[data-pb-flip-back]')).toBeVisible();
    await expect(first.locator('[data-pb-slide]').last()).toBeVisible();
    await expect(first.locator('[data-pb-portfolio-item]').first()).toBeHidden();
    await expect(zones.nth(1).locator('[data-pb-flip-back]')).toBeHidden();
    await expect(zones.nth(1).locator('[data-pb-slide]').first()).toBeVisible();
    await expect(zones.nth(1).locator('[data-pb-portfolio-item]').first()).toBeVisible();
    await first.locator('[data-pb-gallery-item]').first().click();
    await expect(first.locator('dialog.pb-gallery-dialog')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(first.locator('dialog.pb-gallery-dialog')).toBeHidden();
    expect(providerRequests).toEqual([]);
    await first.locator('[data-pb-video-load]').click();
    await expect(first.locator('iframe[title="Vidéo zone"]')).toHaveCount(1);
    await expect.poll(() => providerRequests.length).toBe(1);
    expect(browserErrors).toEqual([]);

    const noJs = await browser.newContext({ javaScriptEnabled: false, baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8080' });
    try {
      const fallback = await noJs.newPage();
      await fallback.goto(entry.url);
      const staticZone = fallback.locator('.aurora-sidebar');
      await expect(staticZone.locator('[data-pb-flip-front]')).toBeVisible();
      await expect(staticZone.locator('[data-pb-flip-back]')).toBeVisible();
      await expect(staticZone.locator('[data-pb-slide]')).toHaveCount(2);
      await expect(staticZone.locator('[data-pb-slide]').last()).toBeVisible();
      await expect(staticZone.locator('[data-pb-gallery-item]')).toHaveCount(2);
      await expect(staticZone.locator('[data-pb-video-load]')).toBeHidden();
      await expect(staticZone.locator('iframe')).toHaveCount(0);
    } finally { await noJs.close(); }
  } finally {
    releaseLive();
    if (entryId !== null) {
      const trashed = await api(`/api/v1/content/post/${entryId}`, 'DELETE');
      expect(trashed.status, trashed.body).toBe(200);
      const deleted = await api(`/api/v1/content/post/${entryId}?force=1`, 'DELETE');
      expect(deleted.status, deleted.body).toBe(204);
    }
    const restored = await saveZone(originalZone);
    expect(restored.status(), await restored.text()).toBe(200);
  }
});
