/* Private journal UI: server revisions and authenticated image blobs. */
(() => {
 'use strict';
 const config = window.tgitConfig;
 const $ = (id) => document.getElementById(`tgit-${id}`);
 let context = null, epoch = 0, trade = null, strategy = null, limits = null;
 let confluenceState = new Map();
 let galleryGeneration = 0, galleryCollection = null, galleryRows = [];
 let strategyVersions = [], pending = new Map(), imageUrls = [], selected = new Map(), busy = false;
 const messages = { loading: 'Loading...', saved: 'Saved.', failed: 'Request failed. Retry with the same file.', empty: 'No records yet.' };
 const notice = (text, error = false) => { for (const id of ['journal-status', 'strategy-status']) { $(id).textContent = text; $(id).setAttribute('role', error ? 'alert' : 'status'); } };
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
 function releaseImages() { galleryGeneration++; for (const url of imageUrls) URL.revokeObjectURL(url); imageUrls = []; selected.clear(); $('compare-images').disabled = true; if ($('image-dialog').open) $('image-dialog').close(); }
 function lock(value) {
  busy = value; window.tgitWriteBusy = value; $('workspace').disabled = value;
  for (const control of document.querySelectorAll('#tgit-journal-section button, #tgit-strategy-section button, #tgit-media-settings-section button')) if (!control.dataset.allowBusy && control.id !== 'tgit-close-image') control.disabled = value;
  $('compare-images').disabled = value || selected.size !== 2;
  if (!value && context) renderTrades(tradeRows);
  if (!value && galleryCollection) galleryCollection.update(galleryRows);
  if (!value && candidateCollection) candidateCollection.update(candidateRows);
  if (!value && context && trade) renderLinkedFills();
  if (!value && historyCollection) historyCollection.update(historyRows);
  if (!value && strategyCollection) renderStrategies();
  if (!value) renderLabelEditors();
  for (const id of ['workspace-form', 'account-form', 'asset-form', 'transaction-form', 'member-form']) for (const control of $(id).querySelectorAll('button')) control.disabled = value;
 }
 function guarded(handler) { return async () => { if (busy || window.tgitWriteBusy) return; lock(true); notice(messages.loading); try { await handler(); if ($('journal-status').textContent === messages.loading) notice(''); } catch (error) { notice(error.message, true); if (error.status === 409 && !strategyForm.hidden && strategy) reloadStrategyButton.hidden = false; else if (error.status === 409 && trade && !imageDraft) reload.hidden = false; } finally { lock(false); } }; }
 let baseline = '', listScroll = 0, tradeRows = [], tradeLoad = 0;
 const list = document.createElement('div'); list.id = 'tgit-trade-list';
 $('new-trade').textContent = 'New trade'; $('new-trade').classList.add('button-primary');
 $('new-trade').before(list); for (const id of ['new-trade', 'trades', 'more-trades']) list.append($(id));
 const detailTabs = document.createElement('div'); detailTabs.className = 'tgit-detail-tabs'; detailTabs.setAttribute('role', 'tablist'); detailTabs.setAttribute('aria-label', 'Trade detail sections');
 $('trade-heading').after(detailTabs);
 const detailPanels = new Map();
 for (const name of ['Summary', 'Plan and journal', 'Transactions', 'Images', 'History']) {
  const id = name.split(' ')[0].toLowerCase(); const tab = button(name, () => selectDetail(id), detailTabs);
  tab.id = `tgit-detail-tab-${id}`; tab.setAttribute('role', 'tab'); tab.setAttribute('aria-controls', `tgit-detail-panel-${id}`); tab.dataset.detailTab = id;
  const panel = document.createElement('div'); panel.id = `tgit-detail-panel-${id}`; panel.setAttribute('role', 'tabpanel'); panel.setAttribute('aria-labelledby', tab.id); panel.tabIndex = 0;
  $('trade-detail').append(panel); detailPanels.set(id, panel);
  tab.addEventListener('keydown', (event) => {
   const tabs = [...detailTabs.querySelectorAll('button')]; const index = tabs.indexOf(tab); let next;
   if (event.key === 'ArrowRight') next = tabs[(index + 1) % tabs.length]; if (event.key === 'ArrowLeft') next = tabs[(index + tabs.length - 1) % tabs.length];
   if (event.key === 'Home') next = tabs[0]; if (event.key === 'End') next = tabs.at(-1);
   if (next) { event.preventDefault(); selectDetail(next.dataset.detailTab); next.focus(); }
  });
 }
 detailPanels.get('summary').append($('trade-summary'));
 detailPanels.get('plan').append($('trade-form'));
 detailPanels.get('transactions').append($('fill-facts'));
 detailPanels.get('images').append($('gallery-section'));
 detailPanels.get('history').append($('trade-history').parentElement);
 const journalForm = $('trade-form');
 const linkInput = journalForm.elements.transaction_ids; const oldLinkLabel = linkInput.closest('label'); linkInput.type = 'hidden'; journalForm.append(linkInput); oldLinkLabel.remove();
 for (const [title, names, minimal] of [
  ['Trade identity', ['title', 'asset_id', 'strategy_version_id', 'state', 'opened_on', 'closed_on'], true],
  ['Plan and rationale', ['thesis', 'entry_rationale', 'exit_rationale', 'confidence'], false],
  ['Price levels', ['premarket_low', 'premarket_high', 'previous_day_low', 'previous_day_high', 'planned_stop', 'planned_target'], false],
  ['Confluences', ['confluences', 'confluence_text', 'original_confluences'], false],
  ['Review and tags', ['emotions', 'lessons', 'notes', 'tags'], false]
 ]) {
  const group = document.createElement('fieldset'); group.className = 'tgit-journal-group tgit-grid'; group.dataset.minimal = String(minimal); text('legend', title, group);
  for (const name of names) group.append(journalForm.elements[name].closest('label'));
  if (title === 'Confluences') group.append($('confluence-checklist'));
  journalForm.append(group);
 }
 const linkHelp = text('p', 'Link existing buys and sells for this asset. Selecting or removing links changes only the journal; posted transactions, cash and lots remain unchanged.', detailPanels.get('transactions'));
 const pickLinks = button('Link transactions', () => { guarded(loadFillCandidates)(); }, detailPanels.get('transactions')); pickLinks.id = 'tgit-link-transactions';
 const fillPicker = document.createElement('div'); fillPicker.id = 'tgit-fill-picker'; fillPicker.hidden = true; detailPanels.get('transactions').append(fillPicker);
 let candidateRows = [], candidateCollection = null, previousAsset = '';
 const chosenFills = () => new Set(linkInput.value.split(',').map((value) => value.trim()).filter(Boolean));
 function selectFill(row, selected) {
  if (busy || window.tgitWriteBusy) { candidateCollection?.update(candidateRows); return; }
  const ids = chosenFills(); if (selected && ids.size >= 200 && !ids.has(String(row.id))) { notice('A trade can link at most 200 transactions.', true); candidateCollection?.update(candidateRows); return; }
  if (selected) ids.add(String(row.id)); else ids.delete(String(row.id)); linkInput.value = [...ids].sort((a, b) => Number(a) - Number(b)).join(', ');
  renderLinkedFills(); candidateCollection?.update(candidateRows); notice(`${ids.size} transaction(s) selected. Save changes to update journal links.`);
 }
 function fillColumns(selection = false) {
  return [
   { key: 'id', label: 'Transaction', identity: true, required: true, sort: (a, b) => Number(a.id) - Number(b.id), render: (row) => `#${row.id}` },
   { key: 'date', label: 'Effective date', sort: (a, b) => a.effective_date.localeCompare(b.effective_date), render: (row) => row.effective_date },
   { key: 'account', label: 'Account', render: (row) => fillAccount(row) },
   { key: 'action', label: 'Action', render: (row) => row.action },
   { key: 'state', label: 'State', render: (row) => row.state },
   { key: 'quantity', label: 'Quantity', numeric: true, render: (row) => tgitDisplayDecimal(row.quantity, 0) },
   { key: 'price', label: 'Unit price', numeric: true, render: (row) => `${tgitDisplayDecimal(row.unit_price)} ${row.currency}` },
   { key: 'fees', label: 'Fees', numeric: true, render: (row) => `${tgitDisplayDecimal(row.fees)} ${row.currency}` },
   { key: 'actions', label: selection ? 'Select' : 'Actions', required: true, render: (row) => {
    if (!isEditor()) return 'Read only';
    if (!selection) { const group = document.createElement('div'); button('Remove link', () => { if (!busy && !window.tgitWriteBusy) selectFill(row, false); }, group); return group; }
    const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.checked = chosenFills().has(String(row.id)); checkbox.disabled = busy; checkbox.setAttribute('aria-label', `Link transaction ${row.id}`); checkbox.addEventListener('change', () => selectFill(row, checkbox.checked)); return checkbox;
   } }
  ];
 }
 const fillAccount = (row) => row.account_name || context.accounts.find((account) => String(account.id) === String(row.account_id))?.name || `#${row.account_id}`;
 function renderLinkedFills() {
  const ids = chosenFills(), rows = [...(trade?.fills || []), ...candidateRows]; const unique = new Map(rows.map((row) => [String(row.id), row]));
  tgitCollection($('fill-facts'), { actor: config.actorId, workspace: `${context.workspace}:${trade?.trade.id || 'new'}`, key: 'linked-fills', title: 'Selected transactions', search: (row) => `${row.id} ${row.effective_date} ${row.action} ${row.state} ${fillAccount(row)}`, columns: fillColumns() }, [...unique.values()].filter((row) => ids.has(String(row.id))));
 }
 async function loadFillCandidates() {
  if (!trade || !isEditor()) return; const expected = epoch, id = String(trade.trade.id), asset = journalForm.elements.asset_id.value; let next = 0; const rows = [];
  do { const page = await api(path(`trades/${id}/fill-candidates?asset_id=${asset}&after=${next}&limit=100`)); if (expected !== epoch || String(trade?.trade.id) !== id || journalForm.elements.asset_id.value !== asset) return; rows.push(...page.items); next = page.next_cursor; } while (next !== null);
  candidateRows = rows; fillPicker.hidden = false;
  candidateCollection = tgitCollection(fillPicker, { actor: config.actorId, workspace: `${context.workspace}:${id}:${asset}`, key: 'fill-candidates', title: 'Eligible transactions', search: (row) => `${row.id} ${row.account_name} ${row.effective_date} ${row.action} ${row.state}`, filters: [{ key: 'state', label: 'Transaction state', default: '', values: [['', 'All'], ['posted', 'Posted'], ['draft', 'Draft']], matches: (row, value) => !value || row.state === value }], columns: fillColumns(true) }, rows);
  renderLinkedFills(); notice('Select transactions, then Save changes. Already assigned or corrected source transactions are excluded.');
 }
 journalForm.elements.asset_id.addEventListener('change', () => {
  if (chosenFills().size && !window.confirm('Changing the asset removes the selected transaction links. Continue?')) { journalForm.elements.asset_id.value = previousAsset; return; }
  previousAsset = journalForm.elements.asset_id.value; linkInput.value = ''; candidateRows = []; candidateCollection = null; tgitResetCollection(fillPicker); fillPicker.hidden = true; renderLinkedFills();
 });
 const labelEditors = new Map();
 for (const [name, singular] of [['tags', 'tag'], ['confluences', 'confluence'], ['strategyTags', 'tag']]) {
  const field = (name === 'strategyTags' ? $('strategy-form') : journalForm).elements[name === 'strategyTags' ? 'tags' : name]; const editable = () => name === 'strategyTags' ? isManager() : isEditor(); field.hidden = true; const wrapper = field.closest('label'); wrapper.firstChild.textContent = singular === 'tag' ? 'Tags' : 'Confluence checklist';
  const chips = document.createElement('div'); chips.className = 'tgit-label-list'; chips.id = `tgit-${name}-labels`; wrapper.append(chips);
  const entry = document.createElement('input'); entry.type = 'text'; entry.id = `tgit-add-${name === 'strategyTags' ? 'strategy-tag' : singular}`; entry.maxLength = 190; entry.setAttribute('aria-label', `New ${singular}`); wrapper.append(entry);
  const add = () => {
   if (busy || window.tgitWriteBusy || !editable()) return; const value = entry.value.trim(), values = labels(field.value);
   if (!value || new TextEncoder().encode(value).length > 190 || /[\r\n\x00-\x1f]/.test(value)) { notice(`Enter a ${singular} of up to 190 UTF-8 bytes on one line.`, true); entry.focus(); return; }
   if (values.includes(value)) { notice(`This ${singular} already exists.`, true); entry.focus(); return; }
   if (values.length >= 50) { notice(`Use at most 50 ${singular}s.`, true); return; }
   field.value = [...values, value].join('\n'); entry.value = ''; if (name === 'confluences') { confluenceState.set(value, false); renderConfluences(); } renderLabelEditors(); notice(`${singular} added.`); entry.focus();
  };
  const addButton = button(`Add ${singular}`, add, wrapper); entry.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); add(); } });
  labelEditors.set(name, { field, chips, entry, addButton, editable });
 }
 function renderLabelEditors() {
  for (const [name, editor] of labelEditors) {
   editor.chips.replaceChildren(); editor.entry.disabled = !editor.editable(); editor.entry.hidden = !editor.editable(); editor.addButton.hidden = !editor.editable();
   for (const value of labels(editor.field.value)) {
    const chip = text('span', value, editor.chips); chip.className = 'tgit-label-chip';
    if (editor.editable()) { const remove = button('Remove', () => { if (busy || window.tgitWriteBusy) return; editor.field.value = labels(editor.field.value).filter((label) => label !== value).join('\n'); if (name === 'confluences') { confluenceState.delete(value); renderConfluences(); } renderLabelEditors(); notice('Label removed.'); editor.entry.focus(); }, chip); remove.setAttribute('aria-label', `Remove ${name === 'confluences' ? 'confluence' : 'tag'} ${value}`); }
   }
  }
 }
 let historyRows = [], historyCollection = null;
 const revisionDetail = document.createElement('section'); revisionDetail.id = 'tgit-revision-detail'; revisionDetail.hidden = true; detailPanels.get('history').append(revisionDetail);
 // History is already a tab; avoid a second nested disclosure around its collection.
 const oldHistoryDisclosure = $('trade-history').parentElement; detailPanels.get('history').prepend($('trade-history')); oldHistoryDisclosure.remove();
 function renderHistory(rows) {
  historyRows = rows;
  const changeLabels = new Map(); let previous = null;
  for (const row of [...rows].sort((a, b) => Number(a.revision) - Number(b.revision))) {
   const payload = JSON.parse(row.payload), changes = [];
   if (previous) for (const section of ['trade', 'fields']) for (const key of new Set([...Object.keys(previous[section] || {}), ...Object.keys(payload[section] || {})])) { if (JSON.stringify(previous[section]?.[key]) !== JSON.stringify(payload[section]?.[key])) changes.push(key.replaceAll('_', ' ')); }
   if (previous && JSON.stringify(previous.transaction_ids) !== JSON.stringify(payload.transaction_ids)) changes.push('transaction links');
   changeLabels.set(row.id, previous ? changes.length ? `${changes.length} changed: ${changes.slice(0, 3).join(', ')}${changes.length > 3 ? ', ...' : ''}` : 'No field changes' : 'Created'); previous = payload;
  }
  historyCollection = tgitCollection($('trade-history'), { actor: config.actorId, workspace: `${context.workspace}:${trade?.trade.id || 'new'}`, key: 'journal-history', title: 'Journal revisions', defaultSort: 'revision', defaultDescending: true,
   search: (row) => `${row.revision} ${row.actor_name || `User #${row.actor_id}`} ${row.created_at}`,
   columns: [
    { key: 'revision', label: 'Revision', required: true, identity: true, sort: (a, b) => Number(a.revision) - Number(b.revision), render: (row) => String(row.revision) },
    { key: 'date', label: 'Saved at', sort: (a, b) => a.created_at.localeCompare(b.created_at), render: (row) => timestamp(row.created_at) },
    { key: 'actor', label: 'Author', render: (row) => row.actor_name || `User #${row.actor_id}` },
    { key: 'changes', label: 'Changes', render: (row) => changeLabels.get(row.id) },
    { key: 'actions', label: 'Actions', required: true, render: (row) => { const group = document.createElement('div'); button('View revision', () => {
     revisionDetail.replaceChildren(); revisionDetail.hidden = false; const heading = text('h3', `Journal revision ${row.revision}`, revisionDetail); heading.tabIndex = -1;
     const payload = JSON.parse(row.payload); const details = document.createElement('dl'); revisionDetail.append(details);
     const entries = [...Object.entries(payload.trade || {}), ...Object.entries(payload.fields || {}), ['transaction_ids', payload.transaction_ids || []]];
     for (const [name, value] of entries) {
      text('dt', name.replaceAll('_', ' '), details);
      const display = Array.isArray(value) ? value.map((item) => item && typeof item === 'object' && 'label' in item ? `${item.checked ? 'Checked' : 'Not checked'}: ${item.label}` : String(item)).join(', ') : value === null || value === '' ? 'Not set' : String(value);
      text('dd', display || 'Not set', details);
     }
     const evidence = document.createElement('details'); text('summary', 'Stored revision evidence', evidence); text('pre', JSON.stringify(payload, null, 2), evidence); revisionDetail.append(evidence);
     button('Close revision', () => { revisionDetail.hidden = true; revisionDetail.replaceChildren(); $('trade-history').querySelector('input[type=search]')?.focus(); }, revisionDetail); heading.focus();
    }, group); return group; } }
   ] }, rows);
 }
 async function loadHistory(data) {
  const expected = epoch, id = String(data.trade.id); let next = data.revisions_cursor; const rows = [...data.revisions];
  while (next !== null) { const page = await api(path(`trades/${id}/revisions?before=${next}&limit=20`)); if (expected !== epoch || String(trade?.trade.id) !== id) return; rows.unshift(...page.items); next = page.next_cursor; }
  if (expected === epoch && String(trade?.trade.id) === id) renderHistory(rows);
 }
 let strategyBaseline = '', strategyRows = [], strategyCollection = null;
 const strategyForm = $('strategy-form'), strategyList = document.createElement('div'); strategyList.id = 'tgit-strategy-list';
 $('strategies').before(strategyList); const newStrategy = $('new-strategy'); newStrategy.textContent = 'New strategy'; newStrategy.classList.add('button-primary'); strategyList.append(newStrategy, $('strategies'));
 const strategyView = document.createElement('section'); strategyView.id = 'tgit-strategy-view'; strategyView.hidden = true; strategyForm.after(strategyView);
 const closeStrategyButton = button('Cancel strategy', () => { if (leaveStrategy()) closeStrategy(); }, strategyForm);
 const reloadStrategyButton = button('Reload latest strategy', () => { if (leaveStrategy() && strategy) guarded(() => editStrategy(strategy.strategy.id))(); }, strategyForm); reloadStrategyButton.hidden = true;
 const strategySnapshot = () => JSON.stringify([...new FormData(strategyForm)]) + labelEditors.get('strategyTags').entry.value;
 const strategyDirty = () => !strategyForm.hidden && isManager() && strategyBaseline !== strategySnapshot();
 const leaveStrategy = () => !strategyDirty() || window.confirm('Discard unsaved strategy changes?');
 function closeStrategy() { strategy = null; strategyForm.reset(); renderLabelEditors(); strategyForm.hidden = true; strategyView.hidden = true; strategyView.replaceChildren(); strategyList.hidden = false; strategyBaseline = ''; newStrategy.focus(); }
 function showStrategy(data) {
  strategy = data; strategyForm.reset(); strategyForm.hidden = false; strategyList.hidden = true; strategyView.hidden = true; strategyView.replaceChildren(); reloadStrategyButton.hidden = true;
  if (data) { const facts = JSON.parse(data.versions.at(-1).payload); for (const name of ['name', 'status', 'description', 'rules']) strategyForm.elements[name].value = facts[name]; strategyForm.elements.tags.value = facts.tags.join('\n'); }
  $('strategy-editing').textContent = data ? `Editing ${data.strategy.name} · version ${data.strategy.revision}` : 'New strategy';
  strategyForm.querySelector('button[type=submit]').classList.add('button-primary'); strategyForm.querySelector('button[type=submit]').textContent = data ? 'Save new version' : 'Create strategy';
  closeStrategyButton.textContent = data ? 'Back to strategies' : 'Cancel strategy'; labelEditors.get('strategyTags').entry.value = ''; renderLabelEditors(); strategyBaseline = strategySnapshot(); strategyForm.elements.name.focus();
 }
 async function editStrategy(id) { const expected = epoch; const data = await api(path(`strategies/${id}`)); if (expected === epoch) { showStrategy(data); await loadStrategies(expected); } }
 function renderStrategies() {
  strategyCollection = tgitCollection($('strategies'), { actor: config.actorId, workspace: context.workspace, key: 'strategies', title: 'Strategies', search: (row) => `${row.name} ${row.tags.join(' ')}`,
   filters: [{ key: 'status', label: 'Strategy view', default: 'active', values: [['active', 'Active'], ['', 'All'], ['archived', 'Archived']], matches: (row, value) => !value || row.status === value }],
   columns: [
    { key: 'name', label: 'Strategy', required: true, identity: true, sort: (a, b) => a.name.localeCompare(b.name), render: (row) => row.name },
    { key: 'status', label: 'Status', render: (row) => row.status },
    { key: 'revision', label: 'Latest version', numeric: true, sort: (a, b) => Number(a.revision) - Number(b.revision), render: (row) => String(row.revision) },
    { key: 'tags', label: 'Tags', render: (row) => row.tags.join(', ') || 'Not set' },
    { key: 'actions', label: 'Actions', required: true, render: (row) => { const group = document.createElement('div'); button('View versions', () => { if (leaveStrategy()) viewStrategy(row.data); }, group); if (isManager()) button('Edit strategy', () => { if (leaveStrategy()) guarded(() => editStrategy(row.id))(); }, group); return group; } }
   ] }, strategyRows);
 }
 function viewStrategy(data) {
  strategyForm.hidden = true; strategyBaseline = ''; strategyList.hidden = true; strategyView.hidden = false; strategyView.replaceChildren();
  text('h3', data.strategy.name, strategyView); text('p', 'Versions are immutable. Trade journals retain their captured strategy version.', strategyView);
  const table = document.createElement('div'); strategyView.append(table);
  const preview = document.createElement('section'); strategyView.append(preview);
  tgitCollection(table, { actor: config.actorId, workspace: `${context.workspace}:${data.strategy.id}`, key: 'strategy-versions', title: 'Strategy versions', defaultSort: 'revision', defaultDescending: true, search: (row) => `${row.revision} ${row.actor_name || row.actor_id} ${JSON.parse(row.payload).name}`,
   columns: [
    { key: 'revision', label: 'Version', required: true, identity: true, sort: (a, b) => Number(a.revision) - Number(b.revision), render: (row) => String(row.revision) },
    { key: 'date', label: 'Saved at', render: (row) => timestamp(row.created_at) },
    { key: 'author', label: 'Author', render: (row) => row.actor_name || `User #${row.actor_id}` },
    { key: 'actions', label: 'Actions', required: true, render: (row) => { const group = document.createElement('div'); button('View version', () => { preview.replaceChildren(); const facts = JSON.parse(row.payload); const heading = text('h4', `${facts.name} · version ${row.revision}`, preview); heading.tabIndex = -1; for (const field of ['description', 'rules']) { text('h5', field, preview); const content = document.createElement('div'); content.innerHTML = facts[field]; preview.append(content); } text('p', facts.tags.join(', '), preview); heading.focus(); }, group); return group; } }
   ] }, data.versions);
  button('Back to strategies', closeStrategy, strategyView);
 }
 const actions = document.createElement('div'); actions.className = 'tgit-actions'; $('trade-detail').append(actions);
 const save = $('trade-form').querySelector('button[type="submit"]'); save.id = 'tgit-save-journal'; save.setAttribute('form', 'tgit-trade-form'); actions.append(save, $('close-trade')); $('close-trade').textContent = 'Back to trades';
 const reload = button('Reload latest journal', () => { if (leave()) guarded(() => openTrade(trade.trade.id))(); }, actions); reload.hidden = true;
 $('trade-form').noValidate = true;
 function selectDetail(id) {
  save.hidden = !['plan', 'transactions'].includes(id) || !isEditor() || (id === 'transactions' && !trade);
  for (const [name, panel] of detailPanels) panel.hidden = name !== id;
  for (const tab of detailTabs.querySelectorAll('button')) { const selected = tab.dataset.detailTab === id; tab.setAttribute('aria-selected', String(selected)); tab.tabIndex = selected ? 0 : -1; }
 }
 function snapshot() { return JSON.stringify([...$('trade-form').elements].filter((field) => field.name).map((field) => [field.name, field.type === 'file' ? [...field.files].map((file) => [file.name, file.size, file.lastModified]) : field.value])) + JSON.stringify([...confluenceState]) + JSON.stringify([...labelEditors.values()].filter((editor) => editor.field.form === journalForm).map((editor) => editor.entry.value)); }
 const dirty = () => strategyDirty() || (!$('trade-detail').hidden && isEditor() && (baseline !== snapshot() || imageDirty()));
 const leave = () => !dirty() || window.confirm('Discard unsaved changes?');
 function routeTrade(id, replace = false) {
  const url = new URL(location.href); url.searchParams.set('tgit_section', 'journal'); url.searchParams.set('tgit_workspace', context.workspace);
  if (id) url.searchParams.set('tgit_trade', String(id)); else url.searchParams.delete('tgit_trade');
  if (url.href !== location.href) history[replace ? 'replaceState' : 'pushState'](null, '', url);
 }
 function closeTrade(updateRoute = true) {
  historyRows = []; historyCollection = null; revisionDetail.hidden = true; revisionDetail.replaceChildren(); tgitResetCollection($('trade-history'));
  tradeLoad++; candidateRows = []; candidateCollection = null; tgitResetCollection(fillPicker); fillPicker.hidden = true; tgitResetCollection($('fill-facts'));
  closeImageEditor(); galleryCollection = null; galleryRows = []; tgitResetCollection($('gallery')); releaseImages(); trade = null; $('trade-detail').hidden = true; list.hidden = false; baseline = '';
  if (updateRoute) routeTrade(null); window.scrollTo(0, listScroll); $('new-trade').focus();
 }
 function renderTrades(items) {
  tradeRows = items;
  tgitCollection($('trades'), { actor: config.actorId, workspace: context.workspace, key: 'trades', title: 'Trades', defaultSort: 'updated', defaultDescending: true,
   search: (row) => `${row.title} ${row.symbol} ${row.exchange} ${(row.tags || []).join(' ')}`,
   filters: [{ key: 'state', label: 'Trade view', default: 'current', values: [['current', 'Current and potential'], ['', 'All'], ['planned', 'Planned'], ['open', 'Open'], ['closed', 'Closed'], ['archived', 'Archived']], matches: (row, value) => !value || (value === 'current' ? ['planned', 'open'].includes(row.state) : row.state === value) }],
   columns: [
    { key: 'title', label: 'Trade title', identity: true, required: true, sort: (a, b) => a.title.localeCompare(b.title), render: (row) => { const link = document.createElement('a'); const url = new URL(location.href); url.searchParams.set('tgit_section', 'journal'); url.searchParams.set('tgit_workspace', context.workspace); url.searchParams.set('tgit_trade', row.id); link.href = url; link.textContent = row.title; link.addEventListener('click', (event) => { if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return; event.preventDefault(); if (leave()) guarded(() => openTrade(row.id))(); }); return link; } },
    { key: 'asset', label: 'Asset', sort: (a, b) => a.symbol.localeCompare(b.symbol), render: (row) => `${row.symbol} · ${row.exchange}` },
    { key: 'state', label: 'Status', sort: (a, b) => a.state.localeCompare(b.state), render: (row) => row.state },
    { key: 'strategy', label: 'Strategy', render: (row) => row.strategy_name || 'Not set' },
    { key: 'opened', label: 'Opened date', render: (row) => row.opened_on || 'Not set' },
    { key: 'updated', label: 'Updated', sort: (a, b) => (a.updated_at || a.created_at).localeCompare(b.updated_at || b.created_at), render: (row) => timestamp(row.updated_at || row.created_at) },
    { key: 'images', label: 'Active images', numeric: true, render: (row) => String(row.image_count) },
    { key: 'actions', label: 'Actions', required: true, render: (row) => { const group = document.createElement('div'); button('View', () => { if (leave()) guarded(() => openTrade(row.id))(); }, group); if (isEditor()) button('Edit', () => { if (leave()) guarded(async () => { await openTrade(row.id); selectDetail('plan'); })(); }, group); return group; } }
   ] }, items);
  $('more-trades').hidden = true;
 }
 async function refreshTrades(expected = epoch) { const items = await all('trades', expected); if (expected === epoch) renderTrades(items); }
 window.addEventListener('beforeunload', (event) => { if (dirty()) { event.preventDefault(); event.returnValue = ''; } });
 $('workspace').addEventListener('change', (event) => {
  if (!leave()) { event.stopImmediatePropagation(); $('workspace').value = context.workspace; return; }
  const url = new URL(location.href); url.searchParams.delete('tgit_trade'); url.searchParams.set('tgit_workspace', $('workspace').value); history.replaceState(null, '', url);
 }, true);
 $('tabs').addEventListener('click', (event) => {
  const target = event.target.closest('[role="tab"]')?.dataset.tab; if (!target || target === new URL(location.href).searchParams.get('tgit_section')) return;
  if (!leave()) { event.preventDefault(); event.stopPropagation(); } else { closeStrategy(); if (!$('trade-detail').hidden && target !== 'journal') closeTrade(false); }
 }, true);
 window.addEventListener('popstate', () => {
  if (!context) return; const url = new URL(location.href);
  if (!leave()) { if (strategyDirty()) { window.tgitSelectSection('strategies'); url.searchParams.set('tgit_section', 'strategies'); history.replaceState(null, '', url); return; } window.tgitSelectSection('journal'); routeTrade(trade?.trade.id || 'new', true); return; }
  if (!strategyForm.hidden || !strategyView.hidden) closeStrategy();
  if (url.searchParams.get('tgit_section') !== 'journal') { if (!$('trade-detail').hidden) closeTrade(false); return; }
  const id = url.searchParams.get('tgit_trade'); if (id === 'new') showTrade(null); else if (/^[1-9][0-9]*$/.test(id || '')) guarded(() => openTrade(id, false))(); else closeTrade(false);
 });
 function renderConfluences() {
  $('confluence-items').replaceChildren();
  for (const labelText of labels($('trade-form').elements.confluences.value)) {
   const label = text('label', '', $('confluence-items')); const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.checked = confluenceState.get(labelText) ?? false; checkbox.disabled = !isEditor(); checkbox.dataset.label = labelText;
   checkbox.addEventListener('change', () => confluenceState.set(labelText, checkbox.checked)); label.append(checkbox, document.createTextNode(labelText));
  }
 }
 $('trade-form').elements.confluences.addEventListener('input', renderConfluences);
 function showTrade(data) {
  reload.hidden = true; candidateRows = []; candidateCollection = null; tgitResetCollection(fillPicker); fillPicker.hidden = true; tgitResetCollection($('fill-facts'));
  save.textContent = data ? 'Save changes' : 'Create trade'; $('close-trade').textContent = data ? 'Back to trades' : 'Cancel';
  closeImageEditor(); galleryCollection = null; galleryRows = []; tgitResetCollection($('gallery')); releaseImages(); confluenceState.clear(); trade = data; const form = $('trade-form'); form.reset();
  listScroll = list.hidden ? listScroll : window.scrollY; list.hidden = true; $('trade-detail').hidden = false; $('trade-heading').textContent = data ? data.trade.title : 'New trade journal';
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
  renderConfluences(); for (const editor of labelEditors.values()) editor.entry.value = ''; renderLabelEditors(); $('trade-summary').replaceChildren();
  if (captured) { const snapshot = JSON.parse(captured.payload); text('p', `Captured strategy: ${snapshot.name} | version ${captured.revision}`, $('trade-summary')); for (const name of ['description', 'rules']) { const content = document.createElement('div'); content.innerHTML = snapshot[name]; $('trade-summary').append(content); } }
  previousAsset = form.elements.asset_id.value; renderLinkedFills(); pickLinks.hidden = !data || !isEditor(); linkHelp.hidden = !data;
  historyRows = []; historyCollection = null; tgitResetCollection($('trade-history')); revisionDetail.hidden = true; revisionDetail.replaceChildren();
  if (!data) renderHistory([]);
  for (const element of form.elements) if (!['button', 'submit'].includes(element.type)) { element.disabled = !isEditor() && (element.tagName === 'SELECT' || ['file', 'checkbox'].includes(element.type)); if ('readOnly' in element) element.readOnly = !isEditor(); }
  save.hidden = !isEditor(); uploadImages.hidden = !isEditor();
  $('gallery-section').hidden = !data; $('upload-progress').replaceChildren();
  for (const field of [...form.elements].filter((field) => field.name)) {
   const label = field.closest('label'); if (label) label.hidden = !data && !['title', 'asset_id', 'strategy_version_id'].includes(field.name);
  }
  $('confluence-checklist').hidden = !data;
  for (const child of form.children) if (child.tagName !== 'LABEL') child.hidden = !data && child.dataset.minimal !== 'true';
  text('p', data ? `${data.trade.state} · ${context.assets.find((asset) => String(asset.id) === String(data.trade.asset_id))?.symbol || ''}` : 'Planned trade — no financial effect', $('trade-summary'));
  if (data && context.assets.some((asset) => String(asset.id) === String(data.trade.asset_id) && asset.asset_class === 'stock')) {
   const asset = String(data.trade.asset_id), workspace = String(context.workspace);
   const fundamentals = button('View stock fundamentals', () => {
    if (busy || window.tgitWriteBusy) return;
    const research = $('tabs').querySelector('[data-tab="research"]'); research.click();
    if (research.getAttribute('aria-selected') === 'true') window.dispatchEvent(new CustomEvent('tgit-open-fundamentals', { detail: { workspace, asset } }));
   }, $('trade-summary'));
   fundamentals.setAttribute('aria-label', `View stock fundamentals for ${context.assets.find((item) => String(item.id) === asset).symbol}`);
  }
  selectDetail(data ? 'summary' : 'plan'); baseline = snapshot();
  routeTrade(data?.trade.id || 'new', Boolean(data) && new URL(location.href).searchParams.get('tgit_trade') === 'new');
 }
 async function openTrade(id, updateRoute = true) { const expected = epoch, load = ++tradeLoad; notice(messages.loading); const data = await api(path(`trades/${id}`)); if (expected !== epoch || load !== tradeLoad) return; showTrade(data); if (!updateRoute) routeTrade(id, true); await Promise.all([refreshGallery(), loadHistory(data)]); if (load !== tradeLoad) return; notice(''); $('trade-detail').scrollIntoView({ block: 'start' }); }
 async function binary(id, variant, expected) {
  const generation = galleryGeneration;
  const response = await fetch(tgitRestUrl(config.root, path(`images/${id}/content?variant=${variant}`)), { cache: 'no-store', credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce } });
  if (!response.ok) throw new Error('Image is unavailable or access was revoked.');
  const blob = await response.blob(); if (expected !== epoch || generation !== galleryGeneration) return null; const url = URL.createObjectURL(blob); imageUrls.push(url); return url;
 }
 async function viewImages(rows) {
  const expected = epoch; $('image-view').replaceChildren(); $('image-dialog').showModal();
  for (const row of rows) { const figure = document.createElement('figure'); const image = document.createElement('img'); image.alt = row.alt_text || row.caption || row.filename; figure.append(image); text('figcaption', row.caption, figure); const url = await binary(row.id, 'original', expected); if (!url || !$('image-dialog').open) return; image.src = url; const link = text('a', 'Download normalized original', figure); link.href = url; link.download = row.filename; $('image-view').append(figure); }
 }
 async function mutateImage(row, operation, extra = {}) { await write(path(`images/${row.id}/${operation}`), { expected_revision: Number(row.revision), ...extra }); await refreshGallery(); await refreshTrades(); }
 let imageBaseline = '', imageDraft = null;
 const uploadInput = $('trade-form').elements.images;
 uploadInput.setAttribute('form', 'tgit-trade-form');
 const uploadLabel = uploadInput.closest('label'), uploadHelp = uploadLabel.nextElementSibling;
 $('upload-progress').before(uploadLabel, uploadHelp);
 const uploadImages = button('Upload selected images', () => { guarded(async () => {
  if (!trade || !isEditor()) throw new Error('Create a trade first; image uploads require journal edit permission.');
  const files = [...uploadInput.files]; if (!files.length) throw new Error('Choose one or more images to upload.');
  const failures = []; for (const file of files) { try { await uploadFile(file); } catch (error) { failures.push(`${file.name}: ${error.message}`); } }
  uploadInput.value = ''; await refreshGallery(); await refreshTrades();
  notice(failures.length ? `${failures.length} image upload(s) failed. ${failures[0]}` : 'Images uploaded.', failures.length > 0);
 })(); }, $('gallery-section'));
 button('Refresh images', () => { if (!leaveImage()) return; guarded(async () => { closeImageEditor(); await refreshGallery(); await refreshTrades(); })(); }, $('gallery-section'));
 uploadImages.id = 'tgit-upload-images'; uploadImages.classList.add('button-primary'); $('upload-progress').before(uploadImages);
 const imageEditor = document.createElement('section'); imageEditor.id = 'tgit-image-editor'; imageEditor.hidden = true; $('gallery').after(imageEditor);
 const imageSnapshot = () => { const form = imageEditor.querySelector('form'); return form ? JSON.stringify([...new FormData(form)]) : ''; };
 const imageDirty = () => !imageEditor.hidden && imageBaseline !== imageSnapshot();
 const leaveImage = () => !imageDirty() || window.confirm('Discard unsaved image details?');
 function closeImageEditor() { imageEditor.hidden = true; imageEditor.replaceChildren(); imageDraft = null; imageBaseline = ''; }
 function editImage(row) {
  if (!leaveImage()) return;
  closeImageEditor(); imageDraft = row; imageEditor.hidden = false;
  const heading = text('h3', `Image details: ${row.filename}`, imageEditor); heading.tabIndex = -1;
  const form = document.createElement('form'); form.className = 'tgit-form';
  for (const [name, labelText] of [['caption', 'Caption'], ['alt_text', 'Accessible description'], ['timeframe', 'Chart timeframe'], ['sort_order', 'Order']]) {
   const label = text('label', labelText, form); const input = document.createElement('input'); input.name = name; input.value = row[name] ?? ''; input.maxLength = name === 'timeframe' ? 64 : 2000;
   if (name === 'sort_order') { input.type = 'number'; input.min = '0'; input.max = '100000'; input.required = true; } label.append(input);
  }
  const label = text('label', 'Image stage', form); const stage = document.createElement('select'); stage.name = 'stage'; stage.required = true;
  for (const [value, caption] of [['before', 'Before trade'], ['entry', 'Entry'], ['exit', 'Exit'], ['review', 'Review']]) { const option = text('option', caption, stage); option.value = value; } stage.value = row.stage || 'review'; label.append(stage);
  help(form, 'Lower order numbers appear first. Stage and timeframe describe the chart; they do not change posted trades.');
  const save = text('button', 'Save image details', form); save.type = 'submit'; save.className = 'button button-primary';
  form.addEventListener('submit', (event) => { event.preventDefault(); guarded(async () => {
   const body = Object.fromEntries(new FormData(form)); body.sort_order = Number(body.sort_order);
   await write(path(`images/${imageDraft.id}/metadata`), { expected_revision: Number(imageDraft.revision), ...body }); closeImageEditor(); await refreshGallery(); await refreshTrades(); notice(messages.saved);
  })(); }); imageEditor.append(form);
  button('Cancel image details', () => { if (leaveImage()) { closeImageEditor(); $('gallery').querySelector('input[type=search]')?.focus(); } }, imageEditor);
  const reloadImage = button('Reload latest image details', () => { if (!leaveImage()) return; const id = imageDraft.id; guarded(async () => { const gallery = await api(path(`trades/${trade.trade.id}/images`)); const current = gallery.items.find((item) => String(item.id) === String(id)); if (!current || current.state === 'deleted') { closeImageEditor(); await refreshGallery(); throw new Error('Image is no longer active.'); } closeImageEditor(); editImage(current); await refreshGallery(); notice('Latest image details loaded.'); })(); }, imageEditor);
  reloadImage.title = 'Discard your image draft and load the current saved metadata.';
  imageBaseline = imageSnapshot(); heading.focus();
 }
 async function refreshGallery() {
  if (!trade) return; await loadSettings(epoch); const expected = epoch, id = String(trade.trade.id);
  const gallery = await api(path(`trades/${id}/images`)); if (expected !== epoch || String(trade?.trade.id) !== id) return;
  releaseImages();
  $('gallery-status').textContent = storageProblem() || 'Images are private. Trash retains storage until cleanup.';
  const thumbnails = new Map();
  galleryRows = gallery.items;
  galleryCollection = tgitCollection($('gallery'), { actor: config.actorId, workspace: `${context.workspace}:${id}`, key: 'images', title: 'Images', defaultSort: 'order',
   search: (row) => `${row.filename} ${row.caption} ${row.alt_text} ${row.timeframe} ${row.stage}`,
   filters: [{ key: 'state', label: 'Image view', default: 'active', values: [['active', 'Active'], ['trash', 'Trash'], ['', 'All']], matches: (row, value) => !value || (value === 'trash' ? row.state === 'deleted' : row.state !== 'deleted') }],
   columns: [
    { key: 'filename', label: 'Filename', identity: true, required: true, sort: (a, b) => a.filename.localeCompare(b.filename), render: (row) => row.filename },
    { key: 'preview', label: 'Preview', render: (row) => {
     if (row.state !== 'ready') return 'Unavailable'; const image = document.createElement('img'); image.className = 'tgit-thumbnail'; image.alt = row.alt_text || row.caption || row.filename;
     const generation = galleryGeneration;
     if (!thumbnails.has(row.id)) thumbnails.set(row.id, binary(row.id, 'thumbnail', expected));
     thumbnails.get(row.id).then((url) => { if (url && image.isConnected && generation === galleryGeneration) image.src = url; }).catch(() => { image.alt = 'Preview unavailable'; }); return image;
    } },
    { key: 'state', label: 'Status', sort: (a, b) => a.state.localeCompare(b.state), render: (row) => row.state === 'deleted' ? 'Trash' : row.state },
    { key: 'stage', label: 'Stage', sort: (a, b) => a.stage.localeCompare(b.stage), render: (row) => row.stage },
    { key: 'timeframe', label: 'Timeframe', render: (row) => row.timeframe || 'Not set' },
    { key: 'caption', label: 'Caption', render: (row) => row.caption || 'Not set' },
    { key: 'order', label: 'Order', numeric: true, sort: (a, b) => Number(a.sort_order) - Number(b.sort_order), render: (row) => String(row.sort_order) },
    { key: 'compare', label: 'Compare', render: (row) => {
     if (row.state !== 'ready') return 'Unavailable'; const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.setAttribute('aria-label', `Compare ${row.filename}`); checkbox.checked = selected.has(row.id);
     checkbox.addEventListener('change', () => { if (checkbox.checked) selected.set(row.id, row); else selected.delete(row.id); $('compare-images').disabled = busy || window.tgitWriteBusy || selected.size !== 2; }); return checkbox;
    } },
    { key: 'actions', label: 'Actions', required: true, render: (row) => {
     const group = document.createElement('div'); group.className = 'tgit-row-actions';
     if (row.state === 'ready') button('View original', guarded(() => viewImages([row])), group);
     if (isEditor()) {
      if (row.state === 'deleted') button('Restore image', guarded(() => mutateImage(row, 'restore')), group);
      else {
       button('Edit details', () => { if (!busy && !window.tgitWriteBusy) editImage(row); }, group);
       if (['reserved', 'failed'].includes(row.state)) {
        const retry = document.createElement('input'); retry.type = 'file'; retry.accept = 'image/jpeg,image/png,image/webp'; retry.setAttribute('aria-label', `Retry or replace the pending file for ${row.filename}`); group.append(retry);
        button('Retry this image', () => { if (!leaveImage()) return; guarded(async () => { if (!retry.files[0]) throw new Error('Select a replacement or the original file for this image.'); await uploadFile(retry.files[0], row); closeImageEditor(); await refreshGallery(); await refreshTrades(); })(); }, group);
       }
       button('Move to Trash', () => { if (!leaveImage()) return; guarded(async () => { await mutateImage(row, 'delete'); closeImageEditor(); })(); }, group);
      }
     } return group;
    } }
   ] }, gallery.items);
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
  event.preventDefault(); if ([...labelEditors.values()].some((editor) => editor.field.form === journalForm && editor.entry.value.trim())) { selectDetail('plan'); notice('Add or clear the pending tag or confluence before saving.', true); return; } if (!leaveImage()) return; if (!$('trade-form').checkValidity()) { selectDetail('plan'); $('trade-form').reportValidity(); return; } guarded(async () => {
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
   await refreshTrades();
   await Promise.all([refreshGallery(), loadHistory(result)]); notice(failures.length ? `Journal saved. ${failures.length} image upload(s) failed. ${failures[0]}` : messages.saved, failures.length > 0);
  })();
 });
 $('new-trade').addEventListener('click', () => { if (busy || !leave()) return; showTrade(null); $('trade-detail').scrollIntoView({ block: 'start' }); $('trade-form').elements.title.focus(); });
 $('close-trade').addEventListener('click', () => { if (busy || !leave()) return; closeTrade(); });

 $('compare-images').addEventListener('click', guarded(() => viewImages([...selected.values()])));
 $('close-image').addEventListener('click', () => $('image-dialog').close());
 $('new-strategy').addEventListener('click', () => { if (!busy && isManager() && leaveStrategy()) showStrategy(null); });
 $('strategy-form').addEventListener('submit', (event) => { event.preventDefault(); if (labelEditors.get('strategyTags').entry.value.trim()) { notice('Add or clear the pending tag before saving.', true); return; } guarded(async () => {
  const input = Object.fromEntries(new FormData(strategyForm)); input.tags = labels(input.tags); if (strategy) input.expected_revision = Number(strategy.strategy.revision);
  await write(path(strategy ? `strategies/${strategy.strategy.id}` : 'strategies'), input); closeStrategy(); await loadStrategies(epoch); notice(messages.saved);
 })(); });
 async function loadStrategies(expected) {
  const items = await all('strategies', expected), details = [];
  for (const item of items) {
   const data = await api(path(`strategies/${item.id}`)); if (expected !== epoch) return; let next = data.versions_cursor;
   while (next !== null) { const page = await api(path(`strategies/${item.id}/versions?before=${next}&limit=20`)); if (expected !== epoch) return; data.versions.unshift(...page.items); next = page.next_cursor; }
   details.push(data);
  }
  if (expected !== epoch) return; strategyVersions = [];
  strategyRows = details.map((data) => { for (const version of data.versions) { const facts = JSON.parse(version.payload); if (facts.status === 'active') strategyVersions.push({ ...version, name: facts.name }); } return { ...data.strategy, tags: JSON.parse(data.versions.at(-1).payload).tags, data }; }); renderStrategies();
 }
 async function loadSettings(expected) { const data = await api(path('media-settings')); if (expected !== epoch) return; limits = data; const form = $('media-settings-form'); for (const name of ['max_images', 'max_file_bytes', 'max_pixels', 'quota_bytes', 'trash_days']) form.elements[name].value = data.limits[name]; $('media-health').textContent = `Retained/reserved bytes: ${data.used_bytes}. ${storageProblem() || 'Private storage is ready.'}`; }
 $('media-settings-form').addEventListener('submit', (event) => { event.preventDefault(); guarded(async () => { const input = Object.fromEntries([...new FormData($('media-settings-form'))].map(([key, value]) => [key, Number(value)])); input.expected_revision = Number(limits.limits.revision); await write(path('media-settings'), input); await loadSettings(epoch); notice(messages.saved); })(); });
 $('media-cleanup').addEventListener('click', guarded(async () => { const result = await write(path('media-cleanup'), {}); await loadSettings(epoch); if (trade) await refreshGallery(); notice(`Cleaned up ${result.purged} expired images/reservations.`); }));
 window.addEventListener('tgit-workspace', async (event) => {
  const routeId = new URL(location.href).searchParams.get('tgit_trade');
  context = event.detail; const expected = ++epoch; trade = null; strategy = null; strategyVersions = []; tradeRows = []; pending.clear(); closeImageEditor(); galleryCollection = null; galleryRows = []; releaseImages(); $('trade-detail').hidden = true; list.hidden = false; baseline = ''; tgitResetCollection($('trades')); tgitResetCollection($('gallery'));
  historyRows = []; historyCollection = null; revisionDetail.hidden = true; revisionDetail.replaceChildren();
  $('trade-form').reset(); confluenceState.clear(); for (const editor of labelEditors.values()) editor.entry.value = ''; renderLabelEditors(); candidateRows = []; candidateCollection = null; tgitResetCollection(fillPicker); fillPicker.hidden = true; tgitResetCollection($('fill-facts'));
  for (const id of ['trade-summary', 'fill-facts', 'trade-history', 'gallery', 'image-view', 'upload-progress']) $(id).replaceChildren();
  choices($('trade-form').elements.asset_id, context.assets, (item) => `${item.symbol} · ${item.exchange}`);
  choices($('trade-form').elements.strategy_version_id, [], (item) => item.name, true);
  $('new-trade').hidden = !isEditor(); closeStrategy(); newStrategy.hidden = !isManager(); strategyRows = []; strategyCollection = null; tgitResetCollection($('strategies')); $('media-settings-section').hidden = context.role !== 'owner'; notice(messages.loading);
  try { await Promise.all([refreshTrades(expected), loadStrategies(expected), loadSettings(expected)]); if (expected === epoch) { notice(''); if (routeId && routeId === new URL(location.href).searchParams.get('tgit_trade')) { if (routeId === 'new') showTrade(null); else if (/^[1-9][0-9]*$/.test(routeId)) await openTrade(routeId); } } } catch (error) { if (expected === epoch) notice(error.message, true); }
 });
})();
