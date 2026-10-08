/* Real disposable Settings controls; no keys or paid transport. */
'use strict';
const fs = require('node:fs'), assert = require('node:assert/strict');
const { chromium } = require(process.env.TGIT_PLAYWRIGHT_MODULE || './browser/node_modules/playwright-core');
const fixture = JSON.parse(fs.readFileSync('tmp/ai-settings-browser-fixtures.json', 'utf8'));
const sessions = JSON.parse(fs.readFileSync('tmp/journal-http-fixtures.json', 'utf8')).sessions;
(async () => {
 const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
 try {
  for (const actor of ['owner','viewer']) {
   const context = await browser.newContext({ viewport: { width:1440,height:900 } }); const session=sessions[actor==='viewer'?'revoked':'owner'];
   await context.addCookies([{name:session.cookie_name,value:session.cookie_value,url:'http://127.0.0.1:19308',httpOnly:true}]);
   const page=await context.newPage(), errors=[]; page.setDefaultTimeout(60000); page.on('pageerror',error=>errors.push(error.message));
   await page.goto('http://127.0.0.1:19308/preview?tgit_ai_fixture=1'); await page.locator('#tgit-content').waitFor({state:'visible'}); await page.locator('#tgit-workspace').selectOption(String(fixture.workspace));
   if(actor==='viewer') { assert.equal(await page.getByRole('tab',{name:'Settings',exact:true}).isVisible(),false); assert.equal(await page.locator('#tgit-ai-section').isVisible(),false); await context.close(); continue; }
   await page.getByRole('tab',{name:'Settings',exact:true}).click(); await page.waitForFunction(()=>!document.getElementById('tgit-ai-policy').inert);
   const policy=page.locator('#tgit-ai-policy'), consent=page.locator('#tgit-ai-consent');
   assert((await page.locator('#tgit-ai-readiness').textContent()).includes('Server key: missing or invalid'));
   assert((await page.locator('#tgit-ai-readiness').textContent()).includes('Summary generation controls: available in Research'));
   await policy.locator('[name=monthly_cap]').fill('15');
   await page.locator('#tgit-ai-activity summary').click(); await page.locator('#tgit-ai-activity-status').getByText(/All saved request pages loaded/).waitFor();
   assert((await page.locator('#tgit-ai-activity-history').textContent()).includes('Cancelled')); assert((await page.locator('#tgit-ai-activity-history').textContent()).includes('Legacy — unbound'));
   await page.getByRole('button',{name:'Reload saved AI requests',exact:true}).click(); await page.locator('#tgit-ai-activity-status').getByText(/All saved request pages loaded/).waitFor(); assert.equal(await policy.locator('[name=monthly_cap]').inputValue(),'15');
   const historyFailure=async(route)=>{ const target=new URL(route.request().url()).searchParams.get('rest_route')||''; if(target.endsWith('/ai-requests')) return route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({message:'Saved request fixture unavailable'})}); return route.continue(); };
   await page.route('**/*',historyFailure); await page.getByRole('button',{name:'Reload saved AI requests',exact:true}).click(); await page.locator('#tgit-ai-activity-status').getByText('Saved request fixture unavailable',{exact:true}).waitFor(); assert.equal(await page.locator('#tgit-ai-activity-history table').count(),0); assert.equal(await policy.locator('[name=monthly_cap]').inputValue(),'15');
   await page.unroute('**/*',historyFailure); await page.getByRole('button',{name:'Reload saved AI requests',exact:true}).click(); await page.locator('#tgit-ai-activity-status').getByText(/All saved request pages loaded/).waitFor();
   await page.locator('#tgit-api-setup summary').filter({hasText:'OpenAI credential preparation'}).click();
   assert((await page.locator('#tgit-openai-connection-status').textContent()).includes('missing or invalid server configuration'));
   assert((await page.locator('#tgit-openai-connection-status').textContent()).includes('generation requires verified server evidence'));
   assert.equal(await page.locator('#tgit-api-setup input').count(),0);
   await policy.locator('[name=monthly_cap]').fill('10.50'); await policy.locator('[name=model]').fill('fixture-text-model');
   await policy.getByRole('button',{name:'Save model and budget',exact:true}).click(); await page.locator('#tgit-ai-status').getByText('Settings saved. No AI request was sent.',{exact:true}).waitFor(); await page.waitForFunction(()=>!window.tgitWriteBusy);
   assert((await page.locator('#tgit-ai-summary').textContent()).includes('10.50 USD'));
   await consent.locator('[name=enabled]').selectOption('true'); await policy.locator('[name=monthly_cap]').fill('15');
   const shortcuts = page.getByRole('navigation',{name:'Settings section shortcuts',exact:true}); await shortcuts.getByRole('link',{name:'API setup',exact:true}).click(); await shortcuts.getByRole('link',{name:'AI settings',exact:true}).click(); assert.equal(await policy.locator('[name=monthly_cap]').inputValue(),'15'); assert.equal(await consent.locator('[name=enabled]').inputValue(),'true');
   await consent.getByRole('button',{name:'Save workspace consent',exact:true}).click(); await page.waitForFunction(()=>!window.tgitWriteBusy); assert.equal(await policy.locator('[name=monthly_cap]').inputValue(),'15');
   page.once('dialog',dialog=>dialog.dismiss()); await page.getByRole('tab',{name:'Overview',exact:true}).click(); assert.equal(await page.locator('#tgit-page-title').textContent(),'Settings');
   page.once('dialog',dialog=>dialog.accept()); await page.getByRole('button',{name:'Reload AI settings',exact:true}).click(); await page.locator('#tgit-ai-status').getByText('AI settings reloaded.',{exact:true}).waitFor(); assert.equal(await policy.locator('[name=monthly_cap]').inputValue(),'10.50'); assert.equal(await consent.locator('[name=enabled]').inputValue(),'true');
   await policy.locator('[name=monthly_cap]').fill('0'); await policy.getByRole('button',{name:'Save model and budget',exact:true}).click(); await page.waitForFunction(()=>!window.tgitWriteBusy); assert((await page.locator('#tgit-ai-summary').textContent()).includes('paused'));
   for(const width of [360,768,1440]) { await page.setViewportSize({width,height:900}); assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`AI settings overflow at ${width}`); }
   const other=await page.locator('#tgit-workspace').evaluate((select,current)=>[...select.options].find(option=>option.value && option.value!==String(current)).value,fixture.workspace);
   await page.locator('#tgit-workspace').selectOption(other); await page.waitForFunction(()=>document.getElementById('tgit-ai-summary').textContent.includes('controlled in its original workspace'));
   assert.equal(await policy.evaluate(form=>form.inert),true); assert.equal(await policy.getByRole('button',{name:'Save model and budget',exact:true}).isDisabled(),true);
   await page.locator('#tgit-workspace').selectOption(String(fixture.workspace)); await page.waitForFunction(()=>!document.getElementById('tgit-ai-policy').inert);
   const failure=async(route)=>{ const target=new URL(route.request().url()).searchParams.get('rest_route')||''; if(target.endsWith('/ai-settings') && route.request().method()==='GET') return route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({message:'AI fixture unavailable'})}); return route.continue(); };
   await page.route('**/*',failure); await page.getByRole('button',{name:'Reload AI settings',exact:true}).click(); await page.locator('#tgit-ai-status').getByText('AI fixture unavailable',{exact:true}).waitFor(); assert.equal(await policy.evaluate(form=>form.inert),true); assert.equal(await consent.evaluate(form=>form.inert),true);
   await page.unroute('**/*',failure); await page.getByRole('button',{name:'Reload AI settings',exact:true}).click(); await page.locator('#tgit-ai-status').getByText('AI settings reloaded.',{exact:true}).waitFor(); assert.equal(await policy.locator('[name=monthly_cap]').inputValue(),'0.00');
   assert.deepEqual(errors,[]); await context.close();
  }
  console.log('PASS AI Settings readiness, saved request activity and recovery, owner/viewer access, real saves, separate consent, unsaved preservation, discard/reload, zero pause, shared read-only controls and responsive cards.');
 } finally { await browser.close(); }
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
