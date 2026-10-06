/* Disposable cached evidence and intercepted refresh responses only. */
'use strict';
const fs = require('node:fs'), assert = require('node:assert/strict');
const { chromium } = require(process.env.TGIT_PLAYWRIGHT_MODULE || './browser/node_modules/playwright-core');
const fixture = JSON.parse(fs.readFileSync('tmp/fundamental-browser-fixtures.json', 'utf8'));
const sessions = JSON.parse(fs.readFileSync('tmp/journal-http-fixtures.json', 'utf8')).sessions;
(async () => {
 const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
 try {
  let journalTrade = '';
  for (const actor of ['owner', 'viewer']) {
   const context = await browser.newContext({ viewport: { width: 1440, height: 900 } }); const session = sessions[actor === 'viewer' ? 'revoked' : 'owner'];
   await context.addCookies([{ name: session.cookie_name, value: session.cookie_value, url: 'http://127.0.0.1:19308', httpOnly: true }]);
   const page = await context.newPage(), errors = []; page.setDefaultTimeout(60000); page.on('pageerror', (error) => errors.push(error.message));
   const open = async () => { await page.goto('http://127.0.0.1:19308/preview'); await page.locator('#tgit-content').waitFor({ state: 'visible' }); await page.locator('#tgit-workspace').selectOption(String(fixture.workspace)); await page.getByRole('tab', { name: 'Research', exact: true }).click(); await page.locator('#tgit-fundamental-history tbody tr').first().waitFor(); };
   await open(); assert.equal(await page.locator('#tgit-fundamental-history tbody tr').count(), 3);
   assert.equal(await page.locator('#tgit-fundamental-owner').isVisible(), actor === 'owner');
   const income = page.locator('#tgit-fundamental-history tbody tr').filter({ hasText: 'income statement' }); await income.getByRole('button', { name: /View snapshot/ }).click();
   await page.locator('#tgit-fundamental-detail').getByText('net margin percent', { exact: true }).waitFor(); assert((await page.locator('#tgit-fundamental-detail').textContent()).includes('-5.00'));
   for (const width of [360, 768, 1440]) { await page.setViewportSize({ width, height: 900 }); assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `Fundamentals overflow at ${width}`); }
   if (actor === 'owner') {
    journalTrade = await page.evaluate(async (fixture) => {
     const response = await fetch(tgitRestUrl(tgitConfig.root, `workspaces/${fixture.workspace}/trades`), { method: 'POST', headers: { 'X-WP-Nonce': tgitConfig.nonce, 'Idempotency-Key': crypto.randomUUID(), 'Content-Type': 'application/json' }, body: JSON.stringify({ asset_id: Number(fixture.asset), title: 'Fundamentals shortcut fixture', state: 'planned', transaction_ids: [], journal: {} }) });
     const envelope = await response.json(); if (!response.ok) throw new Error(envelope.message); return String(envelope.data.trade.id);
    }, fixture);
   }
   const journalUrl = `http://127.0.0.1:19308/preview?tgit_section=journal&tgit_workspace=${fixture.workspace}&tgit_trade=${journalTrade}`;
   await page.goto(journalUrl); const shortcut = page.getByRole('button', { name: /View stock fundamentals for/ }); await shortcut.waitFor(); await page.waitForFunction(() => !window.tgitWriteBusy);
   if (actor === 'owner') {
    await page.getByRole('tab', { name: 'Plan and journal', exact: true }).click(); await page.locator('#tgit-trade-form [name=notes]').fill('Unsaved shortcut guard fixture'); await page.getByRole('tab', { name: 'Summary', exact: true }).click();
    page.once('dialog', (dialog) => dialog.dismiss()); await shortcut.click(); assert.equal(await page.getByRole('tab', { name: 'Trade Journal', exact: true }).getAttribute('aria-selected'), 'true'); assert.equal(await page.locator('#tgit-trade-form [name=notes]').inputValue(), 'Unsaved shortcut guard fixture');
    page.once('dialog', (dialog) => dialog.accept());
   }
   await shortcut.click(); await page.waitForFunction((asset) => document.getElementById('tgit-fundamental-asset').value === String(asset) && !document.getElementById('tgit-fundamental-reload').disabled, fixture.asset);
   assert.equal(new URL(page.url()).searchParams.get('tgit_fundamental_asset'), String(fixture.asset)); assert.equal(await page.locator('#tgit-fundamental-history tbody tr').count(), 3);
   await page.goBack(); await shortcut.waitFor(); await page.waitForFunction(() => !window.tgitWriteBusy); if (actor === 'owner') assert.equal(await page.locator('#tgit-trade-form [name=notes]').inputValue(), '');
   await shortcut.click(); await page.waitForFunction(() => !document.getElementById('tgit-fundamental-reload').disabled); await page.reload(); await page.locator('#tgit-fundamental-history tbody tr').first().waitFor(); assert.equal(await page.locator('#tgit-fundamental-asset').inputValue(), String(fixture.asset));
   if (actor === 'owner') {
    assert(await page.locator('#tgit-fundamental-refresh').isDisabled()); const keys = [];
    await page.route('**/*', async (route) => {
     const path = new URL(route.request().url()).searchParams.get('rest_route') || '';
     if (path.endsWith('/market-data')) return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: { fundamentals_enabled: true, providers: [] } }) });
     if (path.endsWith('/fundamentals/refresh')) { keys.push(route.request().headers()['idempotency-key']); assert.equal(JSON.parse(route.request().postData()).dataset, 'OVERVIEW'); return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: { state: 'uncertain' } }) }); }
     return route.continue();
    });
    await open(); await page.locator('#tgit-fundamental-refresh').click(); await page.waitForFunction(() => !window.tgitWriteBusy && document.getElementById('tgit-fundamental-refresh').textContent === 'Check refresh outcome');
    await open(); await page.locator('#tgit-fundamental-refresh').click(); await page.waitForFunction(() => !window.tgitWriteBusy);
    assert.equal(keys.length, 2); assert.equal(keys[0], keys[1]);
   }
   assert.deepEqual(errors, []); await context.close();
  }
  console.log('PASS Fundamental history and journal shortcuts: owner/viewer access, dirty cancellation/discard, Back/reload, exact metrics, disabled refresh, uncertain retry identity and responsive tables.');
 } finally { await browser.close(); }
})().catch((error) => { console.error(error.stack); process.exitCode = 1; });
