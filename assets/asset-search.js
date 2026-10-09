/* Public suggestions supplement workspace identities; free text never becomes an asset ID. */
(() => {
 'use strict';
 const root = document.getElementById('tgit-app'); if (!root) return;
 let savedAssets = [], workspace = '', catalog = [], loading = null, epoch = 0, sequence = 0;
 const controls = new Map();
 const company = (symbol) => catalog.find((row) => row.symbol === symbol)?.title || '';
 async function load() {
  if (catalog.length || !workspace) return;
  if (!loading) {
   const expected = epoch, selected = workspace;
   loading = (async () => {
    const response = await fetch(tgitRestUrl(tgitConfig.root, `workspaces/${selected}/ticker-catalog`), { credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': tgitConfig.nonce } });
    const result = await response.json();
    if (!response.ok || !Array.isArray(result.data?.items)) throw new Error('unavailable');
    if (expected === epoch) catalog = result.data.items;
   })();
   try { await loading; } finally { if (expected === epoch) loading = null; }
  } else await loading;
 }
 function enhance(field) {
  if (controls.has(field)) return;
  const symbol = field.tagName === 'INPUT';
  const box = document.createElement('div'); box.className = 'tgit-asset-search';
  const input = document.createElement('input'); input.type = 'search'; input.autocomplete = 'off'; input.placeholder = symbol ? 'Ticker or company name' : 'Search saved assets'; input.setAttribute('aria-label', symbol ? 'Find ticker by symbol or company name' : 'Search saved assets by ticker or company name');
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
   if (symbol && field.form?.elements.asset_class?.value === 'crypto') { status.textContent = 'Crypto symbols can be entered manually in Symbol.'; return; }
   if (!query) { status.textContent = symbol ? 'Search the public ticker list; confirm exchange and currency below.' : 'Suggestions select an existing asset in this workspace.'; return; }
   const rows = symbol ? catalog.map((row) => ({ value: row.symbol, text: row.title, search: `${row.symbol} ${row.title}` })) : Array.from(field.options).filter((option) => option.value).map((option) => { const asset = savedAssets.find((row) => String(row.id) === option.value); const title = asset?.asset_class === 'crypto' ? '' : company(option.textContent.split(' · ')[0]); return { value: option.value, text: `${option.textContent}${title ? ` — ${title}` : ''}`, search: `${option.textContent} ${title}` }; });
   const matches = rows.filter((row) => row.search.toUpperCase().includes(query) || (symbol ? row.value : row.text).toUpperCase() === query).sort((a, b) => Number(b.search.toUpperCase().startsWith(query)) - Number(a.search.toUpperCase().startsWith(query)));
   choices = matches.slice(0, 30);
   for (const row of choices) { const option = document.createElement('option'); option.value = symbol ? row.value : row.text; option.label = symbol ? `${row.value} — ${row.text}` : row.text; list.append(option); }
   status.textContent = matches.length ? `${matches.length} matches; showing up to 30. Choose a suggestion.` : symbol ? 'No catalogue match. You can still enter a symbol manually.' : 'No saved asset matches. Add the asset in Settings first.';
  }
  function choose() {
   const choice = choices.find((row) => (symbol ? row.value : row.text) === input.value);
   if (choice && !field.disabled && !window.tgitWriteBusy) {
    field.value = choice.value; field.dispatchEvent(new Event('input', { bubbles: true })); field.dispatchEvent(new Event('change', { bubbles: true }));
    input.setCustomValidity(''); if (field.value !== choice.value) { status.textContent = 'Selection unchanged. Finish or discard unsaved changes first.'; return; } status.textContent = symbol ? `Selected ${choice.value} — ${choice.text}. Confirm exchange and currency.` : 'Saved asset selected.';
   } else input.setCustomValidity(input.value ? 'Choose a suggestion or clear the search and use the original field.' : '');
  }
  input.addEventListener('focus', async () => { render(); const expected = epoch; try { await load(); if (expected === epoch) render(); } catch { if (expected === epoch) status.textContent = 'Company lookup unavailable. Saved asset selection and manual symbol entry still work.'; } });
  input.addEventListener('input', () => { render(); choose(); });
  input.addEventListener('change', choose);
  input.addEventListener('keydown', (event) => { if (event.key === 'Escape') { reset(); field.focus(); } });
  field.addEventListener('input', reset);
  field.addEventListener('change', reset);
  field.form?.addEventListener('reset', () => setTimeout(reset, 0));
  field.form?.elements.asset_class?.addEventListener('change', reset);
  new MutationObserver(() => { reset(); render(); }).observe(field, { childList: true, attributes: true, attributeFilter: ['disabled', 'hidden'] });
  if (label) new MutationObserver(render).observe(label, { attributes: true, attributeFilter: ['hidden'] });
  controls.set(field, { reset, render }); render();
 }
 function scan() { root.querySelectorAll('select[name="asset_id"], #tgit-fundamental-asset, #tgit-asset-form input[name="symbol"]').forEach(enhance); }
 window.addEventListener('tgit-workspace', (event) => { ++epoch; workspace = String(event.detail.workspace || ''); savedAssets = event.detail.assets || []; loading = null; for (const control of controls.values()) control.reset(); scan(); });
 new MutationObserver((changes) => {
  if (changes.some((change) => Array.from(change.addedNodes).some((node) => node.nodeType === 1 && (node.matches('select[name=asset_id], #tgit-fundamental-asset') || node.querySelector('select[name=asset_id], #tgit-fundamental-asset'))))) scan();
 }).observe(root, { childList: true, subtree: true });
 scan();
})();
