<?php
use GainerInteractive\IGTradingJournal\Application\Tracker;
/** Included by the disposable WordPress integration suite. */
$dw = $tracker->create_workspace(['name' => 'Draft fixture'])['id'];
$da = $tracker->create_object($dw, 'accounts', ['name' => 'Draft cash', 'native_currency' => 'USD']);
$df = ['account_id' => (int) $da['id'], 'action' => 'deposit', 'effective_date' => '2026-02-01', 'state' => 'draft', 'amount' => '10', 'currency' => 'USD'];
$draft = $tracker->post($dw, $df, 'draft-create')['transaction'];
$did = (int) $draft['id'];
test('Draft edit preserves revisions without cash effects and rejects stale writes', function () use ($tracker, $dw, $did, $df) {
 $input = ['expected_revision' => 1, 'transaction' => array_replace($df, ['amount' => '20'])];
 $result = $tracker->edit_draft($dw, $did, $input, 'draft-edit');
 equal((int) $result['transaction']['revision'], 2);
 equal($tracker->edit_draft($dw, $did, $input, 'draft-edit'), $result);
 equal(count($tracker->transaction($dw, $did)['revisions']), 2);
 decimal($tracker->list_objects($dw, 'accounts')[0]['cash_balance'], '0');
 try { $tracker->edit_draft($dw, $did, $input, 'draft-stale'); } catch (UnexpectedValueException $e) { return; }
 throw new RuntimeException('Stale draft edit accepted.');
});
test('Contributors edit own drafts and cannot edit others or promote', function () use ($tracker, $db, $viewer, $dw, $did, $df) {
 $tracker->set_member($dw, ['wp_user_id' => $viewer, 'role' => 'contributor', 'state' => 'active']);
 $contributor = new Tracker($db, $viewer, wp_generate_uuid4());
 $own = $contributor->post($dw, $df, 'contributor-draft')['transaction'];
 $contributor->edit_draft($dw, (int) $own['id'], ['expected_revision' => 1, 'transaction' => $df], 'contributor-edit');
 foreach ([fn() => $contributor->edit_draft($dw, $did, ['expected_revision' => 2, 'transaction' => $df], 'foreign-edit'), fn() => $contributor->promote_draft($dw, $did, ['expected_revision' => 2], 'contributor-post')] as $call) {
  try { $call(); } catch (DomainException $e) { continue; } throw new RuntimeException('Contributor permission bypassed.');
 }
});
test('Promotion posts once and retains the immutable source trail', function () use ($tracker, $dw, $did) {
 $result = $tracker->promote_draft($dw, $did, ['expected_revision' => 2], 'draft-promote');
 equal($tracker->promote_draft($dw, $did, ['expected_revision' => 2], 'draft-promote'), $result);
 equal($result['draft']['state'], 'promoted'); equal($result['transaction']['state'], 'posted');
 decimal($tracker->list_objects($dw, 'accounts')[0]['cash_balance'], '20');
 $history = $tracker->transaction($dw, $did)['revisions']; equal(count($history), 3);
 equal((int) json_decode($history[2]['payload'], true)['posted_transaction_id'], (int) $result['transaction']['id']);
 foreach ([fn() => $tracker->promote_draft($dw, $did, ['expected_revision' => 2], 'draft-again'), fn() => $tracker->promote_draft($dw, $did, ['expected_revision' => 3], 'draft-promote')] as $call) {
  try { $call(); } catch (UnexpectedValueException $e) { continue; } throw new RuntimeException('Duplicate promotion accepted.');
 }
});
test('Failed promotion rolls back effects and preserves editable draft', function () use ($tracker, $dw, $df) {
 $draft = $tracker->post($dw, array_replace($df, ['action' => 'withdrawal', 'amount' => '100']), 'draft-overdraw')['transaction'];
 $count = count($tracker->list_objects($dw, 'transactions'));
 rejects(fn() => $tracker->promote_draft($dw, (int) $draft['id'], ['expected_revision' => 1], 'draft-failure'));
 equal(count($tracker->list_objects($dw, 'transactions')), $count);
 $current = $tracker->transaction($dw, (int) $draft['id']); equal($current['transaction']['state'], 'draft'); equal(count($current['revisions']), 1);
 decimal($tracker->list_objects($dw, 'accounts')[0]['cash_balance'], '20');
});
test('Draft REST endpoints enforce revision conflicts and workspace isolation', function () use ($owner, $tracker, $dw, $did, $df, $w2) {
 wp_set_current_user($owner);
 equal(rest_do_request(new WP_REST_Request('GET', '/tgit/v1/workspaces/' . $dw . '/transactions/' . $did))->get_status(), 200);
 equal(rest_do_request(new WP_REST_Request('GET', '/tgit/v1/workspaces/' . $w2 . '/transactions/' . $did))->get_status(), 404);
 $request = new WP_REST_Request('POST', '/tgit/v1/workspaces/' . $dw . '/transactions/' . $did . '/draft');
 $request->set_header('Content-Type', 'application/json'); $request->set_header('Idempotency-Key', 'rest-stale-edit');
 $request->set_body(wp_json_encode(['expected_revision' => 1, 'transaction' => $df]));
 equal(rest_do_request($request)->get_status(), 409);
});

