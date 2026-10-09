/* Synthetic catalogue suggestions against real workspace forms. */
'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.TGIT_PLAYWRIGHT_MODULE||'./browser/node_modules/playwright-core');
const fixture=JSON.parse(fs.readFileSync('tmp/ai-settings-browser-fixtures.json','utf8'));
const session=JSON.parse(fs.readFileSync('tmp/journal-http-fixtures.json','utf8')).sessions.owner;
(async()=>{ const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
 try { const context=await browser.newContext({viewport:{width:1440,height:900}}); await context.addCookies([{name:session.cookie_name,value:session.cookie_value,url:'http://127.0.0.1:19308',httpOnly:true}]);
 const assetUrl=new URL('http://127.0.0.1:19308'); assetUrl.searchParams.set('tgit_ai_fixture','1'); assetUrl.searchParams.set('rest_route',`/tgit/v1/workspaces/${fixture.workspace}/assets`);
 const assetsResponse=await context.request.get(assetUrl.toString(),{headers:{'X-WP-Nonce':session.nonce}}); assert.equal(assetsResponse.status(),200); const existing=(await assetsResponse.json()).data.items;
 for(const stock of ['AAPL','NVDA']) if(!existing.some(row=>row.symbol===stock)) {const created=await context.request.post(assetUrl.toString(),{headers:{'X-WP-Nonce':session.nonce,'Idempotency-Key':require('node:crypto').randomUUID()},data:{symbol:stock,exchange:'SYNTHETIC',asset_class:'stock',quote_currency:'USD'}}); assert.equal(created.status(),200);}
 const page=await context.newPage(); page.setDefaultTimeout(60000); page.on('dialog',dialog=>dialog.accept()); const errors=[]; page.on('pageerror',e=>errors.push(e.message)); let calls=0, savedSymbol='MSFT';
 await page.route('**/*',async route=>{ const u=new URL(route.request().url()); if((u.searchParams.get('rest_route')||'').endsWith('/ticker-catalog')) {++calls; await new Promise(resolve=>setTimeout(resolve,200)); return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({data:{items:[{symbol:'AAPL',title:'Apple Inc.'},{symbol:'NVDA',title:'NVIDIA CORP'},{symbol:savedSymbol,title:'Synthetic Example Holdings'}]}})}); } return route.continue(); });
 await page.goto('http://127.0.0.1:19308/preview?tgit_ai_fixture=1'); await page.locator('#tgit-content').waitFor({state:'visible'}); await page.locator('#tgit-workspace').selectOption(String(fixture.workspace));
 savedSymbol=(await page.locator('#tgit-transaction-form [name=asset_id] option').first().textContent()).split(' · ')[0];
 await page.getByRole('tab',{name:'Settings',exact:true}).click();
 const symbol=page.locator('#tgit-asset-form [name=symbol]'); const search=page.locator('#tgit-asset-form .tgit-asset-search input');
 const catalogueReady=page.waitForResponse(response=>(new URL(response.url()).searchParams.get('rest_route')||'').endsWith('/ticker-catalog')); await search.fill('NVDA'); await symbol.fill('CUSTOM'); assert.equal(await search.inputValue(),''); assert.equal(await search.evaluate(e=>e.checkValidity()),true); await catalogueReady; assert.equal(await symbol.inputValue(),'CUSTOM');
 await search.fill('Apple'); await page.waitForFunction(()=>document.querySelector('#tgit-asset-form datalist option')?.value==='AAPL');
 await search.fill('AAPL'); assert.equal(await symbol.inputValue(),'AAPL'); assert.equal(calls,1);
 await search.fill('no-match'); assert.equal(await search.evaluate(e=>e.checkValidity()),false); await search.press('Escape'); await symbol.fill('MANUAL'); assert.equal(await search.evaluate(e=>e.checkValidity()),true);
 await page.locator('#tgit-asset-form [name=asset_class]').selectOption('crypto'); await search.fill('Bitcoin'); assert.equal(await page.locator('#tgit-asset-form datalist option').count(),0); await search.press('Escape');
 await page.getByRole('tab',{name:'Transactions',exact:true}).click(); await page.getByRole('button',{name:'New transaction',exact:true}).click(); await page.locator('#tgit-transaction-form [name=action]').selectOption('buy');
 const field=page.locator('#tgit-transaction-form [name=asset_id]'); const wrapper=field.locator('xpath=ancestor::div[contains(@class,"tgit-asset-field")][1]'); const savedSearch=wrapper.locator('input');
 const original=await field.inputValue(); const option=await field.locator('option').first().textContent(); const ticker=option.split(' · ')[0];
 await savedSearch.fill('NVIDIA'); const suggestion=await wrapper.locator('datalist option').first().getAttribute('value'); await savedSearch.fill(suggestion); assert.notEqual(await field.inputValue(),original); assert.equal(await savedSearch.evaluate(e=>e.checkValidity()),true); const selected=await field.inputValue();
 await savedSearch.fill('not-saved'); assert.equal(await savedSearch.evaluate(e=>e.checkValidity()),false); assert.equal(await field.inputValue(),selected); await savedSearch.press('Escape');
 await savedSearch.fill('not-saved');
 await page.locator('#tgit-workspace').selectOption(String(fixture.workspace));
 await page.waitForFunction(()=>Array.from(document.querySelectorAll('.tgit-asset-search input')).every(input=>input.value===''));
 assert.equal(await savedSearch.evaluate(e=>e.checkValidity()),true);
 await page.getByRole('button',{name:'New transaction',exact:true}).waitFor({state:'visible'}); await page.getByRole('button',{name:'New transaction',exact:true}).click();
 await page.locator('#tgit-transaction-form [name=action]').selectOption('deposit'); assert.equal(await wrapper.isVisible(),false);
 for(const width of [360,768,1440]) { await page.setViewportSize({width,height:900}); assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2)); }
 assert.deepEqual(errors,[]); await context.close();
 // A catalogue outage must not disable saved dropdowns or manual symbols.
 const c=await browser.newContext(); await c.addCookies([{name:session.cookie_name,value:session.cookie_value,url:'http://127.0.0.1:19308',httpOnly:true}]); const p=await c.newPage(); p.setDefaultTimeout(60000);
 await p.route('**/*',r=>(new URL(r.request().url()).searchParams.get('rest_route')||'').endsWith('/ticker-catalog')?r.fulfill({status:503,contentType:'application/json',body:'{"message":"Synthetic outage"}'}):r.continue());
 await p.goto('http://127.0.0.1:19308/preview?tgit_ai_fixture=1'); await p.locator('#tgit-content').waitFor({state:'visible'}); await p.getByRole('tab',{name:'Settings',exact:true}).click(); await p.locator('#tgit-asset-form .tgit-asset-search input').focus(); await p.locator('#tgit-asset-form .tgit-asset-search small').getByText(/lookup unavailable/).waitFor(); await p.locator('#tgit-asset-form [name=symbol]').fill('CUSTOM'); assert.equal(await p.locator('#tgit-asset-form [name=symbol]').inputValue(),'CUSTOM'); await c.close();
 console.log('PASS ticker/company suggestions, manual and crypto fallback, saved asset IDs, unmatched validation, hidden states, outage and responsive forms.');
 } finally {await browser.close();} })().catch(e=>{console.error(e);process.exit(1);});
