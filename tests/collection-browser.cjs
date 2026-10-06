/* Complete deterministic collections; preferences must not cross workspaces. */
'use strict';
const assert = require('node:assert/strict');
const { chromium } = require(process.env.TGIT_PLAYWRIGHT_MODULE || './browser/node_modules/playwright-core');
(async () => {
 const browser = await chromium.launch({ executablePath: process.env.TGIT_CHROME_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
 try {
  const page = await browser.newPage({ viewport: { width: 1440, height: 900 } }); const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.route('https://fixture.test/**', (route) => route.fulfill({ contentType: 'text/html', body: '<div class="tgit"><div id="rows"></div></div>' }));
  await page.goto('https://fixture.test/'); await page.addStyleTag({ path: 'assets/admin.css' }); await page.addScriptTag({ path: 'assets/collection.js' });
  await page.evaluate(() => {
   window.fixtureRows = Array.from({ length: 101 }, (_, index) => ({ id: String(index + 1), name: `Asset ${String(index + 1).padStart(3, '0')}`, note: index === 100 ? 'Café — 📈 unique last record' : 'Fixture' }));
   window.renderFixture = (workspace = '1', rows = window.fixtureRows) => tgitCollection(document.getElementById('rows'), {
    actor: '7', workspace, key: 'fixture', title: 'Assets', search: (row) => `${row.name} ${row.note}`, columns: [
     { key: 'name', label: 'Name', identity: true, required: true, sort: (a, b) => a.name.localeCompare(b.name), render: (row) => row.name },
     { key: 'note', label: 'Note', render: (row) => row.note }
    ]
   }, rows);
   window.renderFixture();
  });
  assert.equal(await page.locator('tbody tr').count(), 25);
  await page.getByRole('searchbox', { name: 'Search Assets' }).fill('unique last');
  assert.equal(await page.locator('tbody tr').count(), 1); assert.match(await page.locator('tbody').textContent(), /Café — 📈/);
  await page.getByRole('button', { name: 'Clear search' }).click();
  await page.getByRole('button', { name: 'Sort by Name' }).click(); await page.getByRole('button', { name: 'Sort by Name' }).click();
  assert.equal(await page.locator('tbody th').first().textContent(), 'Asset 101');
  assert.equal(await page.locator('thead th').first().getAttribute('aria-sort'), 'descending');
  await page.getByLabel('Rows per page').selectOption('50'); assert.equal(await page.locator('tbody tr').count(), 50);
  await page.getByRole('button', { name: 'Next page' }).click(); assert.equal(await page.locator('tbody th').first().textContent(), 'Asset 051');
  await page.getByText('Columns and density', { exact: true }).click(); await page.getByLabel('Note', { exact: true }).uncheck();
  const regularHeight = await page.locator('tbody tr').first().evaluate((row) => row.getBoundingClientRect().height);
  await page.getByLabel('Compact desktop rows').check();
  assert((await page.locator('tbody tr').first().evaluate((row) => row.getBoundingClientRect().height)) < regularHeight);
  await page.evaluate(() => window.renderFixture()); assert.equal(await page.locator('thead th').count(), 1);
  await page.evaluate(() => window.renderFixture('2', [{ id: '1', name: 'Other workspace', note: 'Safe' }]));
  assert.equal(await page.locator('thead th').count(), 2); assert.equal(await page.getByLabel('Rows per page').inputValue(), '25');
  assert(!(await page.locator('tbody').textContent()).includes('Café'));
  await page.evaluate(() => { localStorage.setItem('tgit-collection:7:3:fixture', 'null'); window.renderFixture('3'); });
  assert.equal(await page.getByLabel('Rows per page').inputValue(), '25');
  assert(!(await page.evaluate(() => JSON.stringify(localStorage))).includes('Café'));
  assert.deepEqual(await page.evaluate(() => [tgitDisplayDecimal('0.000000000000'), tgitDisplayDecimal('123456789012345678.123456789012'), tgitDisplayDecimal('-1.200000'), tgitDisplayDecimal('0.000000000001')]), ['0.00', '123456789012345678.123456789012', '-1.20', '0.000000000001']);
  await page.evaluate(() => window.renderFixture('2', [])); await page.getByText('No records yet.', { exact: true }).waitFor();
  await page.evaluate(() => tgitCollection(document.getElementById('rows'), {
   actor: '7', workspace: '4', key: 'trade-filter', title: 'Trade fixtures', search: (row) => row.name,
   filters: [{ key: 'state', label: 'Trade view', default: 'current', values: [['current', 'Current and potential'], ['', 'All'], ['closed', 'Closed']], matches: (row, value) => !value || (value === 'current' ? row.state !== 'closed' : row.state === value) }],
   columns: [{ key: 'name', label: 'Name', identity: true, required: true, render: (row) => row.name }]
  }, window.fixtureRows.map((row, index) => ({ ...row, state: index % 3 === 0 ? 'closed' : 'planned' }))));
  await page.getByRole('searchbox', { name: 'Search Trade fixtures' }).fill('Asset 001'); assert.equal(await page.locator('tbody tr').count(), 0);
  await page.getByLabel('Trade view', { exact: true }).selectOption(''); assert.equal(await page.locator('tbody tr').count(), 1);
  await page.getByRole('button', { name: 'Clear filters', exact: true }).click(); assert.equal(await page.locator('tbody tr').count(), 25);
  await page.getByLabel('Trade view', { exact: true }).selectOption('closed'); await page.getByText('34 records. View: Closed.', { exact: true }).waitFor();
  for (const width of [360, 768, 1440]) { await page.setViewportSize({ width, height: 900 }); assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)); }
  const searchBox = await page.getByRole('searchbox', { name: 'Search Trade fixtures' }).boundingBox();
  const clearBox = await page.getByRole('button', { name: 'Clear search', exact: true }).boundingBox();
  const filterBox = await page.getByLabel('Trade view', { exact: true }).boundingBox();
  const resetBox = await page.getByRole('button', { name: 'Clear filters', exact: true }).boundingBox();
  assert(Math.abs(searchBox.y + searchBox.height - clearBox.y - clearBox.height) < 1.5, 'Clear search aligns with its input');
  assert(Math.abs(filterBox.y + filterBox.height - resetBox.y - resetBox.height) < 1.5, 'Clear filters aligns with its dropdown');
  await page.getByText('Columns and density', { exact: true }).click();
  assert.equal((await page.getByRole('searchbox', { name: 'Search Trade fixtures' }).boundingBox()).y, searchBox.y, 'Opening column options must not shift toolbar inputs');
  await page.evaluate(() => { tgitResetCollection(document.getElementById('rows')); window.renderFixture('4'); });
  assert.equal(await page.locator('tbody tr').count(), 25, 'Resetting a collection must recreate its visible controls and rows.');
  assert.deepEqual(errors, []);
  console.log('PASS Collections: full-dataset search/sort, stable pagination, scoped preferences, Unicode, exact decimal display, empty state and responsive overflow.');
 } finally { await browser.close(); }
})().catch((error) => { console.error(error); process.exitCode = 1; });