test('Concurrent promotion commits exactly one financial event', function () use ($tracker, $owner, $dw, $df) {
 $draft = $tracker->post($dw, $df, 'race-draft')['transaction'];
 $processes = [];
 foreach (['a', 'b'] as $key) {
  $task = ['operation' => 'promote', 'id' => $draft['id'], 'payload' => ['expected_revision' => 1], 'key' => 'race-promote-' . $key];
  $command = [PHP_BINARY, '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=mysqli', __DIR__ . '/concurrency-worker.php', rtrim(ABSPATH, '/\\'), (string) $owner, (string) $dw, wp_json_encode($task)];
  $pipes = []; $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  if (!is_resource($process)) throw new RuntimeException('Worker failed to start.');
  fclose($pipes[0]); $processes[] = [$process, $pipes];
 }
 $results = [];
 foreach ($processes as [$process, $pipes]) {
  $results[] = trim(stream_get_contents($pipes[1])); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
  if (proc_close($process) !== 0) throw new RuntimeException($error);
 }
 sort($results); equal($results, ['posted', 'rejected']);
 decimal($tracker->list_objects($dw, 'accounts')[0]['cash_balance'], '30');
});
test('Manager promotion retains exact FIFO basis and posted facts cannot be edited', function () use ($tracker, $db, $viewer, $dw, $da) {
 $tracker->set_member($dw, ['wp_user_id' => $viewer, 'role' => 'manager', 'state' => 'active']);
 $manager = new Tracker($db, $viewer, wp_generate_uuid4());
 $asset = $tracker->create_object($dw, 'assets', ['symbol' => 'DRAFT', 'exchange' => 'TEST', 'asset_class' => 'stock', 'quote_currency' => 'USD']);
 $facts = ['account_id' => (int) $da['id'], 'asset_id' => (int) $asset['id'], 'action' => 'buy', 'effective_date' => '2026-02-02', 'state' => 'draft', 'quantity' => '0.123456789012345678', 'unit_price' => '100', 'fees' => '0.1', 'currency' => 'USD'];
 $draft = $tracker->post($dw, $facts, 'draft-exact-buy')['transaction'];
 try { $manager->promote_draft($dw, (int) $draft['id'], ['expected_revision' => 2], 'draft-wrong-revision'); throw new RuntimeException('Stale promotion accepted.'); } catch (UnexpectedValueException $e) {}
 $posted = $manager->promote_draft($dw, (int) $draft['id'], ['expected_revision' => 1], 'manager-post-buy')['transaction'];
 $holding = $tracker->holdings($dw)['items'][0]; decimal($holding['quantity'], '0.123456789012345678'); decimal($holding['remaining_basis'], '12.445678901235');
 try { $manager->edit_draft($dw, (int) $posted['id'], ['expected_revision' => 1, 'transaction' => $facts], 'edit-posted-denied'); } catch (UnexpectedValueException $e) { return; }
 throw new RuntimeException('Posted facts edited.');
});
