/* Private server-side decimal scenarios; these forms never post ledger facts. */
(() => {
 'use strict';
 const config = window.tgitConfig;
 let workspace = '';
 const forms = [
  { id: 'tgit-crypto-calculator', type: 'crypto', output: 'tgit-crypto-result', lines: (result) => [
   `Units: ${result.units}`,
   `Gross position value: ${result.position_value} ${result.currency}`,
   `Net exit value: ${result.net_exit_value} ${result.currency}`,
   `Profit: ${result.profit_amount} ${result.currency} (${result.profit_percentage}%)`,
   result.fees_included ? 'Entered buy and sell fees are included in profit.' : 'No fees were entered.'
  ] },
  { id: 'tgit-risk-calculator', type: 'risk', output: 'tgit-risk-result', lines: (result) => [
   `Stop price: ${result.stop_price} ${result.currency}`,
   `Position size: ${result.position_size} units`,
   `Capital required: ${result.capital_required} ${result.currency}`,
   `Risk at stop: ${result.risk_used} ${result.currency}`
  ] }
 ];
 function show(output, lines, error = false) {
  output.replaceChildren(); output.setAttribute('role', error ? 'alert' : 'status');
  for (const line of lines) { const item = document.createElement('p'); item.textContent = line; output.append(item); }
 }
 for (const item of forms) {
  const form = document.getElementById(item.id);
  const output = document.getElementById(item.output);
  if (!form || !output) continue;
  form.addEventListener('submit', async (event) => {
   event.preventDefault(); if (!workspace) return;
   const button = form.querySelector('button[type="submit"], button:not([type])'); button.disabled = true;
   show(output, [config.i18n.loading]);
   try {
    const payload = Object.fromEntries(new FormData(form));
    const response = await fetch(tgitRestUrl(config.root, `workspaces/${workspace}/calculators/${item.type}`), {
     method: 'POST', credentials: 'same-origin', cache: 'no-store',
     headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce }, body: JSON.stringify(payload)
    });
    const envelope = await response.json();
    if (!response.ok) throw new Error(envelope.message || config.i18n.network);
    show(output, item.lines(envelope.data));
   } catch (error) { show(output, [error.message || config.i18n.network], true); }
   finally { button.disabled = false; }
  });
 }
 window.addEventListener('tgit-workspace', (event) => {
  workspace = event.detail.workspace;
  for (const item of forms) document.getElementById(item.output)?.replaceChildren();
 });
})();
