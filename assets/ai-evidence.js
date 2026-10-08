/* Owner review of immutable saved evidence and explicit summary controls. */
(() => {
 'use strict';
 const config = window.tgitConfig, $ = (id) => document.getElementById(`tgit-ai-evidence${id ? `-${id}` : ''}`);
 let workspace = '', asset = '', generation = 0, busy = false, ready = false, preview = null, selection = null, pending = null, trades = [], summaryControls = null;
 const form = $('form');
 const storage = () => `tgit-ai-evidence:${config.actorId}:${workspace}:${asset}`;
 const status = (text, error = false) => { $('status').textContent = text; $('status').setAttribute('role', error ? 'alert' : 'status'); };
 const persist = (approved = null) => { try { sessionStorage.setItem(storage(), JSON.stringify({ pending, approved })); } catch (_) { /* The same retry identity remains in this page. */ } };
 const controls = () => {
  form.inert = !ready || busy || !!window.tgitWriteBusy || !!pending;
  for (const element of form.querySelectorAll('input, select')) element.disabled = form.inert;
  $('preview').disabled = !ready || busy || !!window.tgitWriteBusy || !!pending || !$('snapshots').querySelector('select');
  $('approve').disabled = !ready || busy || !!window.tgitWriteBusy || !!pending || !preview;
  $('retry').hidden = !pending; $('retry').disabled = !ready || busy || !!window.tgitWriteBusy;
  if (summaryControls) summaryControls();
 };
 async function request(path, body, key) {
  const response = await fetch(tgitRestUrl(config.root, `workspaces/${workspace}/${path}`), { method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce, ...(body ? { 'Content-Type': 'application/json' } : {}), ...(key ? { 'Idempotency-Key': key } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
  const envelope = await response.json();
  if (!response.ok) { const error = new Error(envelope.message || 'Evidence request failed.'); error.status = response.status; throw error; }
  return envelope.data;
 }
 function show(result, approved = false) {
  summaryControls = null; $('detail').replaceChildren();
  const heading = document.createElement('h4'); heading.textContent = approved ? `Approved evidence #${result.id}` : 'Evidence preview'; $('detail').append(heading);
  const summary = document.createElement('p'); summary.textContent = `${result.bundle.asset.symbol} · ${result.bundle.reports.length} fiscal reports · ${result.bundle.omitted_reports} older reports omitted${approved ? ` · Approved ${result.approved_at} UTC` : ` · ${result.bytes} bytes`}`; $('detail').append(summary);
  const note = document.createElement('p'); note.textContent = 'Only the selected saved metrics and optional thesis are included. Prices, news, comparisons, images and other journal fields are excluded. Reporting duration and accounting-policy compatibility are unverified.'; $('detail').append(note);
  const details = document.createElement('details'), title = document.createElement('summary'), pre = document.createElement('pre');
  title.textContent = 'Exact evidence and source fingerprints'; pre.textContent = JSON.stringify(result.bundle, null, 2); pre.style.whiteSpace = 'pre-wrap'; pre.style.overflowWrap = 'anywhere'; details.append(title, pre); $('detail').append(details);
  if (approved) {
   const expected = generation;
   summaryControls = window.tgitAiGenerationPanel($('detail'), result.id, workspace, request, () => expected === generation, (value) => { if (!value || expected === generation) { busy = value; controls(); } });
  }
  const fingerprint = document.createElement('p'); fingerprint.textContent = `Evidence fingerprint: ${result.fingerprint}`; fingerprint.style.overflowWrap = 'anywhere'; $('detail').append(fingerprint);
 }
 function reset() {
  ++generation; summaryControls = null; busy = false; ready = false; preview = null; selection = null; pending = null; trades = []; $('').hidden = true; $('detail').replaceChildren(); $('snapshots').replaceChildren(); status(''); controls();
 }
 window.addEventListener('tgit-evidence-reset', reset);
 window.addEventListener('tgit-workspace', reset);
 form.addEventListener('change', () => { preview = null; selection = null; $('detail').replaceChildren(); status('Selection changed. Preview the evidence before approving.'); controls(); });
 window.addEventListener('tgit-evidence-sources', async (event) => {
  reset(); ({ workspace, asset } = event.detail); if (event.detail.role !== 'owner') return;
  const expected = generation; $('').hidden = false; status('Loading saved thesis choices…');
  const labels = { INCOME_STATEMENT: 'Income statement', BALANCE_SHEET: 'Balance sheet', CASH_FLOW: 'Cash flow' };
  for (const [dataset, label] of Object.entries(labels)) {
   const rows = event.detail.rows.filter((row) => row.dataset === dataset); if (!rows.length) continue;
   const wrapper = document.createElement('label'), select = document.createElement('select'); wrapper.textContent = `${label} snapshot`; select.name = dataset;
   select.add(new Option('Exclude this dataset', ''));
   for (const row of rows) select.add(new Option(`#${row.id} · ${row.retrieved_at} UTC`, String(row.id)));
   wrapper.append(select); $('snapshots').append(wrapper);
  }
  $('trade').replaceChildren(new Option('No thesis', ''));
  try {
   let after = 0;
   do { const page = await request(`trades?asset_id=${asset}&after=${after}&limit=100`); if (expected !== generation) return; trades.push(...page.items); after = page.next_cursor; } while (after !== null);
   for (const trade of trades) $('trade').add(new Option(`${trade.title} · revision ${trade.revision}`, String(trade.id)));
   ready = true;
   let saved = null; try { saved = JSON.parse(sessionStorage.getItem(storage()) || 'null'); } catch (_) { /* Ignore malformed browser state. */ }
   if (saved?.pending && /^[0-9a-f-]{36}$/.test(saved.pending.key || '') && /^[0-9a-f]{64}$/.test(saved.pending.body?.fingerprint || '') && saved.pending.body?.snapshots && typeof saved.pending.body.snapshots === 'object') {
    pending = saved.pending; status('An approval outcome is uncertain. Check it using the original request before preparing another approval.');
   } else if (Number.isSafeInteger(saved?.approved) && saved.approved > 0) {
    const result = await request(`ai-evidence/${saved.approved}`); if (expected !== generation) return; show(result, true); status('Previous approved evidence loaded. It remains unchanged when the journal changes.');
   } else status($('snapshots').querySelector('select') ? 'Choose the saved statements to include, then preview.' : 'Save a fundamental statement snapshot before preparing evidence.');
  } catch (error) { if (expected === generation) status(error.message, true); }
  finally { if (expected === generation) controls(); }
 });
 form.addEventListener('submit', async (event) => {
  event.preventDefault(); if (!ready || busy || window.tgitWriteBusy || pending) return;
  const snapshots = Object.fromEntries([...$('snapshots').querySelectorAll('select')].filter((select) => select.value).map((select) => [select.name, Number(select.value)]));
  if (!Object.keys(snapshots).length) { status('Select at least one saved statement snapshot.', true); return; }
  const body = { snapshots }, trade = trades.find((row) => String(row.id) === $('trade').value);
  if (trade) { body.trade_id = Number(trade.id); body.expected_revision = Number(trade.revision); }
  const expected = generation; busy = true; preview = null; selection = null; controls(); status('Preparing exact evidence…');
  try { const result = await request(`assets/${asset}/ai-evidence/preview`, body); if (expected !== generation) return; preview = result; selection = body; show(result); status('Review the exact evidence, then approve it. No AI request has been sent.'); }
  catch (error) { if (expected === generation) status(`${error.message} Reload saved history if the thesis has changed.`, true); }
  finally { busy = false; controls(); }
 });
 async function approve() {
  if (!ready || busy || window.tgitWriteBusy || (!pending && !preview)) return;
  if (!pending) { pending = { key: crypto.randomUUID(), body: { ...selection, fingerprint: preview.fingerprint } }; persist(); }
  const command = pending, expected = generation; busy = true; window.tgitWriteBusy = true; controls(); status('Saving approval…');
  try {
   const result = await request(`assets/${asset}/ai-evidence/approve`, command.body, command.key); if (expected !== generation) return;
   pending = null; preview = null; selection = null; persist(result.id); status(`Evidence #${result.id} approved. No summary was generated and no AI request was sent.`);
   try { const saved = await request(`ai-evidence/${result.id}`); if (expected === generation) show(saved, true); }
   catch (_) { if (expected === generation) status(`Evidence #${result.id} approved. Reload saved history to read it. No AI request was sent.`); }
  } catch (error) {
   if (expected !== generation) return;
   if ([400, 403, 404, 409].includes(error.status)) { pending = null; preview = null; selection = null; persist(); status(`${error.message} Reload saved history and preview again before approving.`, true); }
   else status(`${error.message} Check approval outcome with the same request; it will not create a duplicate.`, true);
  } finally { busy = false; window.tgitWriteBusy = false; controls(); }
 }
 $('approve').addEventListener('click', approve); $('retry').addEventListener('click', approve);
})();
