/* Full synthetic owner generation workflow; external HTTP intercepted by loopback fixture. */
'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict');
const {chromium}=require(process.env.TGIT_PLAYWRIGHT_MODULE||'./browser/node_modules/playwright-core');
const fixture=JSON.parse(fs.readFileSync('tmp/ai-generation-browser-fixtures.json','utf8'));
const session=JSON.parse(fs.readFileSync('tmp/journal-http-fixtures.json','utf8')).sessions.owner;
const calls=()=>fs.readFileSync('tmp/ai-generation-http-calls.jsonl','utf8').trim().split('\n').filter(Boolean).length;
(async()=>{
 const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
 try {
  const context=await browser.newContext({viewport:{width:1440,height:900}});
  await context.addCookies([{name:session.cookie_name,value:session.cookie_value,url:'http://127.0.0.1:19308',httpOnly:true}]);
  await context.addInitScript(({fixture})=>{sessionStorage.setItem(`tgit-ai-evidence:${fixture.owner}:${fixture.workspace}:${fixture.asset}`,JSON.stringify({pending:null,approved:fixture.approval}));},{fixture});
  const page=await context.newPage(),errors=[]; page.setDefaultTimeout(60000); page.on('pageerror',error=>errors.push(error.message));
  const open=async()=>{await page.goto(`http://127.0.0.1:19308/preview?tgit_generation_fixture=1&tgit_workspace=${fixture.workspace}&tgit_section=research&tgit_fundamental_asset=${fixture.asset}`); await page.getByRole('heading',{name:`Approved evidence #${fixture.approval}`,exact:true}).waitFor();};
  await open(); const card=page.locator('#tgit-ai-evidence');
  await card.getByRole('button',{name:'Check summary setup',exact:true}).click(); await card.getByText(/This check does not estimate the request cost/).waitFor(); assert(await card.getByRole('button',{name:'Generate summary',exact:true}).isDisabled()); assert.equal(calls(),0);
  await page.getByRole('tab',{name:'Settings',exact:true}).click(); await page.waitForFunction(()=>!document.getElementById('tgit-ai-policy').inert);
  const policy=page.locator('#tgit-ai-policy'),consent=page.locator('#tgit-ai-consent'); assert.equal(await policy.locator('[name=enabled]').inputValue(),'false'); assert.equal(await consent.locator('[name=enabled]').inputValue(),'false');
  await policy.locator('[name=enabled]').selectOption('true'); await policy.getByRole('button',{name:'Save model and budget',exact:true}).click(); await page.locator('#tgit-ai-status').getByText('Settings saved. No AI request was sent.',{exact:true}).waitFor(); await page.waitForFunction(()=>!window.tgitWriteBusy); assert.equal(calls(),0);
  await consent.locator('[name=enabled]').selectOption('true'); await consent.getByRole('button',{name:'Save workspace consent',exact:true}).click(); await page.waitForFunction(()=>!window.tgitWriteBusy); await page.getByRole('tab',{name:'Research',exact:true}).click();
  await card.getByRole('button',{name:'Check summary setup',exact:true}).click(); await page.waitForFunction(()=>{const b=[...document.querySelectorAll('#tgit-ai-evidence button')].find(b=>b.textContent==='Generate summary');return b&&!b.disabled;});
  await page.evaluate(()=>{const original=Storage.prototype.setItem;Storage.prototype.setItem=function(key,value){if(key.startsWith('tgit-ai-generation:'))throw new Error('fixture storage unavailable');return original.call(this,key,value);};});
  await card.getByRole('button',{name:'Generate summary',exact:true}).click(); await card.getByText(/Cannot retain the operation identity/).waitFor(); assert.equal(calls(),0);
  await open(); await card.getByRole('button',{name:'Check summary setup',exact:true}).click(); await page.waitForFunction(()=>{const b=[...document.querySelectorAll('#tgit-ai-evidence button')].find(b=>b.textContent==='Generate summary');return b&&!b.disabled;});
  const keys=[]; let phase=0;
  const lost=async(route)=>{const path=new URL(route.request().url()).searchParams.get('rest_route')||'';if(!path.endsWith('/generate'))return route.continue();keys.push(route.request().headers()['idempotency-key']);if(phase++===0)return route.fulfill({status:503,contentType:'application/json',body:JSON.stringify({message:'Fixture outcome unavailable'})});const response=await route.fetch();assert.equal(response.status(),200);await response.dispose();return route.abort('failed');};
  await page.route('**/*',lost); await card.getByRole('button',{name:'Generate summary',exact:true}).click(); await card.getByText(/Check saved summary result using the retained identity/).waitFor(); await page.waitForFunction(()=>!window.tgitWriteBusy); assert.equal(calls(),0);
  await card.getByRole('button',{name:'Check saved summary result',exact:true}).click(); await card.getByText(/No reservation is saved/).waitFor(); await card.getByRole('button',{name:'Retry same summary request',exact:true}).click(); await card.getByText(/Check saved summary result using the retained identity/).waitFor(); await page.waitForFunction(()=>!window.tgitWriteBusy); assert.equal(keys.length,2); assert.equal(keys[0],keys[1]); assert.equal(calls(),2);
  await page.unroute('**/*',lost); await open(); await card.getByRole('button',{name:'Check saved summary result',exact:true}).click(); await card.getByText(/Usage is settled/).waitFor(); assert.equal(calls(),2); assert(await card.getByRole('button',{name:'Retry same summary request',exact:true}).isDisabled());
  const retry=await page.evaluate(async({fixture})=>{const command=JSON.parse(sessionStorage.getItem(`tgit-ai-generation:${fixture.owner}:${fixture.workspace}:${fixture.approval}`));const response=await fetch(tgitRestUrl(window.tgitConfig.root,`workspaces/${fixture.workspace}/ai-evidence/${fixture.approval}/generate`),{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':window.tgitConfig.nonce,'Idempotency-Key':command.key},body:JSON.stringify({expected_config_id:command.config_id})});return{status:response.status,body:await response.json()};},{fixture}); assert.equal(retry.status,200);assert.equal(retry.body.data.state,'settled');assert.equal(calls(),2);
  for(const width of [360,768,1440]){await page.setViewportSize({width,height:900});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`Generation overflow at ${width}`);}
  assert.deepEqual(errors,[]);await context.close();console.log('PASS Explicit enablement and separate consent, storage-denial blocking, same-identity retry, lost delivery result/reload, read-only recovery, no duplicate send and 360/768/1440px layouts.');
 }finally{await browser.close();}
})().catch(error=>{console.error(error.stack);process.exitCode=1;});
