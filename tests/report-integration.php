<?php
/** Disposable price, FX, report and saved view fixtures. */
use GainerInteractive\IGTradingJournal\Application\Tracker;
if (!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE !== true) throw new RuntimeException('Disposable site required.');

test('Schema 8 upgrades from schema 7 and repeats without loss', function () use ($db) {
 update_option('tgit_schema_version', '7');
 \GainerInteractive\IGTradingJournal\Infrastructure\Installer::install();
 equal(get_option('tgit_schema_version'), '8');
 equal($db->row('SHOW COLUMNS FROM ' . $db->table('market_observations') . ' LIKE %s', ['supersedes_id'])['Field'], 'supersedes_id');
 \GainerInteractive\IGTradingJournal\Infrastructure\Installer::install();
});

test('AC02 manual valuation, cash-inclusive allocation and immutable report corrections', function () use ($tracker, $db) {
 $w = (int) $tracker->create_workspace(['name' => 'Manual valuation'])['id'];
 $a = (int) $tracker->create_object($w, 'accounts', ['name' => 'Cash', 'native_currency' => 'USD'])['id'];
 $s = (int) $tracker->create_object($w, 'assets', ['symbol' => 'VALUE', 'exchange' => 'FIXTURE', 'asset_class' => 'stock', 'quote_currency' => 'USD'])['id'];
 $tracker->post($w, ['account_id'=>$a,'action'=>'deposit','effective_date'=>'2026-01-01','state'=>'posted','amount'=>'2000','currency'=>'USD'], 'valuation-deposit');
 $buy = ['account_id'=>$a,'asset_id'=>$s,'action'=>'buy','effective_date'=>'2026-01-02','state'=>'posted','quantity'=>'10','unit_price'=>'100','fees'=>'5','currency'=>'USD'];
 $tracker->post($w, $buy, 'valuation-buy');
 $tracker->post($w, array_replace($buy,['action'=>'sell','effective_date'=>'2026-01-03','quantity'=>'4','unit_price'=>'120','fees'=>'2']), 'valuation-sell');
 $input = ['kind'=>'price','asset_id'=>$s,'currency'=>'USD','value'=>'110','effective_date'=>'2026-01-03','expires_on'=>'2026-01-04','source'=>'Manual close','reason'=>'Statement'];
 $o = $tracker->record_observation($w,$input,'valuation-price'); equal($tracker->record_observation($w,$input,'valuation-price'),$o);
 $filter = ['type'=>'holdings','as_of'=>'2026-01-04'];
 $r = $tracker->generate_report($w,$filter,'valuation-report'); equal($tracker->generate_report($w,$filter,'valuation-report'),$r);
 $p=$r['items'][0]; decimal($p['market_value'],'660'); decimal($p['unrealized_gain'],'57'); decimal($r['base_equity'],'2133'); decimal($r['base_realized_gain'],'76'); equal($r['coverage']['missing_prices'],0);
 decimal($p['realized_gain'],'76'); decimal($p['lifetime_purchase_spend'],'1005'); equal($p['income'],null);
 $economic=$tracker->generate_report($w,['type'=>'holdings','from'=>'2026-01-01','as_of'=>'2026-01-04'],'valuation-economic'); decimal($economic['economic_gain']['amount'],'133'); decimal($economic['economic_gain']['external_net_contributions'],'2000'); equal($economic['economic_gain']['status'],'complete');
 $opening=$tracker->generate_report($w,['type'=>'holdings','from'=>'2026-01-02','as_of'=>'2026-01-04'],'valuation-opening-equity'); decimal($opening['economic_gain']['amount'],'133'); decimal($opening['economic_gain']['opening_equity'],'2000');
 $corrected = $tracker->record_observation($w,array_replace($input,['value'=>'120','supersedes_id'=>(int)$o['id'],'reason'=>'Corrected statement']),'valuation-price-correct');
 equal(count($tracker->observations($w)),2); equal((int)$tracker->observations($w)[0]['superseded_by'],(int)$corrected['id']);
 equal($tracker->report_run($w,(int)$r['id']),$r);
 $new = $tracker->generate_report($w,$filter,'valuation-report-new'); decimal($new['items'][0]['market_value'],'720');
 $stale = $tracker->generate_report($w,['type'=>'holdings','as_of'=>'2026-01-05'],'valuation-report-stale'); equal($stale['coverage']['stale_prices'],1); equal($stale['items'][0]['price']['status'],'stale');
 $staleEconomic=$tracker->generate_report($w,['type'=>'holdings','from'=>'2026-01-01','as_of'=>'2026-01-05'],'valuation-stale-economic'); equal($staleEconomic['economic_gain']['amount'],null);
 $historical=$tracker->generate_report($w,['type'=>'holdings','as_of'=>'2026-01-02'],'valuation-before-price'); equal($historical['items'][0]['market_value'],null); equal($historical['base_equity'],null); decimal($historical['items'][0]['quantity'],'10');
 rejects(fn()=>$tracker->record_observation($w,array_replace($input,['currency'=>'EUR']),'valuation-wrong-currency'));
 try { $tracker->record_observation($w,array_replace($input,['supersedes_id'=>(int)$o['id']]),'valuation-stale-correction'); throw new RuntimeException('Expected conflict.'); } catch (UnexpectedValueException $e) {}
});

