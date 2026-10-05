/* Disposable bought option scenarios and readable calculator values/labels. */
'use strict';
const fs = require('node:fs'), assert = require('node:assert/strict');
const { chromium } = require(process.env.TGIT_PLAYWRIGHT_MODULE || './browser/node_modules/playwright-core');
const fixture = JSON.parse(fs.readFileSync('tmp/journal-http-fixtures.json', 'utf8'));
(async () => {
 const browser = await chromium.launch({ executablePath: process.env.TGIT_CHROME_EXECUTABLE || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
 try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } }), session = fixture.sessions.owner;
  await context.addCookies([{ name: session.cookie_name, value: session.cookie_value, url: 'http://127.0.0.1:19308', httpOnly: true }]);
  const page = await context.newPage(); const errors = []; page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('http://127.0.0.1:19308/preview'); await page.locator('#tgit-workspace').selectOption(String(fixture.workspace)); await page.locator('#tgit-content').waitFor({ state: 'visible' }); await page.getByRole('tab', { name: 'Calculators', exact: true }).click();
  const accordions = page.locator('#tgit-calculators-section details');
  assert.deepEqual(await accordions.locator('summary').allTextContents(), ['Stock profit', 'Crypto profit', 'Bought call / put profit', 'Linear leveraged crypto', 'Long-position risk', 'Short-position risk']);
  assert.deepEqual(await accordions.evaluateAll((items) => items.map((item) => item.open)), [true, false, false, false, false, false]);
  const cryptoHeading = accordions.nth(1).locator('summary'); await cryptoHeading.focus(); await page.keyboard.press('Enter');
  assert.equal(await accordions.nth(1).evaluate((item) => item.open), true);
  await page.locator('#tgit-crypto-calculator [name=buy_price]').fill('75'); await cryptoHeading.focus(); await page.keyboard.press('Space');
  assert.equal(await accordions.nth(1).evaluate((item) => item.open), false); await page.keyboard.press('Enter');
  assert.equal(await page.locator('#tgit-crypto-calculator [name=buy_price]').inputValue(), '75');
  await accordions.evaluateAll((items) => items.forEach((item) => { item.open = true; }));
  const form = page.locator('#tgit-option-calculator'), result = page.locator('#tgit-option-result'), calculate = form.getByRole('button', { name: 'Calculate bought-option profit' });
  await form.getByLabel('Entry option price (premium per share)', { exact: true }).fill('2'); await form.locator('[name=exit_premium]').fill('3');
  await calculate.click(); await result.getByText('Net profit: 100.00 USD', { exact: true }).waitFor(); await result.getByText('Starting capital (premium + entry fee): 200.00 USD', { exact: true }).waitFor();
  await form.locator('[name=multiplier]').fill('10'); await calculate.click(); await result.getByText('Net profit: 10.00 USD', { exact: true }).waitFor();
  await form.locator('[name=multiplier]').fill('100'); await form.locator('[name=mode]').selectOption('expiry'); assert.equal(await form.locator('[name=exit_premium]').isDisabled(), true);
  await form.locator('[name=strike]').fill('100'); await form.locator('[name=underlying_price]').fill('105'); await calculate.click(); await result.getByText('Net profit: 300.00 USD', { exact: true }).waitFor();
  await form.locator('[name=option_type]').selectOption('put'); await form.locator('[name=underlying_price]').fill('95'); await calculate.click(); await result.getByText('Net profit: 300.00 USD', { exact: true }).waitFor();
  await form.locator('[name=underlying_price]').fill('100'); await form.locator('[name=entry_fee]').fill('1'); await form.locator('[name=exit_fee]').fill('2'); await calculate.click(); await result.getByText('Net profit: -203.00 USD', { exact: true }).waitFor();
  await form.locator('[name=contracts]').fill('0'); await calculate.click(); await result.getByText('Value must be positive.').waitFor(); assert.equal(await result.getAttribute('role'), 'alert'); await form.locator('[name=contracts]').fill('1');
  const risk = page.locator('#tgit-risk-calculator'); await risk.getByLabel('Entry price per unit', { exact: true }).fill('300'); await risk.getByLabel('Stop-loss price below entry', { exact: true }).fill('295'); await risk.getByLabel('Maximum loss (risk budget)', { exact: true }).fill('50'); await risk.getByRole('button', { name: 'Calculate size' }).click();
  const loss = page.locator('#tgit-risk-result'); await loss.getByText('Stop loss: 295.00 USD', { exact: true }).waitFor(); await loss.getByText('Position size: 10 units', { exact: true }).waitFor(); await loss.getByText('Starting capital required: 3000.00 USD', { exact: true }).waitFor(); assert(!/\.\d{5}/.test(await loss.textContent()));
  await page.screenshot({ path: 'tmp/options-desktop.png', fullPage: true }); await page.setViewportSize({ width: 360, height: 800 }); assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)); await calculate.click(); await result.getByText('Net profit: -203.00 USD', { exact: true }).waitFor(); await page.screenshot({ path: 'tmp/options-mobile.png', fullPage: true }); assert.deepEqual(errors, []);
  console.log('PASS Bought option browser: premium sale, call/put expiry, multiplier, fees, invalid count, readable decimals/labels and mobile layout.');
 } finally { await browser.close(); }
})().catch((error) => { console.error(error.message); process.exitCode = 1; });
