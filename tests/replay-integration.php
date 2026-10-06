<?php
/** Disposable chronological replay preview fixtures. */
if (!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE !== true) throw new RuntimeException('Disposable site required.');

test('Schema 4 correction table repairs from version 3 and repeats safely', function () use ($db) {
 $before = $db->row('SELECT COUNT(*) AS count FROM ' . $db->table('transaction_corrections'))['count'];
 update_option('tgit_schema_version', '3');
 \GainerInteractive\IGTradingJournal\Infrastructure\Installer::install();
 equal(get_option('tgit_schema_version'), '9');
 \GainerInteractive\IGTradingJournal\Infrastructure\Installer::install();
 equal($db->row('SELECT COUNT(*) AS count FROM ' . $db->table('transaction_corrections'))['count'], $before);
 equal($db->row('SHOW COLUMNS FROM ' . $db->table('transaction_corrections') . ' LIKE %s', ['chronology_id'])['Field'], 'chronology_id');
});

test('Schema 5 replay table repairs from version 4 without losing corrections', function () use ($db) {
 $before = $db->row('SELECT COUNT(*) AS count FROM ' . $db->table('transaction_corrections'))['count'];
 update_option('tgit_schema_version', '4');
 \GainerInteractive\IGTradingJournal\Infrastructure\Installer::install();
 equal(get_option('tgit_schema_version'), '9');
 equal($db->row('SELECT COUNT(*) AS count FROM ' . $db->table('transaction_corrections'))['count'], $before);
 equal($db->row('SHOW COLUMNS FROM ' . $db->table('replay_runs') . ' LIKE %s', ['projection_json'])['Field'], 'projection_json');
});

$rw = (int) $tracker->create_workspace(['name' => 'Replay fixture'])['id'];
$ra = $tracker->create_object($rw, 'accounts', ['name' => 'Historical account', 'native_currency' => 'USD']);
$rs = $tracker->create_object($rw, 'assets', ['symbol' => 'RPLY', 'exchange' => 'FIXTURE', 'asset_class' => 'stock', 'quote_currency' => 'USD']);
$base = ['account_id' => (int) $ra['id'], 'currency' => 'USD', 'state' => 'posted'];
$deposit = $tracker->post($rw, $base + ['action' => 'deposit', 'effective_date' => '2026-01-01', 'amount' => '2000'], 'replay-deposit-fixture');
$buy = $tracker->post($rw, $base + ['action' => 'buy', 'effective_date' => '2026-01-03', 'asset_id' => (int) $rs['id'], 'quantity' => '10', 'unit_price' => '100', 'fees' => '5'], 'replay-buy-fixture');
$sell = $tracker->post($rw, $base + ['action' => 'sell', 'effective_date' => '2026-01-04', 'asset_id' => (int) $rs['id'], 'quantity' => '4', 'unit_price' => '120', 'fees' => '2'], 'replay-sell-fixture');
$proposal = $base + ['action' => 'buy', 'effective_date' => '2026-01-02', 'asset_id' => (int) $rs['id'], 'quantity' => '2', 'unit_price' => '50', 'fees' => '0'];

test('Historical preview recalculates affected FIFO gains without writing history', function () use ($tracker, $rw, $ra, $sell, $proposal) {
 $before = $tracker->transaction($rw, (int) $sell['transaction']['id']);
 $preview = $tracker->replay_preview($rw, $proposal);
 equal($preview['applied'], false); decimal($preview['cash_before'], '1473'); decimal($preview['cash_after'], '1373');
 equal(count($preview['changed_realized_gains']), 1); decimal($preview['changed_realized_gains'][0]['realized_gain_before'], '76'); decimal($preview['changed_realized_gains'][0]['realized_gain_after'], '177');
 equal(count($preview['changed_allocations']), 1);
 equal((int) $preview['changed_allocations'][0]['transaction_id'], (int) $sell['transaction']['id']);
 equal(count($preview['changed_allocations'][0]['before']), 1);
 equal(count($preview['changed_allocations'][0]['after']), 2);
 decimal($tracker->list_objects($rw, 'accounts')[0]['cash_balance'], '1473');
 equal($tracker->transaction($rw, (int) $sell['transaction']['id']), $before);
 equal(count($tracker->list_objects($rw, 'transactions')), 3);
});

