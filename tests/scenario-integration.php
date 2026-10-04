<?php
/** Disposable non-posting calculator REST checks. */
if (!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE !== true) throw new RuntimeException('Disposable site required.');

test('Crypto and risk REST scenarios are private and never post ledger facts', function () use ($tracker, $owner, $viewer) {
 $workspace = (int) $tracker->create_workspace(['name' => 'Scenario fixture'])['id'];
 $crypto = new WP_REST_Request('POST', '/tgit/v1/workspaces/' . $workspace . '/calculators/crypto');
 $crypto->set_header('Content-Type', 'application/json');
 $crypto->set_body(wp_json_encode(['buy_price' => '20', 'sell_price' => '30', 'investment' => '100', 'buy_fee' => '2', 'sell_fee' => '3', 'note' => '<b>example</b>']));
 wp_set_current_user($viewer); equal(rest_do_request($crypto)->get_status(), 403);
 wp_set_current_user($owner); $response = rest_do_request($crypto); equal($response->get_status(), 200);
 $result = $response->get_data()['data']; decimal($result['profit_amount'], '45'); equal($result['posted'], false); equal($result['note'], 'example');
 $risk = new WP_REST_Request('POST', '/tgit/v1/workspaces/' . $workspace . '/calculators/risk');
 $risk->set_header('Content-Type', 'application/json'); $risk->set_body(wp_json_encode(['entry_price' => '100', 'risk_budget' => '50', 'stop_distance' => '5']));
 decimal(rest_do_request($risk)->get_data()['data']['position_size'], '10');
 $crypto->set_body(wp_json_encode(['buy_price' => 20, 'sell_price' => '30', 'investment' => '100'])); equal(rest_do_request($crypto)->get_status(), 400);
 equal(count($tracker->list_objects($workspace, 'transactions')), 0);
});
