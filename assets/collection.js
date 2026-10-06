/* Shared semantic collection for a complete authorized dataset, never a loaded page. */
(() => {
 'use strict';
 const instances = new WeakMap();
 const node = (tag, value, parent) => { const element = document.createElement(tag); if (value !== undefined) element.textContent = value; if (parent) parent.append(element); return element; };
 function create(target, options) {
  target.classList.remove('tgit-cards'); target.classList.add('tgit-collection'); target.replaceChildren();
  let rows = [], page = 0, query = '', order = options.defaultSort || '', descending = options.defaultDescending === true;
  const key = `tgit-collection:${options.actor}:${options.workspace}:${options.key}`;
  let preferences = {};
  try {
   const saved = JSON.parse(localStorage.getItem(key) || '{}');
   if (saved && typeof saved === 'object' && !Array.isArray(saved)) preferences = saved;
  } catch (_) { /* Storage is optional. */ }
  let size = [25, 50, 100].includes(preferences.size) ? preferences.size : 25;
  let compact = preferences.compact === true;
  const visible = new Set(options.columns.filter((column) => column.required || !Array.isArray(preferences.columns) || preferences.columns.includes(column.key)).map((column) => column.key));
  function persist() { try { localStorage.setItem(key, JSON.stringify({ size, compact, columns: [...visible] })); } catch (_) { /* Keep preferences in memory. */ } }
  const toolbar = node('div', undefined, target); toolbar.className = 'tgit-collection-toolbar';
  const title = node('h3', options.title, toolbar);
  const searchLabel = node('label', 'Search', toolbar); const search = node('input', undefined, searchLabel); search.type = 'search'; search.setAttribute('aria-label', `Search ${options.title}`);
  const clear = node('button', 'Clear search', toolbar); clear.type = 'button'; clear.className = 'button';
  const filterValues = {}, filterSelects = new Map();
  for (const filter of options.filters || []) {
   const label = node('label', filter.label, toolbar); const select = node('select', undefined, label);
   select.setAttribute('aria-label', filter.label);
   for (const [value, title] of filter.values) { const option = node('option', title, select); option.value = value; }
   select.value = filter.default || ''; filterValues[filter.key] = select.value;
   filterSelects.set(filter.key, select);
   select.addEventListener('change', () => { filterValues[filter.key] = select.value; page = 0; render(); });
  }
  if ((options.filters || []).length) {
   const reset = node('button', 'Clear filters', toolbar); reset.type = 'button'; reset.className = 'button';
   reset.addEventListener('click', () => { query = ''; search.value = ''; page = 0; for (const [key, select] of filterSelects) { select.value = ''; filterValues[key] = ''; } render(); });
  }
  const settings = node('details', undefined, toolbar); node('summary', 'Columns and density', settings);
  const controls = node('div', undefined, settings); controls.className = 'tgit-collection-options';
  const densityLabel = node('label', undefined, controls); const density = node('input', undefined, densityLabel); density.type = 'checkbox'; density.checked = compact; densityLabel.append(document.createTextNode('Compact desktop rows'));
  density.addEventListener('change', () => { compact = density.checked; persist(); render(); });
  for (const column of options.columns) {
   const label = node('label', undefined, controls); const checkbox = node('input', undefined, label); checkbox.type = 'checkbox'; checkbox.checked = visible.has(column.key); checkbox.disabled = column.required === true; label.append(document.createTextNode(column.label));
   checkbox.addEventListener('change', () => { if (checkbox.checked) visible.add(column.key); else visible.delete(column.key); persist(); render(); });
  }
  const feedback = node('p', '', target); feedback.setAttribute('role', 'status'); feedback.setAttribute('aria-live', 'polite');
  const region = node('div', undefined, target); region.className = 'tgit-table-scroll'; region.tabIndex = 0; region.setAttribute('role', 'region'); region.setAttribute('aria-label', `${options.title} table, scroll horizontally to compare columns`);
  const table = node('table', undefined, region); const caption = node('caption', options.title, table); caption.className = 'screen-reader-text';
  const head = node('thead', undefined, table); const body = node('tbody', undefined, table);
  const pagination = node('div', undefined, target); pagination.className = 'tgit-collection-pagination';
  const previous = node('button', 'Previous page', pagination); previous.type = 'button'; previous.className = 'button';
  const pageInfo = node('span', '', pagination);
  const next = node('button', 'Next page', pagination); next.type = 'button'; next.className = 'button';
  const sizeLabel = node('label', 'Rows per page', pagination); const pageSize = node('select', undefined, sizeLabel);
  for (const value of [25, 50, 100]) { const option = node('option', String(value), pageSize); option.value = value; } pageSize.value = size;
  search.addEventListener('input', () => { query = search.value.trim().toLocaleLowerCase(); page = 0; render(); });
  clear.addEventListener('click', () => { query = ''; search.value = ''; page = 0; render(); search.focus(); });
  previous.addEventListener('click', () => { page--; render(); }); next.addEventListener('click', () => { page++; render(); });
  pageSize.addEventListener('change', () => { size = Number(pageSize.value); page = 0; persist(); render(); });
  function render() {
   target.classList.toggle('tgit-compact', compact); head.replaceChildren(); body.replaceChildren();
   const columns = options.columns.filter((column) => visible.has(column.key)); const header = node('tr', undefined, head);
   for (const column of columns) {
    const cell = node('th', undefined, header); cell.scope = 'col';
    if (column.key === 'actions') cell.classList.add('tgit-actions-cell');
    if (column.sort) {
     cell.setAttribute('aria-sort', order === column.key ? (descending ? 'descending' : 'ascending') : 'none');
     const sort = node('button', column.label, cell); sort.type = 'button'; sort.setAttribute('aria-label', `Sort by ${column.label}`);
     sort.addEventListener('click', () => { descending = order === column.key ? !descending : false; order = column.key; page = 0; render(); head.querySelector(`[data-column="${column.key}"]`).focus(); }); sort.dataset.column = column.key;
    } else cell.textContent = column.label;
   }
   let matches = rows.filter((row) => (!query || options.search(row).toLocaleLowerCase().includes(query)) && (options.filters || []).every((filter) => filter.matches(row, filterValues[filter.key])));
   const sorted = options.columns.find((column) => column.key === order);
   if (sorted) matches = [...matches].sort((a, b) => { const difference = sorted.sort(a, b); return (descending ? -difference : difference) || String(a.id).localeCompare(String(b.id), undefined, { numeric: true }); });
   const pages = Math.max(1, Math.ceil(matches.length / size)); page = Math.max(0, Math.min(page, pages - 1));
   title.textContent = `${options.title} (${rows.length})`;
   const filters = [...filterSelects.values()].filter((select) => select.value).map((select) => select.selectedOptions[0].textContent).join(', ');
   feedback.textContent = rows.length === 0 ? 'No records yet.' : matches.length === 0 ? 'No records match the current search or view.' : `${matches.length} records${query ? ' match your search' : ''}.${filters ? ` View: ${filters}.` : ''}`;
   for (const row of matches.slice(page * size, (page + 1) * size)) {
    const tr = node('tr', undefined, body);
    for (const column of columns) {
     const cell = node(column.identity ? 'th' : 'td', undefined, tr); if (column.identity) cell.scope = 'row'; cell.dataset.label = column.label;
     if (column.numeric) cell.className = 'tgit-number';
     const value = column.render(row); if (value instanceof Node) cell.append(value); else cell.textContent = value ?? 'Not set';
     if (column.key === 'actions') {
      cell.classList.add('tgit-actions-cell');
      let group = cell.firstElementChild;
      if (!group || group.tagName !== 'DIV') { group = document.createElement('div'); group.append(...cell.childNodes); cell.append(group); }
      group.classList.add('tgit-row-actions');
      const captions = { 'View transaction': 'View', 'Edit draft': 'Edit', 'Post draft': 'Post', 'View versions': 'Versions', 'Edit strategy': 'Edit', 'View version': 'View', 'View revision': 'View' };
      for (const button of group.querySelectorAll('button')) {
       const original = button.textContent;
       if (captions[original]) { if (!button.hasAttribute('aria-label')) button.setAttribute('aria-label', original); button.title = original; button.textContent = captions[original]; }
      }
     }
    }
   }
   previous.disabled = page === 0; next.disabled = page >= pages - 1; pageInfo.textContent = `Page ${page + 1} of ${pages}`; clear.disabled = !query;
  }
  return { workspace: options.workspace, update(items, nextOptions) { if (nextOptions) options = nextOptions; rows = items; render(); }, clear() { rows = []; query = ''; search.value = ''; render(); } };
 }
 window.tgitCollection = (target, options, rows) => {
  let instance = instances.get(target);
  if (!instance || instance.workspace !== options.workspace) { instance = create(target, options); instances.set(target, instance); }
  instance.update(rows, options); return instance;
 };
 window.tgitResetCollection = (target) => { instances.delete(target); target.replaceChildren(); };
 window.tgitDisplayDecimal = (value, minimum = 2) => {
  const match = String(value).match(/^(-?\d+)(?:\.(\d+))?$/);
  if (!match) return String(value);
  const fraction = (match[2] || '').replace(/0+$/, '').padEnd(minimum, '0');
  return fraction ? `${match[1]}.${fraction}` : match[1];
 };
})();