test('AC07 uses acquisition FX for basis and sale FX for proceeds, never current FX for both', function () use ($tracker) {
 $w=(int)$tracker->create_workspace(['name'=>'Foreign valuation'])['id'];
 $a=(int)$tracker->create_object($w,'accounts',['name'=>'EUR cash','native_currency'=>'EUR'])['id'];
 $s=(int)$tracker->create_object($w,'assets',['symbol'=>'FXV','exchange'=>'FIXTURE','asset_class'=>'stock','quote_currency'=>'EUR'])['id'];
 $tracker->post($w,['account_id'=>$a,'action'=>'deposit','effective_date'=>'2026-01-01','state'=>'posted','amount'=>'100','currency'=>'EUR'],'fixture-fxv-deposit');
 $buy=['account_id'=>$a,'asset_id'=>$s,'action'=>'buy','effective_date'=>'2026-01-02','state'=>'posted','quantity'=>'1','unit_price'=>'100','fees'=>'0','currency'=>'EUR'];
 $b=$tracker->post($w,$buy,'fixture-fxv-buy')['transaction'];
 $sale=$tracker->post($w,array_replace($buy,['action'=>'sell','effective_date'=>'2026-01-03','unit_price'=>'120']),'fixture-fxv-sell')['transaction'];
 $filter=['type'=>'gains','from'=>'2026-01-03','as_of'=>'2026-01-03'];
 $missing=$tracker->generate_report($w,$filter,'fixture-fxv-missing'); equal($missing['base_realized_gain'],null); equal($missing['base_equity'],null);
 foreach (['2026-01-02'=>'1.10','2026-01-03'=>'1.20'] as $date=>$value) $tracker->record_observation($w,['kind'=>'fx','currency'=>'EUR','value'=>$value,'effective_date'=>$date,'expires_on'=>$date,'source'=>'Statement','reason'=>'Historical FX'],'fixture-fxv-'.$date);
 $r=$tracker->generate_report($w,$filter,'fixture-fxv-complete'); decimal($r['items'][0]['realized_gain'],'20'); decimal($r['items'][0]['base_realized_gain'],'34'); decimal($r['base_realized_gain'],'34'); decimal($r['base_equity'],'144');
 $j=new \GainerInteractive\IGTradingJournal\Application\Journal($GLOBALS['db'],$GLOBALS['owner'],wp_generate_uuid4());
 $j->save_trade($w,0,['asset_id'=>$s,'title'=>'Closed FX group','state'=>'closed','opened_on'=>'2026-01-02','closed_on'=>'2026-01-03','transaction_ids'=>[(int)$b['id'],(int)$sale['id']],'journal'=>['tags'=>['fx']]],'fixture-fxv-group');
 $strategy=$tracker->generate_report($w,['type'=>'strategy','as_of'=>'2026-01-03','tags'=>['fx']],'fixture-fxv-strategy'); equal(count($strategy['items']),1); equal($strategy['items'][0]['wins'],1); decimal($strategy['items'][0]['base_realized_gain'],'34'); decimal($strategy['items'][0]['win_rate_percent'],'100');
 $income=$tracker->generate_report($w,['type'=>'income','as_of'=>'2026-01-03'],'fixture-fxv-income'); equal($income['items'],[]); equal($income['income_status'],'unsupported_accounting_actions');
});

