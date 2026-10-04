/* Focused transaction forms retain exact values and draft posting contracts. */
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
  await page.goto(`http://127.0.0.1:19308/preview?tgit_section=transactions&tgit_workspace=${fixture.workspace}`);
  await page.locator('#tgit-content').waitFor({ state: 'visible' });
  const accountName = `Transaction browser ${Date.now()}`;
  await page.evaluate(async (name) => {
   const workspace = new URL(location.href).searchParams.get('tgit_workspace');
   const response = await fetch(tgitRestUrl(tgitConfig.root, `workspaces/${workspace}/accounts`), { method: 'POST', headers: { 'X-WP-Nonce': tgitConfig.nonce, 'Content-Type': 'application/json' }, body: JSON.stringify({ name, broker: 'Fixture', native_currency: 'USD' }) });
   if (!response.ok) throw new Error('Fixture account creation failed');
   window.dispatchEvent(new CustomEvent('tgit-ledger-refresh'));
  }, accountName);
  await page.locator('#tgit-transaction-form [name=account_id] option').filter({ hasText: accountName }).waitFor({ state: 'attached' });
  const form = page.locator('#tgit-transaction-form'); assert.equal(await form.isVisible(), false);
  await page.locator('#tgit-new-transaction').click(); await form.locator('[name=account_id]').selectOption({ label: `${accountName} (USD)` }); await form.locator('[name=effective_date]').fill('2026-10-04'); await form.locator('[name=amount]').fill('12.345678901234'); await form.locator('[name=state]').selectOption('draft');
  const reject = (dialog) => dialog.dismiss(); page.on('dialog', reject);
  await page.locator('#tgit-cancel-edit').click(); assert.equal(await form.isVisible(), true);
  await page.locator('#tgit-tabs [data-tab=overview]').click(); assert.equal(await form.isVisible(), true); page.off('dialog', reject);
  await form.locator('button[type=submit]').click(); await page.waitForFunction(() => !window.tgitWriteBusy); assert.equal(await page.locator('#tgit-status').textContent(), 'Saved'); assert.equal(await form.isVisible(), false);
  await page.getByRole('combobox', { name: 'Transaction state', exact: true }).selectOption('draft');
  const initial = page.locator('#tgit-transactions tbody tr').filter({ hasText: accountName }); await initial.waitFor();
  const id = await initial.locator('[data-label=ID]').textContent();
  const row = page.locator('#tgit-transactions tbody tr').filter({ has: page.getByText(id, { exact: true }) });
  await page.getByRole('searchbox', { name: 'Search Transactions', exact: true }).fill(id);
  await row.getByRole('button', { name: 'Edit draft', exact: true }).click(); assert.equal(await form.locator('[name=amount]').inputValue(), '12.345678901234');
  await form.locator('[name=amount]').fill('13.345678901234'); await form.locator('button[type=submit]').click(); await page.locator('#tgit-status').getByText('Saved', { exact: true }).waitFor();
  assert.equal(await page.getByRole('searchbox', { name: 'Search Transactions', exact: true }).inputValue(), id);
  await row.getByRole('button', { name: 'Post draft', exact: true }).click(); await page.locator('#tgit-status').getByText('Saved', { exact: true }).waitFor();
  await page.getByRole('combobox', { name: 'Transaction state', exact: true }).selectOption('promoted'); await row.waitFor(); assert.match(await row.textContent(), /source retained/); assert.equal(await row.getByRole('button', { name: 'Edit draft', exact: true }).count(), 0);
  await page.getByRole('searchbox', { name: 'Search Transactions', exact: true }).fill(accountName); await page.getByRole('combobox', { name: 'Transaction state', exact: true }).selectOption('posted'); const posted = page.locator('#tgit-transactions tbody tr'); await posted.waitFor(); assert.notEqual(await posted.locator('[data-label=ID]').textContent(), id); assert.match(await posted.textContent(), /13.345678901234.*immutable/);
  await page.setViewportSize({ width: 360, height: 800 }); assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)); await page.screenshot({ path: 'tmp/transactions-mobile.png', fullPage: true }); assert.deepEqual(errors, []);
  const cursors = [];
  await page.route('**/*', async (route) => {
   const url = new URL(route.request().url()), path = url.searchParams.get('rest_route') || url.pathname;
   if (route.request().method() !== 'GET' || !path.endsWith(`/workspaces/${fixture.workspace}/transactions`)) return route.continue();
   const after = Number(url.searchParams.get('after') || 0); cursors.push(after);
   const rows = Array.from({ length: after ? 1 : 100 }, (_, index) => ({ id: 900000 + (after ? 100 : index), account_id: 0, asset_id: null, action: 'deposit', effective_date: '2026-01-01', state: 'posted', amount: '1.000000000000', currency: 'USD', current_realized_gain: '0.000000000000' }));
   await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: { items: rows, next_cursor: after ? null : 900099 } }) });
  });
  await page.reload(); await page.locator('#tgit-content').waitFor({ state: 'visible' }); await page.getByRole('searchbox', { name: 'Search Transactions', exact: true }).fill('#900100'); await page.locator('#tgit-transactions tbody tr').getByText('#900100', { exact: true }).waitFor(); assert.deepEqual(cursors, [0, 900099]);
  console.log('PASS Transactions: focused forms, dirty guards, exact draft values, retained search, revision save, promotion, immutable posted rows and mobile overflow.');
 } finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
