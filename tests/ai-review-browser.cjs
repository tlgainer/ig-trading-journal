/* Local synthetic review history; no AI/provider transport. */
'use strict';
const fs = require('node:fs'), assert = require('node:assert/strict');
const { chromium } = require(process.env.TGIT_PLAYWRIGHT_MODULE || './browser/node_modules/playwright-core');
const fixture = JSON.parse(fs.readFileSync('tmp/ai-review-browser-fixtures.json', 'utf8'));
const sessions = JSON.parse(fs.readFileSync('tmp/journal-http-fixtures.json', 'utf8')).sessions;
(async () => {
 const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
 try {
  for (const actor of ['owner', 'viewer']) {
   const context = await browser.newContext({ viewport: { width: 1440, height: 900 } }), session = sessions[actor === 'owner' ? 'owner' : 'revoked'];
   await context.addCookies([{ name: session.cookie_name, value: session.cookie_value, url: 'http://127.0.0.1:19308', httpOnly: true }]);
   const page = await context.newPage(), errors = [], cursors = []; page.setDefaultTimeout(60000); page.on('pageerror', (error) => errors.push(error.message));
   await page.route('**/*', async (route) => {
    const url = new URL(route.request().url()), path = url.searchParams.get('rest_route') || '';
    if (!path.endsWith(`/assets/${fixture.asset}/ai-reviews`)) return route.continue();
    cursors.push(url.searchParams.get('after')); url.searchParams.set('limit', '1'); const response = await route.fetch({ url: url.href }); return route.fulfill({ response });
   });
   const open = async () => { await page.goto(`http://127.0.0.1:19308/preview?tgit_review_fixture=1&tgit_workspace=${fixture.workspace}&tgit_section=research&tgit_fundamental_asset=${fixture.asset}`); await page.locator('#tgit-fundamental-history tbody tr').first().waitFor(); };
   await open(); const card = page.locator('#tgit-ai-reviews');
   if (actor === 'viewer') { assert.equal(await card.isVisible(), false); assert.equal(cursors.length, 0); await context.close(); continue; }
   await card.locator('tbody tr').nth(1).waitFor(); assert.deepEqual(cursors, ['0', String(fixture.first), String(fixture.second)]);
   await card.getByRole('button', { name: `View AI review ${fixture.first}`, exact: true }).click(); await card.getByRole('heading', { name: `AI review #${fixture.first}`, exact: true }).waitFor();
   assert((await card.locator('#tgit-ai-reviews-detail').textContent()).includes('<img src=x onerror="window.reviewInjected=true">')); assert.equal(await card.locator('#tgit-ai-reviews-detail img').count(), 0); assert.equal(await page.evaluate(() => window.reviewInjected), undefined);
   await card.getByText('Source provenance', { exact: true }).waitFor(); assert((await card.locator('#tgit-ai-reviews-detail').textContent()).includes(`Approved evidence #${fixture.approval}`)); assert((await card.locator('pre').textContent()).includes('fundamental-metrics-1'));
   for (const width of [360, 768, 1440]) { await page.setViewportSize({ width, height: 900 }); assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Review overflow at ${width}`); }
   await card.getByRole('button', { name: 'Reload saved reviews', exact: true }).click(); await card.locator('tbody tr').nth(1).waitFor(); assert.equal(await card.locator('#tgit-ai-reviews-detail').textContent(), '');
   let failed = false;
   await page.route('**/*', async (route) => { const path = new URL(route.request().url()).searchParams.get('rest_route') || ''; if (!failed && path.endsWith(`/ai-reviews/${fixture.first}`)) { failed = true; return route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ message: 'Fixture integrity conflict' }) }); } return route.fallback(); });
   await card.getByRole('button', { name: `View AI review ${fixture.first}`, exact: true }).click(); await card.getByText('Fixture integrity conflict', { exact: true }).waitFor(); assert.equal(await card.locator('#tgit-ai-reviews-detail').textContent(), '');
   await card.getByRole('button', { name: `View AI review ${fixture.second}`, exact: true }).click(); await card.getByRole('heading', { name: `AI review #${fixture.second}`, exact: true }).waitFor();
   let release, started; const gate = new Promise((resolve) => { release = resolve; }), signal = new Promise((resolve) => { started = resolve; });
   await page.route('**/*', async (route) => { const path = new URL(route.request().url()).searchParams.get('rest_route') || ''; if (!path.endsWith(`/ai-reviews/${fixture.first}`)) return route.fallback(); const response = await route.fetch(); started(); await gate; return route.fulfill({ response }); });
   await card.getByRole('button', { name: `View AI review ${fixture.first}`, exact: true }).click(); await signal; await page.locator('#tgit-workspace').selectOption(String(fixture.other_workspace));
   await card.getByText('No saved AI reviews yet. Summary generation is not available.', { exact: true }).waitFor();
   const delivered = page.waitForResponse((response) => (new URL(response.url()).searchParams.get('rest_route') || '').endsWith(`/ai-reviews/${fixture.first}`)); release(); await (await delivered).finished();
   await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))));
   await page.waitForFunction((asset) => document.getElementById('tgit-fundamental-asset').value === String(asset), fixture.other_asset);
   assert.equal(await card.locator('#tgit-ai-reviews-detail').textContent(), ''); assert.equal(await card.locator('tbody tr').count(), 0);
   assert.deepEqual(errors, []); await context.close();
  }
  console.log('PASS Saved AI review owner/viewer access, complete cursor loading, escaped output, provenance, responsive tables, reload/conflict recovery and stale workspace-response suppression.');
 } finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