test('Report isolation, viewer read-only observations and personal saved view revision checks', function () use ($tracker,$db,$owner,$viewer) {
 $w=(int)$tracker->create_workspace(['name'=>'Report permissions'])['id'];
 $other=(int)$tracker->create_workspace(['name'=>'Other report permissions'])['id'];
 $s=(int)$tracker->create_object($other,'assets',['symbol'=>'PRIVATE','exchange'=>'FIXTURE','asset_class'=>'stock','quote_currency'=>'USD'])['id'];
 try { $tracker->generate_report($w,['type'=>'holdings','as_of'=>'2026-01-03','asset_id'=>$s],'report-foreign-asset'); throw new RuntimeException('Expected foreign rejection.'); } catch (OutOfBoundsException $e) {}
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'active']);
 $v=new Tracker($db,$viewer,wp_generate_uuid4());
 $filters=['type'=>'activity','from'=>'2026-01-01','as_of'=>'2026-01-03','currency'=>'USD'];
 $view=$v->save_view($w,['name'=>'My January','filters'=>$filters],'view-first'); equal(count($v->saved_views($w)),1); equal($tracker->saved_views($w),[]);
 $revised=$v->save_view($w,['view_id'=>(int)$view['id'],'expected_revision'=>1,'name'=>'January activity','filters'=>$filters],'view-revise'); equal((int)$revised['revision'],2);
 try { $v->save_view($w,['view_id'=>(int)$view['id'],'expected_revision'=>1,'name'=>'Stale','filters'=>$filters],'view-stale'); throw new RuntimeException('Expected revision conflict.'); } catch (UnexpectedValueException $e) {}
 try { $tracker->save_view($w,['view_id'=>(int)$view['id'],'expected_revision'=>2,'name'=>'Foreign member view','filters'=>$filters],'view-other-member'); throw new RuntimeException('Expected private view rejection.'); } catch (DomainException $e) {}
 try { $v->record_observation($w,['kind'=>'fx','currency'=>'EUR','value'=>'1.2','effective_date'=>'2026-01-03','expires_on'=>'2026-01-03','source'=>'Manual','reason'=>'Denied'],'view-denied-write'); throw new RuntimeException('Expected write rejection.'); } catch (DomainException $e) {}
 $r=$v->generate_report($w,$filters,'viewer-report');
 try { $tracker->report_run($other,(int)$r['id']); throw new RuntimeException('Expected foreign report rejection.'); } catch (OutOfBoundsException $e) {}
 wp_set_current_user($viewer);
 $request=new WP_REST_Request('POST','/tgit/v1/workspaces/'.$w.'/reports'); $request->set_header('Content-Type','application/json'); $request->set_header('Idempotency-Key','viewer-rest-report'); $request->set_body(wp_json_encode($filters));
 $response=rest_do_request($request); equal($response->get_status(),200); equal($response->get_data()['data']['filters'],$filters);
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'revoked']); equal(rest_do_request($request)->get_status(),403); wp_set_current_user($owner);
});

