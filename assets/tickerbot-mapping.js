/* Explicit, counted identity verification; reading/changing the selection sends no provider request. */
(() => {
 'use strict';
 const config = window.tgitConfig, form = document.getElementById('tgit-tickerbot-mapping-form');
 const submit = document.getElementById('tgit-tickerbot-map-save'), separate = document.getElementById('tgit-tickerbot-map-separate');
 const message = document.getElementById('tgit-tickerbot-map-status'), output = document.getElementById('tgit-tickerbot-map-current');
 let workspace = '', generation = 0, choice = 0, expectedMapping = 0, pending = null, ready = false;
 const storageKey = () => `tgit-tickerbot-mapping:${config.actorId}:${workspace}`;
 function persist() { try { if (pending) sessionStorage.setItem(storageKey(), JSON.stringify(pending)); else sessionStorage.removeItem(storageKey()); } catch (_) { /* Same-page operation remains available. */ } }
 function controls() {
  submit.textContent = pending ? 'Check verification outcome' : 'Verify mapping (one request)';
  submit.disabled = Boolean(window.tgitWriteBusy) || !workspace || !(pending || ready);
  form.elements.asset_id.disabled = Boolean(pending || window.tgitWriteBusy);
  separate.hidden = !pending; separate.disabled = Boolean(window.tgitWriteBusy);
 }
 async function request(asset, body, key) {
  const response = await fetch(tgitRestUrl(config.root, `workspaces/${workspace}/assets/${asset}/tickerbot-mapping`), { method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce, ...(body ? { 'Content-Type': 'application/json', 'Idempotency-Key': key } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
  const result = await response.json(); if (!response.ok) throw new Error(result.message || config.i18n.network); return result.data;
 }
 async function load() {
  const asset = form.elements.asset_id.value, epoch = generation, selected = ++choice; ready = false; output.replaceChildren(); controls();
  if (!workspace || !asset) return;
  try {
   const result = await request(asset); if (epoch !== generation || selected !== choice) return;
   expectedMapping = Number(result.mapping?.id || 0);
   form.elements.provider_symbol.value = pending?.symbol || (result.asset.asset_class === 'crypto' ? `X:${result.asset.symbol}USD` : result.asset.symbol);
   const table = document.createElement('table'); table.className = 'widefat striped'; table.createCaption().textContent = 'Saved asset identity and current mapping';
   const body = table.createTBody();
   for (const [label, value] of [['Symbol', result.asset.symbol], ['Asset class', result.asset.asset_class], ['Exchange or network', result.asset.exchange], ['Quote currency', result.asset.quote_currency], ['Latest saved provider mapping', result.mapping?.provider_symbol || 'Not mapped'], ['Mapping checked (UTC)', result.mapping?.checked_at || 'Not checked']]) {
    const row = body.insertRow(), th = document.createElement('th'); th.scope = 'row'; th.textContent = label; row.append(th); row.insertCell().textContent = value;
   }
   output.replaceChildren(table); ready = true; message.setAttribute('role', 'status'); message.textContent = 'Saved mapping loaded. No provider request was made.';
  } catch (error) { if (epoch === generation && selected === choice) { message.setAttribute('role', 'alert'); message.textContent = error.message; } }
  finally { if (epoch === generation && selected === choice) controls(); }
 }
 form.elements.asset_id.addEventListener('change', () => { if (!pending && !window.tgitWriteBusy) load(); });
 form.addEventListener('submit', async (event) => {
  event.preventDefault(); if (window.tgitWriteBusy || !(ready || pending)) return;
  const epoch = generation;
  pending ||= { asset: form.elements.asset_id.value, symbol: form.elements.provider_symbol.value, expected: expectedMapping, key: crypto.randomUUID() }; persist();
  window.tgitWriteBusy = true; controls();
  try {
   const result = await request(pending.asset, { provider_symbol: pending.symbol, expected_mapping_id: pending.expected }, pending.key);
   if (epoch !== generation) return;
   if (!['uncertain', 'in_flight'].includes(result.state)) { pending = null; persist(); }
   await load();
   const messages = { mapped: 'Exact identity verified and mapping saved. No price was requested; coverage is a separate check.', ambiguous: 'No single compatible identity was found. Check the saved symbol, asset class, exchange and currency. No mapping was saved.', unsupported: 'The candidate was not found. No mapping was saved.', unsupported_identity: 'This saved identity is unsupported. Stocks and ETFs need an exact saved symbol. Crypto currently requires a USD quote identity.', uncertain: 'The verification outcome is uncertain. Checking this operation will not resend it. Any admitted request remains counted.', busy: 'Another account operation is active. Try again after it finishes.', cooldown: 'Wait five minutes after the previous manual operation before verifying.', not_enrolled: 'Save local Tickerbot setup first.', disabled: 'Enable the server transport switch for an explicit test on verified staging. Keep worker and scheduler off.', budget_blocked: 'The shared soft cap is exhausted or zero.', paused: 'Shared requests are paused. Review setup status.', rate_limit: 'The rate limit was reached. Respect the saved pause.', authentication: 'Provider authentication failed; shared requests are paused.', capability: 'Provider access was denied; shared requests are paused.', monthly_quota: 'Provider monthly quota exhausted; shared requests are paused.' };
   message.textContent = messages[result.state] || 'Verification failed. Any admitted request remains counted; no automatic retry was sent.';
   message.setAttribute('role', result.state === 'mapped' ? 'status' : 'alert');
   window.dispatchEvent(new CustomEvent('tgit-tickerbot-mapping-updated', { detail: { workspace } }));
  } catch (error) { if (epoch === generation) { message.setAttribute('role', 'alert'); message.textContent = `${error.message} Check this operation before starting a separate verification.`; } }
  finally { window.tgitWriteBusy = false; if (epoch === generation) controls(); }
 });
 separate.addEventListener('click', () => {
  if (window.tgitWriteBusy || !confirm('A separate verification may consume another request. The previous operation is retained and is not canceled. Continue?')) return;
  pending = null; persist(); load();
 });
 window.addEventListener('tgit-workspace', (event) => {
  ++generation; ++choice; workspace = event.detail.role === 'owner' ? event.detail.workspace : ''; ready = false; pending = null; expectedMapping = 0; form.reset(); output.replaceChildren(); message.textContent = '';
  const rows = event.detail.assets || []; form.elements.asset_id.replaceChildren();
  if (workspace) for (const asset of rows) { const option = document.createElement('option'); option.value = asset.id; option.textContent = `${asset.symbol} · ${asset.exchange} (${asset.quote_currency})`; form.elements.asset_id.append(option); }
  try { const saved = JSON.parse(sessionStorage.getItem(storageKey()) || 'null'); if (saved && /^[1-9][0-9]*$/.test(saved.asset) && typeof saved.symbol === 'string' && /^[A-Za-z0-9][A-Za-z0-9.:-]{0,63}$/.test(saved.symbol) && Number.isSafeInteger(saved.expected) && saved.expected >= 0 && /^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(saved.key)) { pending = saved; form.elements.asset_id.value = saved.asset; } } catch (_) { /* Ignore malformed storage. */ }
  controls(); if (workspace && form.elements.asset_id.value) load();
 });
})();
