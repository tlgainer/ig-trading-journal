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

test('Bought options and stop-loss prices stay private, decimal and non-posting', function () use ($tracker, $owner, $viewer) {
 wp_set_current_user($owner); $workspace = (int) $tracker->create_workspace(['name'=>'Option scenario fixture'])['id'];
 $request = new WP_REST_Request('POST', '/tgit/v1/workspaces/'.$workspace.'/calculators/option'); $request->set_header('Content-Type','application/json');
 $input = ['option_type'=>'call','mode'=>'close','entry_premium'=>'2','exit_premium'=>'3','contracts'=>'1','multiplier'=>'100']; $request->set_body(wp_json_encode($input));
 wp_set_current_user($viewer); equal(rest_do_request($request)->get_status(),403); wp_set_current_user($owner);
 $result = rest_do_request($request)->get_data()['data']; decimal($result['net_profit'],'100'); equal($result['posted'],false);
 foreach (['entry_premium'=>2,'contracts'=>'1.5','option_type'=>'sold','underlying_price'=>'100'] as $field=>$value) { $request->set_body(wp_json_encode(array_replace($input,[$field=>$value]))); equal(rest_do_request($request)->get_status(),400); }
 $request->set_body(wp_json_encode(['option_type'=>'put','mode'=>'expiry','entry_premium'=>'2','strike'=>'100','underlying_price'=>'95','contracts'=>'1','multiplier'=>'100'])); decimal(rest_do_request($request)->get_data()['data']['net_profit'],'300');
 $risk = new WP_REST_Request('POST','/tgit/v1/workspaces/'.$workspace.'/calculators/risk'); $risk->set_header('Content-Type','application/json');
 $risk->set_body(wp_json_encode(['entry_price'=>'300','stop_price'=>'295','risk_budget'=>'50'])); decimal(rest_do_request($risk)->get_data()['data']['position_size'],'10');
 foreach (['stop_price'=>'305','stop_distance'=>'5'] as $field=>$value) { $risk->set_body(wp_json_encode(array_replace(['entry_price'=>'300','stop_price'=>'295','risk_budget'=>'50'],[$field=>$value]))); equal(rest_do_request($risk)->get_status(),400); }
 equal(count($tracker->list_objects($workspace,'transactions')),0);
});

test('Stock profit and short sizing REST are authorized, exact and non-posting', function () use ($tracker, $owner, $viewer) {
 wp_set_current_user($owner);
 $workspace = (int) $tracker->create_workspace(['name' => 'Stock scenario fixture'])['id'];
 foreach (['stock' => ['direction' => 'short', 'entry_price' => '100', 'exit_price' => '90', 'quantity' => '10', 'borrow_cost' => '4', 'dividend_cost' => '1'], 'short-risk' => ['entry_price' => '100', 'stop_price' => '105', 'risk_budget' => '200', 'estimated_costs' => '12']] as $type => $input) {
  $request = new WP_REST_Request('POST', '/tgit/v1/workspaces/' . $workspace . '/calculators/' . $type); $request->set_header('Content-Type', 'application/json'); $request->set_body(wp_json_encode($input));
  wp_set_current_user($viewer); equal(rest_do_request($request)->get_status(), 403);
  wp_set_current_user($owner); $response = rest_do_request($request); equal($response->get_status(), 200); $result = $response->get_data()['data']; equal($result['posted'], false); equal($result['margin_required'], null);
  if ($type === 'stock') decimal($result['net_profit'], '95'); else equal($result['position_size'], '37');
  foreach (['entry_price' => '0', 'unexpected' => 'x', 'currency' => 'USDT'] as $field => $value) {
   $request->set_body(wp_json_encode(array_replace($input, [$field => $value]))); equal(rest_do_request($request)->get_status(), 400);
  }
  $request->set_body(wp_json_encode(array_replace($input, ['entry_price' => 100]))); equal(rest_do_request($request)->get_status(), 400);
 }
 equal(count($tracker->list_objects($workspace, 'transactions')), 0);
});

test('Linear scenarios enforce workspace privacy, decimal strings and non-posting', function () use ($tracker, $owner, $viewer) {
 wp_set_current_user($owner);
 $workspace = (int) $tracker->create_workspace(['name' => 'Linear scenario fixture'])['id'];
 $request = new WP_REST_Request('POST', '/tgit/v1/workspaces/' . $workspace . '/calculators/leveraged');
 $request->set_header('Content-Type', 'application/json');
 $input = ['direction' => 'long', 'entry_price' => '75000', 'exit_price' => '82500', 'collateral' => '2000', 'leverage' => '2', 'currency' => 'USD'];
 $request->set_body(wp_json_encode($input));
 wp_set_current_user($viewer); equal(rest_do_request($request)->get_status(), 403);
 wp_set_current_user($owner); $response = rest_do_request($request); equal($response->get_status(), 200);
 $result = $response->get_data()['data']; decimal($result['net_profit'], '400'); equal($result['posted'], false); equal($result['liquidation_price'], null);
 foreach (['leverage' => 2, 'direction' => 'inverse', 'other_costs' => '-5', 'currency' => 'USDT', 'unexpected' => 'x'] as $field => $value) {
  $request->set_body(wp_json_encode(array_replace($input, [$field => $value]))); equal(rest_do_request($request)->get_status(), 400);
 }
 equal(count($tracker->list_objects($workspace, 'transactions')), 0);
});
