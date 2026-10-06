<?php
/** Disposable opening cash/lot and incomplete-basis fixtures. */
use GainerInteractive\IGTradingJournal\Infrastructure\Installer;
if (!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE !== true) throw new RuntimeException('Disposable site required.');
function opening_conflicts(callable $operation): void { try { $operation(); } catch (UnexpectedValueException $error) { return; } throw new RuntimeException('Expected opening conflict.'); }

test('Schema 3 opening table installs idempotently', function () {
 equal(Installer::ready(), true);
 update_option('tgit_schema_version', '2');
 Installer::install();
 equal(Installer::ready(), true);
 equal(get_option('tgit_schema_version'), '9');
 Installer::install();
});

test('Schema 6 basis table repairs from version 5', function () use ($db) {
 update_option('tgit_schema_version', '5');
 Installer::install();
 equal(get_option('tgit_schema_version'), '9');
 equal($db->row('SHOW COLUMNS FROM ' . $db->table('opening_basis_resolutions') . ' LIKE %s', ['amount'])['Field'], 'amount');
});

$ow = $tracker->create_workspace(['name' => 'Opening fixture'])['id'];
$oa = $tracker->create_object($ow, 'accounts', ['name' => 'Historical brokerage', 'native_currency' => 'USD']);
$os = $tracker->create_object($ow, 'assets', ['symbol' => 'OPEN', 'exchange' => 'FIXTURE', 'asset_class' => 'stock', 'quote_currency' => 'USD']);
$oc = ['account_id' => (int) $oa['id'], 'kind' => 'cash', 'effective_date' => '2026-01-01', 'amount' => '2000.00', 'source_note' => 'Broker statement on opening date'];
$ol = ['account_id' => (int) $oa['id'], 'asset_id' => (int) $os['id'], 'kind' => 'lot', 'effective_date' => '2026-01-01', 'acquired_on' => '2021-06-01', 'quantity' => '10', 'basis_status' => 'complete', 'amount' => '1000.00', 'source_note' => 'Historical lot statement'];

test('Documented opening cash and lots are idempotent without a purchase cash effect', function () use ($tracker, $ow, $oa, $os, $oc, $ol) {
 $cash = $tracker->opening_balance($ow, $oc, 'opening-cash-fixture');
 equal($cash['transaction']['action'], 'opening_cash');
 equal($tracker->opening_balance($ow, $oc, 'opening-cash-fixture'), $cash);
 $lot = $tracker->opening_balance($ow, $ol, 'opening-lot-fixture');
 equal($lot['transaction']['action'], 'opening_lot');
 equal($lot['opening']['acquired_on'], '2021-06-01');
 decimal($tracker->list_objects($ow, 'accounts')[0]['cash_balance'], '2000');
 $holding = $tracker->holdings($ow)['items'][0]; decimal($holding['quantity'], '10'); decimal($holding['remaining_basis'], '1000'); equal($holding['basis_status'], 'complete');
 equal($tracker->transaction($ow, (int) $lot['transaction']['id'])['revisions'][0]['reason'], 'Documented opening balance');
 opening_conflicts(fn() => $tracker->opening_balance($ow, array_replace($oc, ['amount' => '1']), 'opening-cash-fixture'));
 opening_conflicts(fn() => $tracker->opening_balance($ow, $oc, 'opening-cash-again'));
});

test('Opening lots sell from original acquisition date and preserve cash', function () use ($tracker, $ow, $oa, $os) {
 $sell = ['account_id' => (int) $oa['id'], 'asset_id' => (int) $os['id'], 'action' => 'sell', 'effective_date' => '2026-01-02', 'state' => 'posted', 'quantity' => '4', 'unit_price' => '120', 'fees' => '0', 'currency' => 'USD'];
 $result = $tracker->post($ow, $sell, 'opening-sell-fixture'); decimal($result['transaction']['realized_gain'], '80');
 decimal($tracker->list_objects($ow, 'accounts')[0]['cash_balance'], '2480');
 $holding = $tracker->holdings($ow)['items'][0]; decimal($holding['quantity'], '6'); decimal($holding['remaining_basis'], '600');
});

test('Ordinary posting closes the opening window and rejects historical inserts', function () use ($tracker, $ow, $ol, $oa) {
 opening_conflicts(fn() => $tracker->opening_balance($ow, $ol, 'late-opening-fixture'));
 opening_conflicts(fn() => $tracker->post($ow, ['account_id' => (int) $oa['id'], 'action' => 'deposit', 'effective_date' => '2025-12-01', 'state' => 'posted', 'amount' => '1', 'currency' => 'USD'], 'late-deposit-fixture'));
});

