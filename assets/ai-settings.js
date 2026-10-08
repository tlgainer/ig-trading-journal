/* Owner-only preparation controls. Saving never dispatches an AI request. */
(() => {
 'use strict';
 const config = window.tgitConfig, $ = (id) => document.getElementById(`tgit-ai-${id}`);
 const policy = $('policy'), consent = $('consent'), section = $('section');
 let workspace = '', role = '', generation = 0, saved = null, baseline = '', historyGeneration = 0, historyLoading = false, historyLoaded = false;
 const values = (form) => Object.fromEntries([...form.elements].filter((element) => element.name).map((element) => [element.name, element.value]));
 const snapshot = () => JSON.stringify([values(policy), values(consent)]);
 const lock = (form, blocked) => { form.inert = blocked; for (const element of form.querySelectorAll('input, select, textarea, button')) element.disabled = blocked; };
 const dirty = () => role === 'owner' && baseline && snapshot() !== baseline;
 const status = (message, error = false) => { $('status').textContent = message; $('status').setAttribute('role', error ? 'alert' : 'status'); };
 async function request(path, body) {
  const response = await fetch(tgitRestUrl(config.root, `workspaces/${workspace}/${path}`), { method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce, ...(body ? { 'Content-Type': 'application/json' } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
  const result = await response.json();
  if (!response.ok) throw new Error(result.message || config.i18n.network);
  return result.data;
 }
 function summarize() {
  $('summary').textContent = `${saved.can_configure ? 'This workspace controls the shared budget.' : 'The shared budget is controlled in its original workspace.'} Processing is disabled. Spent: ${tgitDisplayDecimal(saved.spent)} USD. Reserved: ${tgitDisplayDecimal(saved.reserved)} USD. Remaining: ${tgitDisplayDecimal(saved.remaining)} USD. Budget period: ${saved.period}. Resets: ${saved.resets_at} UTC (${saved.timezone}).${saved.warning !== 'none' ? ` Budget warning: ${saved.warning.replaceAll('_', ' ')}.` : ''}`;
  const readiness = saved.readiness || {};
  $('readiness').replaceChildren();
  for (const text of [
   `Server key: ${readiness.credential_configured ? 'configured' : 'missing or invalid'}.`,
   `Saved model: ${saved.model || 'not selected'}. Model and pricing evidence: ${readiness.model_evidence_current ? 'current reviewed records' : 'missing, invalid or expired'}.`,
   `Counting access and cost evidence: ${readiness.count_evidence_current ? 'current reviewed records' : 'missing, invalid or expired'}.`,
   `Server processing switch: ${readiness.server_enabled ? 'on; keep off until generation controls are ready' : 'off'}.`,
   `Workspace consent: ${saved.enrolled ? 'saved as Yes' : 'saved as No'}. Budget: ${saved.warning === 'paused' ? 'paused at zero' : 'configured; allowance is checked for each request'}.`,
   'Summary generation controls: unavailable.'
  ]) { const item = document.createElement('li'); item.textContent = text; $('readiness').append(item); }
 }
 const activityStatus = (message, error = false) => { $('activity-status').textContent = message; $('activity-status').setAttribute('role', error ? 'alert' : 'status'); };
 async function cancelUnsent(row, button) {
  if (role !== 'owner' || !row.can_cancel || historyLoading || window.tgitWriteBusy) return;
  const expected = generation;
  window.tgitWriteBusy = true; button.disabled = true;
  activityStatus(`Releasing the unsent reservation for request #${row.id}…`);
  try {
   await request(`ai-requests/${row.id}/cancel`, {});
   if (expected !== generation) return;
   const current = await request('ai-settings');
   if (expected !== generation) return;
   for (const key of ['spent', 'reserved', 'remaining', 'period', 'resets_at', 'timezone', 'warning']) saved[key] = current[key];
   summarize();
   await loadHistory(true);
   if (expected === generation) activityStatus(`Request #${row.id} cancelled. Its unused reservation was released. No AI request was sent. Unsaved settings are preserved.`);
  } catch (error) { if (expected === generation) { row.can_cancel = false; activityStatus(`${error.message} Reload saved requests before retrying; the reservation may already have been released.`, true); } }
  finally { window.tgitWriteBusy = false; }
 }
 async function publishReview(row, button) {
  if (role !== 'owner' || !row.can_publish || historyLoading || window.tgitWriteBusy) return;
  const expected = generation;
  window.tgitWriteBusy = true; button.disabled = true;
  activityStatus(`Saving the completed response for request #${row.id} as a review…`);
  try {
   const review = await request(`ai-requests/${row.id}/publish`, {});
   if (expected !== generation) return;
   await loadHistory(true);
   if (expected === generation) activityStatus(`AI review #${review.id} saved. Read it in Research > Stock fundamentals > Saved AI reviews. No AI request was sent.`);
  } catch (error) { if (expected === generation) { row.can_publish = false; activityStatus(`${error.message} Reload saved requests before retrying; the review may already have been saved. No delivery will be retried.`, true); } }
  finally { window.tgitWriteBusy = false; }
 }
 async function loadHistory(force = false) {
  if (role !== 'owner' || historyLoading || (window.tgitWriteBusy && force !== true)) return;
  const expected = generation, version = ++historyGeneration;
  historyLoading = true; historyLoaded = false; $('activity-reload').disabled = true; tgitResetCollection($('activity-history')); activityStatus('Loading all saved request pages for this workspace…');
  try {
   const rows = []; let after = 0;
   do { const page = await request(`ai-requests?after=${after}&limit=100`); if (expected !== generation || version !== historyGeneration) return; rows.push(...page.items); after = page.next_cursor; } while (after !== null);
   const labels = { reserved: 'Reserved — not sent', dispatched: 'Delivery claimed', uncertain: 'Delivery uncertain', settled: 'Usage settled', overrun: 'Cost overrun', cancelled: 'Cancelled' };
   tgitCollection($('activity-history'), { actor: config.actorId, workspace, key: 'ai-request-history', title: 'Saved AI requests', search: (row) => `Request ${row.id} Evidence ${row.approval_id || ''} ${row.model} ${row.state} ${row.created_at}`, columns: [
    { key: 'id', label: 'Request', identity: true, required: true, render: (row) => `#${row.id}` },
    { key: 'created_at', label: 'Created (UTC)', render: (row) => row.created_at },
    { key: 'model', label: 'Captured model', render: (row) => row.model },
    { key: 'approval_id', label: 'Evidence approval', render: (row) => row.approval_id === null ? 'Legacy — unbound' : `#${row.approval_id}` },
    { key: 'state', label: 'State', render: (row) => labels[row.state] || row.state.replaceAll('_', ' ') },
    { key: 'maximum_cost', label: 'Original cost bound', render: (row) => `${tgitDisplayDecimal(row.maximum_cost)} USD` },
    { key: 'charge', label: 'Settled usage estimate', render: (row) => row.state === 'cancelled' ? 'Not charged — cancelled' : (row.charge === null ? 'Not settled' : `${tgitDisplayDecimal(row.charge)} USD`) },
    { key: 'actions', label: 'Actions', required: true, render: (row) => {
     if (row.review_id !== null) return `Saved review #${row.review_id}`;
     if (row.can_cancel) { const button = document.createElement('button'); button.type = 'button'; button.className = 'button'; button.textContent = 'Cancel unsent request'; button.setAttribute('aria-label', `Cancel unsent request ${row.id}`); button.addEventListener('click', () => cancelUnsent(row, button)); return button; }
     if (!row.can_publish) return 'Not available';
     const button = document.createElement('button'); button.type = 'button'; button.className = 'button'; button.textContent = 'Save as review'; button.setAttribute('aria-label', `Save response for request ${row.id} as a review`); button.addEventListener('click', () => publishReview(row, button)); return button;
    } }
   ] }, rows);
   historyLoaded = true; activityStatus(rows.length ? 'All saved request pages loaded. Search covers this workspace’s request metadata only. No AI request was sent.' : 'No saved AI requests yet. No AI request was sent.');
  } catch (error) { if (expected === generation && version === historyGeneration) { tgitResetCollection($('activity-history')); activityStatus(error.message, true); } }
  finally { if (expected === generation && version === historyGeneration) { historyLoading = false; $('activity-reload').disabled = false; } }
 }
 $('activity').addEventListener('toggle', () => { if ($('activity').open && !historyLoaded) loadHistory(); });
 $('activity-reload').addEventListener('click', loadHistory);
 function fill() {
  if (!saved) return;
  policy.elements.monthly_cap.value = tgitDisplayDecimal(saved.monthly_cap);
  policy.elements.model.value = saved.model;
  consent.elements.enabled.value = String(saved.enrolled);
  lock(policy, !saved.can_configure);
  lock(consent, !saved.configured);
  policy.querySelector('[type=submit]').disabled = policy.inert;
  consent.querySelector('[type=submit]').disabled = consent.inert;
  summarize();
  baseline = snapshot();
 }
 async function load() {
  const expected = generation;
  $('readiness').replaceChildren();
  const data = await request('ai-settings');
  if (expected !== generation) return;
  saved = data; fill();
 }
 async function save(form, path, body) {
  if (window.tgitWriteBusy || !saved || form.inert) return;
  const expected = generation;
  window.tgitWriteBusy = true; lock(form, true); form.querySelector('[type=submit]').disabled = true;
  try {
   const result = await request(path, body);
   if (expected !== generation) return;
   saved = result;
   // Preserve unsaved changes in the other form.
   if (form === policy) {
    policy.elements.monthly_cap.value = tgitDisplayDecimal(saved.monthly_cap);
    policy.elements.model.value = saved.model;
   }
   const old = baseline ? JSON.parse(baseline) : [];
   const current = JSON.parse(snapshot()); old[form === policy ? 0 : 1] = current[form === policy ? 0 : 1]; baseline = JSON.stringify(old);
   summarize();
   status('Settings saved. No AI request was sent.');
  } catch (error) { if (expected === generation) status(`${error.message} Reload AI settings before retrying if the save outcome is uncertain.`, true); }
  finally { window.tgitWriteBusy = false; if (expected === generation) { lock(policy, !saved?.can_configure); lock(consent, !saved?.configured); policy.querySelector('[type=submit]').disabled = policy.inert; consent.querySelector('[type=submit]').disabled = consent.inert; } }
 }
 policy.addEventListener('submit', (event) => {
  event.preventDefault();
  save(policy, 'ai-settings', { enabled: false, monthly_cap: policy.elements.monthly_cap.value, model: policy.elements.model.value, expected_config_id: saved?.config_id || 0 });
 });
 consent.addEventListener('submit', (event) => {
  event.preventDefault();
  save(consent, 'ai-enrollment', { enabled: consent.elements.enabled.value === 'true', expected_enrollment_id: saved?.enrollment_id || 0 });
 });
 const leave = () => !window.tgitWriteBusy && (!dirty() || confirm('Discard unsaved AI settings?'));
 $('reload').addEventListener('click', () => { if (leave()) { lock(policy, true); lock(consent, true); load().then(() => status('AI settings reloaded.')).catch((error) => status(error.message, true)); } });
 document.getElementById('tgit-workspace').addEventListener('change', (event) => { if (!leave()) { event.target.value = workspace; event.stopImmediatePropagation(); } }, true);
 document.getElementById('tgit-tabs').addEventListener('click', (event) => { const tab = event.target.closest('[data-tab]'); if (!tab || tab.dataset.tab === 'settings') return; if (!leave()) { event.preventDefault(); event.stopImmediatePropagation(); } else fill(); }, true);
 document.getElementById('tgit-tabs').addEventListener('keydown', (event) => { if (!['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', 'Home', 'End'].includes(event.key)) return; if (!leave()) { event.preventDefault(); event.stopImmediatePropagation(); } else fill(); }, true);
 window.addEventListener('popstate', () => { if (!dirty()) return; if (leave()) fill(); else { const url = new URL(location.href); url.searchParams.set('tgit_section', 'settings'); history.pushState(null, '', url); window.tgitSelectSection('settings'); } });
 window.addEventListener('beforeunload', (event) => { if (dirty() || window.tgitWriteBusy) { event.preventDefault(); event.returnValue = ''; } });
 window.addEventListener('tgit-workspace', (event) => {
  ++generation; ++historyGeneration; ({ workspace, role } = event.detail); saved = null; baseline = ''; historyLoading = false; historyLoaded = false; $('activity').open = false; $('activity-reload').disabled = false; tgitResetCollection($('activity-history')); activityStatus(''); $('readiness').replaceChildren(); policy.reset(); consent.reset(); section.hidden = role !== 'owner'; lock(policy, true); lock(consent, true); $('summary').textContent = ''; status('');
  if (role === 'owner') { const expected = generation; load().catch((error) => { if (expected === generation) status(error.message, true); }); }
 });
})();