test('Historical preview rejects a later overdraft without writing', function () use ($tracker, $rw, $base) {
 rejects(fn() => $tracker->replay_preview($rw, $base + ['action' => 'withdrawal', 'effective_date' => '2026-01-02', 'amount' => '1999']));
 equal(count($tracker->list_objects($rw, 'transactions')), 3);
});

test('Replay preview REST requires posting membership and returns no mutation', function () use ($tracker, $rw, $owner, $viewer, $proposal) {
 $request = new WP_REST_Request('POST', '/tgit/v1/workspaces/' . $rw . '/replay-preview');
 $request->set_header('Content-Type', 'application/json'); $request->set_body(wp_json_encode($proposal));
 wp_set_current_user($viewer); equal(rest_do_request($request)->get_status(), 403);
 wp_set_current_user($owner); $response = rest_do_request($request); equal($response->get_status(), 200);
 equal($response->get_data()['data']['applied'], false);
 equal(count($tracker->list_objects($rw, 'transactions')), 3);
});

test('Cash correction rejects a later overdraft and preserves source', function () use ($tracker, $rw, $deposit, $base) {
 $input = ['expected_revision' => 1, 'reason' => 'Statement amount corrected', 'replacement' => $base + ['action' => 'deposit', 'effective_date' => '2026-01-01', 'amount' => '500']];
 rejects(fn() => $tracker->correct_cash($rw, (int) $deposit['transaction']['id'], $input, 'replay-correction-overdraft'));
 decimal($tracker->list_objects($rw, 'accounts')[0]['cash_balance'], '1473');
 equal(count($tracker->list_objects($rw, 'transactions')), 3);
});

test('Cash correction supersedes source and retains immutable facts and retry result', function () use ($tracker, $rw, $deposit, $base, $proposal) {
 $source_id = (int) $deposit['transaction']['id'];
 $input = ['expected_revision' => 1, 'reason' => 'Statement amount corrected', 'replacement' => $base + ['action' => 'deposit', 'effective_date' => '2026-01-01', 'amount' => '1500']];
 $result = $tracker->correct_cash($rw, $source_id, $input, 'replay-correction-valid');
 equal($tracker->correct_cash($rw, $source_id, $input, 'replay-correction-valid'), $result);
 equal($result['source']['amount'], '2000.000000000000'); equal($result['source']['revision'], '2');
 equal($result['replacement']['amount'], '1500.000000000000'); decimal($result['cash_after'], '973');
 equal($result['correction']['reason'], 'Statement amount corrected');
 $detail = $tracker->transaction($rw, $source_id); equal(count($detail['revisions']), 2);
 equal((int) $detail['correction']['replacement_transaction_id'], (int) $result['replacement']['id']);
 $rows = $tracker->list_objects($rw, 'transactions'); equal(count($rows), 4);
 equal((int) $rows[0]['corrected_by_id'], (int) $result['replacement']['id']);
 decimal($tracker->list_objects($rw, 'accounts')[0]['cash_balance'], '973');
 $preview = $tracker->replay_preview($rw, $proposal); decimal($preview['cash_before'], '973');
 opening_conflicts(fn() => $tracker->correct_cash($rw, $source_id, $input, 'replay-correction-second'));
});

test('Correction chains preserve original source and one active cash projection', function () use ($tracker, $rw, $deposit, $base) {
 $first = $tracker->transaction($rw, (int) $deposit['transaction']['id'])['correction'];
 $middle = (int) $first['replacement_transaction_id'];
 $input = ['expected_revision' => 1, 'reason' => 'Final verified statement', 'replacement' => $base + ['action' => 'deposit', 'effective_date' => '2026-01-01', 'amount' => '1700']];
 $result = $tracker->correct_cash($rw, $middle, $input, 'replay-correction-chain');
 decimal($result['cash_after'], '1173');
 equal(count($tracker->transaction($rw, $middle)['correction_links']), 2);
 equal(count($tracker->list_objects($rw, 'transactions')), 5);
 equal($tracker->transaction($rw, (int) $deposit['transaction']['id'])['transaction']['amount'], '2000.000000000000');
});