$uw = $tracker->create_workspace(['name' => 'Unresolved opening fixture'])['id'];
$ua = $tracker->create_object($uw, 'accounts', ['name' => 'Unknown basis', 'native_currency' => 'USD']);
$us = $tracker->create_object($uw, 'assets', ['symbol' => 'UNK', 'exchange' => 'FIXTURE', 'asset_class' => 'stock', 'quote_currency' => 'USD']);

test('Unknown opening basis stays visibly incomplete and blocks sales', function () use ($tracker, $uw, $ua, $us) {
 $lot = ['account_id' => (int) $ua['id'], 'asset_id' => (int) $us['id'], 'kind' => 'lot', 'effective_date' => '2026-01-01', 'acquired_on' => '2020-01-01', 'quantity' => '5', 'basis_status' => 'unresolved', 'source_note' => 'Position only, basis missing'];
 $tracker->opening_balance($uw, $lot, 'unknown-lot-fixture');
 $holding = $tracker->holdings($uw)['items'][0]; equal($holding['basis_status'], 'unresolved'); equal($holding['remaining_basis'], null); decimal($holding['quantity'], '5');
 rejects(fn() => $tracker->opening_balance($uw, $lot + ['amount' => '0'], 'invented-basis-fixture'));
 opening_conflicts(fn() => $tracker->post($uw, ['account_id' => (int) $ua['id'], 'asset_id' => (int) $us['id'], 'action' => 'sell', 'effective_date' => '2026-01-02', 'state' => 'posted', 'quantity' => '1', 'unit_price' => '10', 'fees' => '0', 'currency' => 'USD'], 'unknown-sell-fixture'));
});

test('Explicit zero basis is known, unlike an unresolved basis', function () use ($tracker) {
 $workspace = (int) $tracker->create_workspace(['name' => 'Zero basis fixture'])['id'];
 $account = $tracker->create_object($workspace, 'accounts', ['name' => 'Known zero', 'native_currency' => 'USD']);
 $asset = $tracker->create_object($workspace, 'assets', ['symbol' => 'ZERO', 'exchange' => 'FIXTURE', 'asset_class' => 'stock', 'quote_currency' => 'USD']);
 $lot = ['account_id' => (int) $account['id'], 'asset_id' => (int) $asset['id'], 'kind' => 'lot', 'effective_date' => '2026-01-01', 'acquired_on' => '2020-01-01', 'quantity' => '2', 'basis_status' => 'complete', 'amount' => '0', 'source_note' => 'Statement explicitly records zero basis'];
 $tracker->opening_balance($workspace, $lot, 'known-zero-fixture');
 $holding = $tracker->holdings($workspace)['items'][0]; equal($holding['basis_status'], 'complete'); decimal($holding['remaining_basis'], '0');
 $sale = $tracker->post($workspace, ['account_id' => (int) $account['id'], 'asset_id' => (int) $asset['id'], 'action' => 'sell', 'effective_date' => '2026-01-02', 'state' => 'posted', 'quantity' => '1', 'unit_price' => '10', 'fees' => '0', 'currency' => 'USD'], 'known-zero-sale-fixture');
 decimal($sale['transaction']['realized_gain'], '10');
});

test('Opening REST endpoint validates membership and records provenance', function () use ($tracker, $owner, $viewer) {
 $workspace = (int) $tracker->create_workspace(['name' => 'Opening REST fixture'])['id'];
 $account = $tracker->create_object($workspace, 'accounts', ['name' => 'REST cash', 'native_currency' => 'USD']);
 $path = '/tgit/v1/workspaces/' . $workspace . '/opening-balances';
 $payload = ['account_id' => (int) $account['id'], 'kind' => 'cash', 'effective_date' => '2026-01-01', 'amount' => '500', 'source_note' => 'Statement reference 123'];
 $request = new WP_REST_Request('POST', $path); $request->set_header('Content-Type', 'application/json'); $request->set_header('Idempotency-Key', 'opening-rest-fixture'); $request->set_body(wp_json_encode($payload));
 wp_set_current_user($viewer); equal(rest_do_request($request)->get_status(), 403);
 wp_set_current_user($owner); $response = rest_do_request($request); equal($response->get_status(), 200);
 equal($response->get_data()['data']['opening']['source_note'], 'Statement reference 123');
});

