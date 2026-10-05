/* Whole-result holdings table, exact decimal presentation and account scope. */
'use strict';
const fs = require('node:fs'), assert = require('node:assert/strict');
const { chromium } = require(process.env.TGIT_PLAYWRIGHT_MODULE || './browser/node_modules/playwright-core');
const fixture = JSON.parse(fs.readFileSync('tmp/journal-http-fixtures.json', 'utf8'));
(async () => {
 const browser = await chromium.launch({ executablePath: process.env.TGIT_CHROME_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
 try {
  const context = await browser.newContext({ viewport: { width: 1100, height: 900 } }), session = fixture.sessions.owner;
  await context.addCookies([{ name: session.cookie_name, value: session.cookie_value, url: 'http://127.0.0.1:19308', httpOnly: true }]);
  const page = await context.newPage(); page.setDefaultTimeout(60000); const errors = [], cursors = []; page.on('pageerror', (error) => errors.push(error.message));
  let accountId;
  await page.route('**/*', async (route) => {
   const url = new URL(route.request().url()), path = url.searchParams.get('rest_route') || url.pathname;
   if (route.request().method() !== 'GET' || !path.endsWith(`/workspaces/${fixture.workspace}/holdings`)) return route.continue();
   const after = Number(url.searchParams.get('after') || 0); cursors.push(after);
   const rows = Array.from({ length: after ? 1 : 100 }, (_, index) => ({ account_id: accountId, asset_id: after ? 101 : index + 1, symbol: after ? 'CURSOR-SENTINEL' : `ASSET-${index}`, quantity: '30.000000000000000000', remaining_basis: '312.000000000000', basis_status: 'complete', currency: 'USD', realized_gain: '0.000000000000', market_value: null, unrealized_gain: null, price_status: 'missing' }));
   await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: { items: rows, next_cursor: after ? null : 100 } }) });
  });
  await page.route('**/*accounts*', async (route) => { const response = await route.fetch(), json = await response.json(); accountId = json.data.items[0].id; await route.fulfill({ response }); });
  await page.goto(`http://127.0.0.1:19308/preview?tgit_section=overview&tgit_workspace=${fixture.workspace}`); await page.locator('#tgit-content').waitFor({ state: 'visible' });
  const table = page.locator('#tgit-holdings'), search = table.getByRole('searchbox', { name: 'Search Holdings', exact: true }); await search.fill('CURSOR-SENTINEL'); const row = table.locator('tbody tr'); await row.getByText('CURSOR-SENTINEL', { exact: true }).waitFor(); assert.deepEqual(cursors, [0, 100]);
  assert.equal(await row.locator('[data-label=Quantity]').textContent(), '30'); assert.equal(await row.locator('[data-label="Remaining cost basis"]').textContent(), '312.00 USD'); assert.equal(await row.locator('[data-label=Quantity] span').getAttribute('title'), '30.000000000000000000'); assert.match(await row.textContent(), /Missing price/); assert.equal(await row.locator('[data-label="Market value"]').textContent(), 'Unavailable');
  await table.getByRole('combobox', { name: 'Holding account', exact: true }).selectOption(String(accountId)); assert.equal(await row.count(), 1);
  await page.setViewportSize({ width: 360, height: 900 }); assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)); await page.screenshot({ path: 'tmp/holdings-mobile.png', fullPage: true }); assert.deepEqual(errors, []);
  console.log('PASS Holdings: complete cursor loading, second-page search, exact decimal display, account filter, missing valuation and mobile overflow.');
 } finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
