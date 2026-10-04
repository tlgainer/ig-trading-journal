/* All current admin sections on the genuine disposable WordPress fixture. */
'use strict';
const fs = require('node:fs');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.TGIT_PLAYWRIGHT_MODULE || './browser/node_modules/playwright-core');
const fixture = JSON.parse(fs.readFileSync('tmp/journal-http-fixtures.json', 'utf8'));
(async () => {
 const browser = await chromium.launch({ executablePath: process.env.TGIT_CHROME_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
 try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } }); const session = fixture.sessions.owner;
  await context.addCookies([{ name: session.cookie_name, value: session.cookie_value, url: 'http://127.0.0.1:19308', httpOnly: true }]);
  const page = await context.newPage(); page.setDefaultTimeout(60000); const errors = []; page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('http://127.0.0.1:19308/preview'); await page.locator('#tgit-workspace').selectOption(String(fixture.workspace));
  await page.locator('#tgit-content').waitFor({ state: 'visible' });
  for (const width of [360, 768, 1440]) {
   await page.setViewportSize({ width, height: 900 });
   const menu = page.getByRole('button', { name: 'Sections', exact: true }); assert.equal(await menu.isVisible(), width < 782);
   for (const name of ['Overview', 'Transactions', 'Trade Journal', 'Strategies', 'Calculators', 'Research', 'Reports', 'Settings']) {
    if (width < 782) await menu.click(); await page.getByRole('tab', { name, exact: true }).click();
    assert.equal(await page.locator('#tgit-page-title').textContent(), name);
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${name} overflows at ${width}px`);
   }
  }
  await page.locator('#tgit-workspace-management').waitFor({ state: 'visible' });
  assert.equal(await page.locator('#tgit-workspace-management').getAttribute('open'), null);
  assert.equal(await page.locator('#tgit-workspace-form').isVisible(), false);
  await page.locator('#tgit-workspace-management summary').click(); assert.equal(await page.locator('#tgit-workspace-form').isVisible(), true);
  assert.deepEqual(errors, []);
  console.log('PASS All eight admin sections at 360/768/1440px; scoped shell, mobile menu, no overflow and collapsed workspace creation.');
 } finally { await browser.close(); }
})().catch((error) => { console.error(error.message); process.exitCode = 1; });
