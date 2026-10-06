/* Owner settings on the disposable site; never sends provider traffic. */
'use strict';
const fs = require('node:fs'), assert = require('node:assert/strict');
const { chromium } = require(process.env.TGIT_PLAYWRIGHT_MODULE || './browser/node_modules/playwright-core');
const fixture = JSON.parse(fs.readFileSync('tmp/journal-http-fixtures.json', 'utf8'));
(async () => {
 const browser = await chromium.launch({ executablePath: process.env.TGIT_CHROME_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
 try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } }), session = fixture.sessions.owner;
  await context.addCookies([{ name: session.cookie_name, value: session.cookie_value, url: 'http://127.0.0.1:19308', httpOnly: true }]);
  const page = await context.newPage(), errors = []; page.setDefaultTimeout(60000); page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('http://127.0.0.1:19308/preview'); await page.locator('#tgit-content').waitFor({ state: 'visible' });
  const stock = await page.evaluate(async (workspace) => {
   const response = await fetch(tgitRestUrl(tgitConfig.root, `workspaces/${workspace}/assets`), { method: 'POST', headers: { 'X-WP-Nonce': tgitConfig.nonce, 'Content-Type': 'application/json' }, body: JSON.stringify({ symbol: `MKT${Date.now()}`, exchange: 'TESTEX', asset_class: 'stock', quote_currency: 'USD' }) });
   const envelope = await response.json(); if (!response.ok) throw new Error(envelope.message); return envelope.data;
  }, fixture.workspace);
  await page.reload(); await page.locator('#tgit-workspace').selectOption(String(fixture.workspace)); await page.locator('#tgit-content').waitFor({ state: 'visible' });
  await page.getByRole('tab', { name: 'Settings', exact: true }).click();
  await page.waitForFunction(() => document.getElementById('tgit-market-config').textContent.includes('rolling 24 hours') && !document.getElementById('tgit-market-form').inert);
  const form = page.locator('#tgit-market-form'); await form.locator('[name=asset_id]').selectOption(String(stock.id));
  assert.equal(await form.locator('[name=exchange]').inputValue(), 'TESTEX'); assert.equal(await form.locator('[name=currency]').inputValue(), 'USD');
  await form.locator('[name=provider_symbol]').fill(`${stock.symbol}-CONFIRMED`); await form.locator('[name=evidence]').fill('Synthetic provider directory identity check');
  page.once('dialog', (dialog) => dialog.dismiss()); await page.getByRole('tab', { name: 'Overview', exact: true }).click(); assert.equal(await page.locator('#tgit-page-title').textContent(), 'Settings'); assert.equal(await form.locator('[name=provider_symbol]').inputValue(), `${stock.symbol}-CONFIRMED`);
  await form.getByRole('button', { name: 'Save provider mapping', exact: true }).click(); await page.locator('#tgit-market-status').getByText('Provider mapping saved. No request was sent.', { exact: true }).waitFor();
  const row = page.locator('#tgit-market-mappings tbody tr').filter({ hasText: `${stock.symbol}-CONFIRMED` }); assert.equal(await row.count(), 1); assert.equal(await row.getByRole('button', { name: /Refresh price/ }).isDisabled(), true); assert((await row.textContent()).includes('Not fetched'));
  await form.locator('[name=frequency]').selectOption('once'); await form.getByRole('button', { name: 'Save refresh schedule', exact: true }).click();
  await page.locator('#tgit-market-status').getByText('Schedule saved; queueing is pending. Check server configuration and site cron.', { exact: true }).waitFor(); assert((await row.textContent()).includes('Weekdays 6:30 PM NY'));
  await form.locator('[name=frequency]').selectOption('off'); await form.getByRole('button', { name: 'Save refresh schedule', exact: true }).click(); await page.locator('#tgit-market-status').getByText('Automatic refresh disabled.', { exact: true }).waitFor();
  await row.getByRole('button', { name: /Edit .* mapping/ }).click(); await form.locator('[name=enabled]').selectOption('false'); await form.getByRole('button', { name: 'Save provider mapping', exact: true }).click();
  await page.waitForFunction((symbol) => [...document.querySelectorAll('#tgit-market-mappings tbody tr')].some((row) => row.textContent.includes(symbol) && row.textContent.includes('Disabled')), `${stock.symbol}-CONFIRMED`);
  // Simulate an uncertain provider outcome in the browser only; no external call.
  const requestKeys = [];
  await page.route('**/*', async (route) => {
   const target = new URL(route.request().url()).searchParams.get('rest_route') || '';
   if (target.endsWith('/market-data')) return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: { providers: [{ provider: 'fmp', enabled: true, daily_limit: 250 }, { provider: 'alpha_vantage', enabled: false, daily_limit: 25 }] } }) });
   if (target.endsWith('/refresh')) { requestKeys.push(route.request().headers()['idempotency-key']); return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: { state: 'uncertain' } }) }); }
   return route.continue();
  });
  await form.locator('[name=enabled]').selectOption('true'); await form.getByRole('button', { name: 'Save provider mapping', exact: true }).click();
  await row.getByRole('button', { name: /Refresh price/ }).click(); await row.getByRole('button', { name: /Check refresh outcome/ }).waitFor(); await page.waitForFunction(() => !window.tgitWriteBusy);
  await page.reload(); await page.locator('#tgit-workspace').selectOption(String(fixture.workspace)); await page.locator('#tgit-content').waitFor({ state: 'visible' });
  await page.getByRole('tab', { name: 'Settings', exact: true }).click(); await row.getByRole('button', { name: /Check refresh outcome/ }).click(); await page.waitForFunction(() => !window.tgitWriteBusy);
  assert.equal(requestKeys.length, 2); assert.equal(requestKeys[0], requestKeys[1]);
  for (const width of [360, 768, 1440]) { await page.setViewportSize({ width, height: 900 }); assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Market settings overflow at ${width}`); }
  assert.deepEqual(errors, []); console.log('PASS Market-data Settings: stock identity, owner mapping revisions, disabled providers, dirty guard, uncertain retry identity across reload and responsive table.');
 } finally { await browser.close(); }
})().catch((error) => { console.error(error.stack); process.exitCode = 1; });