test('Documented zero price and unresolved basis never invent a gain or return denominator', function () use ($tracker) {
 $w=(int)$tracker->create_workspace(['name'=>'Zero value fixture'])['id'];
 $a=(int)$tracker->create_object($w,'accounts',['name'=>'Opening cash','native_currency'=>'USD'])['id'];
 $s=(int)$tracker->create_object($w,'assets',['symbol'=>'ZERO','exchange'=>'FIXTURE','asset_class'=>'stock','quote_currency'=>'USD'])['id'];
 $tracker->opening_balance($w,['account_id'=>$a,'asset_id'=>$s,'kind'=>'lot','effective_date'=>'2026-01-01','acquired_on'=>'2025-01-01','quantity'=>'1','basis_status'=>'unresolved','source_note'=>'Missing statement'],'zero-opening');
 $input=['kind'=>'price','asset_id'=>$s,'currency'=>'USD','value'=>'0','effective_date'=>'2026-01-01','expires_on'=>'2026-01-01','source'=>'Worthless asset statement','reason'=>'Documented zero valuation'];
 $tracker->record_observation($w,$input,'zero-price');
 $report=$tracker->generate_report($w,['type'=>'holdings','as_of'=>'2026-01-01'],'zero-report');
 decimal($report['items'][0]['market_value'],'0'); decimal($report['base_equity'],'0'); equal($report['items'][0]['remaining_basis'],null); equal($report['items'][0]['unrealized_gain'],null); equal($report['items'][0]['allocation_percent'],null); equal($report['items'][0]['unrealized_return_percent'],null);
 rejects(fn()=>$tracker->record_observation($w,['kind'=>'fx','currency'=>'EUR','value'=>'0','effective_date'=>'2026-01-01','expires_on'=>'2026-01-01','source'=>'Invalid','reason'=>'Invalid'],'zero-fx-rate'));
 rejects(fn()=>$tracker->generate_report($w,['type'=>'activity','as_of'=>'0999-01-01'],'invalid-storage-date'));
});

test('Observation and report audit failures roll back all records and retry identity', function () use ($tracker,$db) {
 $w=(int)$tracker->create_workspace(['name'=>'Reporting rollback'])['id'];
 $observation=['kind'=>'fx','currency'=>'EUR','value'=>'1.1','effective_date'=>'2026-01-01','expires_on'=>'2026-01-01','source'=>'Statement','reason'=>'Fixture'];
 $filter=['type'=>'activity','as_of'=>'2026-01-01'];
 $trigger='tgit_reporting_fail_'.$w;
 $db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture forced failure'");
 try {
  foreach ([fn()=>$tracker->record_observation($w,$observation,'rollback-observation'),fn()=>$tracker->generate_report($w,$filter,'rollback-report'),fn()=>$tracker->save_view($w,['name'=>'Rollback','filters'=>$filter],'rollback-view')] as $operation) {
   $failed=false; try { $operation(); } catch (RuntimeException $e) { $failed=true; } equal($failed,true);
  }
 } finally { $db->query('DROP TRIGGER '.$trigger); }
 equal($tracker->observations($w),[]); equal($tracker->reports($w),[]); equal($tracker->saved_views($w),[]);
 equal($db->rows('SELECT id FROM '.$db->table('idempotency').' WHERE workspace_id = %d',[$w]),[]);
 $tracker->record_observation($w,$observation,'rollback-observation'); $tracker->generate_report($w,$filter,'rollback-report'); $tracker->save_view($w,['name'=>'Rollback','filters'=>$filter],'rollback-view');
 equal(count($tracker->observations($w)),1); equal(count($tracker->reports($w)),1); equal(count($tracker->saved_views($w)),1);
});

test('Reporting refuses inconsistent stored account cash before saving a snapshot', function () use ($tracker,$db) {
 $w=(int)$tracker->create_workspace(['name'=>'Projection integrity'])['id'];
 $a=(int)$tracker->create_object($w,'accounts',['name'=>'Integrity cash','native_currency'=>'USD'])['id'];
 $tracker->post($w,['account_id'=>$a,'action'=>'deposit','effective_date'=>'2026-01-01','state'=>'posted','amount'=>'100','currency'=>'USD'],'integrity-deposit');
 $db->update_object('accounts',$w,$a,['cash_balance'=>'99']);
 $failed=false;
 try { $tracker->generate_report($w,['type'=>'activity','as_of'=>'2026-01-01'],'integrity-report'); } catch (RuntimeException $e) { $failed=true; }
 finally { $db->update_object('accounts',$w,$a,['cash_balance'=>'100']); }
 equal($failed,true); equal($tracker->reports($w),[]);
});
