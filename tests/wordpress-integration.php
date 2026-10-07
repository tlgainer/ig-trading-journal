<?php
/** Run ONLY in a disposable WordPress database: wp eval-file tests/wordpress-integration.php */
declare(strict_types=1);
use GainerInteractive\IGTradingJournal\Application\Tracker;
use GainerInteractive\IGTradingJournal\Infrastructure\Database;
use GainerInteractive\IGTradingJournal\Infrastructure\Installer;
use GainerInteractive\IGTradingJournal\Domain\Decimal;

if (!defined('ABSPATH') || !defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE !== true) {
 throw new RuntimeException('Set TGIT_DISPOSABLE_TEST_SITE=true in a disposable test site wp-config.php. Never run on production.');
}
require_once __DIR__ . '/run.php';
$domain_passed = $passed;
global $wpdb;
Installer::install();
$suffix = wp_generate_uuid4();
$owner = wp_create_user('tgit-owner-' . $suffix, wp_generate_password(), 'owner-' . $suffix . '@example.invalid');
$viewer = wp_create_user('tgit-viewer-' . $suffix, wp_generate_password(), 'viewer-' . $suffix . '@example.invalid');
if (is_wp_error($owner) || is_wp_error($viewer)) { throw new RuntimeException('Unable to create fixture users.'); }
(new WP_User($owner))->set_role('administrator');
$db = new Database($wpdb);
$tracker = new Tracker($db, $owner, wp_generate_uuid4());
$w1 = $tracker->create_workspace(['name' => 'Fixture A', 'base_currency' => 'USD', 'timezone' => 'America/New_York'])['id'];
$w2 = $tracker->create_workspace(['name' => 'Fixture B', 'base_currency' => 'USD', 'timezone' => 'Africa/Casablanca'])['id'];
$account = $tracker->create_object($w1, 'accounts', ['name' => 'Account A', 'native_currency' => 'USD']);
$foreign = $tracker->create_object($w2, 'accounts', ['name' => 'Account B', 'native_currency' => 'USD']);
$asset = $tracker->create_object($w1, 'assets', ['symbol' => 'FIXTURE', 'exchange' => 'TEST', 'asset_class' => 'stock', 'quote_currency' => 'USD']);
$cash = ['account_id' => (int) $account['id'], 'action' => 'deposit', 'effective_date' => '2026-01-01', 'state' => 'posted', 'amount' => '2000', 'currency' => 'USD'];
$buy = ['account_id' => (int) $account['id'], 'asset_id' => (int) $asset['id'], 'action' => 'buy', 'effective_date' => '2026-01-02', 'state' => 'posted', 'quantity' => '10', 'unit_price' => '100', 'fees' => '5', 'currency' => 'USD'];
$sell = array_replace($buy, ['action' => 'sell', 'effective_date' => '2026-01-03', 'quantity' => '4', 'unit_price' => '120', 'fees' => '2']);
test('Database AC 01 and atomic ledger persistence', function () use ($tracker, $w1, $cash, $buy, $sell) {
 $tracker->post($w1, $cash, 'fixture-deposit'); $tracker->post($w1, $buy, 'fixture-buy');
 $result = $tracker->post($w1, $sell, 'fixture-sell'); decimal($result['transaction']['realized_gain'], '76');
 $holding = $tracker->holdings($w1)['items'][0]; decimal($holding['quantity'], '6'); decimal($holding['remaining_basis'], '603');
 decimal($tracker->list_objects($w1, 'accounts')[0]['cash_balance'], '1473');
});
test('Database AC 10 retry returns original event', function () use ($tracker, $w1, $buy) {
 $before = count($tracker->list_objects($w1, 'transactions'));
 $first = $tracker->post($w1, $buy, 'fixture-buy'); $second = $tracker->post($w1, $buy, 'fixture-buy'); equal($first, $second);
 equal(count($tracker->list_objects($w1, 'transactions')), $before);
 try { $tracker->post($w1, array_replace($buy, ['fees' => '6']), 'fixture-buy'); } catch (UnexpectedValueException $e) { return; }
 throw new RuntimeException('Changed payload reused idempotency key.');
});
test('Rejected foreign account leaves no transaction', function () use ($tracker, $w1, $cash, $foreign) {
 $before = count($tracker->list_objects($w1, 'transactions'));
 try { $tracker->post($w1, array_replace($cash, ['account_id' => (int) $foreign['id']]), 'fixture-foreign'); }
 catch (OutOfBoundsException $e) { equal(count($tracker->list_objects($w1, 'transactions')), $before); return; }
 throw new RuntimeException('Foreign account was accepted.');
});
test('Oversell rollback preserves cash and lot', function () use ($tracker, $w1, $sell) {
 $before = $tracker->list_objects($w1, 'accounts')[0]['cash_balance'];
 rejects(fn() => $tracker->post($w1, array_replace($sell, ['quantity' => '7']), 'fixture-oversell'));
 equal($tracker->list_objects($w1, 'accounts')[0]['cash_balance'], $before); decimal($tracker->holdings($w1)['items'][0]['quantity'], '6');
});
test('Draft has no ledger effect', function () use ($tracker, $w1, $buy) {
 $before = $tracker->holdings($w1)['items'];
 $tracker->post($w1, array_replace($buy, ['state' => 'draft']), 'fixture-draft'); equal($tracker->holdings($w1)['items'], $before);
});
test('Cash overdraft rejection preserves cash and history', function () use ($tracker, $w1, $cash) {
 $balance = $tracker->list_objects($w1, 'accounts')[0]['cash_balance'];
 $count = count($tracker->list_objects($w1, 'transactions'));
 rejects(fn() => $tracker->post($w1, array_replace($cash, ['action' => 'withdrawal', 'amount' => '2000', 'effective_date' => '2026-01-04']), 'fixture-overdraft'));
 equal($tracker->list_objects($w1, 'accounts')[0]['cash_balance'], $balance); equal(count($tracker->list_objects($w1, 'transactions')), $count);
});
test('Owner must transfer last ownership', function () use ($tracker, $w1, $owner) { rejects(fn() => $tracker->set_member($w1, ['wp_user_id' => $owner, 'role' => 'viewer', 'state' => 'active'])); });
$tracker->set_member($w1, ['wp_user_id' => $viewer, 'role' => 'viewer', 'state' => 'active']);
$reader = new Tracker($db, $viewer, wp_generate_uuid4());
test('Two-workspace isolation denies foreign reads and viewer posting', function () use ($reader, $w1, $w2, $buy) {
 equal(count($reader->workspaces()), 1);
 foreach ([fn() => $reader->list_objects($w2, 'accounts'), fn() => $reader->post($w1, $buy, 'fixture-viewer')] as $call) {
  $denied = false; try { $call(); } catch (DomainException $e) { $denied = true; } equal($denied, true);
 }
});
$tracker->set_member($w1, ['wp_user_id' => $viewer, 'role' => 'viewer', 'state' => 'revoked']);
test('Revocation affects existing service instance immediately', function () use ($reader, $w1) {
 try { $reader->holdings($w1); } catch (DomainException $e) { return; } throw new RuntimeException('Revoked user retained access.');
});
test('Failure after financial writes rolls back the entire operation', function () use ($db, $tracker, $w1, $cash) {
 $before_cash = $tracker->list_objects($w1, 'accounts')[0]['cash_balance'];
 $before_count = count($tracker->list_objects($w1, 'transactions'));
 $trigger = 'tgit_fixture_fail_' . $w1;
 $db->query('CREATE TRIGGER ' . $trigger . ' BEFORE INSERT ON ' . $db->table('audit_events') . " FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture forced failure'");
 $failed = false;
 try { $tracker->post($w1, array_replace($cash, ['effective_date' => '2026-01-04', 'amount' => '10']), 'fixture-failed-commit'); }
 catch (RuntimeException $e) { $failed = true; }
 finally { $db->query('DROP TRIGGER ' . $trigger); }
 equal($failed, true); equal($tracker->list_objects($w1, 'accounts')[0]['cash_balance'], $before_cash);
 equal(count($tracker->list_objects($w1, 'transactions')), $before_count);
 equal($db->row('SELECT id FROM ' . $db->table('idempotency') . ' WHERE workspace_id = %d AND request_key = %s', [$w1, 'fixture-failed-commit']), null);
});
test('Historical posting blocked without rewriting prior lots', function () use ($tracker, $w1, $buy) {
 try { $tracker->post($w1, $buy, 'fixture-backdate'); } catch (UnexpectedValueException $e) { return; }
 throw new RuntimeException('Earlier-dated post was accepted.');
});
test('REST schemas reject numeric money, unknown fields and oversized pagination', function () use ($owner, $w1, $cash) {
 wp_set_current_user($owner);
 foreach ([array_replace($cash, ['amount' => 100]), $cash + ['unexpected' => 'value']] as $payload) {
  $request = new WP_REST_Request('POST', '/tgit/v1/workspaces/' . $w1 . '/transactions');
  $request->set_header('Content-Type', 'application/json'); $request->set_header('Idempotency-Key', 'fixture-rest-invalid'); $request->set_body(wp_json_encode($payload));
  equal(rest_do_request($request)->get_status(), 400);
 }
 $request = new WP_REST_Request('GET', '/tgit/v1/workspaces/' . $w1 . '/transactions'); $request->set_query_params(['limit' => 101]);
 equal(rest_do_request($request)->get_status(), 400);
});
test('REST denies nonmember administrator and anonymous access', function () use ($viewer, $w1, $w2) {
 (new WP_User($viewer))->set_role('administrator'); wp_set_current_user($viewer);
 foreach ([$w1, $w2] as $workspace) { equal(rest_do_request(new WP_REST_Request('GET', '/tgit/v1/workspaces/' . $workspace . '/accounts'))->get_status(), 403); }
 wp_set_current_user(0); equal(rest_do_request(new WP_REST_Request('GET', '/tgit/v1/workspaces'))->get_status(), 401);
});
test('Private REST responses carry no-store even on denied access', function () use ($w1) {
 $request = new WP_REST_Request('GET', '/tgit/v1/workspaces/' . $w1 . '/accounts');
 $response = apply_filters('rest_post_dispatch', rest_do_request($request), rest_get_server(), $request);
 equal($response->get_headers()['Cache-Control'], 'private, no-store, max-age=0');
});
test('Concurrent sales cannot both consume the same units', function () use ($tracker, $w1, $owner, $sell) {
 if (!function_exists('proc_open')) { throw new RuntimeException('proc_open required for concurrency gate.'); }
 $processes = [];
 foreach (['a', 'b'] as $key) {
  $task = ['payload' => array_replace($sell, ['effective_date' => '2026-01-04']), 'key' => 'fixture-concurrent-' . $key];
  $command = [PHP_BINARY, '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=mysqli', __DIR__ . '/concurrency-worker.php', rtrim(ABSPATH, '/\\'), (string) $owner, (string) $w1, wp_json_encode($task)];
  $pipes = []; $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  if (!is_resource($process)) { throw new RuntimeException('Could not start concurrency worker.'); }
  fclose($pipes[0]); $processes[] = [$process, $pipes];
 }
 $results = [];
 foreach ($processes as [$process, $pipes]) {
  $results[] = trim(stream_get_contents($pipes[1])); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
  if (proc_close($process) !== 0) { throw new RuntimeException('Concurrency worker failed: ' . $error); }
 }
 sort($results); equal($results, ['posted', 'rejected']); decimal($tracker->holdings($w1)['items'][0]['quantity'], '2');
});
echo "Integration fixtures retained in disposable site for inspection. Recreate site after testing.\n";

require __DIR__ . '/draft-integration.php';

require __DIR__ . '/journal-integration.php';

require __DIR__ . '/opening-integration.php';

require __DIR__ . '/replay-integration.php';

require __DIR__ . '/scenario-integration.php';

require __DIR__ . '/research-integration.php';
require __DIR__ . '/report-integration.php';
require __DIR__ . '/provider-integration.php';
require __DIR__ . '/fundamentals-integration.php';
require __DIR__ . '/fundamental-metrics-integration.php';
require __DIR__ . '/fundamental-rest-integration.php';
require __DIR__ . '/fundamental-refresh-integration.php';
require __DIR__ . '/ai-spending-integration.php';

echo sprintf("%d unit and %d integration checks passed.\n", $domain_passed, $passed - $domain_passed);
