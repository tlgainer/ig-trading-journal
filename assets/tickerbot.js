/* Explicit owner setup and counted connection tests; no automatic provider calls. */
(() => {
 'use strict';
 const config = window.tgitConfig;
 const panel = document.getElementById('tgit-tickerbot-setup');
 const button = document.getElementById('tgit-tickerbot-reload');
 const message = document.getElementById('tgit-tickerbot-status');
 const output = document.getElementById('tgit-tickerbot-readiness');
 const form = document.getElementById('tgit-tickerbot-form'), test = document.getElementById('tgit-tickerbot-test'), separate = document.getElementById('tgit-tickerbot-new-test');
 const recovery = document.getElementById('tgit-tickerbot-recovery-form'), recover = document.getElementById('tgit-tickerbot-recover'), recoveryHelp = document.getElementById('tgit-tickerbot-recovery-help');
 let workspace = '', generation = 0, savedRevision = 0, latest = null, operation = '', baseline = '';
 const storageKey = (selected = workspace) => `tgit-tickerbot-connection:${config.actorId}:${selected}`;
 const snapshot = () => `${form.elements.monthly_cap.value}:${form.elements.interval_seconds.value}`;
 const dirty = () => baseline && snapshot() !== baseline;
 window.tgitTickerbotSetupDirty = dirty;
 function controls() {
  test.textContent = operation ? 'Check connection test outcome' : 'Test connection (one request)';
  test.disabled = window.tgitWriteBusy || !latest || (!operation && !(latest.credential_configured && latest.account_scope_configured && latest.transport_enabled && latest.saved?.enrolled));
  separate.hidden = !operation; separate.disabled = Boolean(window.tgitWriteBusy);
  recover.disabled = Boolean(window.tgitWriteBusy) || latest?.saved?.recovery_state !== 'ready' || !recovery.elements.acknowledged.checked;
  const recoveryStates = { ready: 'Review and correct the cause before acknowledging recovery.', controller_only: 'Only the controlling workspace owner can recover this shared pause.', not_paused: 'There is no saved pause to recover.', active_operation: 'An account operation is active. Wait until it finishes or its lease expires, then reload.', wait_for_provider: 'The provider wait period has not ended. Reload after the saved pause time.', legacy_review: 'This pause predates reason tracking or has unknown evidence. Trusted server-side review is required; it cannot be cleared here.' };
  recoveryHelp.textContent = recoveryStates[latest?.saved?.recovery_state] || 'Load saved setup status to review recovery.';
 }
 const onOff = (value) => value ? 'On' : 'Off';
 function render(data) {
  const rows = [
   ['Server API key', data.credential_configured ? 'Configured' : 'Missing or invalid'],
   ['Stable account reference', data.account_scope_configured ? 'Configured' : 'Missing or invalid'],
   ['Provider access', 'Reloading status does not contact the provider'],
   ['Server transport switch', onOff(data.transport_enabled)],
   ['Server worker switch', onOff(data.worker_enabled)],
   ['Server scheduler switch', onOff(data.scheduler_enabled)]
  ];
  const saved = data.saved;
  rows.push(['Workspace enrollment', saved?.enrolled ? 'Saved' : 'Not configured for this account']);
  if (saved?.enrolled) {
   if (saved.connection) rows.push(['Last connection test', `${saved.connection.state} at ${saved.connection.checked_at} UTC`], ['Test credential', saved.connection.credential_matches ? 'Matches current server key' : 'Key changed or unavailable; test again']);
   rows.push(['Recurring review', ({ off: 'Off', reviewed: 'Saved review matches current settings', needs_review: 'Owner review required before recurring work' })[saved.recurring_review] || 'Unavailable'],
    ['Refresh interval', `${saved.interval_seconds} seconds`],
    ['Next due (UTC)', saved.next_due_at || 'Not set'],
    [`Counted attempts (${saved.period}, UTC month)`, String(saved.attempts)],
    ['Shared monthly soft cap', String(saved.soft_cap)],
    ['Shared recurring projection (31-day normal cycle)', String(saved.projected_recurring_calls)],
    ['Shared pause until (UTC)', saved.paused_until || 'No saved pause']);
   rows.push(['Provider wait before recovery (UTC)', saved.recovery_not_before || 'No saved wait']);
   rows.push(['Pause cause', ({ authentication: 'Authentication rejected', capability: 'Provider access denied', rate_limit: 'Rate limit', monthly_quota: 'Monthly quota' })[saved.pause_kind] || (saved.paused_until ? 'Unknown or legacy pause' : 'Not paused')], ['Pause revision', String(saved.pause_revision ?? 0)]);
   if (saved.latest_run) rows.push(['Latest workspace run', `${saved.latest_run.state} (${saved.latest_run.cause})`], ['Run started (UTC)', saved.latest_run.started_at], ['Run completed (UTC)', saved.latest_run.completed_at || 'No completion recorded']);
   else rows.push(['Latest workspace run', 'No saved run']);
  }
  const table = document.createElement('table'); table.className = 'widefat striped';
  const caption = table.createCaption(); caption.textContent = 'Saved Tickerbot readiness';
  const body = table.createTBody();
  for (const [label, value] of rows) {
   const row = body.insertRow(), heading = document.createElement('th'); heading.scope = 'row'; heading.textContent = label;
   row.append(heading); row.insertCell().textContent = value;
  }
  output.replaceChildren(table);
  const note = document.createElement('p');
  note.textContent = 'Projections exclude retries, discovery and other applications. Actual admission limits still apply. Saved switches and due times do not prove provider access, live coverage or a working host scheduler. Current holdings pricing is unchanged.';
  output.append(note);
 }
 async function load() {
  if (!workspace) return;
  const expected = generation, selected = workspace; button.disabled = true; form.inert = true;
  message.textContent = 'Reading saved status…'; output.replaceChildren();
  try {
   const response = await fetch(tgitRestUrl(config.root, `workspaces/${selected}/tickerbot/status`), { credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce } });
   const result = await response.json(); if (expected !== generation) return;
   if (!response.ok) throw new Error(result.message || config.i18n.network);
   latest = result.data; savedRevision = latest.saved?.settings_revision || 0; recovery.reset();
   form.elements.monthly_cap.value = latest.saved?.soft_cap ?? 8000; form.elements.interval_seconds.value = latest.saved?.interval_seconds ?? 7200;
   form.elements.monthly_cap.readOnly = latest.saved?.enrolled && !latest.saved.can_manage_budget;
   document.getElementById('tgit-tickerbot-save').disabled = !latest.account_scope_configured;
   baseline = snapshot(); render(latest); window.dispatchEvent(new CustomEvent('tgit-tickerbot-status-loaded', { detail: { workspace: selected, enrolled: Boolean(latest.saved?.enrolled) } })); message.setAttribute('role', 'status'); message.textContent = 'Saved status loaded. No provider request was made.';
  } catch (error) { if (expected === generation) { message.setAttribute('role', 'alert'); message.textContent = error.message; } }
  finally { if (expected === generation) { button.disabled = false; form.inert = !latest; controls(); } }
 }
 button.addEventListener('click', () => { if (!window.tgitWriteBusy && (!dirty() || confirm('Discard unsaved Tickerbot setup changes?'))) load(); });
 window.addEventListener('tgit-tickerbot-controls-updated', (event) => { if (workspace && event.detail.workspace === workspace && !dirty()) load(); });
 window.addEventListener('tgit-tickerbot-mapping-updated', (event) => { if (workspace && event.detail.workspace === workspace && !dirty()) load(); });
 window.addEventListener('tgit-tickerbot-coverage-updated', (event) => { if (workspace && event.detail.workspace === workspace && !dirty()) load(); });
 async function command(path, body, key) {
  const response = await fetch(tgitRestUrl(config.root, `workspaces/${workspace}/tickerbot/${path}`), { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json', ...(key ? { 'Idempotency-Key': key } : {}) }, body: JSON.stringify(body) });
  const result = await response.json(); if (!response.ok) throw new Error(result.message || config.i18n.network); return result.data;
 }
 recovery.elements.acknowledged.addEventListener('change', controls);
 recovery.addEventListener('submit', async (event) => {
  event.preventDefault(); if (window.tgitWriteBusy || dirty() || latest?.saved?.recovery_state !== 'ready' || !recovery.reportValidity()) { if (dirty()) message.textContent = 'Save or reload unsaved setup changes before recovery.'; return; }
  const expected = generation, revision = latest.saved.pause_revision; window.tgitWriteBusy = true; recovery.inert = true; form.inert = true; button.disabled = true; controls();
  try {
   await command('pause-recovery', { expected_revision: revision, acknowledged: recovery.elements.acknowledged.checked });
   if (expected !== generation) return; await load(); message.setAttribute('role', 'status'); message.textContent = 'Reviewed pause cleared. Usage was not reset and no request was sent. Existing enabled work may resume; normal limits and cooldown still apply.';
  } catch (error) { if (expected === generation) { message.setAttribute('role', 'alert'); message.textContent = `${error.message} Reload saved status before any further recovery attempt.`; } }
  finally { window.tgitWriteBusy = false; if (expected === generation) { recovery.inert = false; form.inert = !latest; button.disabled = false; recovery.reset(); controls(); } }
 });
 form.addEventListener('submit', async (event) => {
  event.preventDefault(); if (window.tgitWriteBusy || !latest || !form.reportValidity()) return;
  const expected = generation; window.tgitWriteBusy = true; form.inert = true; button.disabled = true; controls();
  try {
   await command('settings', { monthly_cap: Number(form.elements.monthly_cap.value), interval_seconds: Number(form.elements.interval_seconds.value), expected_revision: savedRevision });
   if (expected !== generation) return; await load(); message.textContent = 'Local setup saved. No provider request or recurring enrollment was created.';
  } catch (error) { if (expected === generation) { message.setAttribute('role', 'alert'); message.textContent = `${error.message} Reload saved status before retrying if the save outcome is uncertain.`; } }
  finally { window.tgitWriteBusy = false; if (expected === generation) { form.inert = false; button.disabled = false; controls(); } }
 });
 test.addEventListener('click', async () => {
  if (window.tgitWriteBusy || dirty()) { if (dirty()) message.textContent = 'Save or reload local setup before testing the connection.'; return; }
  const expected = generation, selected = workspace; operation ||= crypto.randomUUID();
  try { sessionStorage.setItem(storageKey(), operation); } catch (_) { /* Same-page retry identity remains available. */ }
  window.tgitWriteBusy = true; form.inert = true; button.disabled = true; controls();
  try {
   const result = await command('connection', {}, operation); if (expected !== generation) return;
   if (!['uncertain', 'in_flight'].includes(result.state)) { operation = ''; try { sessionStorage.removeItem(storageKey(selected)); } catch (_) { /* No browser persistence. */ } }
   await load();
   const messages = { connected: 'Connection verified by the fixed AAPL probe. This does not verify coverage for your holdings.', uncertain: 'The outcome is uncertain. This operation will not resend. Check again or explicitly start a separate test; admitted requests remain counted.', authentication: 'The provider rejected authentication. Check the server key; shared requests are paused.', capability: 'The provider denied this capability. Check account access; shared requests are paused.', monthly_quota: 'Provider monthly quota exhausted. Shared requests are paused.', rate_limit: 'Rate limit reached. Respect the saved pause before another test.', cooldown: 'Wait five minutes after the previous manual operation before testing again.', busy: 'Another account operation is active. Try again after it finishes.', paused: 'Shared requests are paused. Review the saved status.', budget_blocked: 'The shared request soft cap is exhausted or zero.', disabled: 'Enable only the server transport switch to allow an explicit connection test. Keep worker and scheduler switches off.', not_enrolled: 'Save local setup before testing.' };
   message.textContent = messages[result.state] || 'Connection test failed. Any admitted attempt remains counted. No automatic retry was sent.';
   message.setAttribute('role', result.state === 'connected' ? 'status' : 'alert');
  } catch (error) { if (expected === generation) { message.setAttribute('role', 'alert'); message.textContent = `${error.message} Check the same test outcome before starting a separate test.`; } }
  finally { window.tgitWriteBusy = false; if (expected === generation) { form.inert = false; button.disabled = false; controls(); } }
 });
 separate.addEventListener('click', () => {
  if (window.tgitWriteBusy || !confirm('A separate test may use another counted request. The previous operation remains recorded and is not canceled. Continue?')) return;
  operation = ''; try { sessionStorage.removeItem(storageKey()); } catch (_) { /* Same-page identity cleared. */ } controls();
 });
 const leave = () => !window.tgitWriteBusy && (!dirty() || confirm('Discard unsaved Tickerbot setup changes?'));
 document.getElementById('tgit-workspace').addEventListener('change', (event) => { if (!leave()) { event.target.value = workspace; event.stopImmediatePropagation(); } }, true);
 document.getElementById('tgit-tabs').addEventListener('click', (event) => { const tab = event.target.closest('[data-tab]'); if (tab && tab.dataset.tab !== 'settings' && !leave()) { event.preventDefault(); event.stopImmediatePropagation(); } }, true);
 document.getElementById('tgit-tabs').addEventListener('keydown', (event) => { if (['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', 'Home', 'End'].includes(event.key) && !leave()) { event.preventDefault(); event.stopImmediatePropagation(); } }, true);
 window.addEventListener('popstate', () => { if (dirty() && !leave()) { const url = new URL(location.href); url.searchParams.set('tgit_section', 'settings'); history.pushState(null, '', url); window.tgitSelectSection('settings'); } });
 window.addEventListener('beforeunload', (event) => { if (dirty() || window.tgitWriteBusy) { event.preventDefault(); event.returnValue = ''; } });
 window.addEventListener('tgit-workspace', (event) => {
  ++generation; workspace = event.detail.role === 'owner' ? event.detail.workspace : '';
  latest = null; baseline = ''; operation = ''; savedRevision = 0; form.reset(); recovery.reset(); recovery.inert = false;
  try { const prior = sessionStorage.getItem(storageKey()); if (/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(prior || '')) operation = prior; } catch (_) { /* Ignore unavailable browser storage. */ }
  panel.hidden = !workspace; button.disabled = false; message.textContent = ''; output.replaceChildren(); controls();
  if (workspace) load();
 });
})();
