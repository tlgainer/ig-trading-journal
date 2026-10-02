/* Server decimal strings are authoritative; no browser financial arithmetic. */
(() => {
 'use strict';
 const config = window.tgitConfig;
 const $ = (id) => document.getElementById(`tgit-${id}`);
 const status = (message, error = false) => { $('status').textContent = message; $('status').dataset.error = String(error); $('status').setAttribute('role', error ? 'alert' : 'status'); };
 let workspaces = [], accounts = [], assets = [], workspace = '', generation = 0;
 let transactionCursor = null, holdingCursor = null;
 let pending = null, editingDraft = null;
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
 function renderTransactions(items, append) {
  cards($('transactions'), items, (row) => [`${row.effective_date} · ${row.action} · ${row.state}`, `#${row.id} · Account #${row.account_id}`, row.asset_id ? `Asset #${row.asset_id} · ${row.quantity} @ ${row.unit_price} ${row.currency} · Fees ${row.fees}` : `${row.amount} ${row.currency}`, `Realized gain: ${row.realized_gain} ${row.currency}`], append, (card, row) => {
   if (row.state !== 'draft') return;
   const role = workspaces.find((item) => String(item.id) === workspace).role;
   const canPost = ['owner', 'manager'].includes(role);
   if (!canPost && !(role === 'contributor' && Number(row.created_by) === Number(config.actorId))) return;
   const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'button'; edit.textContent = config.i18n.edit;
   edit.addEventListener('click', () => {
    const form = $('transaction-form'); form.reset(); editingDraft = { id: row.id, revision: Number(row.revision) }; pending = null;
    for (const name of ['account_id', 'action', 'effective_date', 'asset_id', 'quantity', 'unit_price', 'fees', 'amount']) if (form.elements[name]) form.elements[name].value = row[name] ?? '';
    form.elements.state.value = 'draft'; form.elements.state.disabled = true; actionFields();
    $('editing').textContent = config.i18n.editing + ' #' + row.id + ' / ' + row.revision; $('editing').hidden = false; $('cancel-edit').hidden = false;
    form.scrollIntoView({ block: 'start' }); form.elements.effective_date.focus();
   }); card.append(edit);
   if (canPost) {
    const post = document.createElement('button'); post.type = 'button'; post.className = 'button'; post.textContent = config.i18n.post;
    post.addEventListener('click', async () => {
     const target = path('transactions/' + row.id + '/post'); const identity = target + ':' + row.revision;
     if (!promotionKeys.has(identity)) promotionKeys.set(identity, crypto.randomUUID());
     post.disabled = true; $('workspace').disabled = true; status(config.i18n.loading);
     try { await request(target, { expected_revision: Number(row.revision) }, promotionKeys.get(identity)); promotionKeys.delete(identity); await refresh(); status(config.i18n.saved); }
     catch (error) { if (error.status >= 400 && error.status < 500) promotionKeys.delete(identity); status(error.message, true); }
     finally { post.disabled = false; $('workspace').disabled = false; }
    }); card.append(post);
   }
  });
 }
 function renderHoldings(items, append) {
  cards($('holdings'), items, (row) => [row.symbol, `Account #${row.account_id} · Units ${row.quantity}`, `Remaining basis: ${row.remaining_basis} ${row.currency}`, `Realized gain: ${row.realized_gain} ${row.currency}`, config.i18n.unknown], append);
 }
 function cancelEdit() {
  editingDraft = null; pending = null; const form = $('transaction-form'); form.reset(); form.elements.state.disabled = false;
  const member = workspaces.find((row) => String(row.id) === workspace);
  if (member?.role === 'contributor') form.elements.state.value = 'draft';
  $('editing').hidden = true; $('cancel-edit').hidden = true; actionFields();
 }
 $('cancel-edit').addEventListener('click', cancelEdit);
 async function refresh() {
  cancelEdit();
  const expected = ++generation; status(config.i18n.loading); pending = null;
  const member = workspaces.find((row) => String(row.id) === workspace);
  $('management').hidden = !['owner', 'manager'].includes(member.role);
  $('entry').hidden = member.role === 'viewer';
  $('members-section').hidden = member.role !== 'owner';
  $('content').hidden = true;
  const loaded = await Promise.all([all('accounts', expected), all('assets', expected)]);
  if (expected !== generation) return;
  [accounts, assets] = loaded;
  const [transactions, holdings] = await Promise.all([request(path('transactions?limit=100')), request(path('holdings?limit=100'))]);
  if (expected !== generation) return;
  cards($('accounts'), accounts, (row) => [row.name, `${row.cash_balance} ${row.native_currency}`, row.broker]);
  const form = $('transaction-form');
  choices(form.elements.account_id, accounts, (row) => `${row.name} (${row.native_currency})`);
  choices(form.elements.asset_id, assets, (row) => `${row.symbol} · ${row.exchange} (${row.quote_currency})`);
  const posted = form.elements.state.querySelector('[value="posted"]'); posted.disabled = member.role === 'contributor';
  if (member.role === 'contributor') form.elements.state.value = 'draft';
  renderTransactions(transactions.items, false); transactionCursor = transactions.next_cursor;
  renderHoldings(holdings.items, false); holdingCursor = holdings.next_cursor;
  $('more-transactions').hidden = transactionCursor === null; $('more-holdings').hidden = holdingCursor === null;
  if (member.role === 'owner') {
   const members = await request(path('members')); if (expected !== generation) return;
   cards($('members'), members.items, (row) => [`User #${row.wp_user_id}`, `${row.role} · ${row.state}`]);
  }
  actionFields(); $('content').hidden = false; status('');
 }
 async function loadWorkspaces(selected) {
  workspaces = (await request('workspaces')).items;
  choices($('workspace'), workspaces, (row) => `${row.name} · ${row.base_currency} · ${row.timezone}`);
  $('setup').hidden = !config.canCreate;
  $('workspace-label').hidden = workspaces.length === 0;
  if (!workspaces.length) { $('content').hidden = true; status(config.i18n.empty); return; }
  if (selected && workspaces.some((row) => String(row.id) === selected)) $('workspace').value = selected;
  workspace = $('workspace').value; await refresh();
 }
 function submit(id, handler) {
  const form = $(id);
  form.addEventListener('submit', async (event) => {
   event.preventDefault(); const button = form.querySelector('button[type="submit"], button:not([type])'); button.disabled = true;
   $('workspace').disabled = true; status(config.i18n.loading);
   try { await handler(form); status(config.i18n.saved); }
   catch (error) { status(error.message || config.i18n.network, true); }
   finally { button.disabled = false; $('workspace').disabled = false; }
  });
 }
 submit('workspace-form', async (form) => { const result = await request('workspaces', payload(form)); form.reset(); await loadWorkspaces(String(result.id)); });
 submit('account-form', async (form) => { await request(path('accounts'), payload(form)); form.reset(); await refresh(); });
 submit('asset-form', async (form) => { await request(path('assets'), payload(form)); form.reset(); await refresh(); });
 submit('member-form', async (form) => { const data = payload(form); data.wp_user_id = Number(data.wp_user_id); await request(path('members'), data); form.reset(); await loadWorkspaces(workspace); });
 submit('transaction-form', async (form) => {
  const data = payload(form); data.account_id = Number(data.account_id);
  const account = accounts.find((row) => Number(row.id) === data.account_id);
  if (!account) throw new Error(config.i18n.empty);
  data.currency = account.native_currency;
  if (data.asset_id) data.asset_id = Number(data.asset_id);
  if (editingDraft) data.state = 'draft';
  const target = path(editingDraft ? 'transactions/' + editingDraft.id + '/draft' : 'transactions');
  const command = editingDraft ? { expected_revision: editingDraft.revision, transaction: data } : data;
  const serialized = JSON.stringify({ target, command });
  if (!pending || pending.body !== serialized) pending = { body: serialized, key: crypto.randomUUID() };
  try { await request(target, command, pending.key); }
  catch (error) { if (error.status >= 400 && error.status < 500) pending = null; throw error; }
  pending = null; form.reset(); await refresh();
 });
 $('transaction-form').elements.action.addEventListener('change', actionFields);
 $('workspace').addEventListener('change', () => { workspace = $('workspace').value; refresh().catch((error) => status(error.message, true)); });
 for (const type of ['transactions', 'holdings']) {
  $(`more-${type}`).addEventListener('click', async () => {
   const button = $(`more-${type}`); button.disabled = true; const expected = generation;
   try {
    const cursor = type === 'transactions' ? transactionCursor : holdingCursor;
    const result = await request(path(`${type}?after=${cursor}&limit=100`)); if (expected !== generation) return;
    if (type === 'transactions') { renderTransactions(result.items, true); transactionCursor = result.next_cursor; }
    else { renderHoldings(result.items, true); holdingCursor = result.next_cursor; }
    button.hidden = result.next_cursor === null;
   } catch (error) { status(error.message, true); } finally { button.disabled = false; }
  });
 }
 loadWorkspaces().catch((error) => status(error.message, true));
})();
