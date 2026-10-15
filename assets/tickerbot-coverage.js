/* Explicit mapping-bound coverage metadata; no price inference or polling. */
(() => {
 'use strict';
 const config = window.tgitConfig, form = document.getElementById('tgit-tickerbot-coverage-form');
 const submit = document.getElementById('tgit-tickerbot-coverage-save'), separate = document.getElementById('tgit-tickerbot-coverage-separate');
 const message = document.getElementById('tgit-tickerbot-coverage-status'), output = document.getElementById('tgit-tickerbot-coverage-current');
 let workspace = '', generation = 0, choice = 0, mapping = 0, pending = null, ready = false;
 const storageKey = () => `tgit-tickerbot-coverage:${config.actorId}:${workspace}`;
 function persist() { try { if (pending) sessionStorage.setItem(storageKey(), JSON.stringify(pending)); else sessionStorage.removeItem(storageKey()); } catch (_) { /* Same-page retry remains available. */ } }
 function controls() {
  submit.textContent = pending ? 'Check coverage outcome' : 'Check coverage (one request)'; submit.disabled = Boolean(window.tgitWriteBusy) || !workspace || !(pending || ready);
  form.elements.asset_id.disabled = Boolean(pending || window.tgitWriteBusy); separate.hidden = !pending; separate.disabled = Boolean(window.tgitWriteBusy);
 }
 async function request(asset, body, key) {
  const response = await fetch(tgitRestUrl(config.root, `workspaces/${workspace}/assets/${asset}/tickerbot-coverage`), { method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce, ...(body ? { 'Content-Type': 'application/json', 'Idempotency-Key': key } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
  const result = await response.json(); if (!response.ok) throw new Error(result.message || config.i18n.network); return result.data;
 }
 async function load() {
  const asset = form.elements.asset_id.value, epoch = generation, selected = ++choice; ready = false; output.replaceChildren(); controls(); if (!workspace || !asset) return;
  try {
   const data = await request(asset); if (epoch !== generation || selected !== choice) return;
   mapping = Number(data.mapping?.id || 0); ready = Boolean(data.eligible && mapping > 0);
   const rows = [['Saved symbol', data.asset.symbol], ['Latest mapping', data.mapping?.provider_symbol || 'Not mapped']], receipt = data.coverage, summary = receipt?.summary;
   if (receipt) {
    rows.push(['Last coverage check', receipt.state === 'covered' ? 'Metadata received' : receipt.state], ['Checked locally (UTC)', receipt.checked_at], ['Mapping relevance', receipt.matches_current_mapping ? 'Matches current local identity and mapping' : 'Historical check; mapping or identity changed']);
    if (summary) rows.push(['Published column count', String(summary.columns)], ['Populated column count', String(summary.populated)], ['Price field', summary.price_null ? 'Listed as null' : 'Not listed as null; no quote verified'], ['Price timestamp field', summary.price_asof_null ? 'Listed as null' : 'Not listed as null; no market time verified'], ['Provider response time (UTC)', summary.as_of || 'Unknown']);
   } else rows.push(['Coverage evidence', 'No saved check']);
   const table = document.createElement('table'); table.className = 'widefat striped'; table.createCaption().textContent = 'Saved coverage metadata'; const body = table.createTBody();
   for (const [label, value] of rows) { const row = body.insertRow(), th = document.createElement('th'); th.scope = 'row'; th.textContent = label; row.append(th); row.insertCell().textContent = value; }
   output.replaceChildren(table); message.setAttribute('role', 'status'); message.textContent = ready ? 'Saved coverage loaded. No provider request was made. Metadata does not verify a usable current price.' : 'Verify the current asset mapping before requesting coverage. No provider request was made.';
  } catch (error) { if (epoch === generation && selected === choice) { message.setAttribute('role', 'alert'); message.textContent = error.message; } }
  finally { if (epoch === generation && selected === choice) controls(); }
 }
 form.elements.asset_id.addEventListener('change', () => { if (!pending && !window.tgitWriteBusy) load(); });
 form.addEventListener('submit', async (event) => {
  event.preventDefault(); if (window.tgitWriteBusy || !(ready || pending)) return;
  const epoch = generation; pending ||= { asset: form.elements.asset_id.value, mapping, key: crypto.randomUUID() }; persist(); window.tgitWriteBusy = true; controls();
  try {
   const result = await request(pending.asset, { expected_mapping_id: pending.mapping }, pending.key); if (epoch !== generation) return;
   if (!['uncertain', 'in_flight'].includes(result.state)) { pending = null; persist(); }
   await load();
   const messages = { covered: 'Coverage metadata saved. This did not fetch a price or verify current pricing.', uncertain: 'Coverage outcome is uncertain. Checking this operation will not resend it; admitted attempts remain counted.', unsupported: 'The provider does not recognize this mapped symbol. Review its mapping.', busy: 'Another account operation is active. Try again after it finishes.', cooldown: 'Wait five minutes after the previous manual operation before checking coverage.', disabled: 'Server transport is disabled. No new request was sent.', not_enrolled: 'Save local Tickerbot setup first.', paused: 'Shared requests are paused.', budget_blocked: 'The shared soft cap is exhausted or zero.', rate_limit: 'Rate limit reached. Respect the saved pause.', authentication: 'Authentication failed; shared requests are paused.', capability: 'Provider access denied; shared requests are paused.', monthly_quota: 'Provider monthly quota exhausted; shared requests are paused.', invalid_response: 'Coverage metadata was malformed or inconsistent. The attempt remains counted.' };
   message.textContent = messages[result.state] || 'Coverage request failed. Any admitted request remains counted; no automatic retry was sent.'; message.setAttribute('role', result.state === 'covered' ? 'status' : 'alert');
   window.dispatchEvent(new CustomEvent('tgit-tickerbot-coverage-updated', { detail: { workspace } }));
  } catch (error) { if (epoch === generation) { message.setAttribute('role', 'alert'); message.textContent = `${error.message} Check the same operation before starting a separate check.`; } }
  finally { window.tgitWriteBusy = false; if (epoch === generation) controls(); }
 });
 separate.addEventListener('click', () => { if (window.tgitWriteBusy || !confirm('A separate coverage check may consume another request. The previous operation remains recorded and is not canceled. Continue?')) return; pending = null; persist(); load(); });
 window.addEventListener('tgit-tickerbot-mapping-updated', (event) => { if (workspace && event.detail.workspace === workspace && !pending) load(); });
 window.addEventListener('tgit-workspace', (event) => {
  ++generation; ++choice; workspace = event.detail.role === 'owner' ? event.detail.workspace : ''; ready = false; pending = null; mapping = 0; form.reset(); output.replaceChildren(); message.textContent = ''; form.elements.asset_id.replaceChildren();
  if (workspace) for (const asset of event.detail.assets || []) { const option = document.createElement('option'); option.value = asset.id; option.textContent = `${asset.symbol} · ${asset.exchange} (${asset.quote_currency})`; form.elements.asset_id.append(option); }
  try { const saved = JSON.parse(sessionStorage.getItem(storageKey()) || 'null'); if (saved && /^[1-9][0-9]*$/.test(saved.asset) && Number.isSafeInteger(saved.mapping) && saved.mapping > 0 && /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(saved.key)) { pending = saved; form.elements.asset_id.value = saved.asset; } } catch (_) { /* Ignore malformed storage. */ }
  controls(); if (workspace && form.elements.asset_id.value) load();
 });
})();
