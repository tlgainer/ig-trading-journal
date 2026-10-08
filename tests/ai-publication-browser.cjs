/* Genuine owner publication of stored synthetic output; no AI call. */
'use strict';
const fs = require('node:fs'), assert = require('node:assert/strict');
const { chromium } = require(process.env.TGIT_PLAYWRIGHT_MODULE || './browser/node_modules/playwright-core');
const fixture = JSON.parse(fs.readFileSync('tmp/ai-publication-browser-fixtures.json', 'utf8'));
const session = JSON.parse(fs.readFileSync('tmp/journal-http-fixtures.json', 'utf8')).sessions.owner;
(async () => {
 const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
 try {
  const context = await browser.newContext({ viewport: { width:1440,height:900 } });
  await context.addCookies([{name:session.cookie_name,value:session.cookie_value,url:'http://127.0.0.1:19308',httpOnly:true}]);
  const page = await context.newPage(), errors=[]; page.setDefaultTimeout(60000); page.on('pageerror',error=>errors.push(error.message));
  await page.goto('http://127.0.0.1:19308/preview?tgit_publication_fixture=1'); await page.locator('#tgit-content').waitFor({state:'visible'}); await page.locator('#tgit-workspace').selectOption(String(fixture.workspace)); await page.getByRole('tab',{name:'Settings',exact:true}).click(); await page.waitForFunction(()=>!document.getElementById('tgit-ai-policy').inert);
  await page.locator('#tgit-ai-activity summary').click(); await page.locator('#tgit-ai-activity-status').getByText(/All saved request pages loaded/).waitFor();
  const policy = page.locator('#tgit-ai-policy'); await policy.locator('[name=monthly_cap]').fill('20');
  const failed = async(route) => { const path=new URL(route.request().url()).searchParams.get('rest_route')||''; if(path.endsWith('/publish')) return route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({message:'Fixture publication outcome unavailable'})}); return route.continue(); };
  await page.route('**/*',failed); await page.getByRole('button',{name:`Save response for request ${fixture.request} as a review`,exact:true}).click(); await page.locator('#tgit-ai-activity-status').getByText(/Reload saved requests before retrying/).waitFor(); await page.waitForFunction(()=>!window.tgitWriteBusy); assert.equal(await policy.locator('[name=monthly_cap]').inputValue(),'20'); assert.equal(await page.getByRole('button',{name:`Save response for request ${fixture.request} as a review`,exact:true}).isDisabled(),true);
  await page.unroute('**/*',failed); await page.getByRole('button',{name:'Reload saved AI requests',exact:true}).click(); await page.locator('#tgit-ai-activity-status').getByText(/All saved request pages loaded/).waitFor();
  await page.getByRole('button',{name:`Save response for request ${fixture.request} as a review`,exact:true}).click(); await page.locator('#tgit-ai-activity-status').getByText(/AI review #\d+ saved/).waitFor(); await page.waitForFunction(()=>!window.tgitWriteBusy); assert.equal(await policy.locator('[name=monthly_cap]').inputValue(),'20'); assert((await page.locator('#tgit-ai-activity-history').textContent()).includes('Saved review #'));
  const retry = await page.evaluate(async(requestId)=>{ const response=await fetch(tgitRestUrl(window.tgitConfig.root,`workspaces/${document.getElementById('tgit-workspace').value}/ai-requests/${requestId}/publish`),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':window.tgitConfig.nonce},body:'{}'}); return {status:response.status,body:await response.json()}; },fixture.request);
  assert.equal(retry.status,200); assert.equal(retry.body.data.request_id,fixture.request); assert((await page.locator('#tgit-ai-activity-history').textContent()).includes(`Saved review #${retry.body.data.id}`));
  for(const width of [360,768,1440]) { await page.setViewportSize({width,height:900}); assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`Publication overflow at ${width}`); }
  assert.deepEqual(errors,[]); await context.close(); console.log('PASS Stored-response publication, uncertain outcome/reload, immutable retry, unsaved preservation and 360/768/1440px layouts.');
 } finally { await browser.close(); }
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