test('Opening cash and audit evidence roll back together', function () use ($tracker, $db) {
 $workspace = (int) $tracker->create_workspace(['name' => 'Opening rollback fixture'])['id'];
 $account = $tracker->create_object($workspace, 'accounts', ['name' => 'Rollback cash', 'native_currency' => 'USD']);
 $trigger = 'tgit_opening_fail_' . $workspace;
 $db->query('CREATE TRIGGER ' . $trigger . ' BEFORE INSERT ON ' . $db->table('audit_events') . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture forced failure'");
 $failed = false;
 try { $tracker->opening_balance($workspace, ['account_id' => (int) $account['id'], 'kind' => 'cash', 'effective_date' => '2026-01-01', 'amount' => '500', 'source_note' => 'Rollback statement'], 'opening-rollback-fixture'); }
 catch (RuntimeException $error) { $failed = true; }
 finally { $db->query('DROP TRIGGER ' . $trigger); }
 equal($failed, true);
 decimal($tracker->list_objects($workspace, 'accounts')[0]['cash_balance'], '0');
 equal(count($tracker->list_objects($workspace, 'transactions')), 0);
 equal($db->row('SELECT id FROM ' . $db->table('opening_balances') . ' WHERE workspace_id = %d', [$workspace]), null);
});

test('Retroactive opening cash and lots replay later FIFO without rewriting sales', function () use ($tracker, $db) {
 $workspace = (int) $tracker->create_workspace(['name' => 'Retroactive opening fixture'])['id'];
 $account = $tracker->create_object($workspace, 'accounts', ['name' => 'Prior holdings', 'native_currency' => 'USD']);
 $asset = $tracker->create_object($workspace, 'assets', ['symbol' => 'RETRO', 'exchange' => 'TEST', 'asset_class' => 'stock', 'quote_currency' => 'USD']);
 $base = ['account_id' => (int) $account['id'], 'asset_id' => (int) $asset['id'], 'currency' => 'USD', 'state' => 'posted'];
 $tracker->post($workspace, ['account_id' => (int) $account['id'], 'currency' => 'USD', 'state' => 'posted', 'action' => 'deposit', 'effective_date' => '2026-01-02', 'amount' => '100'], 'retro-deposit');
 $tracker->post($workspace, $base + ['action' => 'buy', 'effective_date' => '2026-01-03', 'quantity' => '5', 'unit_price' => '10', 'fees' => '0'], 'retro-buy');
 $sale = $tracker->post($workspace, $base + ['action' => 'sell', 'effective_date' => '2026-01-04', 'quantity' => '2', 'unit_price' => '20', 'fees' => '0'], 'retro-sell');
 $cash = ['account_id' => (int) $account['id'], 'kind' => 'cash', 'effective_date' => '2026-01-01', 'amount' => '50', 'source_note' => 'Opening statement cash'];
 $posted = $tracker->retroactive_opening($workspace, $cash, 'retro-cash');
 equal($tracker->retroactive_opening($workspace, $cash, 'retro-cash'), $posted);
 decimal($tracker->list_objects($workspace, 'accounts')[0]['cash_balance'], '140');
 $lot = ['account_id' => (int) $account['id'], 'asset_id' => (int) $asset['id'], 'kind' => 'lot', 'effective_date' => '2026-01-01', 'acquired_on' => '2020-01-01', 'quantity' => '2', 'basis_status' => 'complete', 'amount' => '4', 'source_note' => 'Opening statement lot'];
 $tracker->retroactive_opening($workspace, $lot, 'retro-lot');
 $detail = $tracker->transaction($workspace, (int) $sale['transaction']['id']);
 decimal($detail['transaction']['realized_gain'], '20'); decimal($detail['current_calculation']['realized_gain'], '36');
 $holding = $tracker->holdings($workspace)['items'][0]; decimal($holding['quantity'], '5'); decimal($holding['remaining_basis'], '50');
 opening_conflicts(fn() => $tracker->retroactive_opening($workspace, array_replace($cash, ['effective_date' => '2026-01-02']), 'retro-wrong-date'));
 equal(count($db->rows('SELECT id FROM ' . $db->table('replay_runs') . ' WHERE workspace_id = %d', [$workspace])), 2);
});

test('Retroactive opening REST enforces membership and source provenance', function () use ($tracker, $owner, $viewer) {
 $workspace = (int) $tracker->create_workspace(['name' => 'Retroactive REST fixture'])['id'];
 $account = $tracker->create_object($workspace, 'accounts', ['name' => 'Retro REST account', 'native_currency' => 'USD']);
 $tracker->post($workspace, ['account_id' => (int) $account['id'], 'currency' => 'USD', 'state' => 'posted', 'action' => 'deposit', 'effective_date' => '2026-01-02', 'amount' => '100'], 'retro-rest-deposit');
 $request = new WP_REST_Request('POST', '/tgit/v1/workspaces/' . $workspace . '/opening-balances/retroactive');
 $request->set_header('Content-Type', 'application/json'); $request->set_header('Idempotency-Key', 'retro-rest-opening');
 $request->set_body(wp_json_encode(['account_id' => (int) $account['id'], 'kind' => 'cash', 'effective_date' => '2026-01-01', 'amount' => '50', 'source_note' => 'Reconciled broker statement']));
 wp_set_current_user($viewer); equal(rest_do_request($request)->get_status(), 403);
 wp_set_current_user($owner); $response = rest_do_request($request); equal($response->get_status(), 200);
 equal($response->get_data()['data']['opening']['source_note'], 'Reconciled broker statement');
 decimal($tracker->list_objects($workspace, 'accounts')[0]['cash_balance'], '150');
});

test('Unknown opening basis resolves through immutable revision evidence', function () use ($tracker, $db) {
 $workspace = (int) $tracker->create_workspace(['name' => 'Basis resolution fixture'])['id'];
 $account = $tracker->create_object($workspace, 'accounts', ['name' => 'Basis account', 'native_currency' => 'USD']);
 $asset = $tracker->create_object($workspace, 'assets', ['symbol' => 'BASIS', 'exchange' => 'TEST', 'asset_class' => 'stock', 'quote_currency' => 'USD']);
 $lot = $tracker->opening_balance($workspace, ['account_id' => (int) $account['id'], 'asset_id' => (int) $asset['id'], 'kind' => 'lot', 'effective_date' => '2026-01-01', 'acquired_on' => '2020-01-01', 'quantity' => '5', 'basis_status' => 'unresolved', 'source_note' => 'Units from statement'], 'basis-unresolved-lot');
 $id = (int) $lot['transaction']['id'];
 $first = ['expected_revision' => 0, 'amount' => '50', 'reason' => 'Broker cost basis statement'];
 $resolved = $tracker->resolve_opening_basis($workspace, $id, $first, 'basis-first');
 equal($tracker->resolve_opening_basis($workspace, $id, $first, 'basis-first'), $resolved);
 equal((int) $resolved['resolution']['revision'], 1);
 decimal($tracker->holdings($workspace)['items'][0]['remaining_basis'], '50');
 equal($tracker->transaction($workspace, $id)['transaction']['amount'], '0.000000000000');
 opening_conflicts(fn() => $tracker->resolve_opening_basis($workspace, $id, $first, 'basis-stale'));
 $sale = $tracker->post($workspace, ['account_id' => (int) $account['id'], 'asset_id' => (int) $asset['id'], 'currency' => 'USD', 'state' => 'posted', 'action' => 'sell', 'effective_date' => '2026-01-02', 'quantity' => '2', 'unit_price' => '20', 'fees' => '0'], 'basis-sale');
 decimal($sale['transaction']['realized_gain'], '20');
 $tracker->resolve_opening_basis($workspace, $id, ['expected_revision' => 1, 'amount' => '75', 'reason' => 'Corrected broker basis'], 'basis-second');
 $detail = $tracker->transaction($workspace, (int) $sale['transaction']['id']);
 decimal($detail['transaction']['realized_gain'], '20'); decimal($detail['current_calculation']['realized_gain'], '10');
 decimal($tracker->holdings($workspace)['items'][0]['remaining_basis'], '45');
 equal(count($db->rows('SELECT id FROM ' . $db->table('opening_basis_resolutions') . ' WHERE workspace_id = %d AND opening_transaction_id = %d', [$workspace, $id])), 2);
});

test('Opening basis REST denies viewers and resolves explicit zero', function () use ($tracker, $owner, $viewer) {
 $workspace = (int) $tracker->create_workspace(['name' => 'Basis REST fixture'])['id'];
 $account = $tracker->create_object($workspace, 'accounts', ['name' => 'Basis REST account', 'native_currency' => 'USD']);
 $asset = $tracker->create_object($workspace, 'assets', ['symbol' => 'BREST', 'exchange' => 'TEST', 'asset_class' => 'stock', 'quote_currency' => 'USD']);
 $lot = $tracker->opening_balance($workspace, ['account_id' => (int) $account['id'], 'asset_id' => (int) $asset['id'], 'kind' => 'lot', 'effective_date' => '2026-01-01', 'acquired_on' => '2020-01-01', 'quantity' => '1', 'basis_status' => 'unresolved', 'source_note' => 'No original basis'], 'basis-rest-lot');
 $request = new WP_REST_Request('POST', '/tgit/v1/workspaces/' . $workspace . '/opening-balances/' . $lot['transaction']['id'] . '/basis-resolutions');
 $request->set_header('Content-Type', 'application/json'); $request->set_header('Idempotency-Key', 'basis-rest-zero');
 $request->set_body(wp_json_encode(['expected_revision' => 0, 'amount' => '0', 'reason' => 'Statement confirms zero basis']));
 wp_set_current_user($viewer); equal(rest_do_request($request)->get_status(), 403);
 wp_set_current_user($owner); $response = rest_do_request($request); equal($response->get_status(), 200);
 decimal($tracker->holdings($workspace)['items'][0]['remaining_basis'], '0');
});
