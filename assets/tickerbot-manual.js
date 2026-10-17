/* Immediate bounded owner selection. Reads and outcome checks never send providers. */
(() => {
 'use strict';
 const config = window.tgitConfig, panel = document.getElementById('tgit-tickerbot-manual');
 const list = document.getElementById('tgit-tickerbot-manual-assets'), count = document.getElementById('tgit-tickerbot-manual-selection');
 const send = document.getElementById('tgit-tickerbot-manual-send'), fresh = document.getElementById('tgit-tickerbot-manual-new'), status = document.getElementById('tgit-tickerbot-manual-status');
 let workspace = '', saved = null, operation = null, generation = 0, selection = new Set();
 const key = () => `tgit-tickerbot-direct:${config.actorId}:${workspace}`;
 const tell = (text, error = false) => { status.textContent = text; status.setAttribute('role', error ? 'alert' : 'status'); };
 const persist = () => { try { sessionStorage.setItem(key(), JSON.stringify(operation)); } catch (_) { /* Optional storage; retain in memory. */ } };
 function controls() {
  send.disabled = !saved || Boolean(window.tgitWriteBusy) || (!operation && (!selection.size || selection.size > 50));
  send.textContent = operation ? 'Check manual refresh outcome' : 'Refresh selected assets now';
  fresh.hidden = !operation; fresh.disabled = Boolean(window.tgitWriteBusy);
  count.textContent = `${selection.size} assets selected. ${selection.size ? 'One API request for up to 50 mapped assets.' : 'Select at least one mapped asset.'}`;
  list.querySelectorAll('input[type=checkbox]').forEach(input => { input.disabled = Boolean(operation || window.tgitWriteBusy) || input.dataset.mapped !== 'true'; });
 }
 function render() {
  if (!saved) { window.tgitResetCollection(list); controls(); return; }
  const rows = saved.manual_assets || [];
  tgitCollection(list, { actor: config.actorId, workspace, key: 'manual-price-assets', title: 'Assets for immediate refresh', defaultSort: 'symbol',
   search: row => `${row.symbol} ${row.asset_class} ${row.currency} ${row.state}`,
   columns: [
    { key: 'select', label: 'Select', required: true, render: row => { const label = document.createElement('label'), input = document.createElement('input'); input.type = 'checkbox'; input.value = row.asset_id; input.dataset.mapped = String(row.state === 'mapped'); input.checked = selection.has(row.asset_id); input.disabled = Boolean(operation || window.tgitWriteBusy) || row.state !== 'mapped'; input.setAttribute('aria-label', `Select ${row.symbol} (${row.currency}) #${row.asset_id}`); label.append(input); input.addEventListener('change', () => { if (input.checked && selection.size >= 50) { input.checked = false; tell('Select no more than fifty assets for one immediate request.', true); return; } if (input.checked) selection.add(row.asset_id); else selection.delete(row.asset_id); controls(); }); return label; } },
    { key: 'symbol', label: 'Asset', required: true, sort: (a,b) => a.symbol.localeCompare(b.symbol), render: row => row.symbol },
    { key: 'class', label: 'Asset class', render: row => row.asset_class },
    { key: 'currency', label: 'Quote currency', render: row => row.currency },
    { key: 'mapping', label: 'Mapping', required: true, render: row => row.state === 'mapped' ? `Ready: ${row.provider_symbol}` : row.state === 'unsupported' ? 'Unsupported asset' : 'Verify mapping first in Settings' }
   ] }, rows.map(row => ({...row, id: row.asset_id})));
  controls();
 }
 send.addEventListener('click', async () => {
  if (!saved || window.tgitWriteBusy) return;
  if (!operation && (window.tgitTickerbotSetupDirty?.() || window.tgitTickerbotScopeDirty?.())) { tell('Save or reload changed setup and scope controls before refreshing.', true); return; }
  if (!operation) {
   const assets = [...selection].sort((a,b) => a-b); if (!assets.length || assets.length > 50) return;
   operation = { key: crypto.randomUUID(), assets, settings: saved.settings_revision, scope: saved.selection.revision, sent: false };
  }
  const current = operation, expected = generation, selected = workspace, check = current.sent; current.sent = true; persist(); window.tgitWriteBusy = true; controls();
  try {
   const response = await fetch(tgitRestUrl(config.root, `workspaces/${selected}/tickerbot/refresh-selected`), { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: {'Content-Type':'application/json', 'X-WP-Nonce':config.nonce, 'Idempotency-Key':current.key}, body: JSON.stringify({settings_revision:current.settings, scope_revision:current.scope, asset_ids:current.assets, check_only:check}) });
   const result = await response.json(); if (expected !== generation) return; if (!response.ok) throw new Error(result.message || config.i18n.network);
   current.state = result.data.state; persist();
   const messages = { not_started: 'No operation was admitted for this key. Use New manual selection to explicitly request prices; this outcome check sent nothing.', completed: 'Manual refresh completed. Available prices were saved; missing or rejected prices remain unchanged. Review saved diagnostics for coverage.', failed: 'Manual refresh failed or was partial. Check saved diagnostics. No automatic retry was queued.', disabled: 'Enable the Tickerbot API transport and configure its server key. Worker and scheduler may stay off.', cooldown: 'Wait five minutes after the previous manual operation, then use New manual selection.', busy: 'Another workspace operation is active. No request was sent for this selection.', joined: 'An existing account refresh is active. This selection was not added to it; review progress before starting a new manual selection.', running: 'The original request is still running or its outcome is uncertain. Checking again does not resend.', expired: 'The original operation expired. It will not be resent. Review saved diagnostics before a new manual selection.', budget_blocked: 'The shared monthly request budget is exhausted.', paused: 'The provider account is paused. Review the saved pause before another operation.', rate_limit: 'The provider request limit is reached. Wait before a new operation.' };
   tell(`${check ? 'Saved outcome: ' : ''}${messages[current.state] || 'Review saved diagnostics before a new manual selection.'}`, current.state !== 'completed');
   window.dispatchEvent(new CustomEvent('tgit-prices-updated', {detail:{workspace:selected}}));
  } catch (error) { if (expected === generation) tell(`${error.message} Keep this operation and check its outcome; the same key will not resend an admitted request.`, true); }
  finally { window.tgitWriteBusy = false; if (expected === generation) controls(); }
 });
 fresh.addEventListener('click', () => {
  if (window.tgitWriteBusy) return;
  if (operation?.sent && !['not_started','completed','failed','disabled','cooldown','busy','joined','expired','budget_blocked','paused','rate_limit'].includes(operation.state) && !confirm('The previous outcome is uncertain. A new operation can consume additional quota. Continue?')) return;
  operation = null; selection = new Set(); try { sessionStorage.removeItem(key()); } catch (_) {} tell('Choose assets for a new manual request.'); render();
 });
 window.addEventListener('tgit-workspace', event => {
  ++generation; workspace = event.detail.role === 'owner' ? event.detail.workspace : ''; saved = null; operation = null; selection = new Set(); status.textContent = '';
  try { const prior = JSON.parse(sessionStorage.getItem(key()) || 'null'); if (prior && /^[a-f0-9-]{36}$/.test(prior.key) && Array.isArray(prior.assets) && prior.assets.length >= 1 && prior.assets.length <= 50 && prior.assets.every(id => Number.isSafeInteger(id) && id > 0) && Number.isInteger(prior.settings) && Number.isInteger(prior.scope)) { operation = prior; selection = new Set(prior.assets); } } catch (_) {}
  window.tgitResetCollection(list); controls();
 });
 window.addEventListener('tgit-tickerbot-controls-loaded', event => { if (event.detail.workspace !== workspace) return; saved = event.detail.data; render(); });
})();
