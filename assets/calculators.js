/* Private server-side decimal scenarios; these forms never post ledger facts. */
(() => {
 'use strict';
 const config = window.tgitConfig;
 let workspace = '';
 let generation = 0;
 const display = (value) => window.tgitDisplayDecimal ? window.tgitDisplayDecimal(value, 2) : value;
 const forms = [
  { id: 'tgit-option-calculator', type: 'option', output: 'tgit-option-result', lines: (result) => [
   `Bought option: ${result.option_type === 'call' ? 'Call' : 'Put'}`,
   `Starting capital (premium + entry fee): ${display(result.starting_capital)} ${result.currency}`,
   `${result.mode === 'expiry' ? 'Expiration payoff before fees' : 'Exit premium value before fees'}: ${display(result.gross_exit_value)} ${result.currency}`,
   `Total fees: ${display(result.total_fees)} ${result.currency}`,
   `Net profit: ${display(result.net_profit)} ${result.currency}`,
   `Return on starting capital: ${display(result.return_on_capital)}%`,
   'Scenario only. Exercise, assignment and resulting stock/cash transactions are not modeled.'
  ] },
  { id: 'tgit-stock-calculator', type: 'stock', output: 'tgit-stock-result', lines: (result) => [
   `Entry share value: ${display(result.entry_value)} ${result.currency}`,
   `Exit share value: ${display(result.exit_value)} ${result.currency}`,
   `Gross profit: ${display(result.gross_profit)} ${result.currency}`,
   `Entered costs: ${display(result.total_costs)} ${result.currency}`,
   `Net profit: ${display(result.net_profit)} ${result.currency}`,
   `Return on entry share value: ${display(result.return_on_entry_value)}%`,
   result.direction === 'short' ? 'Margin required: unavailable. This scenario does not borrow or sell shares.' : 'This scenario does not buy or sell shares.'
  ] },
  { id: 'tgit-short-risk-calculator', type: 'short-risk', output: 'tgit-short-risk-result', lines: (result) => [
   `Position size: ${result.position_size} whole shares`,
   `Stop loss: ${display(result.stop_price)} ${result.currency}`,
   `Notional exposure: ${display(result.notional_exposure)} ${result.currency}`,
   `Price loss at stop: ${display(result.price_loss_at_stop)} ${result.currency}`,
   `Estimated costs: ${display(result.estimated_costs)} ${result.currency}`,
   `Total estimated risk: ${display(result.risk_used)} ${result.currency}`,
   `Unused risk budget: ${display(result.unused_budget)} ${result.currency}`,
   'Margin required: unavailable. Assumes exit at the stop; actual loss may be larger.'
  ] },
  { id: 'tgit-leveraged-calculator', type: 'leveraged', output: 'tgit-leveraged-result', lines: (result) => [
   `Notional exposure: ${display(result.notional_exposure)} ${result.currency}`,
   `Equivalent units: ${tgitDisplayDecimal(result.equivalent_units, 0)}`,
   `Gross profit: ${display(result.gross_profit)} ${result.currency}`,
   `Entered costs: ${display(result.total_costs)} ${result.currency}`,
   `Net profit: ${display(result.net_profit)} ${result.currency}`,
   `Return on entered collateral: ${display(result.return_on_collateral)}%`,
   'Liquidation price: unavailable. Hypothetical linear scenario; no position is opened.'
  ] },
  { id: 'tgit-crypto-calculator', type: 'crypto', output: 'tgit-crypto-result', lines: (result) => [
   `Units: ${tgitDisplayDecimal(result.units, 0)}`,
   `Gross position value: ${display(result.position_value)} ${result.currency}`,
   `Net exit value: ${display(result.net_exit_value)} ${result.currency}`,
   `Profit: ${display(result.profit_amount)} ${result.currency} (${display(result.profit_percentage)}%)`,
   result.fees_included ? 'Entered buy and sell fees are included in profit.' : 'No fees were entered.'
  ] },
  { id: 'tgit-risk-calculator', type: 'risk', output: 'tgit-risk-result', lines: (result) => [
   `Stop loss: ${display(result.stop_price)} ${result.currency}`,
   `Position size: ${tgitDisplayDecimal(result.position_size, 0)} units`,
   `Starting capital required: ${display(result.capital_required)} ${result.currency}`,
   `Estimated loss at stop: ${display(result.risk_used)} ${result.currency}`
  ] }
 ];
 const stockForm = document.getElementById('tgit-stock-calculator');
 const stockFields = () => {
  if (!stockForm) return;
  const short = stockForm.elements.direction.value === 'short';
  for (const label of stockForm.querySelectorAll('[data-stock-short]')) { label.hidden = !short; label.querySelector('input').disabled = !short; }
  document.getElementById('tgit-stock-result')?.replaceChildren();
 };
 stockForm?.elements.direction.addEventListener('change', stockFields); stockFields();
 const optionForm = document.getElementById('tgit-option-calculator');
 const optionFields = () => {
  if (!optionForm) return;
  for (const label of optionForm.querySelectorAll('[data-option-mode]')) {
   const active = label.dataset.optionMode === optionForm.elements.mode.value;
   label.hidden = !active; const input = label.querySelector('input'); input.disabled = !active; input.required = active;
  }
  document.getElementById('tgit-option-result')?.replaceChildren();
 };
 optionForm?.elements.mode.addEventListener('change', optionFields); optionFields();
 function show(output, lines, error = false) {
  output.replaceChildren(); output.setAttribute('role', error ? 'alert' : 'status');
  for (const line of lines) { const item = document.createElement('p'); item.textContent = line; output.append(item); }
 }
 for (const item of forms) {
  const form = document.getElementById(item.id);
  const output = document.getElementById(item.output);
  if (!form || !output) continue;
  let inputRevision = 0;
  form.addEventListener('input', () => { inputRevision += 1; output.replaceChildren(); });
  form.addEventListener('submit', async (event) => {
   event.preventDefault(); if (!workspace) return;
   const expected = generation;
   const submittedRevision = inputRevision;
   const button = form.querySelector('button[type="submit"], button:not([type])'); button.disabled = true;
   show(output, [config.i18n.loading]);
   try {
    const payload = Object.fromEntries(new FormData(form));
    const response = await fetch(tgitRestUrl(config.root, `workspaces/${workspace}/calculators/${item.type}`), {
     method: 'POST', credentials: 'same-origin', cache: 'no-store',
     headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce }, body: JSON.stringify(payload)
    });
    const envelope = await response.json();
    if (expected !== generation || submittedRevision !== inputRevision) return;
    if (!response.ok) throw new Error(envelope.message || config.i18n.network);
    show(output, item.lines(envelope.data));
   } catch (error) { if (expected === generation && submittedRevision === inputRevision) show(output, [error.message || config.i18n.network], true); }
   finally { button.disabled = false; }
  });
 }
 window.addEventListener('tgit-workspace', (event) => {
  workspace = event.detail.workspace;
  generation += 1;
  for (const item of forms) document.getElementById(item.output)?.replaceChildren();
 });
})();
