/* Owner-only saved scope and explicit recurring intent; GETs never contact providers. */
(() => {
 'use strict';
 const config = window.tgitConfig, panel = document.getElementById('tgit-tickerbot-controls');
 const scope = document.getElementById('tgit-tickerbot-scope-form'), cadence = document.getElementById('tgit-tickerbot-cadence-form');
 const reload = document.getElementById('tgit-tickerbot-controls-reload'), status = document.getElementById('tgit-tickerbot-controls-status'), inventory = document.getElementById('tgit-tickerbot-inventory');
 const refresh = document.getElementById('tgit-tickerbot-refresh'), refreshNew = document.getElementById('tgit-tickerbot-refresh-new');
 let operation = null;
 const refreshKey = () => `tgit-tickerbot-refresh:${config.actorId}:${workspace}`;
 let workspace = '', generation = 0, saved = null, baseline = '', cadenceBaseline = 'off';
 const ids = (name) => [...scope.querySelectorAll(`input[name="${name}"]:checked`)].map(input => Number(input.value));
 const snapshot = () => JSON.stringify([ids('watchlists'), ids('trades')]);
 const dirty = () => Boolean(saved && (snapshot() !== baseline || cadence.elements.enabled.value !== cadenceBaseline || cadence.elements.acknowledged.checked));
 window.tgitTickerbotScopeDirty = dirty;
 const tell = (text, error = false) => { status.setAttribute('role', error ? 'alert' : 'status'); status.textContent = text; };
 function controls() {
  refresh.disabled = !saved || Boolean(window.tgitWriteBusy); refresh.textContent = operation ? 'Check selected-price refresh' : 'Request selected prices'; refreshNew.hidden = !operation; refreshNew.disabled = Boolean(window.tgitWriteBusy);
  scope.querySelector('button').disabled = !saved || Boolean(window.tgitWriteBusy);
  cadence.querySelector('button').disabled = !saved || Boolean(window.tgitWriteBusy) || (cadence.elements.enabled.value === 'on' && !cadence.elements.acknowledged.checked);
 }
 function choices(id, rows, selected, name) {
  const root = document.getElementById(id); root.replaceChildren();
  if (!rows.length) root.textContent = 'No records yet.';
  for (const row of rows) {
   const label = document.createElement('label'), input = document.createElement('input'); input.type = 'checkbox'; input.name = name; input.value = row.id; input.checked = selected.includes(row.id);
   input.disabled = name === 'trades' && row.state !== 'planned' && !input.checked;
   label.append(input, document.createTextNode(` ${row.name ?? row.title}${row.state ? ` (${row.state})` : ''}`)); root.append(label);
  }
 }
 function render(data) {
  choices('tgit-tickerbot-list-choices', data.watchlists, data.selection.watchlists, 'watchlists'); choices('tgit-tickerbot-trade-choices', data.trades, data.selection.trades, 'trades');
  cadence.elements.enabled.value = data.cadence.enabled ? 'on' : 'off'; cadence.elements.acknowledged.checked = false; cadenceBaseline = cadence.elements.enabled.value; baseline = snapshot();
  inventory.replaceChildren(); const note = document.createElement('p');
  note.textContent = `Saved scope revision ${data.selection.revision}. ${data.inventory.items.length} assets; ${data.inventory.symbols.length} mapped symbols; ${data.inventory.batches.length} requests per cycle; ${data.inventory.projected_monthly_attempts} projected requests per 31 days at ${data.interval_seconds} seconds. Unmapped and unsupported assets are excluded from requests. This is a saved-scope projection, not verified price coverage.`; inventory.append(note);
  const wrap = document.createElement('div'); wrap.className = 'tgit-table-scroll'; const table = document.createElement('table'); const caption = table.createCaption(); caption.textContent = 'Complete saved refresh inventory';
  const header = table.createTHead().insertRow(); for (const text of ['Asset', 'Class', 'Included by', 'Mapping status']) { const th = document.createElement('th'); th.scope = 'col'; th.textContent = text; header.append(th); }
  const body = table.createTBody(); for (const item of data.inventory.items) { const row = body.insertRow(); for (const text of [item.symbol, item.asset_class, item.origins.join(', '), ({ mapped: 'Mapped identity; price coverage unverified', needs_mapping: 'Verify mapping first', unsupported: 'Unsupported' })[item.state] || 'Unavailable']) row.insertCell().textContent = text; }
  wrap.append(table); inventory.append(wrap);
 }
 async function request(path, body, key) {
  const response = await fetch(tgitRestUrl(config.root, `workspaces/${workspace}/tickerbot/${path}`), { credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce, ...(key ? { 'Idempotency-Key': key } : {}), ...(body ? { 'Content-Type': 'application/json' } : {}) }, ...(body ? { method: 'POST', body: JSON.stringify(body) } : {}) });
  const result = await response.json(); if (!response.ok) throw new Error(result.message || config.i18n.network); return result.data;
 }
 async function load() {
  if (!workspace) return; const expected = ++generation; saved = null; controls(); reload.disabled = true; scope.inert = true; cadence.inert = true; tell('Reading saved selections and complete inventory…');
  try { const data = await request('controls'); if (expected !== generation) return; saved = data; render(data); tell('Saved selections loaded. No provider request was made.'); }
  catch (error) { if (expected === generation) { inventory.replaceChildren(); tell(error.message, true); } }
  finally { if (expected === generation) { reload.disabled = false; scope.inert = !saved; cadence.inert = !saved; controls(); } }
 }
 async function save(path, body) {
  if (window.tgitTickerbotSetupDirty?.()) { tell('Save or reload changed setup settings before saving these controls.', true); return; }
  const expected = generation, selected = workspace; window.tgitWriteBusy = true; scope.inert = true; cadence.inert = true; reload.disabled = true; controls();
  try { const result = await request(path, body); if (expected !== generation) return; await load(); window.dispatchEvent(new CustomEvent('tgit-tickerbot-controls-updated', { detail: { workspace: selected } })); if (path === 'cadence' && body.enabled && result.queued !== true) tell('Recurring intent saved, but no recovery event was queued. Review the active pricing policy, server flags and host cron, then save again. No provider request was sent.', true); else if (saved) tell('Saved. No provider request was sent. Review the refreshed inventory and readiness status.'); else tell('Saved, but status reload failed. Reload saved selections before another save.', true); }
  catch (error) { if (expected === generation) { saved = null; tell(`${error.message} Reload saved selections before another save.`, true); } }
  finally { window.tgitWriteBusy = false; if (selected === workspace) { reload.disabled = false; scope.inert = !saved; cadence.inert = !saved; cadence.elements.acknowledged.checked = false; controls(); } }
 }

 refresh.addEventListener('click', async () => {
  if (!saved || window.tgitWriteBusy) return;
  if (dirty() || window.tgitTickerbotSetupDirty?.()) { tell('Save or reload changed selections, recurring intent and setup before requesting prices.', true); return; }
  if (!operation) { operation = { key: crypto.randomUUID(), settings: saved.settings_revision, scope: saved.selection.revision }; try { sessionStorage.setItem(refreshKey(), JSON.stringify(operation)); } catch (_) {} }
  const expected = generation, selected = workspace; window.tgitWriteBusy = true; controls(); scope.inert = true; cadence.inert = true;
  try { const result = await request('refresh', { settings_revision: operation.settings, scope_revision: operation.scope }, operation.key); if (expected !== generation) return;
   await load(); window.dispatchEvent(new CustomEvent('tgit-tickerbot-controls-updated', { detail: { workspace: selected } }));
   const messages = { disabled: 'Enable server transport and worker for an explicit test refresh; scheduler may stay off.', cooldown: 'Wait five minutes after the previous manual operation.', busy: 'Another workspace operation is active.', joined: 'An existing account operation is active; no second refresh was created.', completed: 'The original refresh completed. Check saved price diagnostics.', failed: 'The original refresh failed or was partial. Check saved diagnostics; counted requests remain counted.', expired: 'The original refresh lease expired; it will not be resent by this check.' };
   tell(result.state === 'running' ? (result.queued ? 'Refresh queued. Host cron must run the saved batches. Reload progress to inspect receipts.' : 'Refresh saved, but queue repair failed. Check this same operation again; no second run will be created.') : messages[result.state] || 'Check saved diagnostics before starting another refresh.', result.state !== 'running' && result.state !== 'completed');
  } catch (error) { if (expected === generation) tell(`${error.message} Keep this operation and check its outcome before starting a separate refresh.`, true); }
  finally { window.tgitWriteBusy = false; if (selected === workspace) { scope.inert = !saved; cadence.inert = !saved; controls(); } }
 });
 refreshNew.addEventListener('click', () => { if (window.tgitWriteBusy || !confirm('A separate refresh may consume additional counted requests. Prior operations remain recorded. Continue?')) return; operation = null; try { sessionStorage.removeItem(refreshKey()); } catch (_) {} controls(); });
 scope.addEventListener('submit', event => { event.preventDefault(); if (!saved || window.tgitWriteBusy) return; save('scope', { watchlists: ids('watchlists'), trades: ids('trades'), expected_revision: saved.selection.revision }); });
 cadence.addEventListener('change', controls);
 cadence.addEventListener('submit', event => { event.preventDefault(); if (!saved || window.tgitWriteBusy) return; if (snapshot() !== baseline) { tell('Save or reload changed asset selections before recurring review.', true); return; } const enabled = cadence.elements.enabled.value === 'on'; if (enabled && !cadence.elements.acknowledged.checked) return; save('cadence', { enabled, acknowledged: cadence.elements.acknowledged.checked, expected_revision: saved.cadence.revision, expected_settings: saved.settings_revision, expected_scope: saved.selection.revision }); });
 reload.addEventListener('click', () => { if (!window.tgitWriteBusy && (!dirty() || confirm('Discard unsaved asset selections and recurring review?'))) load(); });
 const leave = () => !window.tgitWriteBusy && (!dirty() || confirm('Discard unsaved asset selections and recurring review?'));
 document.getElementById('tgit-workspace').addEventListener('change', event => { if (!leave()) { event.target.value = workspace; event.stopImmediatePropagation(); } }, true);
 document.getElementById('tgit-tabs').addEventListener('click', event => { const tab = event.target.closest('[data-tab]'); if (tab && tab.dataset.tab !== 'settings' && !leave()) { event.preventDefault(); event.stopImmediatePropagation(); } }, true);
 document.getElementById('tgit-tabs').addEventListener('keydown', event => { if (['ArrowRight','ArrowDown','ArrowLeft','ArrowUp','Home','End'].includes(event.key) && !leave()) { event.preventDefault(); event.stopImmediatePropagation(); } }, true);
 window.addEventListener('popstate', () => { if (dirty() && !leave()) { const url = new URL(location.href); url.searchParams.set('tgit_section', 'settings'); history.pushState(null, '', url); window.tgitSelectSection('settings'); } });
 window.addEventListener('beforeunload', event => { if (dirty()) { event.preventDefault(); event.returnValue = ''; } });
 window.addEventListener('tgit-workspace', event => { ++generation; workspace = event.detail.role === 'owner' ? event.detail.workspace : ''; saved = null; baseline = ''; operation = null; try { const prior = JSON.parse(sessionStorage.getItem(refreshKey()) || 'null'); if (prior && /^[a-f0-9-]{36}$/.test(prior.key) && Number.isInteger(prior.settings) && Number.isInteger(prior.scope)) operation = prior; } catch (_) {} scope.reset(); cadence.reset(); inventory.replaceChildren(); panel.hidden = true; status.textContent = ''; controls(); });
 window.addEventListener('tgit-tickerbot-status-loaded', event => { if (event.detail.workspace !== workspace) return; panel.hidden = !event.detail.enrolled; if (event.detail.enrolled && !window.tgitWriteBusy && !dirty()) load(); });
})();
