/* Isolated navigation fixture: no database or customer data. */
'use strict';
const fs = require('node:fs');
const assert = require('node:assert/strict');
const { chromium } = require(process.env.TGIT_PLAYWRIGHT_MODULE || './browser/node_modules/playwright-core');
const names = ['overview', 'transactions', 'journal', 'strategies', 'calculators', 'research', 'reports', 'settings'];
const html = `<div class="tgit"><div id="tgit-content"><div id="tgit-tabs" role="tablist">${names.map((name) => `<button id="tab-${name}" role="tab" data-tab="${name}" aria-controls="panel-${name}">${name}</button>`).join('')}</div>${['accounts', 'holdings', 'transactions'].map((name) => `<section><div id="tgit-${name}"></div></section>`).join('')}<section id="tgit-entry"><input aria-label="Unsaved amount"></section>${['journal-section', 'strategy-section', 'calculators-section', 'research-section', 'reports-section', 'management', 'opening-section', 'members-section', 'media-settings-section'].map((name) => `<section id="tgit-${name}">${name}</section>`).join('')}</div></div>`;
(async () => {
 const browser = await chromium.launch({ executablePath: process.env.TGIT_CHROME_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
 try {
  for (const width of [360, 768, 1440]) {
   const page = await browser.newPage({ viewport: { width, height: 900 } }); const errors = [];
   page.on('pageerror', (error) => errors.push(error.message));
   await page.route('https://fixture.test/**', (route) => route.fulfill({ contentType: 'text/html', body: html }));
   await page.goto('https://fixture.test/?page=tgit&tgit_section=transactions');
   await page.addStyleTag({ path: 'assets/admin.css' }); await page.addScriptTag({ path: 'assets/tabs.js' });
   assert.equal(await page.getByRole('button', { name: 'Sections', exact: true }).isVisible(), width < 782);
   assert.equal(await page.locator('#tgit-page-title').textContent(), 'transactions');
   await page.getByLabel('Unsaved amount').fill('123.456789');
   async function select(name) {
    if (width < 782) await page.getByRole('button', { name: 'Sections', exact: true }).click();
    await page.getByRole('tab', { name, exact: true }).click();
   }
   await select('journal'); await select('transactions');
   assert.equal(await page.getByLabel('Unsaved amount').inputValue(), '123.456789');
   assert.equal(new URL(page.url()).searchParams.get('page'), 'tgit');
   await page.goBack(); assert.equal(await page.locator('#tgit-page-title').textContent(), 'journal');
   await select('settings');
   await page.evaluate(() => window.dispatchEvent(new CustomEvent('tgit-workspace', { detail: { role: 'viewer' } })));
   assert.equal(await page.locator('#tgit-page-title').textContent(), 'overview');
   assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
   assert.deepEqual(errors, []); await page.close();
  }
  for (const folder of ['assets', 'src/Admin']) {
   for (const name of fs.readdirSync(folder).filter((name) => /\.(js|php)$/.test(name))) {
    const source = fs.readFileSync(`${folder}/${name}`, 'utf8');
    assert(!source.includes('\ufffd'), `Replacement character in ${folder}/${name}`);
   }
  }
  console.log('PASS UX shell: deep links, Back, form preservation, viewer visibility, UTF-8, and 360/768/1440px navigation.');
 } finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
