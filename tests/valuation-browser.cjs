/* Actual cached provider valuations on the disposable site; no provider requests. */
'use strict';
const fs = require('node:fs'), assert = require('node:assert/strict');
const { chromium } = require(process.env.TGIT_PLAYWRIGHT_MODULE || './browser/node_modules/playwright-core');
const fixture = JSON.parse(fs.readFileSync('tmp/journal-http-fixtures.json', 'utf8'));
const valuation = JSON.parse(fs.readFileSync('tmp/provider-valuation-fixtures.json', 'utf8'));
(async () => {
 const browser = await chromium.launch({ executablePath: process.env.TGIT_CHROME_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
 try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } }), session = fixture.sessions.owner;
  await context.addCookies([{ name: session.cookie_name, value: session.cookie_value, url: 'http://127.0.0.1:19308', httpOnly: true }]);
  const page = await context.newPage(), errors = []; page.setDefaultTimeout(60000); page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('http://127.0.0.1:19308/preview'); await page.locator('#tgit-workspace').selectOption(String(valuation.workspace)); await page.locator('#tgit-content').waitFor({ state: 'visible' });
  const source = page.locator('#tgit-price-source'), summary = page.locator('#tgit-stock-summary');
  await summary.getByText('Market value: 200.00 USD', { exact: true }).waitFor(); assert.equal(await source.inputValue(), 'manual');
  await source.selectOption('alpha_vantage'); await summary.getByText('Market value: 640.00 USD', { exact: true }).waitFor(); await summary.getByText('Unrealized gain/loss: 140.00 USD', { exact: true }).waitFor();
  assert((await page.locator('#tgit-holdings').textContent()).includes('alpha_vantage end-of-day'));
  await source.selectOption('fmp'); await summary.getByText('USD · Partial coverage', { exact: true }).waitFor(); assert((await page.locator('#tgit-holdings').textContent()).includes('check provider mapping'));
  await source.selectOption('alpha_vantage'); await summary.getByText('Market value: 640.00 USD', { exact: true }).waitFor();
  await page.reload(); await page.locator('#tgit-workspace').selectOption(String(valuation.workspace)); await page.locator('#tgit-content').waitFor({ state: 'visible' }); await summary.getByText('Market value: 640.00 USD', { exact: true }).waitFor(); assert.equal(await source.inputValue(), 'alpha_vantage');
  for (const width of [360, 768, 1440]) { await page.setViewportSize({ width, height: 900 }); assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Valuations overflow at ${width}`); }
  assert.deepEqual(errors, []); console.log('PASS Cached stock valuations: manual/provider selection, exact totals, missing-provider coverage, scoped source restoration and desktop/mobile layout.');
 } finally { await browser.close(); }
})().catch((error) => { console.error(error.stack); process.exitCode = 1; });
