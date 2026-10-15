/* Read-only owner readiness. No polling, enrollment or provider calls. */
(() => {
 'use strict';
 const config = window.tgitConfig;
 const panel = document.getElementById('tgit-tickerbot-setup');
 const button = document.getElementById('tgit-tickerbot-reload');
 const message = document.getElementById('tgit-tickerbot-status');
 const output = document.getElementById('tgit-tickerbot-readiness');
 let workspace = '', generation = 0;
 const onOff = (value) => value ? 'On' : 'Off';
 function render(data) {
  const rows = [
   ['Server API key', data.credential_configured ? 'Configured' : 'Missing or invalid'],
   ['Stable account reference', data.account_scope_configured ? 'Configured' : 'Missing or invalid'],
   ['Provider access', 'Not verified by this status check'],
   ['Server transport switch', onOff(data.transport_enabled)],
   ['Server worker switch', onOff(data.worker_enabled)],
   ['Server scheduler switch', onOff(data.scheduler_enabled)]
  ];
  const saved = data.saved;
  rows.push(['Workspace enrollment', saved?.enrolled ? 'Saved' : 'Not configured for this account']);
  if (saved?.enrolled) {
   rows.push(['Recurring review', ({ off: 'Off', reviewed: 'Saved review matches current settings', needs_review: 'Owner review required before recurring work' })[saved.recurring_review] || 'Unavailable'],
    ['Refresh interval', `${saved.interval_seconds} seconds`],
    ['Next due (UTC)', saved.next_due_at || 'Not set'],
    [`Counted attempts (${saved.period}, UTC month)`, String(saved.attempts)],
    ['Shared monthly soft cap', String(saved.soft_cap)],
    ['Shared recurring projection (31-day normal cycle)', String(saved.projected_recurring_calls)],
    ['Shared pause until (UTC)', saved.paused_until || 'No saved pause']);
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
  const expected = generation, selected = workspace; button.disabled = true;
  message.textContent = 'Reading saved status…'; output.replaceChildren();
  try {
   const response = await fetch(tgitRestUrl(config.root, `workspaces/${selected}/tickerbot/status`), { credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce } });
   const result = await response.json(); if (expected !== generation) return;
   if (!response.ok) throw new Error(result.message || config.i18n.network);
   render(result.data); message.setAttribute('role', 'status'); message.textContent = 'Saved status loaded. No provider request was made.';
  } catch (error) { if (expected === generation) { message.setAttribute('role', 'alert'); message.textContent = error.message; } }
  finally { if (expected === generation) button.disabled = false; }
 }
 button.addEventListener('click', load);
 window.addEventListener('tgit-workspace', (event) => {
  ++generation; workspace = event.detail.role === 'owner' ? event.detail.workspace : '';
  panel.hidden = !workspace; button.disabled = false; message.textContent = ''; output.replaceChildren();
  if (workspace) load();
 });
})();
