/* Owner-only preparation controls. Saving never dispatches an AI request. */
(() => {
 'use strict';
 const config = window.tgitConfig, $ = (id) => document.getElementById(`tgit-ai-${id}`);
 const policy = $('policy'), consent = $('consent'), section = $('section');
 let workspace = '', role = '', generation = 0, saved = null, baseline = '';
 const snapshot = () => JSON.stringify([Object.fromEntries(new FormData(policy)), Object.fromEntries(new FormData(consent))]);
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
 }
 function fill() {
  if (!saved) return;
  policy.elements.monthly_cap.value = tgitDisplayDecimal(saved.monthly_cap);
  policy.elements.model.value = saved.model;
  consent.elements.enabled.value = String(saved.enrolled);
  policy.inert = !saved.can_configure;
  consent.inert = !saved.configured;
  policy.querySelector('[type=submit]').disabled = policy.inert;
  consent.querySelector('[type=submit]').disabled = consent.inert;
  summarize();
  baseline = snapshot();
 }
 async function load() {
  const expected = generation;
  const data = await request('ai-settings');
  if (expected !== generation) return;
  saved = data; fill();
 }
 async function save(form, path, body) {
  if (window.tgitWriteBusy || !saved || form.inert) return;
  const expected = generation;
  window.tgitWriteBusy = true; form.inert = true; form.querySelector('[type=submit]').disabled = true;
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
  finally { window.tgitWriteBusy = false; if (expected === generation) { policy.inert = !saved?.can_configure; consent.inert = !saved?.configured; policy.querySelector('[type=submit]').disabled = policy.inert; consent.querySelector('[type=submit]').disabled = consent.inert; } }
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
 $('reload').addEventListener('click', () => { if (leave()) { policy.inert = true; consent.inert = true; load().then(() => status('AI settings reloaded.')).catch((error) => status(error.message, true)); } });
 document.getElementById('tgit-workspace').addEventListener('change', (event) => { if (!leave()) { event.target.value = workspace; event.stopImmediatePropagation(); } }, true);
 document.getElementById('tgit-tabs').addEventListener('click', (event) => { const tab = event.target.closest('[data-tab]'); if (!tab || tab.dataset.tab === 'settings') return; if (!leave()) { event.preventDefault(); event.stopImmediatePropagation(); } else fill(); }, true);
 document.getElementById('tgit-tabs').addEventListener('keydown', (event) => { if (!['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', 'Home', 'End'].includes(event.key)) return; if (!leave()) { event.preventDefault(); event.stopImmediatePropagation(); } else fill(); }, true);
 window.addEventListener('popstate', () => { if (!dirty()) return; if (leave()) fill(); else { const url = new URL(location.href); url.searchParams.set('tgit_section', 'settings'); history.pushState(null, '', url); window.tgitSelectSection('settings'); } });
 window.addEventListener('beforeunload', (event) => { if (dirty() || window.tgitWriteBusy) { event.preventDefault(); event.returnValue = ''; } });
 window.addEventListener('tgit-workspace', (event) => {
  ++generation; ({ workspace, role } = event.detail); saved = null; baseline = ''; policy.reset(); consent.reset(); section.hidden = role !== 'owner'; policy.inert = true; consent.inert = true; $('summary').textContent = ''; status('');
  if (role === 'owner') { const expected = generation; load().catch((error) => { if (expected === generation) status(error.message, true); }); }
 });
})();
