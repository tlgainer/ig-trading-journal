/* Focused strategy editing, revision conflicts and immutable version viewing. */
'use strict';
const fs = require('node:fs'), assert = require('node:assert/strict');
const { chromium } = require(process.env.TGIT_PLAYWRIGHT_MODULE || './browser/node_modules/playwright-core');
const fixture = JSON.parse(fs.readFileSync('tmp/journal-http-fixtures.json', 'utf8'));
(async () => {
 const browser = await chromium.launch({ executablePath: process.env.TGIT_CHROME_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
 try {
  const context = await browser.newContext({ viewport: { width: 1100, height: 900 } }), session = fixture.sessions.owner;
  await context.addCookies([{ name: session.cookie_name, value: session.cookie_value, url: 'http://127.0.0.1:19308', httpOnly: true }]);
  const page = await context.newPage(); page.setDefaultTimeout(60000); const errors = []; page.on('pageerror', (error) => errors.push(error.message));
  await page.goto(`http://127.0.0.1:19308/preview?tgit_section=strategies&tgit_workspace=${fixture.workspace}`);
  await page.locator('#tgit-strategies tbody tr').first().waitFor(); assert.equal(await page.locator('#tgit-strategy-form').isVisible(), false);
  const name = `Focused strategy ${Date.now()}`, form = page.locator('#tgit-strategy-form'); await page.locator('#tgit-new-strategy').click();
  await form.locator('[name=name]').fill(name); await form.locator('[name=description]').fill('<p>Captured plan</p>'); await form.locator('[name=rules]').fill('<strong>Original rule</strong>'); for (const tag of ['fixture', 'Café']) { await form.getByRole('textbox', { name: 'New tag', exact: true }).fill(tag); await form.getByRole('textbox', { name: 'New tag', exact: true }).press('Enter'); } await form.locator('button[type=submit]').click();
  await page.locator('#tgit-strategy-status').getByText('Saved.', { exact: true }).waitFor(); await page.getByRole('searchbox', { name: 'Search Strategies', exact: true }).fill(name);
  const row = page.locator('#tgit-strategies tbody tr').filter({ has: page.getByText(name, { exact: true }) }); await row.getByRole('button', { name: 'Edit strategy', exact: true }).click();
  await form.locator('[name=rules]').fill('<strong>Unsaved rule</strong>'); const reject = (dialog) => dialog.dismiss(); page.on('dialog', reject); await form.getByRole('button', { name: 'Back to strategies', exact: true }).click(); assert.equal(await form.isVisible(), true);
  await page.locator('#tgit-tabs').getByRole('tab', { name: 'Trade Journal', exact: true }).click(); assert.equal(await form.isVisible(), true); page.off('dialog', reject);
  const conflict = await page.evaluate(async (name) => {
   const workspace = new URL(location.href).searchParams.get('tgit_workspace'), base = `workspaces/${workspace}`;
   const list = (await (await fetch(tgitRestUrl(tgitConfig.root, `${base}/strategies`), { headers: { 'X-WP-Nonce': tgitConfig.nonce } })).json()).data.items, strategy = list.find((item) => item.name === name);
   const current = (await (await fetch(tgitRestUrl(tgitConfig.root, `${base}/strategies/${strategy.id}`), { headers: { 'X-WP-Nonce': tgitConfig.nonce } })).json()).data, facts = JSON.parse(current.versions.at(-1).payload);
   return (await fetch(tgitRestUrl(tgitConfig.root, `${base}/strategies/${strategy.id}`), { method: 'POST', headers: { 'X-WP-Nonce': tgitConfig.nonce, 'Content-Type': 'application/json', 'Idempotency-Key': crypto.randomUUID() }, body: JSON.stringify({ ...facts, rules: '<strong>Concurrent rule</strong>', expected_revision: Number(strategy.revision) }) })).status;
  }, name); assert.equal(conflict, 200);
  await form.locator('button[type=submit]').click(); await form.getByRole('button', { name: 'Reload latest strategy', exact: true }).waitFor(); assert.equal(await form.locator('[name=rules]').inputValue(), '<strong>Unsaved rule</strong>');
  page.once('dialog', (dialog) => dialog.accept()); await form.getByRole('button', { name: 'Reload latest strategy', exact: true }).click(); await page.waitForFunction(() => !window.tgitWriteBusy); assert.equal(await form.locator('[name=rules]').inputValue(), '<strong>Concurrent rule</strong>');
  await form.getByRole('button', { name: 'Back to strategies', exact: true }).click(); assert.equal(await page.getByRole('searchbox', { name: 'Search Strategies', exact: true }).inputValue(), name);
  await row.getByRole('button', { name: 'View versions', exact: true }).click(); assert.equal(await page.locator('#tgit-strategy-view tbody tr').count(), 2);
  const original = page.locator('#tgit-strategy-view tbody tr').filter({ has: page.locator('[data-label=Version]').filter({ hasText: /^1$/ }) }); await original.getByRole('button', { name: 'View version', exact: true }).click(); await page.locator('#tgit-strategy-view').getByText('Original rule', { exact: true }).waitFor();
  await page.setViewportSize({ width: 360, height: 800 }); assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)); await page.screenshot({ path: 'tmp/strategy-mobile.png', fullPage: true });
  assert.deepEqual(errors, []); console.log('PASS Strategy browser: focused create/edit, dirty cancellation/navigation, retained list search, conflict recovery, immutable version view and mobile overflow.');
 } finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
