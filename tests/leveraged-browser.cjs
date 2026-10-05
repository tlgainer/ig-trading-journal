/* Disposable linear scenarios: gains, losses, costs, validation and stale workspace responses. */
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
  await page.locator('#tgit-calculators-section details').evaluateAll((items) => items.forEach((item) => { item.open = true; }));
  const form = page.locator('#tgit-leveraged-calculator'); const output = page.locator('#tgit-leveraged-result');
  for (const [name, value] of Object.entries({ entry_price: '75000', exit_price: '82500', collateral: '2000', leverage: '2' })) await form.locator(`[name=${name}]`).fill(value);
  const calculate = form.getByRole('button', { name: 'Calculate leveraged profit' });
  await calculate.click(); await output.getByText('Net profit: 400.00 USD', { exact: true }).waitFor();
  await output.getByText('Notional exposure: 4000.00 USD', { exact: true }).waitFor();
  await output.getByText('Return on entered collateral: 20.00%', { exact: true }).waitFor();
  await form.locator('[name=exit_price]').fill('67500'); await calculate.click(); await output.getByText('Net profit: -400.00 USD', { exact: true }).waitFor();
  await form.locator('[name=direction]').selectOption('short'); await form.locator('[name=other_costs]').fill('10'); await calculate.click();
  await output.getByText('Net profit: 390.00 USD', { exact: true }).waitFor();
  await form.locator('[name=leverage]').fill('0.5'); await calculate.click(); await output.getByText('Leverage must be at least 1.').waitFor(); assert.equal(await output.getAttribute('role'), 'alert');
  await form.locator('[name=leverage]').fill('2');
  await page.screenshot({ path: 'tmp/leveraged-desktop.png', fullPage: true });
  await page.setViewportSize({ width: 360, height: 800 }); assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
  await calculate.click(); await output.getByText('Net profit: 390.00 USD', { exact: true }).waitFor();
  await page.screenshot({ path: 'tmp/leveraged-mobile.png', fullPage: true });
  let release; const held = new Promise((resolve) => { release = resolve; }); let reached;
  const pending = new Promise((resolve) => { reached = resolve; });
  await page.route('**/*', async (route) => {
   const url = new URL(route.request().url());
   if (!(url.searchParams.get('rest_route') || url.pathname).endsWith('/calculators/leveraged')) return route.continue();
   reached(); await held; await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { net_profit: '999', currency: 'USD' } }) });
  });
  await calculate.click(); await Promise.race([pending, new Promise((_, reject) => setTimeout(() => reject(new Error('Scenario request was not intercepted')), 10000))]);
  await page.evaluate(() => window.dispatchEvent(new CustomEvent('tgit-workspace', { detail: { workspace: '', role: 'viewer', accounts: [], assets: [], timezone: 'America/New_York' } })));
  release(); await calculate.waitFor({ state: 'visible' }); await page.waitForFunction(() => !document.querySelector('#tgit-leveraged-calculator button').disabled);
  assert.equal(await output.textContent(), ''); assert.deepEqual(errors, []);
  console.log('PASS Linear scenario browser: gains/losses/costs, short direction, validation, mobile layout and stale workspace response.');
 } finally { await browser.close(); }
})().catch((error) => { console.error(error.message); process.exitCode = 1; });
