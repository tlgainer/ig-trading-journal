/* Local saved diagnostics only; never dispatches or requests provider data. */
(() => {
 'use strict';
 const panel=document.getElementById('tgit-tickerbot-diagnostics'), reload=document.getElementById('tgit-tickerbot-diagnostics-reload'), status=document.getElementById('tgit-tickerbot-diagnostics-status'), output=document.getElementById('tgit-tickerbot-diagnostics-output'), config=window.tgitConfig;
 let workspace='',generation=0;
 const states={pending:'Pending',waiting:'Waiting for retry',dispatching:'Request admitted; outcome pending',completed:'Completed',failed:'Failed / partial',running:'Running',expired:'Expired',cancelled:'Cancelled'};
 function table(caption,labels,rows,wide=false){
  const wrap=document.createElement('div');wrap.className='tgit-table-scroll';const t=document.createElement('table');if(wide)t.className='tgit-diagnostics-table';t.createCaption().textContent=caption;const head=t.createTHead().insertRow();for(const label of labels){const h=document.createElement('th');h.scope='col';h.textContent=label;head.append(h);}const body=t.createTBody();for(const values of rows){const row=body.insertRow();for(const value of values){const cell=row.insertCell();if(value instanceof Node)cell.append(value);else cell.textContent=value;}}wrap.append(t);output.append(wrap);
 }
 function render(data){
  output.replaceChildren();const p=document.createElement('p');p.textContent=`Saved snapshot at ${data.read_at} UTC. ${data.counted_attempts} counted attempts in the latest quote run. These are saved records, not live worker or scheduler verification.`;output.append(p);
  const run=data.run;table('Latest saved quote run',['Recorded state','Cause','Started (UTC)','Completed (UTC)','Lease / plan'],run?[[states[run.state]||'Unknown',run.cause,run.started_at,run.completed_at||'Not recorded',`${run.lease_status === 'expired' ? 'Lease expired; saved running state may be abandoned' : run.lease_status === 'active' ? 'Lease active' : 'Terminal saved run'}; ${data.plan_saved?'frozen plan saved':'no frozen plan'}`]]:[['No saved quote run','Unavailable','Unavailable','Unavailable','No plan']]);
  table('Saved batches for that frozen run',['Batch','State','Counted attempts','Assets with receipts','Next due (UTC)','Last saved request outcome','Last saved issues'],data.batches.map(row=>[String(row.ordinal),states[row.state]||'Unknown',String(row.attempts),String(row.received_assets),['pending','waiting'].includes(row.state)?row.next_due_at:'Not scheduled',({completed:'Response completed',transport_error:'Transport error',provider_error:'Provider error',authentication:'Authentication rejected',capability:'Access denied',monthly_quota:'Monthly quota exhausted',rate_limit:'Rate limited',invalid_response:'Invalid response',unsupported:'Unsupported'})[row.outcome]||'No outcome recorded',Object.entries(row.issues).map(([code,count])=>`${({unsupported:'No supported quote returned',identity_mismatch:'Identity mismatch',invalid_quote:'Invalid quote',other:'Other issue'})[code]||'Other issue'}: ${count}`).join('; ')||'None recorded']));
  const note=document.createElement('p');note.textContent=`Source time becomes stale after twice the saved interval (${2*data.interval_seconds} seconds), including weekends/market closures. Recent means within that diagnostic window, not realtime. Fair-value source time is a provider fetch, not a trade. Response time and receipt time never replace unknown or old source time. Batch receipts can be partial and do not prove current cache coverage.`;output.append(note);
  const sourceNames={fair_value:'Fair value',trade:'Trade',quote:'Quote',minute_bar:'Minute bar',day_bar:'Day bar',previous_close:'Previous close',exchange_rate:'Exchange rate'};
  table('Complete saved-scope cached Tickerbot prices',['Asset','Cached price','Source / time meaning','Source time (UTC)','Response assembled (UTC)','Last received (UTC)','Source age'],data.marks.map(row=>{
   const q=row.mark;if(!q)return[row.symbol,'Unavailable',row.mapping_state==='needs_mapping'?'Verify mapping first':row.mapping_state==='unsupported'?'Unsupported':'No cached price','Unknown','Unknown','Unknown','Unavailable'];
   const price=document.createElement('span');price.textContent=`${tgitDisplayDecimal(q.price,2,8)} ${q.currency}`;price.title=`${q.price} ${q.currency}`;
   const meaning=q.source_time_semantics==='provider_fetch_time'?'provider fetch time':q.source_time_semantics==='source_observation_time'?'source observation time':'unknown source time';
   const age=({recent:'Within diagnostic age window',stale:'Stale',unknown:'Unknown source age',future:'Future source time; age unavailable'})[q.age.state]||'Unknown source age';
   return[row.symbol,price,`${sourceNames[q.price_source]||'Unknown source'}; ${meaning}`,q.price_asof||'Unknown',q.response_as_of||'Unknown',q.last_received_at||'Unknown',`${age}${q.age.seconds===null?'':`; ${q.age.seconds} seconds`}`];
  }),true);
 }
 async function load(){
  if(!workspace||window.tgitWriteBusy)return;const expected=++generation,selected=workspace;reload.disabled=true;status.setAttribute('role','status');status.textContent='Reading saved progress and cached prices…';output.replaceChildren();
  try{const response=await fetch(tgitRestUrl(config.root,`workspaces/${selected}/tickerbot/diagnostics`),{credentials:'same-origin',cache:'no-store',headers:{'X-WP-Nonce':config.nonce}});const result=await response.json();if(expected!==generation)return;if(!response.ok)throw new Error(result.message||config.i18n.network);render(result.data);status.textContent='Saved diagnostics loaded. No provider request was made.';}
  catch(error){if(expected===generation){status.setAttribute('role','alert');status.textContent=error.message;output.replaceChildren();}}
  finally{if(expected===generation)reload.disabled=false;}
 }
 reload.addEventListener('click',load);
 window.addEventListener('tgit-workspace',event=>{++generation;workspace=event.detail.role==='owner'?event.detail.workspace:'';panel.hidden=true;output.replaceChildren();status.textContent='';reload.disabled=false;});
 window.addEventListener('tgit-tickerbot-status-loaded',event=>{if(event.detail.workspace!==workspace)return;panel.hidden=!event.detail.enrolled;if(event.detail.enrolled)load();});
})();
