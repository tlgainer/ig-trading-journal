/* Owner-only mappings; prices stay decimal strings and credentials stay server-side. */
(() => {
 'use strict';
 const config = window.tgitConfig, $ = (id) => document.getElementById(`tgit-market-${id}`);
 const form = $('form'), section = document.getElementById('tgit-market-data-section');
 let workspace = '', role = '', assets = [], mappings = [], providers = [], generation = 0, baseline = '';
 const refreshKeys = new Map();
 const keyStorage = () => `tgit-market-requests:${config.actorId}:${workspace}`;
 function persistKeys() { try { sessionStorage.setItem(keyStorage(), JSON.stringify([...refreshKeys])); } catch (_) { /* Retry identity remains available in this page. */ } }
 const snapshot = () => JSON.stringify(Object.fromEntries(new FormData(form)));
 const dirty = () => role === 'owner' && baseline && snapshot() !== baseline;
 const status = (message, error = false) => { $('status').textContent = message; $('status').setAttribute('role', error ? 'alert' : 'status'); };
 async function request(target, body, key) {
  const response = await fetch(tgitRestUrl(config.root, target), { method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce, ...(body ? { 'Content-Type': 'application/json' } : {}), ...(key ? { 'Idempotency-Key': key } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
  const result = await response.json();
  if (!response.ok) throw new Error(result.message || config.i18n.network);
  return result.data;
 }
 function fill() {
  const asset = assets.find((row) => String(row.id) === form.elements.asset_id.value);
  const mapping = mappings.find((row) => String(row.asset_id) === form.elements.asset_id.value && row.provider === form.elements.provider.value);
  form.elements.provider_symbol.value = mapping?.provider_symbol || asset?.symbol || '';
  form.elements.exchange.value = asset?.exchange || ''; form.elements.currency.value = asset?.quote_currency || '';
  form.elements.evidence.value = mapping?.evidence || ''; form.elements.enabled.value = mapping && String(mapping.enabled) === '0' ? 'false' : 'true';
  form.elements.frequency.value = mapping?.frequency || 'off'; form.elements.frequency.disabled = !mapping;
  $('save-schedule').disabled = !mapping;
  form.querySelector('[type=submit]').disabled = !asset; baseline = snapshot();
 }
 function render() {
  tgitCollection($('mappings'), { actor: config.actorId, workspace, key: 'provider-mappings', title: 'Stock price mappings', search: (row) => `${row.provider_symbol} ${row.provider} ${row.exchange} ${row.currency}`, columns: [
   { key: 'symbol', label: 'Provider symbol', identity: true, required: true, sort: (a, b) => a.provider_symbol.localeCompare(b.provider_symbol), render: (row) => row.provider_symbol },
   { key: 'provider', label: 'Provider', render: (row) => row.provider === 'fmp' ? 'FMP' : 'Alpha Vantage' },
   { key: 'exchange', label: 'Exchange', render: (row) => row.exchange },
   { key: 'enabled', label: 'Requests', render: (row) => String(row.enabled) === '1' ? 'Enabled' : 'Disabled' },
   { key: 'price', label: 'End-of-day price', numeric: true, render: (row) => row.price === null ? 'Not fetched' : `${tgitDisplayDecimal(row.price)} ${row.currency}` },
   { key: 'session', label: 'Price session', render: (row) => row.session_date || 'Not fetched' },
   { key: 'frequency', label: 'Automatic refresh', render: (row) => ({ once: 'Weekdays 6:30 PM NY', twice: 'Weekdays 6:30 and 10:30 PM NY' })[row.frequency] || 'Off' },
   { key: 'actions', label: 'Actions', required: true, render: (row) => {
    const actions = document.createElement('div'); actions.className = 'tgit-row-actions';
    const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'button'; edit.textContent = 'Edit mapping'; edit.setAttribute('aria-label', `Edit ${row.provider_symbol} ${row.provider} mapping`);
    edit.addEventListener('click', () => { if (window.tgitWriteBusy || (dirty() && !confirm('Discard unsaved market-data changes?'))) return; form.elements.asset_id.value = row.asset_id; form.elements.provider.value = row.provider; fill(); form.elements.provider_symbol.focus(); });
    const refresh = document.createElement('button'); refresh.type = 'button'; refresh.className = 'button'; refresh.textContent = refreshKeys.has(String(row.id)) ? 'Check refresh outcome' : 'Refresh price'; refresh.setAttribute('aria-label', `${refresh.textContent} for ${row.provider_symbol} ${row.provider}`);
    refresh.disabled = String(row.enabled) !== '1' || !providers.find((provider) => provider.provider === row.provider)?.enabled;
    refresh.addEventListener('click', async () => {
     if (window.tgitWriteBusy) return; window.tgitWriteBusy = true; refresh.disabled = true;
     const selected = workspace, expected = generation, slot = String(row.id); const key = refreshKeys.get(slot) || crypto.randomUUID(); refreshKeys.set(slot, key); persistKeys();
     try {
      const result = await request(`workspaces/${selected}/provider-mappings/${row.id}/refresh`, {}, key);
      if (expected !== generation) return;
      const messages = { completed: 'End-of-day price saved. Holdings use the selected stock price source.', uncertain: 'Delivery is uncertain. Checking this outcome will not resend the request.', failed: 'Provider request failed. This attempt still counts toward the quota.', invalid_response: 'Provider returned incomplete or unavailable price data. This attempt still counts toward the quota.', disabled: 'Enable this provider in the server configuration before refreshing.', blocked: 'Refresh blocked. Reload the current mapping and check permissions.', unavailable: 'Refresh unavailable. Check the outcome before starting another request.' };
      if (['completed', 'failed', 'invalid_response', 'disabled', 'blocked'].includes(result.state)) { refreshKeys.delete(slot); persistKeys(); }
      await load(false); status(messages[result.state] || 'Refresh did not complete.', result.state !== 'completed');
      if (result.state === 'completed') window.dispatchEvent(new CustomEvent('tgit-prices-updated', { detail: { workspace: selected } }));
     } catch (error) { if (expected === generation) status(error.message, true); }
     finally { window.tgitWriteBusy = false; if (expected === generation) render(); }
    });
    actions.append(edit, refresh); return actions;
   } }
  ] }, mappings);
 }
 async function load(reset = true) {
  const expected = generation, selected = workspace;
  const configuration = await request(`workspaces/${selected}/market-data`); if (expected !== generation) return;
  const rows = []; let after = 0;
  do { const result = await request(`workspaces/${selected}/provider-mappings?after=${after}&limit=100`); if (expected !== generation) return; rows.push(...result.items); after = result.next_cursor; } while (after !== null);
  providers = configuration.providers; mappings = rows;
  $('config').textContent = providers.map((row) => `${row.provider === 'fmp' ? 'FMP' : 'Alpha Vantage'}: ${row.enabled ? 'configured' : 'disabled or missing server key'}; ${row.daily_limit} requests per rolling 24 hours shared across workspaces.`).join(' ');
  render(); if (reset) fill();
 }
 for (const name of ['asset_id', 'provider']) form.elements[name].addEventListener('change', () => {
  const previous = baseline ? JSON.parse(baseline) : null, current = Object.fromEntries(new FormData(form));
  if (previous) { current[name] = previous[name]; if (JSON.stringify(current) !== baseline && !confirm('Discard unsaved market-data changes?')) { form.elements[name].value = previous[name]; return; } }
  fill();
 });
 form.addEventListener('submit', async (event) => {
  event.preventDefault(); if (window.tgitWriteBusy) return; window.tgitWriteBusy = true; form.querySelector('[type=submit]').disabled = true;
  const selected = workspace, expected = generation, input = Object.fromEntries(new FormData(form));
  const prior = mappings.find((row) => String(row.asset_id) === input.asset_id && row.provider === input.provider);
  const body = { provider: input.provider, provider_symbol: input.provider_symbol, exchange: input.exchange, currency: input.currency, enabled: input.enabled === 'true', evidence: input.evidence, expected_mapping_id: Number(prior?.id || 0) };
  try { await request(`workspaces/${selected}/assets/${input.asset_id}/provider-mappings`, body); if (expected === generation) { await load(); status('Provider mapping saved. No request was sent.'); } }
  catch (error) { if (expected === generation) status(`${error.message} Reload Settings before retrying if the save outcome is uncertain.`, true); }
  finally { window.tgitWriteBusy = false; if (expected === generation) form.querySelector('[type=submit]').disabled = !assets.length; }
 });
 $('save-schedule').addEventListener('click', async () => {
  if (window.tgitWriteBusy) return;
  const before = baseline ? JSON.parse(baseline) : {}, input = Object.fromEntries(new FormData(form));
  const frequency = input.frequency; delete before.frequency; delete input.frequency;
  if (JSON.stringify(before) !== JSON.stringify(input)) { status('Save mapping changes before changing its refresh schedule.', true); return; }
  const mapping = mappings.find((row) => String(row.asset_id) === input.asset_id && row.provider === input.provider); if (!mapping) return;
  window.tgitWriteBusy = true; $('save-schedule').disabled = true; const selected = workspace, expected = generation;
  try {
   const result = await request(`workspaces/${selected}/provider-mappings/${mapping.id}/schedule`, { frequency, expected_schedule_id: Number(mapping.schedule_id || 0) });
   if (expected !== generation) return; await load();
   status(frequency === 'off' ? 'Automatic refresh disabled.' : result.queued ? 'Refresh schedule saved. The next weekday slot is queued.' : 'Schedule saved; queueing is pending. Check server configuration and site cron.');
  } catch (error) { if (expected === generation) status(`${error.message} Reload Settings before retrying if the save outcome is uncertain.`, true); }
  finally { window.tgitWriteBusy = false; if (expected === generation) $('save-schedule').disabled = !mapping; }
 });
 const leave = () => !window.tgitWriteBusy && (!dirty() || confirm('Discard unsaved market-data changes?'));
 document.getElementById('tgit-workspace').addEventListener('change', (event) => { if (!leave()) { event.target.value = workspace; event.stopImmediatePropagation(); } }, true);
 document.getElementById('tgit-tabs').addEventListener('click', (event) => { const tab = event.target.closest('[data-tab]'); if (tab && tab.dataset.tab !== 'settings' && !leave()) { event.preventDefault(); event.stopImmediatePropagation(); } else if (tab && tab.dataset.tab !== 'settings') fill(); }, true);
 document.getElementById('tgit-tabs').addEventListener('keydown', (event) => { if (!['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', 'Home', 'End'].includes(event.key)) return; if (!leave()) { event.preventDefault(); event.stopImmediatePropagation(); } else fill(); }, true);
 window.addEventListener('popstate', () => { if (!dirty()) return; if (leave()) fill(); else { const url = new URL(location.href); url.searchParams.set('tgit_section', 'settings'); history.pushState(null, '', url); window.tgitSelectSection('settings'); } });
 window.addEventListener('beforeunload', (event) => { if (dirty() || window.tgitWriteBusy) { event.preventDefault(); event.returnValue = ''; } });
 window.addEventListener('tgit-workspace', (event) => {
  ++generation; ({ workspace, role } = event.detail); assets = event.detail.assets.filter((row) => row.asset_class === 'stock'); mappings = []; providers = []; refreshKeys.clear(); baseline = ''; form.reset(); $('mappings').replaceChildren(); status(''); section.hidden = role !== 'owner';
  try { const stored = JSON.parse(sessionStorage.getItem(keyStorage()) || '[]'); if (Array.isArray(stored) && stored.length <= 1000) for (const item of stored) if (Array.isArray(item) && /^[1-9][0-9]*$/.test(item[0]) && typeof item[1] === 'string' && /^[0-9a-f-]{36}$/.test(item[1])) refreshKeys.set(item[0], item[1]); } catch (_) { /* Ignore malformed browser state. */ }
  form.elements.asset_id.replaceChildren(); for (const asset of assets) { const option = document.createElement('option'); option.value = asset.id; option.textContent = `${asset.symbol} · ${asset.exchange} (${asset.quote_currency})`; form.elements.asset_id.append(option); }
  fill(); form.inert = role === 'owner';
  if (role === 'owner') { const expected = generation; load().catch((error) => { if (expected === generation) status(error.message, true); }).finally(() => { if (expected === generation) form.inert = false; }); }
 });
})();
