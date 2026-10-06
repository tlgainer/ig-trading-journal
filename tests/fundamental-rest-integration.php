<?php
/** Fundamental routes use synthetic saved evidence on the disposable site. */
if (!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE !== true) throw new RuntimeException('Disposable site required.');

test('Fundamental missing required fields return validation errors without PHP warnings', function () use ($owner) {
 wp_set_current_user($owner); [$s,$w,$asset,$mapping]=provider_context();
 set_error_handler(static function($severity,$message,$file,$line) { throw new ErrorException($message,0,$severity,$file,$line); });
 try {
  equal(provider_rest('POST','workspaces/'.$w.'/assets/'.$asset.'/fundamental-metrics',[])->get_status(),400);
  equal(provider_rest('POST','workspaces/'.$w.'/provider-mappings/'.$mapping.'/fundamentals/refresh',[],'missing-dataset')->get_status(),400);
 } finally { restore_error_handler(); }
});

test('Fundamental REST history paginates private evidence and permits explicit viewer membership', function () use ($owner,$viewer,$tracker) {
 wp_set_current_user($owner); [$s,$w,$asset,$mapping,$ids]=saved_metric_context();
 $path='workspaces/'.$w.'/assets/'.$asset.'/fundamentals';
 $response=provider_rest('GET',$path.'?limit=1'); equal($response->get_status(),200);
 $data=$response->get_data()['data']; equal(count($data['items']),1); equal($data['next_cursor'],$data['items'][0]['id']);
 equal(count(provider_rest('GET',$path.'?after='.$data['next_cursor'])->get_data()['data']['items']),2);
 equal(provider_rest('GET',$path.'?limit=101')->get_status(),400);
 equal(provider_rest('GET',$path.'?after=-1')->get_status(),400);
 equal($response->get_headers()['Cache-Control'],'private, no-store, max-age=0');
 [$other,$foreign,$foreignAsset]=saved_metric_context();
 equal(provider_rest('GET','workspaces/'.$w.'/assets/'.$foreignAsset.'/fundamentals')->get_status(),404);
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'active']); wp_set_current_user($viewer);
 equal(provider_rest('GET',$path)->get_status(),200);
 equal(provider_rest('GET','workspaces/'.$foreign.'/assets/'.$foreignAsset.'/fundamentals')->get_status(),403);
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'revoked']); equal(provider_rest('GET',$path)->get_status(),403);
 wp_set_current_user($owner);
});

test('Fundamental REST metrics require explicit compatible sources and reject client capex policy', function () use ($owner,$viewer,$tracker,$db) {
 wp_set_current_user($owner); [$s,$w,$asset,$mapping,$ids]=saved_metric_context(true);
 $path='workspaces/'.$w.'/assets/'.$asset.'/fundamental-metrics'; $before=$s->fundamentals($w,$asset);
 $response=provider_rest('POST',$path,['snapshots'=>$ids]); equal($response->get_status(),200);
 file_put_contents(dirname(__DIR__).'/tmp/fundamental-browser-fixtures.json',wp_json_encode(['workspace'=>$w,'asset'=>$asset,'mapping'=>$mapping,'snapshots'=>$ids]));
 equal($response->get_data()['data'],$s->fundamental_metrics($w,$asset,$ids));
 equal($response->get_data()['data']['reports'][0]['metrics']['free_cash_flow']['status'],'unknown_capex_convention');
 equal($response->get_data()['data']['comparison_version'],'fundamental-comparisons-1');
 equal($response->get_data()['data']['comparisons'][0]['changes']['net_margin_percent']['status'],'missing_prior_period');
 decimal($response->get_data()['data']['comparisons'][1]['changes']['net_margin_percent']['change'],'5');
 equal($response->get_data()['data']['comparisons'][1]['days_between'],365);
 foreach([[],['snapshots'=>[]],['snapshots'=>$ids,'capex_convention'=>'positive_outflow'],['snapshots'=>['INCOME_STATEMENT'=>(string)$ids['INCOME_STATEMENT']]]] as $body) equal(provider_rest('POST',$path,$body)->get_status(),400);
 equal(provider_rest('POST',$path,['snapshots'=>['CASH_FLOW'=>$ids['BALANCE_SHEET']]])->get_status(),409);
 [$other,$foreign,$foreignAsset,$foreignMapping,$foreignIds]=saved_metric_context();
 equal(provider_rest('POST',$path,['snapshots'=>$foreignIds])->get_status(),404);
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'active']); wp_set_current_user($viewer);
 equal(provider_rest('POST',$path,['snapshots'=>$ids])->get_status(),200); wp_set_current_user($owner);
 equal($s->fundamentals($w,$asset),$before); equal($db->rows('SELECT * FROM '.$db->table('transactions').' WHERE workspace_id = %d',[$w]),[]);
});

test('Fundamental REST refresh is owner-only, strictly typed and separately disabled', function () use ($owner,$viewer,$tracker,$db) {
 wp_set_current_user($owner); [$s,$w,$asset,$mapping]=provider_context(); $base='workspaces/'.$w;
 $path=$base.'/provider-mappings/'.$mapping.'/fundamentals/refresh';
 equal(provider_rest('GET',$base.'/market-data')->get_data()['data']['fundamentals_enabled'],false);
 foreach([[],['dataset'=>'UNKNOWN'],['dataset'=>[]],['dataset'=>'OVERVIEW','symbol'=>'OTHER']] as $body) equal(provider_rest('POST',$path,$body,'fixture-refresh')->get_status(),400);
 equal(provider_rest('POST',$path,['dataset'=>'OVERVIEW'])->get_status(),400);
 equal(provider_rest('POST',$path,['dataset'=>'OVERVIEW'],str_repeat('a',81))->get_status(),400);
 $response=provider_rest('POST',$path,['dataset'=>'OVERVIEW'],'fixture-refresh'); equal($response->get_status(),200); equal($response->get_data()['data']['state'],'disabled');
 equal($db->rows('SELECT * FROM '.$db->table('provider_requests').' WHERE workspace_id = %d',[$w]),[]);
 [$other,$foreign,$foreignAsset,$foreignMapping]=provider_context();
 equal(provider_rest('POST',$base.'/provider-mappings/'.$foreignMapping.'/fundamentals/refresh',['dataset'=>'OVERVIEW'],'fixture-foreign')->get_status(),404);
 foreach(['manager','viewer'] as $role) { $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>$role,'state'=>'active']); wp_set_current_user($viewer); equal(provider_rest('POST',$path,['dataset'=>'OVERVIEW'],'fixture-role')->get_status(),403); wp_set_current_user($owner); }
});
