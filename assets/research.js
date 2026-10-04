/* Manual watchlists and authored notes; every request stays in the selected workspace. */
(() => {
 'use strict';
 const config = window.tgitConfig;
 const $ = (id) => document.getElementById(`tgit-${id}`);
 let workspace = '', role = '', assets = [], lists = [], items = [], notes = [], generation = 0;
 let editingItem = null, editingNote = null;
 const pending = new Map();
 const path = (suffix) => `workspaces/${workspace}/${suffix}`;
 const status = (message, error = false) => { $('research-status').textContent = message; $('research-status').setAttribute('role', error ? 'alert' : 'status'); };
 async function request(suffix, body, slot) {
  const url = tgitRestUrl(config.root, path(suffix));
  const identity = body ? JSON.stringify({ suffix, body }) : '';
  const prior = pending.get(slot);
  const key = body ? (prior?.identity === identity ? prior.key : crypto.randomUUID()) : '';
  if (body) pending.set(slot, { identity, key });
  const response = await fetch(url, { method: body ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce, ...(body ? { 'Content-Type': 'application/json', 'Idempotency-Key': key } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
  const envelope = await response.json();
  if (!response.ok) { if (response.status < 500) pending.delete(slot); throw new Error(envelope.message || config.i18n.network); }
  pending.delete(slot); return envelope.data;
 }
 async function all(suffix) {
  const rows = []; let after = 0;
  do { const result = await request(`${suffix}${suffix.includes('?') ? '&' : '?'}after=${after}&limit=100`); rows.push(...result.items); after = result.next_cursor; } while (after !== null);
  return rows;
 }
 function options(select, rows, label) {
  const selected = select.value; select.replaceChildren();
  for (const row of rows) { const option = document.createElement('option'); option.value = row.id; option.textContent = label(row); select.append(option); }
  if (rows.some((row) => String(row.id) === selected)) select.value = selected;
 }
 function card(target, lines, edit) {
  const article = document.createElement('article'); article.className = 'tgit-card';
  for (const [index, line] of lines.entries()) { const node = document.createElement(index === 0 ? 'strong' : 'p'); node.textContent = line; article.append(node); }
  if (edit && role !== 'viewer') { const button = document.createElement('button'); button.type = 'button'; button.className = 'button'; button.textContent = config.i18n.editResearch; button.addEventListener('click', edit); article.append(button); }
  target.append(article);
 }
 function clearItem() { editingItem = null; $('watchlist-item-form').reset(); $('watchlist-item-form').elements.watchlist_id.disabled = false; $('watchlist-item-form').elements.asset_id.disabled = false; $('watchlist-cancel').hidden = true; }
 function clearNote() { editingNote = null; $('research-note-form').reset(); $('research-reason-label').hidden = true; $('research-note-form').elements.reason.required = false; $('research-cancel').hidden = true; }
 function renderItems() {
  const target = $('watchlist-items'); target.replaceChildren();
  if (!items.length) { target.textContent = config.i18n.empty; return; }
  for (const item of items) card(target, [item.symbol + ' · ' + item.exchange, `Your status: ${item.status}`, `Targets: ${item.target_buy ?? '—'} / ${item.target_sell ?? '—'} ${item.quote_currency}`, item.thesis, `Tags: ${item.tags.join(', ')}`], () => {
   editingItem = item; const form = $('watchlist-item-form'); form.elements.watchlist_id.value = item.watchlist_id; form.elements.asset_id.value = item.asset_id; form.elements.watchlist_id.disabled = true; form.elements.asset_id.disabled = true;
   for (const field of ['target_buy', 'target_sell', 'status', 'thesis']) form.elements[field].value = item[field] ?? '';
   form.elements.tags.value = item.tags.join(', '); $('watchlist-cancel').hidden = false; form.scrollIntoView({ block: 'start' });
  });
 }
 function renderNotes() {
  const target = $('research-notes'); target.replaceChildren();
  if (!notes.length) { target.textContent = config.i18n.empty; return; }
  for (const note of notes) card(target, [note.symbol + ' · ' + note.exchange, `Revision ${note.revision}`, note.content], () => {
   editingNote = note; const form = $('research-note-form'); form.elements.asset_id.value = note.asset_id; form.elements.content.value = note.content;
   form.elements.reason.value = ''; form.elements.reason.required = true; $('research-reason-label').hidden = false; $('research-cancel').hidden = false; form.scrollIntoView({ block: 'start' });
  });
 }
 async function refresh() {
  if (!workspace) return; const expected = ++generation;
  const loaded = await Promise.all([all('watchlists'), all('research-notes')]);
  if (expected !== generation) return; [lists, notes] = loaded;
  options($('watchlist-item-form').elements.watchlist_id, lists, (row) => row.name);
  options($('watchlist-item-form').elements.asset_id, assets, (row) => `${row.symbol} · ${row.exchange}`);
  options($('research-note-form').elements.asset_id, assets, (row) => `${row.symbol} · ${row.exchange}`);
  const listId = $('watchlist-item-form').elements.watchlist_id.value;
  items = listId ? await all(`watchlists/${listId}/items`) : [];
  if (expected !== generation) return; renderItems(); renderNotes(); status('');
 }
 $('watchlist-item-form').elements.watchlist_id.addEventListener('change', async () => {
  const listId = $('watchlist-item-form').elements.watchlist_id.value; clearItem(); $('watchlist-item-form').elements.watchlist_id.value = listId;
  try { items = listId ? await all(`watchlists/${listId}/items`) : []; renderItems(); } catch (error) { status(error.message, true); }
 });
 $('watchlist-cancel').addEventListener('click', clearItem);
 $('research-cancel').addEventListener('click', clearNote);
 $('watchlist-form').addEventListener('submit', async (event) => {
  event.preventDefault(); const form = event.currentTarget; const button = form.querySelector('button'); button.disabled = true;
  try { await request('watchlists', { name: form.elements.name.value }, 'watchlist'); form.reset(); await refresh(); status(config.i18n.saved); }
  catch (error) { status(error.message, true); } finally { button.disabled = false; }
 });
 $('watchlist-item-form').addEventListener('submit', async (event) => {
  event.preventDefault(); const form = event.currentTarget; const button = form.querySelector('button'); button.disabled = true;
  const listId = Number(form.elements.watchlist_id.value);
  const replacement = { asset_id: Number(form.elements.asset_id.value), target_buy: form.elements.target_buy.value, target_sell: form.elements.target_sell.value, thesis: form.elements.thesis.value, tags: form.elements.tags.value.split(',').map((tag) => tag.trim()).filter(Boolean), status: form.elements.status.value };
  try {
   if (editingItem) await request(`watchlists/${listId}/items/${editingItem.id}/revisions`, { expected_revision: Number(editingItem.revision), replacement }, 'watchlist-item');
   else await request(`watchlists/${listId}/items`, replacement, 'watchlist-item');
   clearItem(); form.elements.watchlist_id.value = String(listId); await refresh(); status(config.i18n.saved);
  } catch (error) { status(error.message, true); } finally { button.disabled = false; }
 });
 $('research-note-form').addEventListener('submit', async (event) => {
  event.preventDefault(); const form = event.currentTarget; const button = form.querySelector('button'); button.disabled = true;
  try {
   if (editingNote) await request(`research-notes/${editingNote.id}/revisions`, { expected_revision: Number(editingNote.revision), content: form.elements.content.value, reason: form.elements.reason.value }, 'research-note');
   else await request('research-notes', { asset_id: Number(form.elements.asset_id.value), content: form.elements.content.value }, 'research-note');
   clearNote(); await refresh(); status(config.i18n.saved);
  } catch (error) { status(error.message, true); } finally { button.disabled = false; }
 });
 window.addEventListener('tgit-workspace', (event) => {
  workspace = event.detail.workspace; role = event.detail.role; assets = event.detail.assets; pending.clear(); clearItem(); clearNote();
  for (const name of ['watchlist-form', 'watchlist-item-form', 'research-note-form']) $(name).hidden = role === 'viewer';
  refresh().catch((error) => status(error.message, true));
 });
})();
