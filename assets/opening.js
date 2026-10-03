/* Documented account starting points; financial values remain decimal strings. */
(() => {
 'use strict';
 const form = document.getElementById('tgit-opening-form');
 const section = document.getElementById('tgit-opening-section');
 const status = document.getElementById('tgit-opening-status');
 const workspaceSelect = document.getElementById('tgit-workspace');
 const config = window.tgitConfig;
 let context = null;
 let pending = null;
 const choice = (select, rows, label) => {
  select.replaceChildren();
  for (const row of rows) { const option = document.createElement('option'); option.value = row.id; option.textContent = label(row); select.append(option); }
 };
 function fields() {
  const lot = form.elements.kind.value === 'lot';
  for (const label of form.querySelectorAll('[data-opening-cash], [data-opening-lot]')) {
   const visible = lot ? label.hasAttribute('data-opening-lot') : label.hasAttribute('data-opening-cash');
   label.hidden = !visible;
   const control = label.querySelector('input,select'); control.disabled = !visible;
   control.required = visible && control.name !== 'basis_amount' && control.name !== 'basis_status';
  }
  form.elements.basis_amount.disabled = !lot || form.elements.basis_status.value === 'unresolved';
  form.elements.basis_amount.required = lot && !form.elements.basis_amount.disabled;
 }
 form.elements.kind.addEventListener('change', fields);
 form.elements.basis_status.addEventListener('change', fields);
 window.addEventListener('tgit-workspace', (event) => {
  context = event.detail; section.hidden = !['owner', 'manager'].includes(context.role);
  choice(form.elements.account_id, context.accounts, (row) => `${row.name} (${row.native_currency})`);
  choice(form.elements.asset_id, context.assets, (row) => `${row.symbol} (${row.quote_currency})`);
  fields();
 });
 form.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!context || window.tgitWriteBusy) return;
  const account = context.accounts.find((row) => String(row.id) === form.elements.account_id.value);
  if (!account) { status.textContent = 'Choose an account.'; return; }
  const kind = form.elements.kind.value;
  const body = { account_id: Number(account.id), kind, effective_date: form.elements.effective_date.value, source_note: form.elements.source_note.value.trim() };
  if (kind === 'cash') body.amount = form.elements.cash_amount.value;
  else {
   body.asset_id = Number(form.elements.asset_id.value);
   body.acquired_on = form.elements.acquired_on.value;
   body.quantity = form.elements.quantity.value;
   body.basis_status = form.elements.basis_status.value;
   if (body.basis_status === 'complete') body.amount = form.elements.basis_amount.value;
  }
  const identity = JSON.stringify({ workspace: context.workspace, body });
  if (!pending || pending.identity !== identity) pending = { identity, key: crypto.randomUUID() };
  window.tgitWriteBusy = true;
  const submit = form.querySelector('[type="submit"]'); submit.disabled = true; workspaceSelect.disabled = true;
  status.textContent = 'Recording opening balance...';
  try {
   const response = await fetch(tgitRestUrl(config.root, `workspaces/${context.workspace}/opening-balances`), { method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce, 'Idempotency-Key': pending.key }, body: JSON.stringify(body) });
   const result = await response.json();
   if (!response.ok) { const error = new Error(result.message || 'Opening balance failed.'); error.status = response.status; throw error; }
   pending = null; form.reset(); fields();
   status.textContent = 'Opening balance recorded. The account and holdings are refreshing.';
   window.dispatchEvent(new Event('tgit-ledger-refresh'));
  } catch (error) {
   if (error.status >= 400 && error.status < 500) pending = null;
   status.textContent = error.message;
  } finally { window.tgitWriteBusy = false; submit.disabled = false; workspaceSelect.disabled = false; }
 });
})();
