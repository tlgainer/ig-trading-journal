/* Manual observations and reproducible reports. Decimal strings stay on the server. */
(() => {
 'use strict';
 const config = window.tgitConfig, $ = (id) => document.getElementById(`tgit-${id}`);
 let workspace = '', role = '', assets = [], views = [], observations = [], cursor = null, reportCursor = null, correcting = null, generation = 0;
 const pending = new Map();
 const message = (text, error = false) => { $('report-status').textContent = text; $('report-status').setAttribute('role', error ? 'alert' : 'status'); };
 async function request(suffix, body, slot = suffix) {
  const target = `workspaces/${workspace}/${suffix}`, identity = JSON.stringify({ target, body });
  const prior = pending.get(slot), key = prior?.identity === identity ? prior.key : crypto.randomUUID();
  if (body) pending.set(slot, { identity, key });
  const response = await fetch(tgitRestUrl(config.root, target), { method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce, ...(body ? { 'Content-Type': 'application/json', 'Idempotency-Key': key } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
  const envelope = await response.json();
  if (!response.ok) { if (response.status < 500) pending.delete(slot); throw new Error(envelope.message || config.i18n.network); }
  pending.delete(slot); return envelope.data;
 }
 function options(select, rows, label, empty = null) {
  const current = select.value; select.replaceChildren();
  if (empty !== null) { const option = document.createElement('option'); option.value = ''; option.textContent = empty; select.append(option); }
  for (const row of rows) { const option = document.createElement('option'); option.value = row.id; option.textContent = label(row); select.append(option); }
  if ([...select.options].some((option) => option.value === current)) select.value = current;
 }
 const today = (timezone) => { const parts = new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date()); const part = (name) => parts.find((item) => item.type === name).value; return `${part('year')}-${part('month')}-${part('day')}`; };
 const display = (value, minimum = 2) => value == null ? 'Unavailable' : tgitDisplayDecimal(value, minimum);
 function card(target, lines) { const article = document.createElement('article'); article.className = 'tgit-card'; for (const line of lines) { const p = document.createElement('p'); p.textContent = line; article.append(p); } target.append(article); return article; }
 function observationFields() {
  const form = $('observation-form'), price = form.elements.kind.value === 'price';
  $('observation-asset-label').hidden = !price; form.elements.asset_id.disabled = !price; form.elements.asset_id.required = price;
  form.elements.currency.readOnly = price;
  if (price) form.elements.currency.value = assets.find((asset) => String(asset.id) === form.elements.asset_id.value)?.quote_currency || '';
 }
 function cancelCorrection() { correcting = null; $('observation-form').reset(); for (const name of ['kind', 'asset_id', 'effective_date']) $('observation-form').elements[name].disabled = false; $('observation-cancel').hidden = true; observationFields(); }
 function renderObservations() {
  const target = $('observations'); target.replaceChildren();
  if (!observations.length) target.textContent = config.i18n.empty;
  for (const row of observations) {
   const asset = assets.find((item) => String(item.id) === String(row.asset_id));
   const article = card(target, [`#${row.id}: ${row.kind === 'price' ? (asset?.symbol || `Asset #${row.asset_id}`) : `${row.currency} → ${row.base_currency}`} = ${display(row.value)} ${row.kind === 'price' ? row.currency : ''}`, `Effective ${row.effective_date}; valid through ${row.expires_on}; observed ${row.observed_at} UTC`, `${row.source}: ${row.reason}`, row.superseded_by ? `Superseded by #${row.superseded_by}` : 'Active observation']);
   if (['owner', 'manager'].includes(role) && !row.superseded_by) {
    const button = document.createElement('button'); button.type = 'button'; button.className = 'button'; button.textContent = 'Correct observation';
    button.addEventListener('click', () => { cancelCorrection(); correcting = row; const form = $('observation-form'); for (const name of ['kind', 'asset_id', 'currency', 'value', 'effective_date', 'expires_on', 'source']) form.elements[name].value = row[name] ?? ''; observationFields(); for (const name of ['kind', 'asset_id', 'effective_date']) form.elements[name].disabled = true; form.elements.reason.value = ''; $('observation-cancel').hidden = false; form.scrollIntoView({ block: 'start' }); }); article.append(button);
   }
  }
  $('observations-more').hidden = cursor === null;
 }
 function tradeFilters() {
  const form = $('report-form'), positions = ['holdings', 'allocation'].includes(form.elements.type.value);
  $('report-trade-filters').hidden = positions;
  for (const name of ['action', 'strategy_version_id', 'tags', 'images']) form.elements[name].disabled = positions;
  form.elements.action.disabled = positions || form.elements.type.value === 'strategy';
 }
 function filters() {
  const result = Object.fromEntries([...new FormData($('report-form'))].filter(([, value]) => value !== ''));
  for (const key of ['account_id', 'asset_id', 'strategy_version_id']) if (result[key]) result[key] = Number(result[key]);
  if (result.tags) result.tags = result.tags.split(',').map((tag) => tag.trim()).filter(Boolean);
  return result;
 }
 function renderReport(report) {
  $('report-summary').replaceChildren(); $('report-results').replaceChildren();
  const coverage = report.coverage;
  card($('report-summary'), [`Saved report #${report.id}: ${report.filters.type}; as-of ${report.as_of} (${report.timezone})`, `Base: ${report.base_currency}; equity: ${display(report.base_equity)}; period ledger realized gain: ${display(report.base_realized_gain)}`, `Economic gain: ${display(report.economic_gain?.amount)} (${(report.economic_gain?.status || 'Unavailable').replaceAll('_', ' ')})`, `Coverage: ${coverage.missing_prices} missing prices, ${coverage.stale_prices} stale prices, ${coverage.missing_fx} missing FX references, ${coverage.stale_fx} stale FX references; ${coverage.unresolved_basis} unresolved positions. Not reconciled.`, `Calculation ${report.calculation_version}; generated ${report.generated_at}`, `Period: ${report.filters.from} through ${report.as_of}`, report.valuation_scope || '', report.allocation_denominator, ...report.notes]);
  $('report-load-form').elements.report_id.value = report.id;
  if (!report.items.length) card($('report-results'), [report.filters.type === 'income' ? 'Income unavailable: dividend and interest posting are not implemented.' : config.i18n.empty]);
  for (const row of report.items) {
   let lines;
   if (['holdings', 'allocation'].includes(report.filters.type)) {
    const quote = row.price.observation;
    lines = [`${row.symbol} · account #${row.account_id} · ${row.currency}`, `Units: ${display(row.quantity, 0)}`, `Market value: ${display(row.market_value)} ${row.currency} / ${display(row.base_value)} ${report.base_currency}`, `Remaining basis: ${display(row.remaining_basis)} ${row.currency} / ${display(row.base_basis)} ${report.base_currency}`, `Unrealized gain: ${display(row.unrealized_gain)} ${row.currency} / ${display(row.base_unrealized_gain)} ${report.base_currency}`, `Unrealized return: ${display(row.unrealized_return_percent)}%; allocation including cash: ${display(row.allocation_percent)}%`, `Realized gain through as-of: ${display(row.realized_gain)} ${row.currency}; within period: ${display(row.period_realized_gain)} ${row.currency}`, `Lifetime purchase spend: ${display(row.lifetime_purchase_spend)} ${row.currency}; income unavailable`, quote ? `${row.price.status} price: ${display(quote.value)} ${row.currency}, ${quote.source}, effective ${quote.effective_date}, observed ${quote.observed_at} UTC` : 'Missing price.'];
   } else if (report.filters.type === 'strategy') {
    lines = [`Strategy version: ${row.strategy_version_id} · ${row.currency}`, `Closed trades: ${row.closed_trades}; wins: ${row.wins}; losses: ${row.losses}; break-even: ${row.break_even}`, `Win rate: ${display(row.win_rate_percent)}%`, `Realized gain: ${display(row.realized_gain)} ${row.currency} / ${display(row.base_realized_gain)} ${report.base_currency}`, row.policy];
   } else {
    lines = [`${row.effective_date}: ${row.action} · ${row.symbol || 'Cash'} · account #${row.account_id}`, `Cash effect: ${display(row.cash_delta)} ${row.currency} / ${display(row.base_cash_delta)} ${report.base_currency}`];
    if (['buy', 'sell'].includes(row.action)) lines.push(`Units: ${display(row.quantity, 0)}; unit price: ${display(row.unit_price)} ${row.currency}; fees: ${display(row.fees)} ${row.currency}`);
    if (row.action === 'sell') lines.push(`FIFO realized gain: ${display(row.realized_gain)} ${row.currency} / ${display(row.base_realized_gain)} ${report.base_currency}`);
   }
   if (row.fx) lines.push(row.fx.observation ? `${row.fx.status} FX: 1 ${row.currency} = ${display(row.fx.rate)} ${report.base_currency}; ${row.fx.observation.source}, effective ${row.fx.observation.effective_date}` : (row.fx.status === 'missing' ? 'Missing native-to-base FX.' : 'Native currency equals base currency; FX rate is 1.'));
   card($('report-results'), lines);
  }
 }
 async function refresh() {
  const expected = ++generation;
  const [prices, saved, strategies, history] = await Promise.all([request('observations?after=0&limit=100'), request('saved-views'), request('strategies?after=0&limit=100'), request('reports?after=0&limit=100')]);
  if (expected !== generation) return;
  observations = prices.items; cursor = prices.next_cursor; views = saved.items;
  let viewAfter = saved.next_cursor;
  while (viewAfter !== null) { const page = await request(`saved-views?after=${viewAfter}&limit=100`); if (expected !== generation) return; views.push(...page.items); viewAfter = page.next_cursor; }
  reportCursor = history.next_cursor; renderHistory(history.items, false);
  options($('view-form').elements.view_id, views, (row) => row.name, 'New view');
  // Include every immutable version of each strategy, not only its latest version.
  const versions = [];
  let strategyAfter = strategies.next_cursor;
  while (strategyAfter !== null) { const page = await request(`strategies?after=${strategyAfter}&limit=100`); if (expected !== generation) return; strategies.items.push(...page.items); strategyAfter = page.next_cursor; }
  for (const strategy of strategies.items) { const detail = await request(`strategies/${strategy.id}`); if (expected !== generation) return; for (const version of detail.versions) versions.push({ id: version.id, name: `${strategy.name} / version ${version.revision}` }); }
  options($('report-form').elements.strategy_version_id, versions, (row) => row.name, 'All');
  renderObservations(); message('');
 }
 function renderHistory(rows, append) {
  if (!append) $('report-history').replaceChildren();
  for (const row of rows) { const option = document.createElement('option'); option.value = row.id; option.label = `${row.report_type} / ${row.as_of} / ${row.created_at} UTC`; $('report-history').append(option); }
  $('reports-more').hidden = reportCursor === null;
 }
 function bind(id, action) {
  $(id).addEventListener('submit', async (event) => {
   event.preventDefault(); if (window.tgitWriteBusy) return;
   window.tgitWriteBusy = true; $('workspace').disabled = true; message(config.i18n.loading);
   const buttons = [...$('reports-section').querySelectorAll('button')].map((button) => [button, button.disabled]);
   for (const [button] of buttons) button.disabled = true;
   try { await action(event.currentTarget); message(config.i18n.saved); }
   catch (error) { message(error.message, true); }
   finally { window.tgitWriteBusy = false; $('workspace').disabled = false; for (const [button, disabled] of buttons) button.disabled = disabled; }
  });
 }
 bind('observation-form', async (form) => {
  const data = Object.fromEntries(new FormData(form));
  if (correcting) Object.assign(data, { kind: correcting.kind, asset_id: Number(correcting.asset_id), effective_date: correcting.effective_date, supersedes_id: Number(correcting.id) });
  if (data.kind === 'price') data.asset_id = Number(data.asset_id); else delete data.asset_id;
  await request('observations', data, 'observation'); cancelCorrection(); await refresh(); window.dispatchEvent(new Event('tgit-ledger-refresh'));
 });
 bind('report-form', async () => { renderReport(await request('reports', filters(), 'report')); await refresh(); });
 bind('view-form', async (form) => {
  const data = { name: form.elements.name.value, filters: filters() }, prior = views.find((view) => String(view.id) === form.elements.view_id.value);
  if (prior) Object.assign(data, { view_id: Number(prior.id), expected_revision: Number(prior.revision) });
  const saved = await request('saved-views', data, 'view'); await refresh(); form.elements.view_id.value = saved.id;
 });
 bind('report-load-form', async (form) => renderReport(await request(`reports/${form.elements.report_id.value}`)));
 $('observation-form').elements.kind.addEventListener('change', observationFields);
 $('observation-form').elements.asset_id.addEventListener('change', observationFields);
 $('observation-cancel').addEventListener('click', cancelCorrection);
 $('report-form').elements.type.addEventListener('change', tradeFilters);
 $('view-form').elements.view_id.addEventListener('change', (event) => {
  const saved = views.find((view) => String(view.id) === event.target.value);
  if (!saved) { $('view-form').elements.name.value = ''; return; }
  const form = $('report-form'); form.reset(); const input = JSON.parse(saved.payload);
  for (const [key, value] of Object.entries(input)) if (form.elements[key]) form.elements[key].value = Array.isArray(value) ? value.join(', ') : value;
  $('view-form').elements.name.value = saved.name; tradeFilters();
 });
 $('observations-more').addEventListener('click', async () => { const expected = generation; try { const more = await request(`observations?after=${cursor}&limit=100`); if (expected !== generation) return; observations.push(...more.items); cursor = more.next_cursor; renderObservations(); } catch (error) { message(error.message, true); } });
 $('reports-more').addEventListener('click', async () => { const expected = generation; try { const more = await request(`reports?after=${reportCursor}&limit=100`); if (expected !== generation) return; reportCursor = more.next_cursor; renderHistory(more.items, true); } catch (error) { message(error.message, true); } });
 window.addEventListener('tgit-workspace', (event) => {
  const changed = workspace !== event.detail.workspace; ({ workspace, role, assets } = event.detail); ++generation;
  options($('observation-form').elements.asset_id, assets, (row) => `${row.symbol} · ${row.exchange} (${row.quote_currency})`);
  options($('report-form').elements.account_id, event.detail.accounts, (row) => `${row.name} (${row.native_currency})`, 'All');
  options($('report-form').elements.asset_id, assets, (row) => `${row.symbol} · ${row.exchange}`, 'All');
  $('observation-editor').hidden = !['owner', 'manager'].includes(role);
  if (changed) { cancelCorrection(); $('report-form').reset(); $('view-form').reset(); $('report-summary').replaceChildren(); $('report-results').replaceChildren(); const date = today(event.detail.timezone || 'America/New_York'); $('report-form').elements.as_of.value = date; $('observation-form').elements.effective_date.value = date; $('observation-form').elements.expires_on.value = date; }
  observationFields(); tradeFilters(); refresh().catch((error) => message(error.message, true));
 });
})();
