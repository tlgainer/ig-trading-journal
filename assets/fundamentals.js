/* Saved evidence is readable by members; only owners can explicitly fetch a dataset. */
(() => {
 'use strict';
 const config = window.tgitConfig, $ = (id) => document.getElementById(`tgit-fundamental-${id}`);
 let workspace = '', role = '', generation = 0, mappings = [], enabled = false, rows = [], loading = false, ready = false, focusPending = false;
 const keys = new Map();
 const storage = () => `tgit-fundamental-requests:${config.actorId}:${workspace}`;
 const persist = () => { try { sessionStorage.setItem(storage(), JSON.stringify([...keys])); } catch (_) { /* Keep identity in this page. */ } };
 const status = (message, error = false) => { $('status').textContent = message; $('status').setAttribute('role', error ? 'alert' : 'status'); };
 const mapping = () => mappings.find((row) => String(row.asset_id) === $('asset').value && row.provider === 'alpha_vantage' && String(row.enabled) === '1');
 const slot = () => `${mapping()?.id}:${$('dataset').value}`;
 function controls() {
  $('reload').disabled = loading || !$('asset').value || !!window.tgitWriteBusy;
  $('refresh').disabled = loading || !enabled || !mapping() || !!window.tgitWriteBusy;
  $('refresh').textContent = keys.has(slot()) ? 'Check refresh outcome' : 'Refresh selected dataset';
  $('asset').disabled = loading || !!window.tgitWriteBusy; $('dataset').disabled = loading || !!window.tgitWriteBusy;
 }
 async function request(path, body, key) {
  const response = await fetch(tgitRestUrl(config.root, `workspaces/${workspace}/${path}`), { method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce, ...(body ? { 'Content-Type': 'application/json' } : {}), ...(key ? { 'Idempotency-Key': key } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
  const envelope = await response.json(); if (!response.ok) throw new Error(envelope.message || 'Fundamental request failed.'); return envelope.data;
 }
 async function all(path, expected) {
  const result = []; let after = 0;
  do { const page = await request(`${path}?after=${after}&limit=100`); if (expected !== generation) return null; result.push(...page.items); after = page.next_cursor; } while (after !== null);
  return result;
 }
 const label = (value) => value.toLowerCase().replace(/_/g, ' ');
 async function detail(row) {
  const expected = generation; $('detail').replaceChildren();
  const heading = document.createElement('h4'); heading.textContent = `${label(row.dataset)} snapshot #${row.id}`; $('detail').append(heading);
  const provenance = document.createElement('p'); provenance.textContent = `Provider: Alpha Vantage · ${row.provider_symbol} · Retrieved: ${row.retrieved_at} UTC · Mapping #${row.mapping_id}`; $('detail').append(provenance);
  if (row.dataset === 'OVERVIEW') { const pre = document.createElement('pre'); pre.style.whiteSpace = 'pre-wrap'; pre.style.overflowWrap = 'anywhere'; try { pre.textContent = JSON.stringify(JSON.parse(row.evidence_json), null, 2); } catch (_) { pre.textContent = 'Saved evidence could not be displayed.'; } $('detail').append(pre); return; }
  try {
   const result = await request(`assets/${$('asset').value}/fundamental-metrics`, { snapshots: { [row.dataset]: Number(row.id) } }); if (expected !== generation) return;
   const metrics = result.reports.flatMap((report) => Object.entries(report.metrics).map(([name, metric]) => ({ period: `${report.period_type} ${report.fiscal_date_ending}`, name: label(name), value: metric.value === null ? `Unavailable: ${label(metric.status)}` : `${tgitDisplayDecimal(metric.value)} ${metric.unit}`, currency: report.reported_currency })));
   const table = document.createElement('div'); $('detail').append(table);
   tgitCollection(table, { actor: config.actorId, workspace, key: `fundamental-metrics-${row.id}`, title: 'Snapshot metrics', search: (metric) => `${metric.period} ${metric.name}`, columns: [
    { key: 'period', label: 'Fiscal period', required: true, render: (metric) => metric.period }, { key: 'name', label: 'Metric', identity: true, required: true, render: (metric) => metric.name }, { key: 'value', label: 'Value / coverage', required: true, render: (metric) => metric.value }, { key: 'currency', label: 'Reported currency', render: (metric) => metric.currency }
   ] }, metrics);
  } catch (error) { if (expected === generation) status(error.message, true); }
 }
 async function load() {
  const expected = ++generation; loading = true; controls(); tgitResetCollection($('history')); $('detail').replaceChildren(); status('Loading saved history…');
  try {
   if (!$('asset').value) { rows = []; status('Create a stock asset to view fundamentals.'); return; }
   const loaded = await all(`assets/${$('asset').value}/fundamentals`, expected); if (expected !== generation) return; rows = loaded;
   tgitCollection($('history'), { actor: config.actorId, workspace, key: `fundamental-history-${$('asset').value}`, title: 'Saved fundamental snapshots', search: (row) => `${row.dataset} ${row.provider_symbol} ${row.retrieved_at}`, columns: [
    { key: 'id', label: 'Snapshot', identity: true, required: true, render: (row) => `#${row.id}` }, { key: 'dataset', label: 'Dataset', render: (row) => label(row.dataset) }, { key: 'retrieved', label: 'Retrieved (UTC)', render: (row) => row.retrieved_at }, { key: 'symbol', label: 'Provider symbol', render: (row) => row.provider_symbol }, { key: 'actions', label: 'Actions', required: true, render: (row) => { const button = document.createElement('button'); button.type = 'button'; button.className = 'button'; button.textContent = 'View'; button.setAttribute('aria-label', `View snapshot ${row.id}`); button.addEventListener('click', () => { ++generation; detail(row); }); return button; } }
   ] }, rows); status(rows.length ? 'Saved history loaded. No provider request was sent.' : 'No saved fundamental snapshots yet.');
  } catch (error) { if (expected === generation) status(error.message, true); }
  finally { if (expected === generation) { loading = false; controls(); } }
 }
 function routeAsset() {
  const url = new URL(location.href);
  if (url.searchParams.get('tgit_section') !== 'research') return;
  url.searchParams.set('tgit_fundamental_asset', $('asset').value); history.replaceState(null, '', url);
 }
 async function selectAsset(asset, focus = false) {
  if (![...$('asset').options].some((option) => option.value === String(asset))) return;
  $('asset').value = String(asset); routeAsset(); focusPending = focus;
  if (!ready) return;
  await load(); if (focusPending && !loading) { focusPending = false; $('asset').focus(); $('card').scrollIntoView({ block: 'start' }); }
 }
 $('asset').addEventListener('change', () => { routeAsset(); load(); }); $('reload').addEventListener('click', load); $('dataset').addEventListener('change', controls);
 window.addEventListener('tgit-open-fundamentals', (event) => {
  if (String(event.detail.workspace) !== String(workspace) || window.tgitWriteBusy || new URL(location.href).searchParams.get('tgit_section') !== 'research') return;
  selectAsset(event.detail.asset, true);
 });
 window.addEventListener('popstate', () => {
  const url = new URL(location.href), asset = url.searchParams.get('tgit_fundamental_asset');
  if (url.searchParams.get('tgit_section') === 'research' && String(url.searchParams.get('tgit_workspace')) === String(workspace) && asset && asset !== $('asset').value) selectAsset(asset);
 });
 $('refresh').addEventListener('click', async () => {
  const selected = mapping(); if (window.tgitWriteBusy || !selected || !enabled || role !== 'owner') return;
  window.tgitWriteBusy = true; controls(); const expected = generation, selectedSlot = slot(), key = keys.get(selectedSlot) || crypto.randomUUID(); keys.set(selectedSlot, key); persist();
  try {
   const result = await request(`provider-mappings/${selected.id}/fundamentals/refresh`, { dataset: $('dataset').value }, key); if (expected !== generation) return;
   if (['completed', 'failed', 'invalid_response', 'disabled', 'blocked'].includes(result.state)) { keys.delete(selectedSlot); persist(); }
   const selectedWorkspace = workspace; await load(); if (workspace !== selectedWorkspace) return;
   const messages = { completed: 'Fundamental snapshot saved. This is provider evidence, not an AI review.', uncertain: 'Delivery is uncertain. Check this outcome with the same request key; it will not resend.', failed: 'Provider request failed; this attempt counts toward the quota.', invalid_response: 'Provider data was unavailable or invalid; this attempt counts toward the quota.', disabled: 'Fundamental refresh is disabled on the server.', blocked: 'Refresh blocked. Reload the mapping and check permissions.', unavailable: 'Refresh unavailable. Check the outcome before starting another request.' };
   status(messages[result.state] || 'Refresh did not complete.', result.state !== 'completed');
  } catch (error) { if (expected === generation) status(`${error.message} Check the outcome using the same request key.`, true); }
  finally { window.tgitWriteBusy = false; controls(); }
 });
 window.addEventListener('tgit-workspace', async (event) => {
  const expected = ++generation; ({ workspace, role } = event.detail); mappings = []; enabled = false; rows = []; keys.clear(); loading = true; ready = false; focusPending = false;
  $('owner').hidden = role !== 'owner'; $('history').replaceChildren(); $('detail').replaceChildren(); $('config').textContent = ''; $('asset').replaceChildren();
  for (const asset of event.detail.assets.filter((row) => row.asset_class === 'stock')) { const option = document.createElement('option'); option.value = asset.id; option.textContent = `${asset.symbol} · ${asset.exchange} (${asset.quote_currency})`; $('asset').append(option); }
  const url = new URL(location.href), routedAsset = url.searchParams.get('tgit_fundamental_asset');
  if (String(url.searchParams.get('tgit_workspace')) === String(workspace) && [...$('asset').options].some((option) => option.value === routedAsset)) $('asset').value = routedAsset;
  try { const saved = JSON.parse(sessionStorage.getItem(storage()) || '[]'); if (Array.isArray(saved) && saved.length <= 1000) for (const item of saved) if (Array.isArray(item) && typeof item[0] === 'string' && /^[1-9][0-9]*:(OVERVIEW|INCOME_STATEMENT|BALANCE_SHEET|CASH_FLOW)$/.test(item[0]) && typeof item[1] === 'string' && /^[0-9a-f-]{36}$/.test(item[1])) keys.set(...item); } catch (_) { /* Ignore malformed browser state. */ }
  controls();
  try {
   if (role === 'owner') { const configuration = await request('market-data'); const loaded = await all('provider-mappings', expected); if (expected !== generation) return; mappings = loaded; enabled = configuration.fundamentals_enabled === true; $('config').textContent = enabled ? 'Each selected dataset consumes one Alpha Vantage request, sharing the quote allowance. Confirm an enabled mapping in Settings.' : 'Refresh is disabled. Saved history remains readable. Configure fundamentals and an Alpha Vantage mapping before refreshing.'; }
  } catch (error) { if (expected === generation) $('config').textContent = error.message; }
  if (expected === generation) { ready = true; await load(); if (focusPending && !loading) { focusPending = false; $('asset').focus(); $('card').scrollIntoView({ block: 'start' }); } }
 });
})();
