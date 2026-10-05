/* Disposable long/short profit and whole-share short risk scenarios. */
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
  const page = await context.newPage(); const errors = []; page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('http://127.0.0.1:19308/preview'); await page.locator('#tgit-workspace').selectOption(String(fixture.workspace));
  await page.locator('#tgit-content').waitFor({ state: 'visible' }); await page.getByRole('tab', { name: 'Calculators', exact: true }).click();
  const stock = page.locator('#tgit-stock-calculator'); const profit = page.locator('#tgit-stock-result');
  for (const [name, value] of Object.entries({ entry_price: '100', exit_price: '110', quantity: '10', entry_fee: '2', exit_fee: '3' })) await stock.locator(`[name=${name}]`).fill(value);
  assert.equal(await stock.locator('[name=borrow_cost]').isVisible(), false);
  await stock.getByRole('button', { name: 'Calculate stock profit' }).click(); await profit.getByText('Net profit: 95.00 USD', { exact: true }).waitFor();
  await stock.locator('[name=direction]').selectOption('short'); assert.equal(await profit.textContent(), '');
  await stock.locator('[name=exit_price]').fill('90'); await stock.locator('[name=borrow_cost]').fill('4'); await stock.locator('[name=dividend_cost]').fill('1');
  await stock.getByRole('button', { name: 'Calculate stock profit' }).click(); await profit.getByText('Net profit: 90.00 USD', { exact: true }).waitFor();
  await stock.locator('[name=exit_price]').fill('110'); await stock.getByRole('button', { name: 'Calculate stock profit' }).click(); await profit.getByText('Net profit: -110.00 USD', { exact: true }).waitFor();
  await stock.locator('[name=direction]').selectOption('long'); await stock.getByRole('button', { name: 'Calculate stock profit' }).click(); await profit.getByText('Net profit: 95.00 USD', { exact: true }).waitFor();
  const risk = page.locator('#tgit-short-risk-calculator'); const result = page.locator('#tgit-short-risk-result');
  for (const [name, value] of Object.entries({ entry_price: '100', stop_price: '105', risk_budget: '200', estimated_costs: '12' })) await risk.locator(`[name=${name}]`).fill(value);
  const size = risk.getByRole('button', { name: 'Calculate short size' }); await size.click();
  await result.getByText('Position size: 37 whole shares', { exact: true }).waitFor(); await result.getByText('Total estimated risk: 197.00 USD', { exact: true }).waitFor();
  await result.getByText('Unused risk budget: 3.00 USD', { exact: true }).waitFor();
  await risk.locator('[name=stop_price]').fill('95'); await size.click(); await result.getByText('Short stop price must be above entry price.').waitFor(); assert.equal(await result.getAttribute('role'), 'alert');
  await risk.locator('[name=stop_price]').fill('105'); await risk.locator('[name=estimated_costs]').fill('200'); await size.click(); await result.getByText('Estimated costs must be below the risk budget.').waitFor();
  await risk.locator('[name=estimated_costs]').fill('12'); await size.click(); await result.getByText('Position size: 37 whole shares', { exact: true }).waitFor();
  await page.screenshot({ path: 'tmp/stock-desktop.png', fullPage: true }); await page.setViewportSize({ width: 360, height: 800 });
  assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)); await size.click(); await result.getByText('Position size: 37 whole shares', { exact: true }).waitFor();
  await page.screenshot({ path: 'tmp/stock-mobile.png', fullPage: true });
  let release; let reached; const held = new Promise((resolve) => { release = resolve; }); const pending = new Promise((resolve) => { reached = resolve; });
  await page.route('**/*', async (route) => {
   const url = new URL(route.request().url());
   if (!(url.searchParams.get('rest_route') || url.pathname).endsWith('/calculators/short-risk')) return route.continue();
   reached(); await held; await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: { position_size: '999', currency: 'USD' } }) });
  });
  await size.click(); await Promise.race([pending, new Promise((_, reject) => setTimeout(() => reject(new Error('Short risk request was not intercepted')), 10000))]);
  await risk.locator('[name=risk_budget]').fill('100'); release(); await page.waitForFunction(() => !document.querySelector('#tgit-short-risk-calculator button').disabled);
  assert.equal(await result.textContent(), ''); assert.deepEqual(errors, []);
  console.log('PASS Stock browser: long/short profit and costs, direction fields, whole-share risk sizing, validation and mobile layout.');
 } finally { await browser.close(); }
})().catch((error) => { console.error(error.message); process.exitCode = 1; });
