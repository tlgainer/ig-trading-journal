/* Server decimal strings are authoritative; no browser financial arithmetic. */
(() => {
 'use strict';
 const config = window.tgitConfig;
 const $ = (id) => document.getElementById(`tgit-${id}`);
 const status = (message, error = false) => { $('status').textContent = message; $('status').dataset.error = String(error); $('status').setAttribute('role', error ? 'alert' : 'status'); };
 let workspaces = [], accounts = [], assets = [], workspace = '', generation = 0;
 let transactionBaseline = null, transactionRows = [];
 let pending = null, editingDraft = null, transactionViewGeneration = 0;
 const promotionKeys = new Map();
 async function request(path, body, key) {
  const response = await fetch(tgitRestUrl(config.root, path), { credentials: 'same-origin', cache: 'no-store', method: body ? 'POST' : 'GET', headers: { 'X-WP-Nonce': config.nonce, ...(body ? { 'Content-Type': 'application/json' } : {}), ...(key ? { 'Idempotency-Key': key } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
  const result = await response.json();
  if (!response.ok) { const error = new Error(result.message || config.i18n.network); error.status = response.status; throw error; }
  return result.data;
 }
 const path = (suffix) => `workspaces/${workspace}/${suffix}`;
 const payload = (form) => Object.fromEntries(new FormData(form));
 function cards(target, rows, render, append = false, decorate = () => {}) {
  if (!append) target.replaceChildren();
  if (!rows.length && !append) { target.textContent = config.i18n.empty; return; }
  for (const row of rows) {
   const card = document.createElement('article'); card.className = 'tgit-card';
   for (const [index, text] of render(row).entries()) { const line = document.createElement(index === 0 ? 'strong' : 'p'); line.textContent = text; card.append(line); }
   decorate(card, row); target.append(card);
  }
 }
 function choices(select, rows, label) {
  const current = select.value; select.replaceChildren();
  for (const row of rows) { const option = document.createElement('option'); option.value = row.id; option.textContent = label(row); select.append(option); }
  if (rows.some((row) => String(row.id) === current)) select.value = current;
 }
 function actionFields() {
  const form = $('transaction-form'); const security = ['buy', 'sell'].includes(form.elements.action.value);
  for (const label of form.querySelectorAll('[data-security], [data-cash]')) {
   const visible = label.hasAttribute('data-security') ? security : !security;
   label.hidden = !visible; const input = label.querySelector('input,select'); input.disabled = !visible; input.required = visible;
  }
 }
 async function all(suffix, expected) {
  const rows = []; let cursor = 0;
  do { const result = await request(path(`${suffix}?after=${cursor}&limit=100`)); if (expected !== generation) return []; rows.push(...result.items); cursor = result.next_cursor; } while (cursor !== null);
  return rows;
 }
 function renderTransactions(items) {
  transactionRows = items;
  const accountName = (row) => accounts.find((account) => String(account.id) === String(row.account_id))?.name || `Account #${row.account_id}`;
  const assetName = (row) => assets.find((asset) => String(asset.id) === String(row.asset_id))?.symbol || (row.asset_id ? `Asset #${row.asset_id}` : 'Cash');
  const actions = (row) => {
   const card = document.createElement('div'); card.className = 'tgit-row-actions';
   const view = document.createElement('button'); view.type = 'button'; view.className = 'button'; view.textContent = 'View transaction'; view.addEventListener('click', () => viewTransaction(row.id).catch((error) => status(error.message, true))); card.append(view);
   if (row.state !== 'draft') { const evidence = document.createElement('span'); evidence.textContent = row.corrected_by_id ? `Superseded by #${row.corrected_by_id}` : row.state === 'promoted' ? 'Promoted · source retained' : 'Posted · immutable'; card.append(evidence); return card; }
   const role = workspaces.find((item) => String(item.id) === workspace).role;
   const canPost = ['owner', 'manager'].includes(role);
   if (!canPost && !(role === 'contributor' && Number(row.created_by) === Number(config.actorId))) return card;
   const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'button'; edit.textContent = config.i18n.edit;
   edit.addEventListener('click', () => editTransaction(row.id).catch((error) => status(error.message, true))); card.append(edit);
   if (canPost) {
    const post = document.createElement('button'); post.type = 'button'; post.className = 'button'; post.textContent = config.i18n.post;
    post.addEventListener('click', async () => {
     if (window.tgitWriteBusy) return; window.tgitWriteBusy = true;
     const target = path('transactions/' + row.id + '/post'); const identity = target + ':' + row.revision;
     if (!promotionKeys.has(identity)) promotionKeys.set(identity, crypto.randomUUID());
     post.disabled = true; $('workspace').disabled = true; status(config.i18n.loading);
     try { await request(target, { expected_revision: Number(row.revision) }, promotionKeys.get(identity)); promotionKeys.delete(identity); await refresh(); status(config.i18n.saved); }
     catch (error) { if (error.status >= 400 && error.status < 500) promotionKeys.delete(identity); status(error.message, true); }
     finally { window.tgitWriteBusy = false; post.disabled = false; $('workspace').disabled = false; }
    }); card.append(post);
   }
   return card;
  };
  tgitCollection($('transactions'), { actor: config.actorId, workspace, key: 'transactions', title: 'Transactions', defaultSort: 'date', defaultDescending: true,
   search: (row) => `#${row.id} ${row.effective_date} ${row.action} ${row.state} ${accountName(row)} ${assetName(row)} ${row.currency}`,
   filters: [{ key: 'state', label: 'Transaction state', values: [['', 'All'], ['draft', 'Draft'], ['posted', 'Posted'], ['promoted', 'Promoted sources']], matches: (row, value) => !value || row.state === value }],
   columns: [
    { key: 'id', label: 'ID', identity: true, required: true, render: (row) => `#${row.id}` },
    { key: 'date', label: 'Effective date', sort: (a, b) => a.effective_date.localeCompare(b.effective_date), render: (row) => row.effective_date },
    { key: 'account', label: 'Account', sort: (a, b) => accountName(a).localeCompare(accountName(b)), render: accountName },
    { key: 'action', label: 'Action', sort: (a, b) => a.action.localeCompare(b.action), render: (row) => row.action },
    { key: 'asset', label: 'Asset', render: assetName },
    { key: 'state', label: 'State', render: (row) => row.corrected_by_id ? `${row.state} · Corrected` : row.state },
    { key: 'amount', label: 'Amount / quantity', numeric: true, render: (row) => row.action === 'opening_lot' ? `${tgitDisplayDecimal(row.quantity, 0)} units · Opening balance` : row.asset_id ? `${row.quantity} @ ${row.unit_price} ${row.currency}; fees ${row.fees}` : `${row.amount} ${row.currency}` },
    { key: 'gain', label: 'Realized gain', numeric: true, render: (row) => `${row.current_realized_gain} ${row.currency}` },
    { key: 'actions', label: 'Actions', required: true, render: actions }
   ] }, items);
 }
 function renderHoldings(items) {
  const accountName = (row) => accounts.find((account) => String(account.id) === String(row.account_id))?.name || `Account #${row.account_id}`;
  const decimal = (value, currency = '', minimum = 2) => { if (value === null || value === undefined) return 'Unavailable'; const span = document.createElement('span'); span.textContent = `${tgitDisplayDecimal(value, minimum)}${currency ? ` ${currency}` : ''}`; span.title = `${value}${currency ? ` ${currency}` : ''}`; return span; };
  tgitCollection($('holdings'), { actor: config.actorId, workspace, key: 'holdings', title: 'Holdings', defaultSort: 'symbol',
   search: (row) => `${row.symbol} ${accountName(row)} ${row.currency} ${row.basis_status} ${row.price_status}`,
   filters: [{ key: 'account', label: 'Holding account', values: [['', 'All accounts'], ...accounts.map((account) => [String(account.id), account.name])], matches: (row, value) => !value || String(row.account_id) === value }],
   columns: [
    { key: 'symbol', label: 'Asset', identity: true, required: true, sort: (a, b) => a.symbol.localeCompare(b.symbol), render: (row) => row.symbol },
    { key: 'account', label: 'Account', sort: (a, b) => accountName(a).localeCompare(accountName(b)), render: accountName },
    { key: 'quantity', label: 'Quantity', numeric: true, render: (row) => decimal(row.quantity, '', 0) },
    { key: 'basis', label: 'Remaining cost basis', numeric: true, render: (row) => row.basis_status === 'unresolved' ? 'Unresolved' : decimal(row.remaining_basis, row.currency) },
    { key: 'realized', label: 'Realized gain', numeric: true, render: (row) => decimal(row.realized_gain, row.currency) },
    { key: 'market', label: 'Market value', numeric: true, render: (row) => decimal(row.market_value, row.currency) },
    { key: 'unrealized', label: 'Unrealized gain', numeric: true, render: (row) => decimal(row.unrealized_gain, row.currency) },
    { key: 'price', label: 'Price coverage', render: (row) => row.price_status === 'missing' ? 'Missing price — enter a manual price in Reports' : `${row.price_status}: ${row.price_observation?.source || 'Manual observation'} / ${row.price_observation?.effective_date || 'Date unavailable'}` }
   ] }, items.map((row) => ({ ...row, id: `${row.account_id}:${row.asset_id}` })));
 }
 function cancelEdit() {
  transactionViewGeneration++; transactionDetail.hidden = true; transactionDetail.replaceChildren(); transactionError.textContent = ''; reloadTransaction.hidden = true;
  transactionBaseline = null; $('entry').hidden = true; $('transactions').closest('section').hidden = false;
  editingDraft = null; pending = null; const form = $('transaction-form'); form.reset(); form.elements.state.disabled = false;
  const member = workspaces.find((row) => String(row.id) === workspace);
  if (member?.role === 'contributor') form.elements.state.value = 'draft';
  $('editing').hidden = true; $('cancel-edit').hidden = true; actionFields();
 }
 const transactionForm = $('transaction-form');
 const transactionError = document.createElement('p'); transactionError.id = 'tgit-transaction-error'; transactionError.setAttribute('role', 'alert'); transactionError.setAttribute('aria-live', 'assertive'); transactionForm.before(transactionError);
 const reloadTransaction = document.createElement('button'); reloadTransaction.id = 'tgit-reload-transaction'; reloadTransaction.type = 'button'; reloadTransaction.className = 'button'; reloadTransaction.textContent = 'Reload latest transaction'; reloadTransaction.hidden = true; transactionForm.before(reloadTransaction);
 const transactionDetail = document.createElement('section'); transactionDetail.id = 'tgit-transaction-detail'; transactionDetail.hidden = true; $('panel-transactions').append(transactionDetail);
 const draftButton = transactionForm.querySelector('button[type=submit]'); draftButton.textContent = 'Save draft'; draftButton.className = 'button'; draftButton.dataset.transactionState = 'draft';
 const postButton = document.createElement('button'); postButton.type = 'submit'; postButton.className = 'button button-primary'; postButton.textContent = 'Post transaction'; postButton.dataset.transactionState = 'posted'; transactionForm.append(postButton);
 transactionForm.elements.state.closest('label').hidden = true;
 function transactionButtons() {
  const role = workspaces.find((row) => String(row.id) === workspace)?.role;
  transactionForm.elements.state.value = 'draft';
  postButton.hidden = Boolean(editingDraft) || !['owner', 'manager'].includes(role);
  draftButton.textContent = editingDraft ? 'Save draft changes' : 'Save draft';
 }
 async function editTransaction(id) {
  if (!leaveTransaction()) return;
  const loadingSnapshot = snapshot(), wasEditing = !$('entry').hidden;
  const expected = generation, editGeneration = ++transactionViewGeneration, data = await request(path(`transactions/${id}`)); if (expected !== generation || editGeneration !== transactionViewGeneration) return;
  if (wasEditing && loadingSnapshot !== snapshot()) { transactionError.textContent = 'Your input changed while loading. Reload again when ready to discard it.'; return; }
  const row = data.transaction;
  renderTransactions(transactionRows.map((item) => String(item.id) === String(row.id) ? { ...item, ...row, current_realized_gain: data.current_calculation?.realized_gain ?? row.realized_gain } : item));
  if (row.state !== 'draft') { await viewTransaction(id, true); return; }
  transactionDetail.hidden = true; transactionError.textContent = ''; reloadTransaction.hidden = true;
  transactionForm.reset(); editingDraft = { id: row.id, revision: Number(row.revision) }; pending = null;
  for (const name of ['account_id', 'action', 'effective_date', 'asset_id', 'quantity', 'unit_price', 'fees', 'amount']) transactionForm.elements[name].value = row[name] ?? '';
  transactionForm.elements.state.value = 'draft'; transactionForm.elements.state.disabled = true; actionFields(); transactionButtons();
  $('editing').textContent = `${config.i18n.editing} #${row.id} / ${row.revision}`; $('editing').hidden = false;
  openTransaction(); transactionForm.elements.effective_date.focus();
 }
 function facts(target, values) {
  const list = document.createElement('dl'); list.className = 'tgit-card';
  for (const [label, value] of values) { const term = document.createElement('dt'), description = document.createElement('dd'); term.textContent = label; description.textContent = value ?? 'Unavailable'; list.append(term, description); }
  target.append(list);
 }
 function revisionFacts(row) {
  const labels = { state: 'State', action: 'Action', effective_date: 'Effective date', account_id: 'Account ID', currency: 'Currency', ...(row.asset_id ? { asset_id: 'Asset ID', quantity: 'Quantity', unit_price: 'Unit price', fees: 'Fees' } : { amount: 'Amount' }), realized_gain: 'Original realized gain' };
  return Object.entries(labels).filter(([key]) => Object.hasOwn(row, key)).map(([key, label]) => [label, row[key]]);
 }
 async function viewTransaction(id, confirmed = false) {
  if (!confirmed && !leaveTransaction()) return;
  const loadingSnapshot = snapshot(), wasEditing = !$('entry').hidden;
  const expected = generation, viewGeneration = ++transactionViewGeneration;
  const data = await request(path(`transactions/${id}`)); if (expected !== generation || viewGeneration !== transactionViewGeneration) return;
  if (wasEditing && loadingSnapshot !== snapshot()) { transactionError.textContent = 'Your input changed while loading. Open the transaction again when ready to discard it.'; return; }
  const row = data.transaction; transactionDetail.replaceChildren(); transactionDetail.hidden = false; $('entry').hidden = true; $('transactions').closest('section').hidden = true; transactionBaseline = null;
  const heading = document.createElement('h2'); heading.textContent = `Transaction #${row.id}`; transactionDetail.append(heading);
  const back = document.createElement('button'); back.type = 'button'; back.className = 'button'; back.textContent = 'Back to transactions'; back.addEventListener('click', () => { cancelEdit(); newTransaction.focus(); }); transactionDetail.append(back);
  const explanation = document.createElement('p'); explanation.textContent = row.state === 'draft' ? 'Draft: no financial effect. Posting creates a separate immutable ledger entry.' : row.state === 'promoted' ? 'Promoted source: retained evidence of a draft that was posted.' : 'Posted history is immutable. Replayed calculations are shown separately from the original facts.'; transactionDetail.append(explanation);
  facts(transactionDetail, [['State', row.state], ['Revision', row.revision], ['Action', row.action], ['Effective date', row.effective_date], ['Account', accounts.find((account) => String(account.id) === String(row.account_id))?.name || `#${row.account_id}`], ['Asset', assets.find((asset) => String(asset.id) === String(row.asset_id))?.symbol || 'Cash'], ['Currency', row.currency], ...(row.asset_id ? [['Quantity', row.quantity], ['Unit price', row.unit_price], ['Fees', row.fees]] : [['Amount', row.amount]]), ['Original realized gain', row.realized_gain], ['Current realized gain', data.current_calculation?.realized_gain]]);
  const links = document.createElement('div'); links.className = 'tgit-actions'; transactionDetail.append(links);
  const linked = new Set();
  for (const revision of data.revisions) { const payload = JSON.parse(revision.payload); if (payload.posted_transaction_id) linked.add(String(payload.posted_transaction_id)); }
  for (const correction of data.correction_links) { for (const key of ['source_transaction_id', 'replacement_transaction_id']) if (String(correction[key]) !== String(id)) linked.add(String(correction[key])); }
  for (const related of linked) { const button = document.createElement('button'); button.type = 'button'; button.className = 'button'; button.textContent = `View related transaction #${related}`; button.addEventListener('click', () => viewTransaction(related).catch((error) => status(error.message, true))); links.append(button); }
  const history = document.createElement('div'), revisionSnapshot = document.createElement('div'); transactionDetail.append(history, revisionSnapshot);
  tgitCollection(history, { actor: config.actorId, workspace: `${workspace}:${id}`, key: 'transaction-revisions', title: 'Transaction revisions', defaultSort: 'revision', defaultDescending: true, search: (revision) => `${revision.revision} ${revision.actor_id} ${revision.reason} ${revision.created_at}`, columns: [
   { key: 'revision', label: 'Revision', identity: true, required: true, sort: (a, b) => Number(a.revision) - Number(b.revision), render: (revision) => revision.revision },
   { key: 'date', label: 'Saved at (UTC)', render: (revision) => revision.created_at },
   { key: 'author', label: 'Author ID', render: (revision) => `#${revision.actor_id}` },
   { key: 'reason', label: 'Reason', render: (revision) => revision.reason },
   { key: 'view', label: 'Evidence', required: true, render: (revision) => { const button = document.createElement('button'); button.type = 'button'; button.className = 'button'; button.textContent = 'View revision'; button.addEventListener('click', () => { revisionSnapshot.replaceChildren(); const payload = JSON.parse(revision.payload), factsRow = payload.transaction || payload.draft || payload; const title = document.createElement('h3'); title.textContent = `Transaction revision ${revision.revision}`; revisionSnapshot.append(title); facts(revisionSnapshot, revisionFacts(factsRow)); const raw = document.createElement('details'), summary = document.createElement('summary'), pre = document.createElement('pre'); summary.textContent = 'Raw revision evidence'; pre.textContent = JSON.stringify(payload, null, 2); raw.append(summary, pre); revisionSnapshot.append(raw); }); return button; } }
  ] }, data.revisions.map((revision) => ({ ...revision, id: revision.revision })));
  back.focus();
 }
 reloadTransaction.addEventListener('click', () => { if (editingDraft) editTransaction(editingDraft.id).catch((error) => { transactionError.textContent = error.message; }); });
 transactionForm.addEventListener('invalid', (event) => { transactionError.textContent = event.target.validationMessage; }, true);
 const newTransaction = document.createElement('button'); newTransaction.id = 'tgit-new-transaction'; newTransaction.type = 'button'; newTransaction.className = 'button button-primary'; newTransaction.textContent = 'New transaction';
 $('transactions').before(newTransaction); $('cancel-edit').textContent = 'Back to transactions';
 const snapshot = () => JSON.stringify(payload($('transaction-form')));
 const dirtyTransaction = () => !$('entry').hidden && transactionBaseline !== null && transactionBaseline !== snapshot();
 function leaveTransaction() { return !window.tgitWriteBusy && (!dirtyTransaction() || confirm('Discard unsaved transaction changes?')); }
 function openTransaction() { transactionViewGeneration++; transactionButtons(); $('entry').hidden = false; $('transactions').closest('section').hidden = true; $('cancel-edit').hidden = false; transactionBaseline = snapshot(); }
 newTransaction.addEventListener('click', () => { cancelEdit(); openTransaction(); $('transaction-form').elements.effective_date.focus(); });
 $('cancel-edit').addEventListener('click', () => { if (leaveTransaction()) { cancelEdit(); newTransaction.focus(); } });
 $('tabs').addEventListener('click', (event) => { const tab = event.target.closest('[data-tab]'); if (!tab || tab.dataset.tab === 'transactions') return; if (!leaveTransaction()) { event.preventDefault(); event.stopImmediatePropagation(); } else cancelEdit(); }, true);
 $('tabs').addEventListener('keydown', (event) => { if (!['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', 'Home', 'End'].includes(event.key) || !event.target.closest('[data-tab]') || $('entry').hidden) return; if (!leaveTransaction()) { event.preventDefault(); event.stopImmediatePropagation(); } else cancelEdit(); }, true);
 $('workspace').addEventListener('change', (event) => { if (!leaveTransaction()) { $('workspace').value = workspace; event.stopImmediatePropagation(); } }, true);
 window.addEventListener('beforeunload', (event) => { if (dirtyTransaction()) { event.preventDefault(); event.returnValue = ''; } });
 window.addEventListener('popstate', () => { if (!dirtyTransaction()) return; if (leaveTransaction()) cancelEdit(); else { const url = new URL(location.href); url.searchParams.set('tgit_section', 'transactions'); history.pushState(null, '', url); window.tgitSelectSection('transactions'); } });
 async function refresh() {
  cancelEdit();
  const expected = ++generation; status(config.i18n.loading); pending = null;
  const member = workspaces.find((row) => String(row.id) === workspace);
  $('management').hidden = !['owner', 'manager'].includes(member.role);
  newTransaction.hidden = member.role === 'viewer';
  $('members-section').hidden = member.role !== 'owner';
  $('content').hidden = true;
  const loaded = await Promise.all([all('accounts', expected), all('assets', expected)]);
  if (expected !== generation) return;
  [accounts, assets] = loaded;
  const [transactions, holdings] = await Promise.all([all('transactions', expected), all('holdings', expected)]);
  if (expected !== generation) return;
  tgitCollection($('accounts'), { actor: config.actorId, workspace, key: 'accounts', title: 'Cash accounts', search: (row) => `${row.name} ${row.broker} ${row.native_currency}`, columns: [
   { key: 'name', label: 'Account', identity: true, required: true, sort: (a, b) => a.name.localeCompare(b.name), render: (row) => row.name },
   { key: 'broker', label: 'Broker', sort: (a, b) => a.broker.localeCompare(b.broker), render: (row) => row.broker || 'Not set' },
   { key: 'currency', label: 'Currency', sort: (a, b) => a.native_currency.localeCompare(b.native_currency), render: (row) => row.native_currency },
   { key: 'balance', label: 'Available cash', numeric: true, render: (row) => `${tgitDisplayDecimal(row.cash_balance)} ${row.native_currency}` }
  ] }, accounts);
  const form = $('transaction-form');
  choices(form.elements.account_id, accounts, (row) => `${row.name} (${row.native_currency})`);
  choices(form.elements.asset_id, assets, (row) => `${row.symbol} · ${row.exchange} (${row.quote_currency})`);
  const posted = form.elements.state.querySelector('[value="posted"]'); posted.disabled = member.role === 'contributor';
  if (member.role === 'contributor') form.elements.state.value = 'draft';
  renderTransactions(transactions);
  renderHoldings(holdings);
  $('more-transactions').hidden = true; $('more-holdings').hidden = true;
  if (member.role === 'owner') {
   const members = await request(path('members')); if (expected !== generation) return;
   cards($('members'), members.items, (row) => [`User #${row.wp_user_id}`, `${row.role} · ${row.state}`]);
  }
  actionFields(); transactionButtons(); $('content').hidden = false; status('');
  window.dispatchEvent(new CustomEvent('tgit-workspace', { detail: { workspace, role: member.role, timezone: member.timezone, accounts, assets } }));
 }
 async function loadWorkspaces(selected) {
  workspaces = (await request('workspaces')).items;
  choices($('workspace'), workspaces, (row) => `${row.name} · ${row.base_currency} · ${row.timezone}`);
  $('setup').hidden = !config.canCreate;
  if (workspaces.length) {
   let management = $('workspace-management');
   if (!management) {
    management = document.createElement('details'); management.id = 'tgit-workspace-management';
    const summary = document.createElement('summary'); summary.textContent = 'Create another workspace';
    management.append(summary); $('panel-settings').append(management);
   }
   management.hidden = !config.canCreate; management.append($('setup'));
  } else $('workspace-label').before($('setup'));
  $('workspace-label').hidden = workspaces.length === 0;
  if (!workspaces.length) { $('content').hidden = true; status(config.i18n.empty); return; }
  if (selected && workspaces.some((row) => String(row.id) === selected)) $('workspace').value = selected;
  workspace = $('workspace').value; await refresh();
 }
 function submit(id, handler) {
  const form = $(id);
  form.addEventListener('submit', async (event) => {
   event.preventDefault(); if (window.tgitWriteBusy) return; window.tgitWriteBusy = true; const button = form.querySelector('button[type="submit"], button:not([type])'); button.disabled = true;
   $('workspace').disabled = true; status(config.i18n.loading);
   try { if (id === 'transaction-form') transactionError.textContent = ''; await handler(form, event.submitter); status(config.i18n.saved); }
   catch (error) { status(error.message || config.i18n.network, true); if (id === 'transaction-form') { transactionError.textContent = error.message + (/Insufficient cash/i.test(error.message) ? ' Save this entry as a draft, then record funding dated before the purchase, or document opening balances in Settings.' : ''); reloadTransaction.hidden = error.status !== 409 || !editingDraft; } }
   finally { window.tgitWriteBusy = false; button.disabled = false; $('workspace').disabled = false; }
  });
 }
 submit('workspace-form', async (form) => { const result = await request('workspaces', payload(form)); form.reset(); await loadWorkspaces(String(result.id)); });
 submit('account-form', async (form) => { await request(path('accounts'), payload(form)); form.reset(); await refresh(); });
 submit('asset-form', async (form) => { await request(path('assets'), payload(form)); form.reset(); await refresh(); });
 submit('member-form', async (form) => { const data = payload(form); data.wp_user_id = Number(data.wp_user_id); await request(path('members'), data); form.reset(); await loadWorkspaces(workspace); });
 submit('transaction-form', async (form, submitter) => {
  const data = payload(form); data.account_id = Number(data.account_id);
  const account = accounts.find((row) => Number(row.id) === data.account_id);
  if (!account) throw new Error(config.i18n.empty);
  data.currency = account.native_currency;
  if (data.asset_id) data.asset_id = Number(data.asset_id);
  data.state = editingDraft ? 'draft' : submitter?.dataset.transactionState || 'draft';
  const target = path(editingDraft ? 'transactions/' + editingDraft.id + '/draft' : 'transactions');
  const command = editingDraft ? { expected_revision: editingDraft.revision, transaction: data } : data;
  const serialized = JSON.stringify({ target, command });
  if (!pending || pending.body !== serialized) pending = { body: serialized, key: crypto.randomUUID() };
  try { await request(target, command, pending.key); }
  catch (error) { if (error.status >= 400 && error.status < 500) pending = null; throw error; }
  pending = null; form.reset(); await refresh();
 });
 $('transaction-form').elements.action.addEventListener('change', actionFields);
 window.addEventListener('tgit-ledger-refresh', () => refresh().catch((error) => status(error.message, true)));
 $('workspace').addEventListener('change', () => { workspace = $('workspace').value; refresh().catch((error) => status(error.message, true)); });
 loadWorkspaces(new URL(location.href).searchParams.get('tgit_workspace')).catch((error) => status(error.message, true));
})();
