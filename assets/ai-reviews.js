/* Owner-only saved interpretation; reads never dispatch AI or providers. */
(() => {
 'use strict';
 const config = window.tgitConfig, $ = (id) => document.getElementById(`tgit-ai-reviews${id ? `-${id}` : ''}`);
 let workspace = '', asset = '', generation = 0, detailGeneration = 0, loading = false;
 const status = (text, error = false) => { $('status').textContent = text; $('status').setAttribute('role', error ? 'alert' : 'status'); };
 const controls = () => { $('reload').disabled = loading || !asset || !!window.tgitWriteBusy; };
 async function request(path) {
  const response = await fetch(tgitRestUrl(config.root, `workspaces/${workspace}/${path}`), { credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce } });
  const envelope = await response.json(); if (!response.ok) throw new Error(envelope.message || 'Saved review request failed.'); return envelope.data;
 }
 function paragraph(target, text) { const node = document.createElement('p'); node.textContent = text; node.style.overflowWrap = 'anywhere'; target.append(node); }
 function heading(target, text, level = 'h4') { const node = document.createElement(level); node.textContent = text; target.append(node); }
 function reset() { ++generation; ++detailGeneration; workspace = ''; asset = ''; loading = false; $('').hidden = true; tgitResetCollection($('history')); $('detail').replaceChildren(); status(''); controls(); }
 window.addEventListener('tgit-workspace', reset); window.addEventListener('tgit-evidence-reset', reset);
 async function detail(row) {
  if (window.tgitWriteBusy || loading) return;
  const expected = generation, view = ++detailGeneration; $('detail').replaceChildren(); status('Loading saved review and approved evidence…');
  try {
   const review = await request(`ai-reviews/${row.id}`); if (expected !== generation || view !== detailGeneration) return;
   const evidence = await request(`ai-evidence/${review.approval_id}`); if (expected !== generation || view !== detailGeneration) return;
   if (String(review.asset_id) !== asset || String(evidence.asset_id) !== asset || evidence.fingerprint !== review.evidence_fingerprint) throw new Error('Saved review evidence does not match the selected stock.');
   const target = $('detail'); heading(target, `AI review #${review.id}`);
   paragraph(target, `${evidence.bundle.asset.symbol} · Model: ${review.output.model} · Saved: ${review.created_at} UTC · Approved evidence #${review.approval_id}`);
   paragraph(target, 'AI-generated interpretation, not verified facts. Citation membership does not verify claim accuracy. Approved input contains saved evidence only; no live news or prices were supplied. Treat the interpretation as unverified and not as an investment recommendation.');
   heading(target, 'Summary'); paragraph(target, review.output.summary);
   heading(target, 'Findings and cited snapshots');
   const sources = Object.entries(evidence.bundle.sources);
   for (const finding of review.output.findings) {
    paragraph(target, finding.text);
    paragraph(target, `Cited snapshots: ${finding.source_ids.map((id) => { const source = sources.find(([, value]) => value.snapshot_id === id); if (!source) throw new Error('Review citation is outside its approved evidence.'); return `#${id} (${source[0].toLowerCase().replace(/_/g, ' ')})`; }).join(', ')}`);
   }
   heading(target, 'Source provenance');
   for (const [dataset, source] of sources) paragraph(target, `${dataset.toLowerCase().replace(/_/g, ' ')} · Snapshot #${source.snapshot_id} · Retrieved ${source.retrieved_at} UTC · Fingerprint ${source.fingerprint}`);
   paragraph(target, `Evidence fingerprint: ${review.evidence_fingerprint}`); paragraph(target, `Review fingerprint: ${review.output_fingerprint}`);
   const disclosure = document.createElement('details'), summary = document.createElement('summary'), pre = document.createElement('pre');
   summary.textContent = 'Exact approved metrics and optional thesis'; pre.textContent = JSON.stringify(evidence.bundle, null, 2); pre.style.whiteSpace = 'pre-wrap'; pre.style.overflowWrap = 'anywhere'; disclosure.append(summary, pre); target.append(disclosure);
   status('Saved review loaded. No AI or provider request was sent.'); target.scrollIntoView({ block: 'nearest' });
  } catch (error) { if (expected === generation && view === detailGeneration) { $('detail').replaceChildren(); status(error.message, true); } }
 }
 async function load() {
  const expected = ++generation; ++detailGeneration; loading = true; controls(); tgitResetCollection($('history')); $('detail').replaceChildren(); status('Loading all saved reviews for this stock…');
  try {
   const rows = []; let after = 0;
   do { const page = await request(`assets/${asset}/ai-reviews?after=${after}&limit=100`); if (expected !== generation) return; rows.push(...page.items); after = page.next_cursor; } while (after !== null);
   tgitCollection($('history'), { actor: config.actorId, workspace, key: `ai-review-history-${asset}`, title: 'Saved AI reviews', search: (row) => `Review ${row.id} Evidence ${row.approval_id} ${row.created_at}`, columns: [
    { key: 'id', label: 'Review', identity: true, required: true, render: (row) => `#${row.id}` }, { key: 'created_at', label: 'Saved (UTC)', render: (row) => row.created_at }, { key: 'approval_id', label: 'Evidence approval', render: (row) => `#${row.approval_id}` },
    { key: 'actions', label: 'Actions', required: true, render: (row) => { const button = document.createElement('button'); button.type = 'button'; button.className = 'button'; button.textContent = 'View'; button.setAttribute('aria-label', `View AI review ${row.id}`); button.addEventListener('click', () => detail(row)); return button; } }
   ] }, rows);
   status(rows.length ? 'All saved review pages for this stock loaded. Search covers this stock’s saved review metadata, not summary text or other assets.' : 'No saved AI reviews yet. Summary generation is not available.');
  } catch (error) { if (expected === generation) { tgitResetCollection($('history')); status(error.message, true); } }
  finally { if (expected === generation) { loading = false; controls(); } }
 }
 window.addEventListener('tgit-evidence-sources', (event) => { reset(); if (event.detail.role !== 'owner') return; ({ workspace, asset } = event.detail); $('').hidden = false; load(); });
 $('reload').addEventListener('click', () => { if (!loading && !window.tgitWriteBusy && asset) load(); });
})();