test('Cash correction REST denies viewers and requires matching revision', function () use ($tracker, $rw, $owner, $viewer, $base, $buy) {
 $request = new WP_REST_Request('POST', '/tgit/v1/workspaces/' . $rw . '/transactions/' . $buy['transaction']['id'] . '/corrections');
 $request->set_header('Content-Type', 'application/json'); $request->set_header('Idempotency-Key', 'replay-rest-correction');
 $request->set_body(wp_json_encode(['expected_revision' => 2, 'reason' => 'Stale security correction', 'replacement' => $base + ['action' => 'deposit', 'effective_date' => '2026-01-01', 'amount' => '1700']]));
 wp_set_current_user($viewer); equal(rest_do_request($request)->get_status(), 403);
 wp_set_current_user($owner); equal(rest_do_request($request)->get_status(), 409);
 equal(count($tracker->list_objects($rw, 'transactions')), 5);
});

test('Audit failure rolls back a cash correction and its replacement', function () use ($tracker, $db) {
 $workspace = (int) $tracker->create_workspace(['name' => 'Correction rollback fixture'])['id'];
 $account = $tracker->create_object($workspace, 'accounts', ['name' => 'Rollback account', 'native_currency' => 'USD']);
 $base = ['account_id' => (int) $account['id'], 'currency' => 'USD', 'state' => 'posted', 'action' => 'deposit', 'effective_date' => '2026-01-01'];
 $source = $tracker->post($workspace, $base + ['amount' => '1000'], 'correction-rollback-source');
 $trigger = 'tgit_correct_fail_' . $workspace;
 $db->query('CREATE TRIGGER ' . $trigger . ' BEFORE INSERT ON ' . $db->table('audit_events') . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture forced failure'");
 $failed = false;
 try { $tracker->correct_cash($workspace, (int) $source['transaction']['id'], ['expected_revision' => 1, 'reason' => 'Rollback statement', 'replacement' => $base + ['amount' => '900']], 'correction-rollback-write'); }
 catch (RuntimeException $error) { $failed = true; }
 finally { $db->query('DROP TRIGGER ' . $trigger); }
 equal($failed, true); equal(count($tracker->list_objects($workspace, 'transactions')), 1);
 decimal($tracker->list_objects($workspace, 'accounts')[0]['cash_balance'], '1000');
 equal(count($tracker->transaction($workspace, (int) $source['transaction']['id'])['revisions']), 1);
 equal(count($db->rows('SELECT id FROM ' . $db->table('replay_runs') . ' WHERE workspace_id = %d', [$workspace])), 0);
});

test('Historical cash posting replays later balances and retries once', function () use ($tracker, $rw, $base) {
 $input = $base + ['action' => 'deposit', 'effective_date' => '2026-01-02', 'amount' => '100'];
 $posted = $tracker->post_historical_cash($rw, $input, 'historical-cash-fixture');
 equal($tracker->post_historical_cash($rw, $input, 'historical-cash-fixture'), $posted);
 decimal($posted['cash_after'], '1273'); decimal($tracker->list_objects($rw, 'accounts')[0]['cash_balance'], '1273');
 equal(count($tracker->list_objects($rw, 'transactions')), 6);
 rejects(fn() => $tracker->post_historical_cash($rw, $base + ['action' => 'withdrawal', 'effective_date' => '2026-01-02', 'amount' => '2000'], 'historical-overdraft-fixture'));
 equal(count($tracker->list_objects($rw, 'transactions')), 6);
});

test('Historical cash REST enforces posting permission', function () use ($rw, $owner, $viewer, $base) {
 $request = new WP_REST_Request('POST', '/tgit/v1/workspaces/' . $rw . '/historical-cash');
 $request->set_header('Content-Type', 'application/json'); $request->set_header('Idempotency-Key', 'historical-rest-fixture');
 $request->set_body(wp_json_encode($base + ['action' => 'deposit', 'effective_date' => '2026-01-02', 'amount' => '1']));
 wp_set_current_user($viewer); equal(rest_do_request($request)->get_status(), 403);
 wp_set_current_user($owner); $response = rest_do_request($request); equal($response->get_status(), 200);
 decimal($response->get_data()['data']['cash_after'], '1274');
});

test('Same-day correction chains retain the source ordering position', function () use ($tracker) {
 $workspace = (int) $tracker->create_workspace(['name' => 'Same day correction fixture'])['id'];
 $account = $tracker->create_object($workspace, 'accounts', ['name' => 'Same day cash', 'native_currency' => 'USD']);
 $base = ['account_id' => (int) $account['id'], 'currency' => 'USD', 'state' => 'posted', 'effective_date' => '2026-01-01'];
 $deposit = $tracker->post($workspace, $base + ['action' => 'deposit', 'amount' => '1000'], 'same-day-deposit-fixture');
 $tracker->post($workspace, $base + ['action' => 'withdrawal', 'amount' => '900'], 'same-day-withdrawal-fixture');
 $first = $tracker->correct_cash($workspace, (int) $deposit['transaction']['id'], ['expected_revision' => 1, 'reason' => 'First statement', 'replacement' => $base + ['action' => 'deposit', 'amount' => '950']], 'same-day-correction-one');
 decimal($first['cash_after'], '50');
 $second = $tracker->correct_cash($workspace, (int) $first['replacement']['id'], ['expected_revision' => 1, 'reason' => 'Verified statement', 'replacement' => $base + ['action' => 'deposit', 'amount' => '925']], 'same-day-correction-two');
 decimal($second['cash_after'], '25');
 equal((int) $first['correction']['chronology_id'], (int) $deposit['transaction']['id']);
 equal((int) $second['correction']['chronology_id'], (int) $deposit['transaction']['id']);
});

test('Historical security replay versions allocations and governs later sales', function () use ($tracker, $db) {
 $workspace = (int) $tracker->create_workspace(['name' => 'Security replay fixture'])['id'];
 $account = $tracker->create_object($workspace, 'accounts', ['name' => 'FIFO replay', 'native_currency' => 'USD']);
 $asset = $tracker->create_object($workspace, 'assets', ['symbol' => 'FIFOR', 'exchange' => 'TEST', 'asset_class' => 'stock', 'quote_currency' => 'USD']);
 $base = ['account_id' => (int) $account['id'], 'currency' => 'USD', 'state' => 'posted', 'asset_id' => (int) $asset['id']];
 $tracker->post($workspace, ['account_id' => (int) $account['id'], 'currency' => 'USD', 'state' => 'posted', 'action' => 'deposit', 'effective_date' => '2026-01-01', 'amount' => '1000'], 'security-replay-deposit');
 $buy = $tracker->post($workspace, $base + ['action' => 'buy', 'effective_date' => '2026-01-03', 'quantity' => '10', 'unit_price' => '10', 'fees' => '0'], 'security-replay-buy');
 $sell = $tracker->post($workspace, $base + ['action' => 'sell', 'effective_date' => '2026-01-04', 'quantity' => '5', 'unit_price' => '20', 'fees' => '0'], 'security-replay-sell');
 $original_gain = $sell['transaction']['realized_gain']; decimal($original_gain, '50');
 $historical = $tracker->post_historical_security($workspace, $base + ['action' => 'buy', 'effective_date' => '2026-01-02', 'quantity' => '5', 'unit_price' => '8', 'fees' => '0'], 'security-replay-historical');
 equal($tracker->post_historical_security($workspace, $base + ['action' => 'buy', 'effective_date' => '2026-01-02', 'quantity' => '5', 'unit_price' => '8', 'fees' => '0'], 'security-replay-historical'), $historical);
 decimal($historical['cash_after'], '960');
 $detail = $tracker->transaction($workspace, (int) $sell['transaction']['id']);
 decimal($detail['transaction']['realized_gain'], '50'); decimal($detail['current_calculation']['realized_gain'], '60');
 equal((int) $detail['current_calculation']['allocations'][0]['lot_id'], (int) $historical['transaction']['id']);
 $position = $tracker->holdings($workspace)['items'][0]; decimal($position['quantity'], '10'); decimal($position['remaining_basis'], '100'); decimal($position['realized_gain'], '60');
 $later = $tracker->post($workspace, $base + ['action' => 'sell', 'effective_date' => '2026-01-05', 'quantity' => '2', 'unit_price' => '20', 'fees' => '0'], 'security-replay-later');
 decimal($later['transaction']['realized_gain'], '20'); decimal($tracker->holdings($workspace)['items'][0]['quantity'], '8');
 $correction = $tracker->correct_cash($workspace, (int) $historical['transaction']['id'], ['expected_revision' => 1, 'reason' => 'Corrected statement price', 'replacement' => $base + ['action' => 'buy', 'effective_date' => '2026-01-02', 'quantity' => '5', 'unit_price' => '9', 'fees' => '0']], 'security-replay-correction');
 decimal($correction['cash_after'], '995');
 decimal($tracker->transaction($workspace, (int) $sell['transaction']['id'])['current_calculation']['realized_gain'], '55');
 equal($tracker->transaction($workspace, (int) $historical['transaction']['id'])['current_calculation'], null);
 $runs = $db->rows('SELECT * FROM ' . $db->table('replay_runs') . ' WHERE workspace_id = %d AND account_id = %d ORDER BY id', [$workspace, $account['id']]);
 equal(count($runs), 3); equal($runs[0]['trigger_action'], 'historical_buy'); equal($runs[2]['trigger_action'], 'correction');
 $count = count($tracker->list_objects($workspace, 'transactions'));
 rejects(fn() => $tracker->correct_cash($workspace, (int) $buy['transaction']['id'], ['expected_revision' => 1, 'reason' => 'Invalid reduced holding', 'replacement' => $base + ['action' => 'buy', 'effective_date' => '2026-01-03', 'quantity' => '1', 'unit_price' => '10', 'fees' => '0']], 'security-replay-oversell'));
 equal(count($tracker->list_objects($workspace, 'transactions')), $count);
 equal(count($db->rows('SELECT id FROM ' . $db->table('replay_runs') . ' WHERE workspace_id = %d AND account_id = %d', [$workspace, $account['id']])), 3);
});

test('Historical security REST requires posting membership and returns replay evidence', function () use ($tracker, $owner, $viewer) {
 $workspace = (int) $tracker->create_workspace(['name' => 'Security REST fixture'])['id'];
 $account = $tracker->create_object($workspace, 'accounts', ['name' => 'REST account', 'native_currency' => 'USD']);
 $asset = $tracker->create_object($workspace, 'assets', ['symbol' => 'RESTF', 'exchange' => 'TEST', 'asset_class' => 'stock', 'quote_currency' => 'USD']);
 $tracker->post($workspace, ['account_id' => (int) $account['id'], 'currency' => 'USD', 'state' => 'posted', 'action' => 'deposit', 'effective_date' => '2026-01-01', 'amount' => '500'], 'security-rest-deposit');
 $request = new WP_REST_Request('POST', '/tgit/v1/workspaces/' . $workspace . '/historical-transactions');
 $request->set_header('Content-Type', 'application/json'); $request->set_header('Idempotency-Key', 'security-rest-buy');
 $request->set_body(wp_json_encode(['account_id' => (int) $account['id'], 'currency' => 'USD', 'state' => 'posted', 'asset_id' => (int) $asset['id'], 'action' => 'buy', 'effective_date' => '2026-01-02', 'quantity' => '2', 'unit_price' => '10', 'fees' => '0']));
 wp_set_current_user($viewer); equal(rest_do_request($request)->get_status(), 403);
 wp_set_current_user($owner); $response = rest_do_request($request); equal($response->get_status(), 200);
 decimal($response->get_data()['data']['cash_after'], '480');
});
