/* Private journal UI: server revisions and authenticated image blobs. */
(() => {
 'use strict';
 const config = window.tgitConfig;
 const $ = (id) => document.getElementById(`tgit-${id}`);
 let context = null, epoch = 0, trade = null, strategy = null, limits = null, cursor = null;
 let confluenceState = new Map();
 let strategyVersions = [], pending = new Map(), imageUrls = [], selected = new Map(), busy = false;
 const messages = { loading: 'Loading...', saved: 'Saved.', failed: 'Request failed. Retry with the same file.', empty: 'No records yet.' };
 const notice = (text, error = false) => { $('journal-status').textContent = text; $('journal-status').setAttribute('role', error ? 'alert' : 'status'); };
 const path = (suffix) => `workspaces/${context.workspace}/${suffix}`;
 const isEditor = () => context && ['owner', 'manager', 'contributor'].includes(context.role);
 const isManager = () => context && ['owner', 'manager'].includes(context.role);
 const text = (tag, value, parent) => { const element = document.createElement(tag); element.textContent = value; if (parent) parent.append(element); return element; };
 const button = (label, handler, parent) => { const element = text('button', label, parent); element.type = 'button'; element.className = 'button'; element.disabled = busy; element.addEventListener('click', handler); return element; };
 const help = (parent, description) => { const tip = text('span', '?', parent); tip.className = 'tgit-help'; tip.tabIndex = 0; tip.setAttribute('role', 'note'); tip.setAttribute('aria-label', description); tip.dataset.tip = description; return tip; };
 const timestamp = (value) => { const date = new Date(value.replace(' ', 'T') + 'Z'); return new Intl.DateTimeFormat(undefined, { timeZone: context.timezone || 'America/New_York', year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', timeZoneName: 'short' }).format(date); };
 const labels = (value) => value.split(/\r?\n/).map((item) => item.trim()).filter(Boolean);
 const storageProblem = () => {
  if (!limits?.configured) return 'An owner must save Settings ? Image quota and retention before uploads.';
  if (limits.storage_status === 'gd_unavailable') return 'PHP GD is unavailable in the WordPress web runtime. Ask the host to enable GD with JPEG, PNG and WebP support.';
  if (limits.storage_status === 'image_codecs_unavailable') return 'PHP GD is missing JPEG, PNG or WebP support in the WordPress web runtime.';
  if (!limits.storage_ready) return 'Private image storage is unavailable. Configure TGIT_PRIVATE_MEDIA_DIR as a writable directory outside the web root; see docs/private-images.md.';
  return '';
 };
 function choices(select, rows, label, blank = false) { select.replaceChildren(); if (blank) { const option = text('option', 'None', select); option.value = ''; } for (const row of rows) { const option = text('option', label(row), select); option.value = row.id; } }
 async function api(target, body, key) {
  const response = await fetch(tgitRestUrl(config.root, target), { method: body ? 'POST' : 'GET', cache: 'no-store', credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce, ...(body ? { 'Content-Type': 'application/json' } : {}), ...(key ? { 'Idempotency-Key': key } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
  const data = await response.json(); if (!response.ok) { const error = new Error(data.message || messages.failed); error.status = response.status; throw error; } return data.data;
 }
 async function write(target, body) {
  const identity = JSON.stringify({ target, body }); if (!pending.has(identity)) pending.set(identity, crypto.randomUUID());
  try { const result = await api(target, body, pending.get(identity)); pending.delete(identity); return result; }
  catch (error) { if (error.status >= 400 && error.status < 500) pending.delete(identity); throw error; }
 }
 async function all(type, expected) {
  const items = []; let next = 0;
  do { const page = await api(path(`${type}?after=${next}&limit=100`)); if (expected !== epoch) return []; items.push(...page.items); next = page.next_cursor; } while (next !== null);
  return items;
 }
 function releaseImages() { for (const url of imageUrls) URL.revokeObjectURL(url); imageUrls = []; selected.clear(); $('compare-images').disabled = true; if ($('image-dialog').open) $('image-dialog').close(); }
 function lock(value) {
  busy = value; window.tgitWriteBusy = value; $('workspace').disabled = value;
  for (const control of document.querySelectorAll('#tgit-journal-section button, #tgit-strategy-section button, #tgit-media-settings-section button')) if (!control.dataset.allowBusy && control.id !== 'tgit-close-image') control.disabled = value;
  $('compare-images').disabled = value || selected.size !== 2;
  for (const id of ['workspace-form', 'account-form', 'asset-form', 'transaction-form', 'member-form']) for (const control of $(id).querySelectorAll('button')) control.disabled = value;
 }
 function guarded(handler) { return async () => { if (busy || window.tgitWriteBusy) return; lock(true); notice(messages.loading); try { await handler(); if ($('journal-status').textContent === messages.loading) notice(''); } catch (error) { notice(error.message, true); } finally { lock(false); } }; }
 function renderTrades(items, append = false) {
  if (!append) $('trades').replaceChildren();
  if (!items.length && !append) text('p', messages.empty, $('trades'));
  for (const item of items) { const card = document.createElement('article'); card.className = 'tgit-card'; text('h3', item.title, card); text('p', `#${item.id} | ${item.state} | revision ${item.revision}`, card); button('Open journal', guarded(() => openTrade(item.id)), card); $('trades').append(card); }
 }
 function renderConfluences() {
  $('confluence-items').replaceChildren();
  for (const labelText of labels($('trade-form').elements.confluences.value)) {
   const label = text('label', '', $('confluence-items')); const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.checked = confluenceState.get(labelText) ?? false; checkbox.disabled = !isEditor(); checkbox.dataset.label = labelText;
   checkbox.addEventListener('change', () => confluenceState.set(labelText, checkbox.checked)); label.append(checkbox, document.createTextNode(labelText));
  }
 }
 $('trade-form').elements.confluences.addEventListener('input', renderConfluences);
 function showTrade(data) {
  releaseImages(); confluenceState.clear(); trade = data; const form = $('trade-form'); form.reset();
  $('trade-detail').hidden = false; $('trade-heading').textContent = data ? data.trade.title : 'New trade journal';
  choices(form.elements.asset_id, context.assets, (item) => `${item.symbol} | ${item.exchange}`);
  const captured = data?.strategy_version;
  const versions = [...strategyVersions]; if (captured && !versions.some((item) => String(item.id) === String(captured.id))) versions.push({ ...captured, name: JSON.parse(captured.payload).name });
  choices(form.elements.strategy_version_id, versions, (item) => `${item.name} | version ${item.revision}`, true);
  if (data) {
   for (const name of ['title', 'asset_id', 'state', 'opened_on', 'closed_on', 'strategy_version_id']) form.elements[name].value = data.trade[name] ?? '';
   form.elements.transaction_ids.value = data.fills.map((item) => item.id).join(', ');
   for (const [name, value] of Object.entries(data.journal.fields)) if (form.elements[name]) {
    if (name === 'confluences') { const entries = value.map((item) => typeof item === 'string' ? { label: item, checked: true } : item); form.elements[name].value = entries.map((item) => item.label).join('\n'); confluenceState = new Map(entries.map((item) => [item.label, item.checked])); }
    else form.elements[name].value = Array.isArray(value) ? value.join('\n') : value ?? '';
   }
  }
  renderConfluences(); $('trade-summary').replaceChildren();
  if (captured) { const snapshot = JSON.parse(captured.payload); text('p', `Captured strategy: ${snapshot.name} | version ${captured.revision}`, $('trade-summary')); for (const name of ['description', 'rules']) { const content = document.createElement('div'); content.innerHTML = snapshot[name]; $('trade-summary').append(content); } }
  $('fill-facts').replaceChildren();
  for (const fill of data?.fills ?? []) text('p', `Fill #${fill.id}: ${fill.effective_date} | ${fill.action} | ${fill.state} | ${fill.quantity} @ ${fill.unit_price} ${fill.currency} | fees ${fill.fees}`, $('fill-facts'));
  $('trade-history').replaceChildren();
  const history = (items, prepend = false) => { const fragment = document.createDocumentFragment(); for (const revision of items) { const details = document.createElement('details'); text('summary', `Revision ${revision.revision} | user #${revision.actor_id} | ${timestamp(revision.created_at)}`, details); text('pre', JSON.stringify(JSON.parse(revision.payload), null, 2), details); fragment.append(details); } if (prepend) $('trade-history').prepend(fragment); else $('trade-history').append(fragment); };
  history(data?.revisions ?? []);
  if (data?.revisions_cursor) { let before = data.revisions_cursor; const older = button('Load older journal revisions', guarded(async () => { const page = await api(path(`trades/${data.trade.id}/revisions?before=${before}&limit=20`)); history(page.items, true); before = page.next_cursor; older.hidden = before === null; }), $('trade-history')); }
  for (const element of form.elements) if (!['button', 'submit'].includes(element.type)) { element.disabled = !isEditor() && (element.tagName === 'SELECT' || ['file', 'checkbox'].includes(element.type)); if ('readOnly' in element) element.readOnly = !isEditor(); }
  form.querySelector('button[type="submit"]').hidden = !isEditor();
  $('gallery-section').hidden = !data; $('upload-progress').replaceChildren();
 }
 async function openTrade(id) { const expected = epoch; notice(messages.loading); const data = await api(path(`trades/${id}`)); if (expected !== epoch) return; showTrade(data); await refreshGallery(); notice(''); $('trade-detail').scrollIntoView({ block: 'start' }); }
 async function binary(id, variant, expected) {
  const response = await fetch(tgitRestUrl(config.root, path(`images/${id}/content?variant=${variant}`)), { cache: 'no-store', credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce } });
  if (!response.ok) throw new Error('Image is unavailable or access was revoked.');
  const blob = await response.blob(); if (expected !== epoch) return null; const url = URL.createObjectURL(blob); imageUrls.push(url); return url;
 }
 async function viewImages(rows) {
  const expected = epoch; $('image-view').replaceChildren(); $('image-dialog').showModal();
  for (const row of rows) { const figure = document.createElement('figure'); const image = document.createElement('img'); image.alt = row.alt_text || row.caption || row.filename; figure.append(image); text('figcaption', row.caption, figure); const url = await binary(row.id, 'original', expected); if (!url || !$('image-dialog').open) return; image.src = url; const link = text('a', 'Download normalized original', figure); link.href = url; link.download = row.filename; $('image-view').append(figure); }
 }
 async function mutateImage(row, operation, extra = {}) { await write(path(`images/${row.id}/${operation}`), { expected_revision: Number(row.revision), ...extra }); await refreshGallery(); }
 async function refreshGallery() {
  if (!trade) return; await loadSettings(epoch); const expected = epoch, id = String(trade.trade.id);
  const gallery = await api(path(`trades/${id}/images`)); if (expected !== epoch || String(trade?.trade.id) !== id) return;
  releaseImages(); $('gallery').replaceChildren();
  $('gallery-status').textContent = storageProblem() || 'Images are private. Trash retains storage until cleanup.';
  if (!gallery.items.length) text('p', 'No images yet.', $('gallery'));
  for (const row of gallery.items) {
   const card = document.createElement('article'); card.className = 'tgit-card'; text('h4', row.filename, card); text('p', `${row.state} | ${row.stage} | ${row.timeframe}`, card);
   if (row.state === 'ready') {
    const image = document.createElement('img'); image.className = 'tgit-thumbnail'; image.alt = row.alt_text || row.caption || row.filename; card.append(image);
    binary(row.id, 'thumbnail', expected).then((url) => { if (url && image.isConnected) image.src = url; }).catch((error) => text('p', error.message, card));
    text('p', row.caption, card); button('View original', guarded(() => viewImages([row])), card);
    const label = document.createElement('label'); const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; label.append(checkbox, document.createTextNode(' Select for comparison')); card.append(label);
    checkbox.addEventListener('change', () => { if (checkbox.checked) selected.set(row.id, row); else selected.delete(row.id); $('compare-images').disabled = selected.size !== 2; });
   }
   if (isEditor()) {
    if (['reserved', 'failed'].includes(row.state)) { const retry = document.createElement('input'); retry.type = 'file'; retry.accept = 'image/jpeg,image/png,image/webp'; retry.setAttribute('aria-label', `Retry or replace the pending file for ${row.filename}`); card.append(retry); button('Retry this image', guarded(async () => { if (!retry.files[0]) throw new Error('Select a replacement or the original file for this image.'); await uploadFile(retry.files[0], row); await refreshGallery(); }), card); }
    if (row.state !== 'deleted') {
     const form = document.createElement('form'); form.className = 'tgit-form';
     for (const [name, labelText] of [['caption', 'Caption'], ['alt_text', 'Accessible description'], ['timeframe', 'Chart timeframe'], ['sort_order', 'Order']]) { const label = text('label', labelText, form); const input = document.createElement('input'); input.name = name; input.value = row[name] ?? ''; input.maxLength = name === 'timeframe' ? 64 : 2000; if (name === 'sort_order') { input.type = 'number'; input.min = '0'; input.max = '100000'; } label.append(input); if (name === 'sort_order') help(form, 'Lower numbers appear first in the gallery. Images with the same order use upload order.'); }
     const label = text('label', 'Image stage', form); const stage = document.createElement('select'); stage.name = 'stage'; stage.required = true; for (const [value, caption] of [['before', 'Before trade'], ['entry', 'Entry'], ['exit', 'Exit'], ['review', 'Review']]) { const option = text('option', caption, stage); option.value = value; } stage.value = row.stage || 'review'; label.append(stage); help(form, 'Choose where this image belongs in the trade journal.');
     const save = text('button', 'Save image details', form); save.type = 'submit'; save.className = 'button';
     form.addEventListener('submit', (event) => { event.preventDefault(); guarded(async () => { const body = Object.fromEntries(new FormData(form)); body.sort_order = Number(body.sort_order); await mutateImage(row, 'metadata', body); })(); }); card.append(form);
     button('Remove image (recoverable)', guarded(() => mutateImage(row, 'delete')), card);
    } else button('Restore image', guarded(() => mutateImage(row, 'restore')), card);
   }
   $('gallery').append(card);
  }
 }
 function uploadBinary(target, file, key, progress, signal) {
  return new Promise((resolve, reject) => {
   const xhr = new XMLHttpRequest(); xhr.open('POST', tgitRestUrl(config.root, target)); xhr.withCredentials = true; xhr.setRequestHeader('X-WP-Nonce', config.nonce); xhr.setRequestHeader('Idempotency-Key', key);
   xhr.upload.addEventListener('progress', (event) => { if (event.lengthComputable) progress.value = event.loaded / event.total * 100; });
   xhr.addEventListener('load', () => { let result; try { result = JSON.parse(xhr.responseText); } catch (error) { reject(new Error(`Upload returned HTTP ${xhr.status} without a valid response. Check the server upload limit or security rules.`)); return; } if (xhr.status < 200 || xhr.status >= 300) { reject(new Error(result.message || `Upload failed with HTTP ${xhr.status}.`)); return; } resolve(result.data); });
   xhr.addEventListener('error', () => reject(new Error(messages.failed))); xhr.addEventListener('abort', () => reject(new Error('Upload canceled.'))); signal.addEventListener('abort', () => xhr.abort(), { once: true });
   const body = new FormData(); body.append('file', file); xhr.send(body);
  });
 }
 async function uploadFile(file, reservation = null) {
  const container = document.createElement('div'); const message = text('p', `${file.name}: reserving...`, container); const progress = document.createElement('progress'); progress.max = 100; progress.value = 0; progress.setAttribute('aria-label', `Upload progress for ${file.name}`); container.append(progress); $('upload-progress').append(container);
  const controller = new AbortController(); const cancel = button('Cancel upload', () => controller.abort(), container); cancel.dataset.allowBusy = 'true'; cancel.disabled = false;
  let row = reservation;
  try {
   if (storageProblem()) throw new Error(storageProblem());
   if (file.size > Number(limits.limits.max_file_bytes)) throw new Error('File exceeds the configured image limit.');
   const hashBytes = new Uint8Array(await crypto.subtle.digest('SHA-256', await file.arrayBuffer())); const hash = [...hashBytes].map((item) => item.toString(16).padStart(2, '0')).join('');
   if (!row) row = await write(path(`trades/${trade.trade.id}/images`), { filename: file.name, hash, size: file.size });
   else {
    const gallery = await api(path(`trades/${trade.trade.id}/images`)); const current = gallery.items.find((item) => String(item.id) === String(row.id));
    if (current?.state === 'ready') { message.textContent = file.name + ': already ready.'; return; }
    if (!current) throw new Error('Image is no longer available.');
    row = await write(path(`images/${row.id}/retry`), { expected_revision: Number(current.revision), filename: file.name, hash, size: file.size });
   }
   if (controller.signal.aborted) throw new Error('Upload canceled.');
   const target = path(`images/${row.id}/upload`), identity = target + ':' + hash;
   if (!pending.has(identity)) pending.set(identity, crypto.randomUUID());
   message.textContent = `${file.name}: uploading and normalizing...`;
   await uploadBinary(target, file, pending.get(identity), progress, controller.signal); pending.delete(identity); message.textContent = `${file.name}: ready.`;
  } catch (error) {
   message.textContent = `${file.name}: ${error.message}`;
   if (row && controller.signal.aborted) { const gallery = await api(path(`trades/${trade.trade.id}/images`)); const current = gallery.items.find((item) => String(item.id) === String(row.id)); if (current && current.state !== 'deleted') await write(path(`images/${row.id}/delete`), { expected_revision: Number(current.revision) }); }
   throw error;
  } finally { cancel.disabled = true; }
 }
 $('trade-form').addEventListener('submit', (event) => {
  event.preventDefault(); guarded(async () => {
   const form = $('trade-form'), files = [...form.elements.images.files]; const input = {};
   for (const name of ['title', 'state', 'opened_on', 'closed_on']) input[name] = form.elements[name].value;
   input.asset_id = Number(form.elements.asset_id.value); input.strategy_version_id = form.elements.strategy_version_id.value ? Number(form.elements.strategy_version_id.value) : null;
   input.transaction_ids = form.elements.transaction_ids.value.trim() ? form.elements.transaction_ids.value.split(',').map((item) => Number(item.trim())) : [];
   input.journal = {};
   for (const name of ['thesis', 'entry_rationale', 'exit_rationale', 'emotions', 'lessons', 'notes', 'confluence_text', 'original_confluences', 'premarket_low', 'premarket_high', 'previous_day_low', 'previous_day_high', 'planned_stop', 'planned_target']) input.journal[name] = form.elements[name].value;
   input.journal.tags = labels(form.elements.tags.value);
   input.journal.confluences = [...$('confluence-items').querySelectorAll('input')].map((item) => ({ label: item.dataset.label, checked: item.checked }));
   input.journal.confidence = form.elements.confidence.value === '' ? null : Number(form.elements.confidence.value);
   if (trade) input.expected_revision = Number(trade.trade.revision);
   const result = await write(path(trade ? `trades/${trade.trade.id}` : 'trades'), input); showTrade(result);
   const failures = []; for (const file of files) { try { await uploadFile(file); } catch (error) { failures.push(`${file.name}: ${error.message}`); } }
   const page = await api(path('trades?limit=100')); renderTrades(page.items); cursor = page.next_cursor; $('more-trades').hidden = cursor === null;
   await refreshGallery(); notice(failures.length ? `Journal saved. ${failures.length} image upload(s) failed. ${failures[0]}` : messages.saved, failures.length > 0);
  })();
 });
 $('new-trade').addEventListener('click', () => { if (busy) return; showTrade(null); $('trade-detail').scrollIntoView({ block: 'start' }); $('trade-form').elements.title.focus(); });
 $('close-trade').addEventListener('click', () => { if (busy) return; releaseImages(); trade = null; $('trade-detail').hidden = true; });
 $('more-trades').addEventListener('click', guarded(async () => { const page = await api(path(`trades?after=${cursor}&limit=100`)); renderTrades(page.items, true); cursor = page.next_cursor; $('more-trades').hidden = cursor === null; }));
 $('compare-images').addEventListener('click', guarded(() => viewImages([...selected.values()])));
 $('close-image').addEventListener('click', () => $('image-dialog').close());
 $('new-strategy').addEventListener('click', () => { if (busy) return; strategy = null; $('strategy-form').reset(); $('strategy-editing').textContent = 'New strategy'; });
 $('strategy-form').addEventListener('submit', (event) => { event.preventDefault(); guarded(async () => { const form = $('strategy-form'); const input = Object.fromEntries(new FormData(form)); input.tags = labels(input.tags); if (strategy) input.expected_revision = Number(strategy.strategy.revision); await write(path(strategy ? `strategies/${strategy.strategy.id}` : 'strategies'), input); strategy = null; form.reset(); $('strategy-editing').textContent = 'New strategy'; await loadStrategies(epoch); notice(messages.saved); })(); });
 async function loadStrategies(expected) {
  const items = await all('strategies', expected); const details = await Promise.all(items.map((item) => api(path(`strategies/${item.id}`)))); if (expected !== epoch) return;
  strategyVersions = []; $('strategies').replaceChildren();
  for (const data of details) {
   const card = document.createElement('article'); card.className = 'tgit-card'; text('h4', data.strategy.name, card); text('p', `${data.strategy.status} | version ${data.strategy.revision}`, card);
   for (const version of data.versions) { const facts = JSON.parse(version.payload); if (facts.status === 'active') strategyVersions.push({ ...version, name: facts.name }); const revision = document.createElement('details'); text('summary', `Version ${version.revision} | ${timestamp(version.created_at)}`, revision); for (const field of ['description', 'rules']) { const content = document.createElement('div'); content.innerHTML = facts[field]; revision.append(content); } text('p', facts.tags.join(', '), revision); card.append(revision); }
   if (data.versions_cursor) { let before = data.versions_cursor; const older = button('Load older strategy versions', guarded(async () => { const page = await api(path(`strategies/${data.strategy.id}/versions?before=${before}&limit=20`)); for (const version of page.items) { const facts = JSON.parse(version.payload); const details = document.createElement('details'); text('summary', 'Version ' + version.revision + ' | ' + timestamp(version.created_at), details); for (const field of ['description', 'rules']) { const content = document.createElement('div'); content.innerHTML = facts[field]; details.append(content); } card.insertBefore(details, older); if (facts.status === 'active' && !strategyVersions.some((item) => item.id === version.id)) strategyVersions.push({ ...version, name: facts.name }); } before = page.next_cursor; older.hidden = before === null; }), card); }
   if (isManager()) button('Edit strategy', guarded(async () => { strategy = data; const facts = JSON.parse(data.versions.at(-1).payload); const form = $('strategy-form'); for (const name of ['name', 'status', 'description', 'rules']) form.elements[name].value = facts[name]; form.elements.tags.value = facts.tags.join('\n'); $('strategy-editing').textContent = `Editing #${data.strategy.id}, revision ${data.strategy.revision}`; form.scrollIntoView({ block: 'start' }); }), card);
   $('strategies').append(card);
  }
  if (!details.length) text('p', messages.empty, $('strategies'));
 }
 async function loadSettings(expected) { const data = await api(path('media-settings')); if (expected !== epoch) return; limits = data; const form = $('media-settings-form'); for (const name of ['max_images', 'max_file_bytes', 'max_pixels', 'quota_bytes', 'trash_days']) form.elements[name].value = data.limits[name]; $('media-health').textContent = `Retained/reserved bytes: ${data.used_bytes}. ${storageProblem() || 'Private storage is ready.'}`; }
 $('media-settings-form').addEventListener('submit', (event) => { event.preventDefault(); guarded(async () => { const input = Object.fromEntries([...new FormData($('media-settings-form'))].map(([key, value]) => [key, Number(value)])); input.expected_revision = Number(limits.limits.revision); await write(path('media-settings'), input); await loadSettings(epoch); notice(messages.saved); })(); });
 $('media-cleanup').addEventListener('click', guarded(async () => { const result = await write(path('media-cleanup'), {}); await loadSettings(epoch); if (trade) await refreshGallery(); notice(`Cleaned up ${result.purged} expired images/reservations.`); }));
 window.addEventListener('tgit-workspace', async (event) => {
  context = event.detail; const expected = ++epoch; trade = null; strategy = null; pending.clear(); releaseImages(); $('trade-detail').hidden = true;
  $('new-trade').hidden = !isEditor(); $('strategy-form').hidden = !isManager(); $('strategy-form').reset(); $('media-settings-section').hidden = context.role !== 'owner'; notice(messages.loading);
  try { const page = await api(path('trades?limit=100')); if (expected !== epoch) return; renderTrades(page.items); cursor = page.next_cursor; $('more-trades').hidden = cursor === null; await Promise.all([loadStrategies(expected), loadSettings(expected)]); if (expected === epoch) notice(''); } catch (error) { if (expected === epoch) notice(error.message, true); }
 });
})();
