/* Disposable saved evidence only; no external AI or provider calls. */
'use strict';
const fs = require('node:fs'), assert = require('node:assert/strict');
const { chromium } = require(process.env.TGIT_PLAYWRIGHT_MODULE || './browser/node_modules/playwright-core');
const fixture = JSON.parse(fs.readFileSync('tmp/ai-evidence-browser-fixtures.json', 'utf8'));
const sessions = JSON.parse(fs.readFileSync('tmp/journal-http-fixtures.json', 'utf8')).sessions;
(async () => {
 const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
 try {
  for (const actor of ['owner', 'viewer']) {
   const context = await browser.newContext({ viewport: { width: 1440, height: 900 } }), session = sessions[actor === 'owner' ? 'owner' : 'revoked'];
   await context.addCookies([{ name: session.cookie_name, value: session.cookie_value, url: 'http://127.0.0.1:19308', httpOnly: true }]);
   const page = await context.newPage(), errors = []; page.setDefaultTimeout(60000); page.on('pageerror', (error) => errors.push(error.message));
   const open = async () => {
    await page.goto(`http://127.0.0.1:19308/preview?tgit_workspace=${fixture.workspace}&tgit_section=research&tgit_fundamental_asset=${fixture.asset}`);
    await page.locator('#tgit-fundamental-history tbody tr').first().waitFor();
   };
   await open(); const card = page.locator('#tgit-ai-evidence');
   if (actor === 'viewer') { assert.equal(await card.isVisible(), false); await context.close(); continue; }
   await card.waitFor(); await page.waitForFunction(() => !document.getElementById('tgit-ai-evidence-form').inert);
   const choose = async () => { await card.locator('[name=INCOME_STATEMENT]').selectOption(String(fixture.snapshots.INCOME_STATEMENT)); await card.locator('#tgit-ai-evidence-trade').selectOption(String(fixture.trade)); };
   await card.getByRole('button', { name: 'Preview selected evidence', exact: true }).click(); await card.getByText('Select at least one saved statement snapshot.', { exact: true }).waitFor();
   await choose(); await card.getByRole('button', { name: 'Preview selected evidence', exact: true }).click(); await card.getByRole('heading', { name: 'Evidence preview', exact: true }).waitFor();
   const exact = await card.locator('pre').textContent(); assert(exact.includes('Saved synthetic thesis')); assert(!exact.includes('EXCLUDED-PRIVATE-NOTE'));
   await card.locator('#tgit-ai-evidence-trade').selectOption(''); assert(await card.getByRole('button', { name: 'Approve this evidence', exact: true }).isDisabled());
   await choose(); await card.getByRole('button', { name: 'Preview selected evidence', exact: true }).click(); await card.getByRole('heading', { name: 'Evidence preview', exact: true }).waitFor();
   for (const width of [360, 768, 1440]) { await page.setViewportSize({ width, height: 900 }); assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Evidence overflow at ${width}`); }
   await card.getByRole('button', { name: 'Approve this evidence', exact: true }).click(); await card.getByRole('heading', { name: /Approved evidence #/ }).waitFor();
   assert((await card.locator('#tgit-ai-evidence-status').textContent()).includes('No summary was generated'));
   await card.getByRole('button', { name: 'Check summary setup', exact: true }).click(); await card.getByText(/This check does not estimate the request cost/).waitFor(); assert((await card.textContent()).includes('Summary generation controls are not yet available.'));
   const original = await card.locator('pre').textContent(); await open(); await card.getByRole('heading', { name: /Approved evidence #/ }).waitFor(); assert.equal(await card.locator('pre').textContent(), original);
   await page.waitForFunction(() => !document.getElementById('tgit-ai-evidence-form').inert); await choose(); await card.getByRole('button', { name: 'Preview selected evidence', exact: true }).click(); await card.getByRole('heading', { name: 'Evidence preview', exact: true }).waitFor();
   const keys = []; let lost = false;
   await page.route('**/*', async (route) => {
    const path = new URL(route.request().url()).searchParams.get('rest_route') || '';
    if (!path.endsWith('/ai-evidence/approve')) return route.continue();
    keys.push(route.request().headers()['idempotency-key']);
    if (!lost) { lost = true; const response = await route.fetch(); assert.equal(response.status(), 200); await response.dispose(); return route.abort('failed'); }
    return route.continue();
   });
   await card.getByRole('button', { name: 'Approve this evidence', exact: true }).click(); await card.getByRole('button', { name: 'Check approval outcome', exact: true }).waitFor();
   await page.waitForFunction(() => !window.tgitWriteBusy); await open(); await card.getByRole('button', { name: 'Check approval outcome', exact: true }).waitFor();
   assert(await page.evaluate(() => document.getElementById('tgit-ai-evidence-form').inert));
   await card.getByRole('button', { name: 'Check approval outcome', exact: true }).click(); await card.getByRole('heading', { name: /Approved evidence #/ }).waitFor();
   assert.equal(keys.length, 2); assert.equal(keys[0], keys[1]); assert.equal(await card.getByRole('button', { name: 'Check approval outcome', exact: true }).isVisible(), false);
   assert.deepEqual(errors, []); await context.close();
  }
  console.log('PASS AI evidence preflight, owner/viewer controls, exact thesis exclusions, selection invalidation, approval/reload, responsive card and lost-response recovery using the original key.');
 } finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
