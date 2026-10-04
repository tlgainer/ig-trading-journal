/* Disposable desktop/mobile manual observation, saved-view and report workflow. */
'use strict';
const fs = require('node:fs'), assert = require('node:assert/strict');
const { chromium } = require(process.env.TGIT_PLAYWRIGHT_MODULE || './browser/node_modules/playwright-core');
const fixture = JSON.parse(fs.readFileSync('tmp/journal-http-fixtures.json', 'utf8'));
(async () => {
 const browser = await chromium.launch({ executablePath: process.env.TGIT_CHROME_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
 try {
  for (const width of [1100, 360]) {
   const priceSource = `Browser price ${width} ${Date.now()}`;
   const context = await browser.newContext({ viewport: { width, height: 900 } }), session = fixture.sessions.owner;
   await context.addCookies([{ name: session.cookie_name, value: session.cookie_value, url: 'http://127.0.0.1:19308', httpOnly: true }]);
   const page = await context.newPage(); page.setDefaultTimeout(30000); const errors = []; page.on('pageerror', (error) => errors.push(error.message));
   await page.goto('http://127.0.0.1:19308/preview'); await page.locator('#tgit-workspace').selectOption(String(fixture.workspace));
   await page.getByRole('tab', { name: 'Reports', exact: true }).click();
   const form = page.locator('#tgit-observation-form'); await form.locator('select[name=asset_id] option').first().waitFor({ state: 'attached' });
   await form.locator('select[name=asset_id]').selectOption({ index: 0 });
   await form.locator('input[name=value]').fill('110'); await form.locator('input[name=effective_date]').fill('2026-01-03'); await form.locator('input[name=expires_on]').fill('2026-01-04');
   await form.locator('input[name=source]').fill(priceSource); await form.locator('input[name=reason]').fill('Manual fixture');
   await form.getByRole('button', { name: 'Save observation', exact: true }).click(); await page.locator('#tgit-observations').getByText(`${priceSource}: Manual fixture`, { exact: true }).waitFor();
   const report = page.locator('#tgit-report-form'); await report.locator('input[name=as_of]').fill('2026-01-04'); await report.locator('select[name=type]').selectOption('holdings');
   await report.getByRole('button', { name: 'Generate report' }).click(); await page.locator('#tgit-report-summary').getByText(/Saved report #.*holdings/).waitFor();
   await page.waitForFunction(() => !window.tgitWriteBusy);
   const savedId = await page.locator('#tgit-report-load-form input').inputValue(); assert.ok(Number(savedId) > 0);
   const view = page.locator('#tgit-view-form'), name = `Browser saved view ${width} ${Date.now()}`;
   await view.locator('input[name=name]').fill(name); await view.getByRole('button', { name: 'Save current filters' }).click();
   await view.locator('select[name=view_id] option').filter({ hasText: name }).waitFor({ state: 'attached' });
   await report.locator('select[name=type]').selectOption('activity'); await view.locator('select[name=view_id]').selectOption(''); await view.locator('select[name=view_id]').selectOption({ label: name });
   assert.equal(await report.locator('select[name=type]').inputValue(), 'holdings'); assert.equal(await report.locator('input[name=as_of]').inputValue(), '2026-01-04');
   const observation = page.locator('#tgit-observations article').filter({ hasText: `${priceSource}: Manual fixture` }); await observation.getByRole('button', { name: 'Correct observation' }).click();
   await form.locator('input[name=value]').fill('120'); await form.locator('input[name=reason]').fill('Corrected fixture'); await form.getByRole('button', { name: 'Save observation', exact: true }).click();
   await observation.getByText(/Superseded by/).waitFor();
   await page.locator('#tgit-report-load-form input').fill(savedId); await page.getByRole('button', { name: 'Load saved report', exact: true }).click();
   await page.locator('#tgit-report-status').getByText('Saved', { exact: true }).waitFor();
   assert.ok((await page.locator('#tgit-report-summary').textContent()).includes(`Saved report #${savedId}`));
   await form.locator('select[name=kind]').selectOption('fx'); await form.locator('input[name=currency]').fill('EUR'); await form.locator('input[name=value]').fill('1.10');
   await form.locator('input[name=effective_date]').fill('2026-01-03'); await form.locator('input[name=expires_on]').fill('2026-01-04'); await form.locator('input[name=source]').fill(priceSource + ' FX'); await form.locator('input[name=reason]').fill('Native to base');
   await form.getByRole('button', { name: 'Save observation', exact: true }).click();
   await page.locator('#tgit-observations').getByText(`${priceSource} FX: Native to base`, { exact: true }).waitFor().catch(async (error) => { throw new Error(error.message + ' Status: ' + await page.locator('#tgit-report-status').textContent()); });
   await report.locator('select[name=type]').selectOption('income'); await report.getByRole('button', { name: 'Generate report' }).click(); await page.locator('#tgit-report-results').getByText('Income unavailable: dividend and interest posting are not implemented.').waitFor();
   assert.deepEqual(errors, []); assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
   await page.screenshot({ path: `tmp/reports-${width}.png`, fullPage: true }); await context.close();
   console.log(`PASS ${width}px manual price/FX, correction, immutable report load, saved view, unavailable income and no horizontal scroll.`);
  }
 } finally { await browser.close(); }
})().catch((error) => { console.error(error.message); process.exitCode = 1; });
