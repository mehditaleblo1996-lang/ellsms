/* Optional browser regression check; run against a disposable development/staging account.
 * See docs/panel-ui-review.md. No sends, settings writes or account mutations are performed.
 */
const { chromium } = require('playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

(async () => {
  const base = process.env.ELLSMS_UI_BASE_URL;
  const username = process.env.ELLSMS_UI_USERNAME;
  const password = process.env.ELLSMS_UI_PASSWORD;
  assert(base && username && password, 'Set ELLSMS_UI_BASE_URL, ELLSMS_UI_USERNAME and ELLSMS_UI_PASSWORD for a test account.');
  const output = process.env.ELLSMS_UI_OUTPUT || fs.mkdtempSync('/tmp/ellsms-ui-');
  fs.mkdirSync(output, { recursive: true });
  const browser = await chromium.launch({
    executablePath: process.env.ELLSMS_UI_CHROMIUM || undefined,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  const checks = [], errors = [], measurements = [];
  try {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, reducedMotion: 'reduce' });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(new URL('/login', base).href);
    await page.locator('#authUsername').fill(username);
    await page.locator('#authPassword').fill(password);
    await page.locator('#authReveal').click();
    assert.equal(await page.locator('#authPassword').getAttribute('type'), 'text');
    await page.locator('#authReveal').click();
    await page.locator('.auth-submit').click();
    await page.waitForURL('**/dashboard');
    checks.push('Login and password visibility');

    const sidebar = page.locator('#sidebar');
    const toggle = page.locator('#menuToggle');
    assert.equal(await sidebar.evaluate(el => el.inert), true);
    await toggle.click();
    assert.equal(await sidebar.getAttribute('aria-modal'), 'true');
    assert.equal(await page.locator('.main').evaluate(el => el.inert), true);
    assert.equal(await page.locator('#sidebarClose').evaluate(el => el === document.activeElement), true);
    const drawer = await sidebar.boundingBox();
    assert(drawer.width <= 358 && drawer.height <= 844);
    await page.screenshot({ path: path.join(output, 'mobile-menu.png') });
    checks.push('Mobile drawer size, focus and background isolation');

    await page.locator('#navSearch').fill('ارسال');
    assert(await page.locator('.nav-item:visible').count() > 0);
    await page.locator('#navSearch').fill('no-such-menu-123');
    assert(await page.locator('#navSearchEmpty').isVisible());
    await page.locator('#navSearch').fill('');
    await sidebar.evaluate(el => el.querySelectorAll('details').forEach(group => { group.open = true; }));
    const targets = await page.locator('.nav-item, .nav-label').evaluateAll(elements => elements.map(el => ({
      label: el.textContent.trim(), width: el.getBoundingClientRect().width, height: el.getBoundingClientRect().height,
    })));
    assert(targets.every(target => target.width >= 44 && target.height >= 44));
    checks.push(`${targets.length} navigation targets at least 44px; search and empty state`);
    await sidebar.locator('a').last().focus();
    await page.keyboard.press('Tab');
    assert(await sidebar.evaluate(el => el.contains(document.activeElement)));
    await page.keyboard.press('Escape');
    assert(await toggle.evaluate(el => el === document.activeElement));
    assert.equal(await sidebar.evaluate(el => el.inert), true);
    await toggle.click();
    await page.locator('#sidebarBackdrop').click({ position: { x: 4, y: 300 } });
    assert.equal(await toggle.getAttribute('aria-expanded'), 'false');
    checks.push('Focus containment, Escape, focus return and backdrop dismissal');

    await toggle.click();
    await page.setViewportSize({ width: 1440, height: 1000 });
    assert.equal(await page.locator('.main').evaluate(el => el.inert), false);
    assert.equal(await sidebar.evaluate(el => el.inert), false);
    assert.equal(await page.locator('#sidebarClose').isVisible(), false);
    const group = sidebar.locator('details').nth(1);
    if (await group.count()) {
      const id = await group.getAttribute('data-group');
      if (await group.getAttribute('open') === null) await group.locator('summary').click();
      await page.reload();
      assert.equal(await sidebar.locator(`details[data-group="${id}"]`).getAttribute('open'), '');
    }
    assert.equal(await page.locator('.content').evaluate(el => getComputedStyle(el).animationName), 'none');
    checks.push('Desktop resize, saved expansion and reduced motion');
    await page.screenshot({ path: path.join(output, 'desktop-panel.png'), fullPage: true });

    const menus = await sidebar.locator('.nav-item').evaluateAll(links => links.map(el => ({
      href: el.getAttribute('href'), label: el.textContent.trim(),
    })));
    if (process.env.ELLSMS_UI_FULL === '1') {
      for (const width of [320, 360, 390, 768, 1024, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        for (const menu of menus) {
          const url = new URL(menu.href, base);
          assert.equal(url.origin, new URL(base).origin);
          const response = await page.goto(url.href);
          await page.evaluate(() => document.fonts.ready);
          const layout = await page.evaluate(() => ({
            viewport: innerWidth, document: document.documentElement.scrollWidth,
            panel: !!document.querySelector('.panel .content'),
            overflow: [...document.querySelectorAll('.content *')].filter(el => {
              const rect = el.getBoundingClientRect();
              return rect.width > 0 && (rect.left < -1 || rect.right > innerWidth + 1) && !el.closest('.table-wrap, pre, .tabs-nav');
            }).map(el => el.tagName + '.' + el.className),
          }));
          measurements.push({ ...menu, width, status: response.status(), ...layout });
          assert(response.ok() && layout.panel, `Menu did not load: ${menu.href}`);
          assert(layout.document <= layout.viewport + 1 && !layout.overflow.length, `Horizontal overflow: ${menu.href} at ${width}px`);
        }
      }
      checks.push(`${measurements.length} menu/viewport combinations`);
    }
    assert.deepEqual(errors, [], 'Browser runtime errors');
    console.log(JSON.stringify({ checks, output, measurements, errors }, null, 2));
    fs.writeFileSync(path.join(output, 'results.json'), JSON.stringify({ checks, measurements, errors }, null, 2));
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
