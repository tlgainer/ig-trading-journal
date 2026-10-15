/* Isolated mapping UI: local reads, explicit sends and immutable retry inputs. */
'use strict';
const assert = require('node:assert/strict');
const { chromium } = require(process.env.TGIT_PLAYWRIGHT_MODULE || './browser/node_modules/playwright-core');
const html = '<div class="tgit"><form id="tgit-tickerbot-mapping-form"><select name="asset_id"></select><input name="provider_symbol" readonly><button id="tgit-tickerbot-map-save" type="submit">Verify</button><button id="tgit-tickerbot-map-separate" type="button">Separate</button></form><p id="tgit-tickerbot-map-status" role="status"></p><div id="tgit-tickerbot-map-current"></div></div>';
(async () => {
 const browser = await chromium.launch({ executablePath: process.env.TGIT_CHROME_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
 try {
  const page = await browser.newPage(); await page.route('http://fixture.local/**', (route) => route.fulfill({ contentType: 'text/html', body: html })); await page.goto('http://fixture.local/');
  async function setup() {
   await page.addStyleTag({ path: 'assets/admin.css' });
   await page.evaluate(() => {
    window.tgitConfig = { root: 'http://fixture.local/', nonce: 'synthetic', actorId: 1, i18n: { network: 'Unavailable' } };
    window.tgitRestUrl = (root, path) => root + path; window.fixtureRequests = []; window.fixturePending = [];
    crypto.randomUUID = () => '11111111-1111-4111-8111-111111111111';
    window.fetch = (url, options) => { window.fixtureRequests.push({ url, options }); return new Promise((resolve) => window.fixturePending.push(resolve)); };
   });
   await page.addScriptTag({ path: 'assets/tickerbot-mapping.js' });
  }
  const change = (workspace, id, role = 'owner') => page.evaluate(({ workspace, id, role }) => window.dispatchEvent(new CustomEvent('tgit-workspace', { detail: { workspace, role, assets: [{ id, symbol: 'AAPL', exchange: 'XNAS', quote_currency: 'USD' }] } })), { workspace, id, role });
  const respond = (data, index = 0) => page.evaluate(({ data, index }) => window.fixturePending.splice(index, 1)[0]({ ok: true, json: async () => ({ data }) }), { data, index });
  const local = { asset: { id: '2', symbol: 'AAPL', asset_class: 'stock', exchange: 'XNAS', quote_currency: 'USD' }, mapping: null };
  await setup(); await change('1', '1'); await change('2', '2'); await respond(local, 1);
  await page.getByText('Saved mapping loaded. No provider request was made.', { exact: true }).waitFor();
  await respond({ asset: { symbol: 'FOREIGN-STALE' } }); assert(!(await page.locator('#tgit-tickerbot-map-current').textContent()).includes('FOREIGN-STALE'));
  assert.equal(await page.locator('[name=provider_symbol]').inputValue(), 'AAPL');
  assert((await page.evaluate(() => window.fixtureRequests)).every((row) => row.options.method === 'GET'));
  await page.getByRole('button', { name: 'Verify mapping (one request)', exact: true }).click();
  const sent = await page.evaluate(() => window.fixtureRequests.at(-1)); assert.equal(sent.options.method, 'POST'); assert.deepEqual(JSON.parse(sent.options.body), { provider_symbol: 'AAPL', expected_mapping_id: 0 });
  await respond({ state: 'uncertain' }); await page.waitForFunction(() => window.fixturePending.length === 1); await respond(local); await page.waitForFunction(() => !window.tgitWriteBusy);
  assert.equal(await page.locator('[name=asset_id]').isDisabled(), true);
  await page.reload(); await setup(); await change('2', '2'); await respond(local);
  await page.getByRole('button', { name: 'Check verification outcome', exact: true }).waitFor();
  await page.getByRole('button', { name: 'Check verification outcome', exact: true }).click();
  const retry = await page.evaluate(() => window.fixtureRequests.at(-1)); assert.equal(retry.options.headers['Idempotency-Key'], sent.options.headers['Idempotency-Key']); assert.equal(retry.options.body, sent.options.body);
  await respond({ state: 'mapped', mapping_id: '37', checked_at: '2026-10-15 12:00:00' }); await page.waitForFunction(() => window.fixturePending.length === 1); local.mapping = { id: '37', provider_symbol: '<img src=x onerror=alert(1)>', checked_at: '2026-10-15 12:00:00' }; await respond(local); await page.waitForFunction(() => !window.tgitWriteBusy);
  assert.equal(await page.locator('#tgit-tickerbot-map-current img').count(), 0); assert.equal(await page.locator('[name=asset_id]').isEnabled(), true);
  for (const width of [360, 768, 1440]) { await page.setViewportSize({ width, height: 900 }); assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Mapping fixture overflow at ${width}`); }
  await change('2', '2', 'viewer'); assert.equal(await page.locator('#tgit-tickerbot-map-current').textContent(), ''); assert.equal(await page.locator('#tgit-tickerbot-map-save').isDisabled(), true);
  console.log('PASS Tickerbot mapping UI: local reads, explicit send, stale-response fencing, fixed retry inputs across reload, safe text, owner controls and responsive fixture.');
 } finally { await browser.close(); }
})().catch((error) => { console.error(error.stack); process.exitCode = 1; });
