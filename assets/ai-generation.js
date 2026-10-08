/* Explicit owner generation with retained identity and read-only recovery. */
(() => {
 'use strict';
 window.tgitAiGenerationPanel = (host, approval, workspace, send, current, changeBusy) => {
  const panel = document.createElement('section'), title = document.createElement('h4'), setupButton = document.createElement('button'), generateButton = document.createElement('button'), recoverButton = document.createElement('button'), message = document.createElement('p'), outcome = document.createElement('p');
  title.textContent = 'AI summary';
  for (const button of [setupButton, generateButton, recoverButton]) { button.type = 'button'; button.className = 'button'; }
  generateButton.classList.add('button-primary'); setupButton.textContent = 'Check summary setup'; recoverButton.textContent = 'Check saved summary result';
  for (const text of [message, outcome]) { text.setAttribute('role', 'status'); text.setAttribute('aria-live', 'polite'); }
  message.textContent = 'Check saved setup first. Generate summary sends only this approved evidence and optional thesis to OpenAI. Maximum generated output: 2,000 tokens. Exact cost is checked against the shared budget after complete-input counting.';
  const storeKey = `tgit-ai-generation:${window.tgitConfig.actorId}:${workspace}:${approval}`;
  let setup = null, command = null, retryAllowed = false, waiting = false, damaged = false;
  const active = () => current() && panel.isConnected;
  try {
   const raw = sessionStorage.getItem(storeKey);
   if (raw !== null) {
    command = JSON.parse(raw);
    if (command.version !== 1 || !Number.isSafeInteger(command.config_id) || command.config_id < 1 || !/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(command.key)) throw new Error('invalid identity');
    outcome.textContent = 'A saved operation identity exists. Check its result before retrying. Looking up the result does not count tokens or send an AI request.';
   }
  } catch (_) { damaged = true; command = null; outcome.textContent = 'Saved request identity is unavailable or invalid. Generation is blocked to avoid losing retry identity. Review saved request activity in Settings.'; }
  const controls = () => {
   setupButton.disabled = waiting || !!window.tgitWriteBusy;
   generateButton.textContent = command ? 'Retry same summary request' : 'Generate summary';
   generateButton.disabled = damaged || waiting || !!window.tgitWriteBusy || !setup?.can_generate || (!!command && !retryAllowed);
   recoverButton.hidden = !command; recoverButton.disabled = waiting || !!window.tgitWriteBusy;
  };
  const labels = { original_owner: 'Use evidence approved by your own owner account.', credential_configured: 'Configure the OpenAI server key in API setup.', model_evidence_current: 'Have the server administrator verify access and pricing for the saved model.', count_evidence_current: 'Have the server administrator verify counting access and cost.', server_enabled: 'Server processing is off.', policy_enabled: 'Shared AI processing is off.', workspace_consent: 'Save workspace consent in Settings.', budget_available: 'Restore an available monthly allowance in Settings.', workflow_available: 'Summary generation controls are unavailable.' };
  setupButton.addEventListener('click', async () => {
   if (waiting || window.tgitWriteBusy || !active()) return;
   waiting = true; setup = null; controls(); message.textContent = 'Checking saved summary setup…';
   try {
    const result = await send(`ai-evidence/${approval}/preflight`);
    if (!active()) return;
    setup = result;
    message.textContent = `Saved model: ${result.model || 'not selected'}. Remaining allowance: ${tgitDisplayDecimal(result.remaining)} USD. ${result.blockers.map(key => labels[key] || key).join(' ')} This check does not estimate the request cost, reserve allowance or send an AI request. Generate summary uses only the approved evidence and may incur API charges; maximum generated output is 2,000 tokens.`;
   } catch (error) { if (active()) message.textContent = `Setup check failed: ${error.message} Try Check summary setup again.`; }
   finally { waiting = false; if (active()) controls(); }
  });
  const report = (result) => {
   retryAllowed = result.state === 'not_reserved';
   const descriptions = { not_reserved: 'No reservation is saved. Check current setup before explicitly retrying with this same identity. A previous counting attempt may have occurred.', in_progress: 'This operation is still active. Check the saved result later; do not start another request.', reserved: 'The request was reserved but not sent. Cancel its unsent reservation in Settings; checking or retrying does not send it.', dispatched: 'Delivery was claimed. Do not resend. Its budget hold remains until verified usage is available.', uncertain: 'Delivery or cost is uncertain. Do not resend. Its budget hold remains.', settled: 'Usage is settled. If the response is eligible, use Save as review in Settings > Saved AI request activity. Settlement alone does not mean a usable review exists.', overrun: 'Usage exceeded the cost bound. New work is blocked pending budget review.', cancelled: 'This unused reservation was cancelled. This operation will not be sent.', disabled: 'Server processing is off. No new delivery was made.', unavailable: 'No usable counting result was obtained. Check saved summary result before considering an explicit retry.' };
   outcome.textContent = `${result.request_id ? `Request #${result.request_id}. ` : ''}${descriptions[result.state] || 'Check saved request activity for details.'}`;
  };
  recoverButton.addEventListener('click', async () => {
   if (waiting || window.tgitWriteBusy || !command || !active()) return;
   waiting = true; retryAllowed = false; controls(); outcome.textContent = 'Reading the saved operation result…';
   try { const result = await send(`ai-evidence/${approval}/generation?operation_key=${encodeURIComponent(command.key)}`); if (active()) report(result); }
   catch (error) { if (active()) outcome.textContent = `${error.message} Try Check saved summary result again. No generation retry was made.`; }
   finally { waiting = false; if (active()) controls(); }
  });
  generateButton.addEventListener('click', async () => {
   if (generateButton.disabled || !active()) return;
   try {
    if (!command) command = { version: 1, key: crypto.randomUUID(), config_id: setup.config_id };
    else if (retryAllowed) command.config_id = setup.config_id;
    sessionStorage.setItem(storeKey, JSON.stringify(command));
    if (sessionStorage.getItem(storeKey) !== JSON.stringify(command)) throw new Error('identity not retained');
   } catch (_) { damaged = true; outcome.textContent = 'Cannot retain the operation identity. Generation is blocked. No AI request was sent.'; controls(); return; }
   waiting = true; retryAllowed = false; window.tgitWriteBusy = true; changeBusy(true); controls(); outcome.textContent = 'Counting approved input and requesting one budget-checked summary…';
   try { const result = await send(`ai-evidence/${approval}/generate`, { expected_config_id: command.config_id }, command.key); if (active()) report(result); }
   catch (error) { if (active()) outcome.textContent = `${error.message} Check saved summary result using the retained identity before retrying. No automatic retry will occur.`; }
   finally { window.tgitWriteBusy = false; waiting = false; changeBusy(false); if (active()) controls(); }
  });
  const actions = document.createElement('div'); actions.className = 'tgit-row-actions'; actions.append(setupButton, generateButton, recoverButton); panel.append(title, message, actions, outcome); host.append(panel); controls(); return controls;
 };
})();
