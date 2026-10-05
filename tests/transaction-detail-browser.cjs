/* Read-only detail/revision rendering on desktop and mobile. */
'use strict';
const fs = require('node:fs'), assert = require('node:assert/strict');
const { chromium } = require(process.env.TGIT_PLAYWRIGHT_MODULE || './browser/node_modules/playwright-core');
const fixture = JSON.parse(fs.readFileSync('tmp/journal-http-fixtures.json', 'utf8'));
(async () => {
 const browser = await chromium.launch({ executablePath: process.env.TGIT_CHROME_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
 try {
  const context = await browser.newContext({ viewport: { width: 1100, height: 900 } }), session = fixture.sessions.owner;
  await context.addCookies([{ name: session.cookie_name, value: session.cookie_value, url: 'http://127.0.0.1:19308', httpOnly: true }]);
  const page = await context.newPage(); page.setDefaultTimeout(60000); const errors = []; page.on('pageerror', (error) => errors.push(error.message));
  await page.goto(`http://127.0.0.1:19308/preview?tgit_section=transactions&tgit_workspace=${fixture.workspace}`); await page.locator('#tgit-content').waitFor({ state: 'visible' });
  await page.waitForFunction(() => !window.tgitWriteBusy); await page.locator('#tgit-transactions').getByRole('combobox', { name: 'Transaction state', exact: true }).selectOption('posted'); await page.locator('#tgit-transactions tbody tr').first().getByRole('button', { name: 'View transaction', exact: true }).click();
  const detail = page.locator('#tgit-transaction-detail'); await detail.waitFor({ timeout: 15000 }).catch(async () => { throw new Error(`Detail did not open: ${await page.locator('#tgit-status').textContent()}; browser errors: ${errors.join(', ')}`); }); assert.match(await detail.textContent(), /Original realized gain.*Current realized gain/s); assert.equal(await detail.locator('form, textarea, button[type=submit]').count(), 0); assert.equal(await detail.getByRole('button', { name: /Edit draft|Post draft|Post transaction/ }).count(), 0);
  await detail.getByRole('button', { name: 'View revision', exact: true }).first().click(); await detail.getByText('Raw revision evidence', { exact: true }).click();
  for (const width of [1100, 360]) { await page.setViewportSize({ width, height: 900 }); assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)); await page.screenshot({ path: `tmp/transaction-detail-${width}.png`, fullPage: true }); }
  assert.deepEqual(errors, []); console.log('PASS Read-only transaction details and revision evidence at desktop/mobile widths.');
 } finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
