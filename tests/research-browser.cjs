/* Disposable manual watchlist, authored research and scenario browser workflow. */
'use strict';
const fs = require('node:fs');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.TGIT_PLAYWRIGHT_MODULE || './browser/node_modules/playwright-core');
const fixture = JSON.parse(fs.readFileSync('tmp/journal-http-fixtures.json', 'utf8'));
(async () => {
 const browser = await chromium.launch({ executablePath: process.env.TGIT_CHROME_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
 try {
  const context = await browser.newContext({ viewport: { width: 1100, height: 900 } }); const session = fixture.sessions.owner;
  await context.addCookies([{ name: session.cookie_name, value: session.cookie_value, url: 'http://127.0.0.1:19308', httpOnly: true }]);
  const page = await context.newPage(); page.setDefaultTimeout(60000); const errors = []; page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('http://127.0.0.1:19308/preview'); await page.locator('#tgit-workspace').selectOption(String(fixture.workspace));
  await page.getByRole('tab', { name: 'Research', exact: true }).click();
  const name = 'Browser watchlist ' + Date.now(); const create = page.locator('#tgit-watchlist-form');
  await create.locator('input[name=name]').fill(name); await create.getByRole('button', { name: 'Create watchlist' }).click();
  const item = page.locator('#tgit-watchlist-item-form'); await item.locator('select[name=watchlist_id]').selectOption({ label: name });
  await item.locator('select[name=asset_id]').selectOption({ index: 0 });
  await item.locator('input[name=target_buy]').fill('90'); await item.locator('input[name=target_sell]').fill('120');
  await item.locator('textarea[name=thesis]').fill('User-authored target'); await item.locator('input[name=tags]').fill('manual, fixture');
  await item.getByRole('button', { name: 'Save watchlist item' }).click();
  await page.locator('#tgit-watchlist-items').getByText('Targets: 90.000000000000000000 / 120.000000000000000000 USD').waitFor();
  await page.locator('#tgit-watchlist-items article').first().getByRole('button', { name: 'Edit' }).click();
  await item.locator('input[name=target_buy]').fill('85'); await item.getByRole('button', { name: 'Save watchlist item' }).click();
  await page.locator('#tgit-watchlist-items').getByText('Targets: 85.000000000000000000 / 120.000000000000000000 USD').waitFor();
  const note = page.locator('#tgit-research-note-form'); await note.locator('select[name=asset_id]').selectOption({ index: 0 });
  await note.locator('textarea[name=content]').fill('Browser research thesis'); await note.getByRole('button', { name: 'Save research note' }).click();
  await page.locator('#tgit-research-notes').getByText('Browser research thesis').waitFor();
  await page.locator('#tgit-research-notes article').first().getByRole('button', { name: 'Edit' }).click();
  await note.locator('textarea[name=content]').fill('Revised browser thesis'); await note.locator('input[name=reason]').fill('New facts');
  await note.getByRole('button', { name: 'Save research note' }).click(); await page.locator('#tgit-research-notes').getByText('Revised browser thesis').waitFor();
  await page.getByRole('tab', { name: 'Calculators', exact: true }).click();
  const calc = page.locator('#tgit-crypto-calculator'); await calc.locator('input[name=buy_price]').fill('20'); await calc.locator('input[name=sell_price]').fill('30'); await calc.locator('input[name=investment]').fill('100');
  await calc.getByRole('button', { name: 'Calculate profit' }).click(); await page.locator('#tgit-crypto-result').getByText('Profit: 50.000000000000 USD (50.000000000000%)').waitFor();
  assert.deepEqual(errors, []);
  console.log('PASS Browser watchlist create/revise, research create/revise and private calculator; no JavaScript errors.');
 } finally { await browser.close(); }
})().catch((error) => { console.error(error.message); process.exitCode = 1; });
