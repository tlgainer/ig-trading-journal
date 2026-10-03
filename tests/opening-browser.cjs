/* Real WordPress cookie/nonce browser workflow for documented starting positions. */
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
  await page.getByRole('tab', { name: 'Settings', exact: true }).click();
  const name = 'Opening browser cash ' + Date.now();
  const add = page.locator('#tgit-account-form'); await add.locator('input[name=name]').fill(name); await add.locator('input[name=native_currency]').fill('USD'); await add.getByRole('button', { name: 'Add account' }).click();
  const form = page.locator('#tgit-opening-form'); await form.locator('select[name=account_id]').selectOption({ label: `${name} (USD)` });
  await form.locator('input[name=effective_date]').fill('2026-01-01'); await form.locator('input[name=cash_amount]').fill('500'); await form.locator('input[name=source_note]').fill('Opening broker statement');
  await form.getByRole('button', { name: 'Record opening balance' }).click(); await page.locator('#tgit-opening-status').getByText('Opening balance recorded.', { exact: false }).waitFor();
  await page.getByRole('tab', { name: 'Overview', exact: true }).click(); await page.locator('#tgit-accounts').getByText(name, { exact: true }).waitFor();
  const cash = page.locator('#tgit-accounts article').filter({ has: page.getByText(name, { exact: true }) }); await cash.getByText('500.000000000000 USD').waitFor();
  await page.getByRole('tab', { name: 'Settings', exact: true }).click();
  await form.locator('select[name=account_id]').selectOption({ label: `${name} (USD)` }); await form.locator('select[name=kind]').selectOption('lot');
  await form.locator('select[name=asset_id]').selectOption({ index: 0 }); const assetId = await form.locator('select[name=asset_id]').inputValue();
  await form.locator('input[name=effective_date]').fill('2026-01-01'); await form.locator('input[name=acquired_on]').fill('2021-01-01'); await form.locator('input[name=quantity]').fill('2');
  await form.locator('input[name=basis_amount]').fill('200'); await form.locator('input[name=source_note]').fill('Original purchase statement');
  await form.getByRole('button', { name: 'Record opening balance' }).click(); await page.locator('#tgit-opening-status').getByText('Opening balance recorded.', { exact: false }).waitFor();
  await page.getByRole('tab', { name: 'Overview', exact: true }).click();
  const holding = page.locator('#tgit-holdings article').filter({ has: page.getByText('Account #', { exact: false }) }).filter({ hasText: 'Units 2.000000000000000000' });
  await holding.getByText('Remaining basis: 200.000000000000 USD').waitFor();
  await page.getByRole('tab', { name: 'Transactions', exact: true }).click(); const tx = page.locator('#tgit-transaction-form');
  await tx.locator('select[name=account_id]').selectOption({ label: `${name} (USD)` }); await tx.locator('select[name=action]').selectOption('sell'); await tx.locator('select[name=asset_id]').selectOption(assetId);
  await tx.locator('input[name=effective_date]').fill('2026-01-02'); await tx.locator('input[name=quantity]').fill('1'); await tx.locator('input[name=unit_price]').fill('150');
  await tx.getByRole('button', { name: 'Save transaction' }).click();
  await page.getByRole('tab', { name: 'Overview', exact: true }).click(); await cash.getByText('650.000000000000 USD').waitFor();
  assert.deepEqual(errors, []);
  console.log('PASS Browser opening cash and lot, then FIFO sale, with refreshed account/holding views and no JavaScript errors.');
 } finally { await browser.close(); }
})().catch((error) => { console.error(error.message); process.exitCode = 1; });
