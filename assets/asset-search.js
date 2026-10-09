/* Public suggestions supplement workspace identities; free text never becomes an asset ID. */
(() => {
 'use strict';
 const root = document.getElementById('tgit-app'); if (!root) return;
 let savedAssets = [], workspace = '', catalogs = { stock: [], crypto: [] }, loading = { stock: null, crypto: null }, epoch = 0, sequence = 0;
 const controls = new Map();
 const names = (symbol, kind) => catalogs[kind].filter((row) => row.symbol === symbol).map((row) => `${row.title}${row.id ? ` [${row.id}]` : ''}`).join(' | ');
 async function load(kind) {
  if (catalogs[kind].length || !workspace) return;
  if (!loading[kind]) {
   const expected = epoch, selected = workspace;
   loading[kind] = (async () => {
    const response = await fetch(tgitRestUrl(tgitConfig.root, `workspaces/${selected}/${kind === 'crypto' ? 'coin-catalog' : 'ticker-catalog'}`), { credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': tgitConfig.nonce } });
    const result = await response.json();
    if (!response.ok || !Array.isArray(result.data?.items)) throw new Error('unavailable');
    if (expected === epoch) catalogs[kind] = result.data.items;
   })();
   try { await loading[kind]; } finally { if (expected === epoch) loading[kind] = null; }
  } else await loading[kind];
 }
 function enhance(field) {
  if (controls.has(field)) return;
  const symbol = field.tagName === 'INPUT';
  const kind = () => field.form?.elements.asset_class?.value === 'crypto' ? 'crypto' : 'stock';
  const box = document.createElement('div'); box.className = 'tgit-asset-search';
  const input = document.createElement('input'); input.type = 'search'; input.autocomplete = 'off'; input.placeholder = symbol ? 'Ticker, coin or company name' : 'Search saved assets'; input.setAttribute('aria-label', symbol ? 'Find asset by symbol, coin or company name' : 'Search saved assets by symbol, coin or company name');
  const list = document.createElement('datalist'); list.id = `tgit-asset-suggestions-${++sequence}`; input.setAttribute('list', list.id);
  const status = document.createElement('small'); status.id = `${list.id}-status`; status.setAttribute('role', 'status'); input.setAttribute('aria-describedby', status.id);
  box.append(input, list, status);
  // Avoid nesting an additional input inside the field's label.
  const label = field.closest('label');
  const wrapper = document.createElement('div'); wrapper.className = 'tgit-asset-field';
  (label || field).replaceWith(wrapper); wrapper.append(label || field, box);
  let choices = [];
  function reset() { input.value = ''; input.setCustomValidity(''); list.replaceChildren(); status.textContent = ''; }
  function render() {
   input.disabled = field.disabled; wrapper.hidden = Boolean(label?.hidden) || field.hidden;
   const query = input.value.trim().toUpperCase(); list.replaceChildren(); choices = [];
   if (!query) { status.textContent = symbol ? 'Search the public asset list; confirm the asset, exchange and currency.' : 'Suggestions select an existing asset in this workspace.'; return; }
   const rows = symbol ? catalogs[kind()].map((row) => ({ value: row.symbol, pick: kind() === 'crypto' ? `${row.symbol} — ${row.title} [${row.id}]` : row.symbol, text: `${row.title}${row.id ? ` [${row.id}]` : ''}`, search: `${row.symbol} ${row.title} ${row.id || ''}` })) : Array.from(field.options).filter((option) => option.value).map((option) => { const asset = savedAssets.find((row) => String(row.id) === option.value); const title = names(asset?.symbol || option.textContent.split(' · ')[0], asset?.asset_class === 'crypto' ? 'crypto' : 'stock'); return { value: option.value, text: `${option.textContent}${title ? ` — ${title}` : ''}`, search: `${option.textContent} ${title}` }; });
   const matches = rows.filter((row) => row.search.toUpperCase().includes(query) || (symbol ? row.pick : row.text).toUpperCase() === query).sort((a, b) => Number(b.search.toUpperCase().startsWith(query)) - Number(a.search.toUpperCase().startsWith(query)));
   choices = matches.slice(0, 30);
   for (const row of choices) { const option = document.createElement('option'); option.value = symbol ? row.pick : row.text; option.label = symbol ? `${row.value} — ${row.text}` : row.text; list.append(option); }
   status.textContent = matches.length ? `${matches.length} matches; showing up to 30. Choose a suggestion.` : symbol ? 'No catalogue match. You can still enter a symbol manually.' : 'No saved asset matches. Add the asset in Settings first.';
  }
  function choose() {
   const choice = choices.find((row) => (symbol ? row.pick : row.text) === input.value);
   if (choice && !field.disabled && !window.tgitWriteBusy) {
    field.value = choice.value; field.dispatchEvent(new Event('input', { bubbles: true })); field.dispatchEvent(new Event('change', { bubbles: true }));
    input.setCustomValidity(''); if (field.value !== choice.value) { status.textContent = 'Selection unchanged. Finish or discard unsaved changes first.'; return; } status.textContent = symbol ? `Selected ${choice.value} — ${choice.text}. Confirm exchange and currency.` : 'Saved asset selected.';
   } else input.setCustomValidity(input.value ? 'Choose a suggestion or clear the search and use the original field.' : '');
  }
  input.addEventListener('focus', async () => { render(); const expected = epoch; try {
   const kinds = symbol ? [kind()] : [...new Set(savedAssets.map((asset) => asset.asset_class === 'crypto' ? 'crypto' : 'stock'))];
   const results = await Promise.allSettled(kinds.map(load));
   if (expected === epoch) { render(); if (results.some((result) => result.status === 'rejected')) status.textContent = 'Asset lookup partly unavailable. Saved asset selection and manual symbol entry still work.'; }
  } catch { if (expected === epoch) status.textContent = 'Asset lookup unavailable. Manual entry still works.'; } });
  input.addEventListener('input', () => { render(); choose(); });
  input.addEventListener('change', choose);
  input.addEventListener('keydown', (event) => { if (event.key === 'Escape') { reset(); field.focus(); } });
  field.addEventListener('input', reset);
  field.addEventListener('change', reset);
  field.form?.addEventListener('reset', () => setTimeout(reset, 0));
  field.form?.elements.asset_class?.addEventListener('change', () => { reset(); render(); });
  new MutationObserver(() => { reset(); render(); }).observe(field, { childList: true, attributes: true, attributeFilter: ['disabled', 'hidden'] });
  if (label) new MutationObserver(render).observe(label, { attributes: true, attributeFilter: ['hidden'] });
  controls.set(field, { reset, render }); render();
 }
 function scan() { root.querySelectorAll('select[name="asset_id"], #tgit-fundamental-asset, #tgit-asset-form input[name="symbol"]').forEach(enhance); }
 window.addEventListener('tgit-workspace', (event) => { ++epoch; workspace = String(event.detail.workspace || ''); savedAssets = event.detail.assets || []; loading = { stock: null, crypto: null }; for (const control of controls.values()) control.reset(); scan(); });
 new MutationObserver((changes) => {
  if (changes.some((change) => Array.from(change.addedNodes).some((node) => node.nodeType === 1 && (node.matches('select[name=asset_id], #tgit-fundamental-asset') || node.querySelector('select[name=asset_id], #tgit-fundamental-asset'))))) scan();
 }).observe(root, { childList: true, subtree: true });
 scan();
})();
