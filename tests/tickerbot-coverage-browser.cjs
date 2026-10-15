/* Isolated coverage UI: metadata is never presented as a verified price. */
'use strict';
const assert = require('node:assert/strict');
const { chromium } = require(process.env.TGIT_PLAYWRIGHT_MODULE || './browser/node_modules/playwright-core');
const html = '<div class="tgit"><form id="tgit-tickerbot-coverage-form"><select name="asset_id"></select><button id="tgit-tickerbot-coverage-save" type="submit">Check</button><button id="tgit-tickerbot-coverage-separate" type="button">Separate</button></form><p id="tgit-tickerbot-coverage-status" role="status"></p><div id="tgit-tickerbot-coverage-current"></div></div>';
(async () => {
 const browser = await chromium.launch({ executablePath: process.env.TGIT_CHROME_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
 try {
  const page = await browser.newPage(); await page.route('http://fixture.local/**', (route) => route.fulfill({ contentType: 'text/html', body: html })); await page.goto('http://fixture.local/');
  async function setup() {
   await page.addStyleTag({ path: 'assets/admin.css' });
   await page.evaluate(() => {
    window.tgitConfig = { root: 'http://fixture.local/', nonce: 'synthetic', actorId: 1, i18n: { network: 'Unavailable' } };
    window.tgitRestUrl = (root, path) => root + path; window.fixtureRequests = []; window.fixturePending = []; crypto.randomUUID = () => '11111111-1111-4111-8111-111111111111';
    window.fetch = (url, options) => { window.fixtureRequests.push({ url, options }); return new Promise((resolve) => window.fixturePending.push(resolve)); };
   });
   await page.addScriptTag({ path: 'assets/tickerbot-coverage.js' });
  }
  const change = (workspace, id, role = 'owner') => page.evaluate(({ workspace, id, role }) => window.dispatchEvent(new CustomEvent('tgit-workspace', { detail: { workspace, role, assets: [{ id, symbol: 'AAPL', exchange: 'XNAS', quote_currency: 'USD' }] } })), { workspace, id, role });
  const respond = (data, index = 0) => page.evaluate(({ data, index }) => window.fixturePending.splice(index, 1)[0]({ ok: true, json: async () => ({ data }) }), { data, index });
  const local = { asset: { id: '2', symbol: 'AAPL' }, eligible: true, mapping: { id: '17', provider_symbol: 'AAPL' }, coverage: null };
  await setup(); await change('1', '1'); await change('2', '2'); await respond(local, 1);
  await page.getByText(/Saved coverage loaded/).waitFor(); await respond({ asset: { symbol: 'FOREIGN-STALE' } }); assert(!(await page.locator('#tgit-tickerbot-coverage-current').textContent()).includes('FOREIGN-STALE'));
  assert((await page.evaluate(() => window.fixtureRequests)).every((row) => row.options.method === 'GET'));
  await page.getByRole('button', { name: 'Check coverage (one request)', exact: true }).click();
  const sent = await page.evaluate(() => window.fixtureRequests.at(-1)); assert.deepEqual(JSON.parse(sent.options.body), { expected_mapping_id: 17 });
  await respond({ state: 'uncertain' }); await page.waitForFunction(() => window.fixturePending.length === 1); await respond(local); await page.waitForFunction(() => !window.tgitWriteBusy); assert.equal(await page.locator('[name=asset_id]').isDisabled(), true);
  await page.reload(); await setup(); await change('2', '2'); local.mapping.id = '37'; await respond(local);
  await page.getByRole('button', { name: 'Check coverage outcome', exact: true }).waitFor(); await page.getByRole('button', { name: 'Check coverage outcome', exact: true }).click();
  const retry = await page.evaluate(() => window.fixtureRequests.at(-1)); assert.equal(retry.options.headers['Idempotency-Key'], sent.options.headers['Idempotency-Key']); assert.equal(retry.options.body, sent.options.body);
  await respond({ state: 'covered' }); await page.waitForFunction(() => window.fixturePending.length === 1);
  local.mapping.provider_symbol = '<img src=x onerror=alert(1)>'; local.coverage = { state: 'covered', checked_at: '2026-10-15 12:00:00', matches_current_mapping: false, summary: { columns: 3, populated: 2, price_null: true, price_asof_null: false, as_of: null } }; await respond(local); await page.waitForFunction(() => !window.tgitWriteBusy);
  const text = await page.locator('#tgit-tickerbot-coverage-current').textContent(); assert(text.includes('Historical check')); assert(text.includes('Listed as null')); assert(text.includes('Unknown')); assert(!text.includes('0.00 USD')); assert.equal(await page.locator('#tgit-tickerbot-coverage-current img').count(), 0);
  for (const width of [360, 768, 1440]) { await page.setViewportSize({ width, height: 900 }); assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Coverage fixture overflow at ${width}`); }
  await change('3', '3'); await respond({ asset: { symbol: 'NO-MAP' }, mapping: null, coverage: null, eligible: false }); await page.getByText(/Verify the current asset mapping/).waitFor(); assert.equal(await page.locator('#tgit-tickerbot-coverage-save').isDisabled(), true);
  await change('3', '3', 'viewer'); assert.equal(await page.locator('#tgit-tickerbot-coverage-current').textContent(), ''); assert.equal(await page.locator('#tgit-tickerbot-coverage-save').isDisabled(), true);
  console.log('PASS Coverage UI: local-only reads, explicit counted action, fixed retry mapping across reload, historical labels, null/unknown semantics, safe text and responsive fixture.');
 } finally { await browser.close(); }
})().catch((error) => { console.error(error.stack); process.exitCode = 1; });
