/* Real calculator UI/REST on a disposable workspace; no provider requests. */
'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.TGIT_PLAYWRIGHT_MODULE||'./browser/node_modules/playwright-core');
const fixture=JSON.parse(fs.readFileSync('tmp/journal-http-fixtures.json','utf8'));
(async()=>{const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});try{
 const context=await browser.newContext({viewport:{width:1440,height:900}}),session=fixture.sessions.owner;
 await context.addCookies([{name:session.cookie_name,value:session.cookie_value,url:'http://127.0.0.1:19308',httpOnly:true}]);
 const page=await context.newPage();page.setDefaultTimeout(60000);const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://127.0.0.1:19308/preview');await page.locator('#tgit-content').waitFor({state:'visible'});await page.locator('#tgit-workspace').selectOption(String(fixture.workspace));await page.getByRole('tab',{name:'Calculators',exact:true}).click();
 const cases=[
 ['stock',{entry_price:'100',exit_price:'115',quantity:'10',stop_loss:'95'}],
 ['crypto',{buy_price:'100',sell_price:'115',investment:'1000',stop_loss:'95'}],
 ['option',{entry_premium:'2',exit_premium:'5',stop_loss:'1'}],
 ['leveraged',{entry_price:'100',exit_price:'115',collateral:'500',leverage:'2',stop_loss:'95'}],
 ['risk',{entry_price:'100',risk_budget:'50',stop_price:'95',take_profit:'115'}],
 ['short-risk',{entry_price:'100',risk_budget:'50',stop_price:'105',take_profit:'85'}]
 ];
 for(const [type,values] of cases){const form=page.locator(`#tgit-${type}-calculator`);await form.evaluate(e=>e.closest('details').open=true);for(const [name,value] of Object.entries(values))await form.locator(`[name="${name}"]`).fill(value);await form.locator('button').click();await page.waitForFunction(id=>document.getElementById(id).textContent.includes('Risk-to-reward'),`tgit-${type}-result`);assert((await page.locator(`#tgit-${type}-result`).textContent()).includes('1:3.00'));assert.equal(await page.locator(`#tgit-${type}-result dt`).filter({hasText:'Estimated loss at stop'}).count(),1);}
 const option=page.locator('#tgit-option-calculator');assert.equal(await option.locator('[name=stop_loss]').getAttribute('required'),null);await option.locator('[name=option_type]').selectOption('put');await option.locator('button').click();await page.waitForFunction(()=>document.getElementById('tgit-option-result').textContent.includes('1:3.00'));await option.locator('[name=mode]').selectOption('expiry');assert.equal(await option.locator('[name=stop_loss]').isDisabled(),true);assert.equal(await option.locator('[name=stop_loss]').isVisible(),false);
 const stock=page.locator('#tgit-stock-calculator');await stock.locator('[name=stop_loss]').fill('');await stock.locator('button').click();await page.waitForFunction(()=>document.getElementById('tgit-stock-result').textContent.includes('Net profit'));assert(!(await page.locator('#tgit-stock-result').textContent()).includes('Risk-to-reward'));
 for(const width of [360,768,1440]){await page.setViewportSize({width,height:900});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));}assert.deepEqual(errors,[]);console.log('PASS six calculator risk/reward forms, bought call/put premiums, optional-field fallback, expiry isolation and responsive summaries.');
 }finally{await browser.close();}})().catch(e=>{console.error(e);process.exit(1)});
