/* Complete revision cursor loading and read-only journal controls on disposable data. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { chromium } = require(process.env.TGIT_PLAYWRIGHT_MODULE || './browser/node_modules/playwright-core');
const fixture = JSON.parse(fs.readFileSync('tmp/journal-http-fixtures.json', 'utf8'));
const history = JSON.parse(fs.readFileSync('tmp/history-ui-fixture.json', 'utf8'));
(async () => {
 const browser = await chromium.launch({ executablePath: process.env.TGIT_CHROME_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
 try {
  for (const kind of ['owner', 'revoked']) {
   const context = await browser.newContext({ viewport: { width: 360, height: 800 } }), session = fixture.sessions[kind];
   await context.addCookies([{ name: session.cookie_name, value: session.cookie_value, url: 'http://127.0.0.1:19308', httpOnly: true }]);
   const page = await context.newPage(); page.setDefaultTimeout(60000); const errors = []; page.on('pageerror', (error) => errors.push(error.message));
   await page.goto(`http://127.0.0.1:19308/preview?tgit_section=journal&tgit_workspace=${history.workspace}&tgit_trade=${history.trade}`);
   await page.locator('#tgit-trade-detail').waitFor({ state: 'visible' }); await page.waitForFunction(() => !window.tgitWriteBusy && document.querySelector('#tgit-trade-history h3')?.textContent === 'Journal revisions (25)');
   await page.getByRole('tab', { name: 'History', exact: true }).click(); assert.equal(await page.locator('#tgit-trade-history tbody tr').count(), 25);
   assert.equal(await page.locator('#tgit-trade-history tbody tr').first().locator('[data-label=Revision]').textContent(), '25');
   const oldest = page.locator('#tgit-trade-history tbody tr').filter({ has: page.locator('[data-label=Revision]').filter({ hasText: /^1$/ }) });
   await oldest.getByRole('button', { name: 'View revision', exact: true }).click(); await page.locator('#tgit-revision-detail').getByRole('heading', { name: 'Journal revision 1', exact: true }).waitFor();
   await page.locator('#tgit-revision-detail').getByRole('button', { name: 'Close revision', exact: true }).click();
   await page.screenshot({ path: `tmp/history-${kind}.png`, fullPage: true });
   if (kind === 'revoked') {
    // This user is revoked in the media workspace but explicitly a viewer in this separate history workspace.
    await page.getByRole('tab', { name: 'Plan and journal', exact: true }).click();
    assert.equal(await page.getByRole('button', { name: 'Add tag', exact: true }).isVisible(), false); assert.equal(await page.getByRole('button', { name: 'Add confluence', exact: true }).isVisible(), false); assert.equal(await page.locator('#tgit-save-journal').isVisible(), false);
   }
   assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)); assert.deepEqual(errors, []); await context.close();
  }
  console.log('PASS History browser: all 25 authorized revisions, oldest snapshot beyond initial API page, mobile overflow and viewer read-only controls.');
 } finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
