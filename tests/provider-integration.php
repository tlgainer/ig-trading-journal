<?php
/** Disposable provider persistence/quota fixtures. No keys or external requests. */
use GainerInteractive\IGTradingJournal\Application\MarketData;
use GainerInteractive\IGTradingJournal\Application\Tracker;
use GainerInteractive\IGTradingJournal\Infrastructure\Installer;
use GainerInteractive\IGTradingJournal\Infrastructure\QuoteRefresh;
if (!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE !== true) throw new RuntimeException('Disposable site required.');

function provider_context(): array {
 global $tracker, $db, $owner;
 $workspace = (int)$tracker->create_workspace(['name'=>'Provider synthetic fixture'])['id'];
 $asset = (int)$tracker->create_object($workspace,'assets',['symbol'=>'FIXTURE','exchange'=>'TESTEX','asset_class'=>'stock','quote_currency'=>'USD'])['id'];
 $service = new MarketData($db,$owner,wp_generate_uuid4());
 $input = ['provider'=>'alpha_vantage','provider_symbol'=>'FIXTURE','exchange'=>'TESTEX','currency'=>'USD','enabled'=>true,'evidence'=>'Synthetic identity confirmation','expected_mapping_id'=>0];
 $mapping = $service->save_mapping($workspace,$asset,$input);
 return [$service,$workspace,$asset,(int)$mapping['id'],$input];
}

function provider_body(string $price='320'): string {
 return wp_json_encode(['Global Quote'=>['01. symbol'=>'FIXTURE','05. price'=>$price,'08. previous close'=>'315','07. latest trading day'=>'2026-01-02']]);
}

function provider_conflict(callable $callback): void {
 try { $callback(); } catch (UnexpectedValueException $error) { return; }
 throw new RuntimeException('Expected provider conflict.');
}

function provider_fingerprint(string $label): string {
 // Synthetic server credential identity is isolated per retained fixture run.
 return hash('sha256', $label . ':' . $GLOBALS['owner']);
}

test('Schema 9 upgrades from 8, repairs a missing provider table and preserves manual evidence', function () use ($db) {
 $before=$db->row('SELECT COUNT(*) AS total FROM '.$db->table('market_observations'))['total'];
 update_option('tgit_schema_version','8'); Installer::install(); equal(get_option('tgit_schema_version'),'10');
 $db->query('DROP TABLE '.$db->table('provider_quotes')); Installer::install();
 equal($db->row('SHOW COLUMNS FROM '.$db->table('provider_quotes').' LIKE %s',['session_date'])['Field'],'session_date');
 equal($db->row('SELECT COUNT(*) AS total FROM '.$db->table('market_observations'))['total'],$before);
});

test('Provider mappings append revisions, enforce identity and reject foreign workspace assets', function () use ($tracker,$db) {
 [$s,$w,$asset,$mapping,$input]=provider_context();
 provider_conflict(fn()=>$s->save_mapping($w,$asset,$input));
 rejects(fn()=>$s->save_mapping($w,$asset,array_replace($input,['currency'=>'EUR','expected_mapping_id'=>$mapping])));
 rejects(fn()=>$s->save_mapping($w,$asset,array_replace($input,['exchange'=>'OTHER','expected_mapping_id'=>$mapping])));
 $other=(int)$tracker->create_workspace(['name'=>'Foreign provider fixture'])['id'];
 try { $s->save_mapping($other,$asset,$input); throw new RuntimeException('Foreign mapping accepted'); } catch (OutOfBoundsException $error) {}
 $new=$s->save_mapping($w,$asset,array_replace($input,['expected_mapping_id'=>$mapping,'enabled'=>false]));
 equal($db->object('provider_mappings',$w,$mapping)['enabled'],'1'); equal($new['enabled'],'0');
 provider_conflict(fn()=>$s->reserve($w,$mapping,provider_fingerprint('fixture'),'disabled-old'));
 provider_conflict(fn()=>$s->reserve($w,(int)$new['id'],provider_fingerprint('fixture'),'disabled-new'));
});

test('Provider request lifecycle saves exact evidence once without touching ledger/manual prices', function () use ($db) {
 [$s,$w,$asset,$mapping]=provider_context();
 $r=$s->reserve($w,$mapping,provider_fingerprint('lifecycle'),'once'); equal($s->reserve($w,$mapping,provider_fingerprint('lifecycle'),'once'),$r);
 equal(array_keys($r),['id','mapping_id','state']);
 provider_conflict(fn()=>$s->complete_alpha_quote($w,$r['id'],provider_body()));
 $s->dispatch($w,$r['id']); provider_conflict(fn()=>$s->dispatch($w,$r['id']));
 $quote=$s->complete_alpha_quote($w,$r['id'],provider_body('320.123456789123456789')); decimal($quote['price'],'320.123456789123456789'); equal($quote['currency'],'USD'); equal($quote['exchange'],'TESTEX');
 equal($s->complete_alpha_quote($w,$r['id'],provider_body('320.123456789123456789')),$quote);
 provider_conflict(fn()=>$s->complete_alpha_quote($w,$r['id'],provider_body('321')));
 equal(count($s->quotes($w,$asset)),1);
 equal($db->row('SELECT COUNT(*) AS total FROM '.$db->table('transactions').' WHERE workspace_id = %d',[$w])['total'],'0');
 equal($db->row('SELECT COUNT(*) AS total FROM '.$db->table('market_observations').' WHERE workspace_id = %d',[$w])['total'],'0');
 $upper=$s->reserve($w,$mapping,provider_fingerprint('lifecycle'),'CASE'); $lower=$s->reserve($w,$mapping,provider_fingerprint('lifecycle'),'case'); equal($upper['id'] === $lower['id'],false);
});

test('Provider quotas are shared across workspaces and preserve five on-demand requests', function () {
 [$s,$w,$asset,$mapping]=provider_context(); [$other,$w2,$asset2,$mapping2]=provider_context(); $digest=provider_fingerprint('shared-quota');
 for($i=0;$i<20;$i++) { ($i%2?$other:$s)->reserve($i%2?$w2:$w,$i%2?$mapping2:$mapping,$digest,'scheduled-'.$i); }
 provider_conflict(fn()=>$s->reserve($w,$mapping,$digest,'scheduled-over'));
 for($i=0;$i<5;$i++) $other->reserve($w2,$mapping2,$digest,'manual-'.$i,false);
 provider_conflict(fn()=>$s->reserve($w,$mapping,$digest,'manual-over',false));
 equal($s->reserve($w,$mapping,provider_fingerprint('independent-credential'),'different-key')['state'],'reserved');
});

test('Expired reservations cannot dispatch and uncertain requests keep consuming quota', function () use ($db) {
 [$s,$w,$asset,$mapping]=provider_context(); $digest=provider_fingerprint('rolling-quota'); $old='2025-01-01 00:00:00';
 $expired=$s->reserve($w,$mapping,$digest,'expired');
 $db->update_object('provider_requests',$w,$expired['id'],['created_at'=>$old]);
 provider_conflict(fn()=>$s->dispatch($w,$expired['id']));
 for($i=0;$i<20;$i++) { $r=$s->reserve($w,$mapping,$digest,'uncertain-'.$i); $s->dispatch($w,$r['id']); $db->update_object('provider_requests',$w,$r['id'],['created_at'=>$old,'dispatched_at'=>$old]); }
 provider_conflict(fn()=>$s->reserve($w,$mapping,$digest,'uncertain-over'));
 $s->fail($w,$r['id']); $s->fail($w,$r['id']);
 equal($s->reserve($w,$mapping,$digest,'known-old-failure')['state'],'reserved');
});

test('FMP quota uses its own 250-request allowance with on-demand headroom', function () {
 [$s,$w,$asset,$mapping,$input]=provider_context();
 $fmp=$s->save_mapping($w,$asset,array_replace($input,['provider'=>'fmp'])); $digest=provider_fingerprint('fmp-quota');
 for($i=0;$i<245;$i++) $s->reserve($w,(int)$fmp['id'],$digest,'fmp-scheduled-'.$i);
 provider_conflict(fn()=>$s->reserve($w,(int)$fmp['id'],$digest,'fmp-scheduled-over'));
 for($i=0;$i<5;$i++) $s->reserve($w,(int)$fmp['id'],$digest,'fmp-manual-'.$i,false);
 provider_conflict(fn()=>$s->reserve($w,(int)$fmp['id'],$digest,'fmp-manual-over',false));
});

test('Known recent failures still count and changed mappings block in-flight completions', function () use ($db) {
 [$s,$w,$asset,$mapping,$input]=provider_context(); $digest=provider_fingerprint('failed-current');
 for($i=0;$i<20;$i++) { $r=$s->reserve($w,$mapping,$digest,'failed-'.$i); $s->dispatch($w,$r['id']); $s->fail($w,$r['id']); }
 provider_conflict(fn()=>$s->reserve($w,$mapping,$digest,'failure-over'));
 $r=$s->reserve($w,$mapping,provider_fingerprint('mapping-change'),'in-flight'); $s->dispatch($w,$r['id']);
 $s->save_mapping($w,$asset,array_replace($input,['expected_mapping_id'=>$mapping,'enabled'=>false]));
 provider_conflict(fn()=>$s->complete_alpha_quote($w,$r['id'],provider_body())); equal($s->quotes($w,$asset),[]);
 equal($db->object('provider_requests',$w,$r['id'])['state'],'dispatched');
});

test('Provider operations recheck owner authorization and isolate quote history', function () use ($db,$tracker,$owner,$viewer) {
 [$s,$w,$asset,$mapping]=provider_context(); $digest=provider_fingerprint('revocation');
 $r=$s->reserve($w,$mapping,$digest,'before-revoke');
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'active']);
 $v=new MarketData($db,$viewer,wp_generate_uuid4()); equal($v->quotes($w,$asset),[]);
 try { $v->reserve($w,$mapping,$digest,'denied'); throw new RuntimeException('Viewer reserved'); } catch (DomainException $error) {}
 [$other,$w2,$asset2,$mapping2]=provider_context();
 try { $other->quotes($w2,$asset); throw new RuntimeException('Foreign asset read'); } catch (OutOfBoundsException $error) {}
 $s->dispatch($w,$r['id']);
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'owner','state'=>'active']);
 $tracker->set_member($w,['wp_user_id'=>$owner,'role'=>'owner','state'=>'revoked']);
 foreach([fn()=>$s->dispatch($w,$r['id']),fn()=>$s->complete_alpha_quote($w,$r['id'],provider_body()),fn()=>$s->quotes($w,$asset)] as $callback) {
  try { $callback(); throw new RuntimeException('Revoked owner accessed provider operation'); } catch (DomainException $error) {}
 }
});

test('Provider quote audit failure rolls back evidence while keeping the dispatch reservation', function () use ($db) {
 global $wpdb;
 [$s,$w,$asset,$mapping]=provider_context(); $r=$s->reserve($w,$mapping,provider_fingerprint('audit-rollback'),'quote'); $s->dispatch($w,$r['id']);
 $trigger=$wpdb->prefix.'provider_fault';
 $db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture fault'");
 try { try { $s->complete_alpha_quote($w,$r['id'],provider_body()); throw new LogicException('Audit failure accepted'); } catch (RuntimeException $error) {} }
 finally { $db->query('DROP TRIGGER '.$trigger); }
 equal($s->quotes($w,$asset),[]); equal($db->object('provider_requests',$w,$r['id'])['state'],'dispatched');
 equal(count($s->quotes($w,$asset)),0); $s->complete_alpha_quote($w,$r['id'],provider_body()); equal(count($s->quotes($w,$asset)),1);
});

test('Concurrent workspaces cannot both reserve the final shared provider request', function () use ($db,$owner) {
 [$s,$w,$asset,$mapping]=provider_context(); [$other,$w2,$asset2,$mapping2]=provider_context();
 for($round=0;$round<10;$round++) {
 $digest=provider_fingerprint('concurrent-quota-'.$round);
 for($i=0;$i<24;$i++) $s->reserve($w,$mapping,$digest,'fill-'.$i,false);
 $processes=[];
 foreach([[$w,$mapping,'first'],[$w2,$mapping2,'second']] as [$workspace,$map,$key]) {
  $task=['operation'=>'provider_reserve','id'=>$map,'fingerprint'=>$digest,'key'=>$key];
  $command=[PHP_BINARY,'-d','extension_dir='.ini_get('extension_dir'),'-d','extension=mysqli',__DIR__.'/concurrency-worker.php',rtrim(ABSPATH,'/\\'),(string)$owner,(string)$workspace,wp_json_encode($task)];
  $pipes=[]; $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); if(!is_resource($process)) throw new RuntimeException('Worker failed'); fclose($pipes[0]); $processes[]=[$process,$pipes];
 }
 $results=[]; foreach($processes as [$process,$pipes]) { $results[]=trim(stream_get_contents($pipes[1])); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); if(proc_close($process)!==0) throw new RuntimeException('Provider worker failed: '.$error); }
 sort($results); equal($results,['posted','rejected']);
 }
});

function provider_http_mock($response, callable $callback, &$calls, string $provider='alpha_vantage') {
 $calls=0;
 $filter=static function($pre,$args,$url) use($response,&$calls,$provider) {
  ++$calls;
  equal(parse_url($url,PHP_URL_SCHEME),'https'); equal(parse_url($url,PHP_URL_HOST),'fmp'===$provider?'financialmodelingprep.com':'www.alphavantage.co'); equal(parse_url($url,PHP_URL_PATH),'fmp'===$provider?'/stable/historical-price-eod/light':'/query');
  equal($args['redirection'],0); equal($args['sslverify'],true); equal($args['limit_response_size'],2097152); equal($args['timeout'],20);
  parse_str(parse_url($url,PHP_URL_QUERY),$query); equal($query['symbol'],'FIXTURE');
  if ('fmp'===$provider) { equal($query['apikey'],TGIT_FMP_API_KEY); equal($query['from'],gmdate('Y-m-d',time()-1209600)); equal($query['to'],gmdate('Y-m-d')); equal(isset($query['function']),false); }
  else { equal($query['function'],'GLOBAL_QUOTE'); equal($query['apikey'],TGIT_ALPHA_VANTAGE_API_KEY); }
  // Always short-circuit; even simulated failures never reach the network.
  return $response;
 };
 add_filter('pre_http_request',$filter,10,3);
 try { return $callback(); } finally { remove_filter('pre_http_request',$filter,10); }
}

function provider_http_response(string $body, int $status=200): array {
 return ['headers'=>['content-type'=>'application/json'],'body'=>$body,'response'=>['code'=>$status,'message'=>'Synthetic'],'cookies'=>[]];
}

test('Quote refresh remains disabled without explicit server configuration', function () use ($db,$owner) {
 [$s,$w,$asset,$mapping]=provider_context(); equal(QuoteRefresh::enabled(),false);
 equal(QuoteRefresh::run($w,$owner,$mapping,'disabled-worker')['state'],'disabled');
 equal($db->row('SELECT COUNT(*) AS total FROM '.$db->table('provider_requests').' WHERE workspace_id = %d',[$w])['total'],'0');
 rejects(fn()=>QuoteRefresh::schedule($w,$owner,$mapping,time()+3600));
 // Synthetic credential only in this disposable PHP process, never in configuration files.
 define('TGIT_MARKET_DATA_ENABLED',true); define('TGIT_ALPHA_VANTAGE_API_KEY','synthetic-provider-key-'.$owner);
});

test('Quote refresh uses bounded HTTPS and reuses saved evidence without a second call', function () use ($owner) {
 [$s,$w,$asset,$mapping]=provider_context();
 $result=provider_http_mock(provider_http_response(provider_body('320.123456789123456789')),fn()=>QuoteRefresh::run($w,$owner,$mapping,'worker-success'),$calls);
 equal($calls,1); equal($result['state'],'completed'); decimal($result['quote']['price'],'320.123456789123456789');
 $again=provider_http_mock(new WP_Error('fixture','Must not send again'),fn()=>QuoteRefresh::run($w,$owner,$mapping,'worker-success'),$calls);
 equal($calls,0); equal($again,$result);
});

test('Quote transport errors stay uncertain and bad HTTP/JSON responses consume attempts', function () use ($db,$owner) {
 [$s,$w,$asset,$mapping]=provider_context();
 $result=provider_http_mock(new WP_Error('fixture_timeout','Synthetic transport failure'),fn()=>QuoteRefresh::run($w,$owner,$mapping,'worker-timeout'),$calls);
 equal($calls,1); equal($result['state'],'uncertain');
 $again=provider_http_mock(provider_http_response(provider_body()),fn()=>QuoteRefresh::run($w,$owner,$mapping,'worker-timeout'),$calls); equal($calls,0); equal($again['state'],'uncertain');
 foreach([[503,'unavailable','failed'],[302,'redirect','failed'],[200,'{"Information":"synthetic entitlement failure"}','invalid_response'],[200,'not-json','invalid_response']] as [$status,$body,$expected]) {
  $result=provider_http_mock(provider_http_response($body,$status),fn()=>QuoteRefresh::run($w,$owner,$mapping,'worker-failure-'.$status.'-'.$expected.'-'.strlen($body)),$calls); equal($calls,1); equal($result['state'],$expected);
 }
 equal($s->quotes($w,$asset),[]);
 equal($db->row('SELECT COUNT(*) AS total FROM '.$db->table('provider_requests').' WHERE workspace_id = %d',[$w])['total'],'5');
});

test('Quote jobs require an owner, deduplicate and stop on deactivation preserving evidence', function () use ($db,$tracker,$owner,$viewer) {
 [$s,$w,$asset,$mapping]=provider_context(); $at=time()+3600; $args=[$w,$owner,$mapping,$at];
 QuoteRefresh::schedule($w,$owner,$mapping,$at); QuoteRefresh::schedule($w,$owner,$mapping,$at); equal(wp_next_scheduled('tgit_quote_refresh',$args),$at);
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'active']);
 try { QuoteRefresh::schedule($w,$viewer,$mapping,$at); throw new RuntimeException('Viewer scheduled quote'); } catch(DomainException $error) {}
 $result=provider_http_mock(provider_http_response(provider_body()),fn()=>QuoteRefresh::run($w,$viewer,$mapping,'unauthorized'),$calls); equal($calls,0); equal($result['state'],'blocked');
 provider_http_mock(provider_http_response(provider_body()),fn()=>QuoteRefresh::job($w,$owner,$mapping,$at),$calls); equal($calls,1);
 provider_http_mock(provider_http_response(provider_body()),fn()=>QuoteRefresh::job($w,$owner,$mapping,$at),$calls); equal($calls,0);
 $quotes=$s->quotes($w,$asset); equal(count($quotes),1); QuoteRefresh::deactivate(); equal(wp_next_scheduled('tgit_quote_refresh',$args),false); equal($s->quotes($w,$asset),$quotes);
});

test('FMP worker requires its own key and preserves immutable evidence on retries', function () use ($db,$owner) {
 [$s,$w,$asset,$mapping,$input]=provider_context(); $fmp=$s->save_mapping($w,$asset,array_replace($input,['provider'=>'fmp'])); $map=(int)$fmp['id'];
 equal(QuoteRefresh::enabled('fmp'),false); equal(QuoteRefresh::run($w,$owner,$map,'fmp-disabled')['state'],'disabled');
 equal($db->row('SELECT COUNT(*) AS total FROM '.$db->table('provider_requests').' WHERE workspace_id = %d',[$w])['total'],'0');
 define('TGIT_FMP_API_KEY','synthetic-fmp-key-'.$owner);
 $body='[{"symbol":"FIXTURE","date":"2026-01-02","price":320.123456789123456789},{"symbol":"FIXTURE","date":"2025-12-31","price":315}]';
 $result=provider_http_mock(provider_http_response($body),fn()=>QuoteRefresh::run($w,$owner,$map,'fmp-success'),$calls,'fmp'); equal($calls,1); equal($result['state'],'completed'); decimal($result['quote']['price'],'320.123456789123456789'); equal($result['quote']['provider'],'fmp'); equal($result['quote']['currency'],'USD');
 $again=provider_http_mock(new WP_Error('fixture','Do not resend'),fn()=>QuoteRefresh::run($w,$owner,$map,'fmp-success'),$calls,'fmp'); equal($calls,0); equal($again,$result);
 provider_conflict(fn()=>$s->complete_alpha_quote($w,(int)$result['quote']['request_id'],provider_body()));
 $at=time()+3600; QuoteRefresh::schedule($w,$owner,$map,$at); equal(wp_next_scheduled('tgit_quote_refresh',[$w,$owner,$map,$at]),$at); QuoteRefresh::deactivate();
});

test('FMP failures preserve quota and uncertain delivery is never resent', function () use ($owner) {
 [$s,$w,$asset,$mapping,$input]=provider_context(); $fmp=$s->save_mapping($w,$asset,array_replace($input,['provider'=>'fmp'])); $map=(int)$fmp['id'];
 $result=provider_http_mock(new WP_Error('fixture','Synthetic timeout'),fn()=>QuoteRefresh::run($w,$owner,$map,'fmp-timeout'),$calls,'fmp'); equal($calls,1); equal($result['state'],'uncertain');
 $again=provider_http_mock(provider_http_response('[]'),fn()=>QuoteRefresh::run($w,$owner,$map,'fmp-timeout'),$calls,'fmp'); equal($calls,0); equal($again['state'],'uncertain');
 foreach ([[402,'not entitled','failed'],[302,'redirect','failed'],[200,'{"Error Message":"synthetic"}','invalid_response'],[200,'[]','invalid_response']] as [$status,$body,$state]) {
  $result=provider_http_mock(provider_http_response($body,$status),fn()=>QuoteRefresh::run($w,$owner,$map,'fmp-failure-'.$status.'-'.strlen($body)),$calls,'fmp'); equal($calls,1); equal($result['state'],$state);
 }
 equal($s->quotes($w,$asset),[]);
});

function provider_rest(string $method, string $path, ?array $body=null, string $key='') {
 $parts=explode('?', $path, 2); $request=new WP_REST_Request($method,'/tgit/v1/'.$parts[0]);
 if (isset($parts[1])) { parse_str($parts[1],$query); $request->set_query_params($query); }
 if ($body!==null) { $request->set_header('content-type','application/json'); $request->set_body(wp_json_encode((object)$body)); }
 if ($key!=='') $request->set_header('idempotency-key',$key);
 return rest_do_request($request);
}

test('Owner REST mapping controls paginate current revisions and hide credentials', function () use ($owner,$viewer,$tracker) {
 wp_set_current_user($owner); [$s,$w,$asset,$mapping,$input]=provider_context(); $base='workspaces/'.$w;
 $status=provider_rest('GET',$base.'/market-data'); equal($status->get_status(),200); equal(count($status->get_data()['data']['providers']),2); equal(str_contains(wp_json_encode($status->get_data()),TGIT_FMP_API_KEY),false);
 $updated=$s->save_mapping($w,$asset,array_replace($input,['expected_mapping_id'=>$mapping,'enabled'=>false]));
 $list=provider_rest('GET',$base.'/provider-mappings?limit=1'); equal($list->get_status(),200); $data=$list->get_data()['data']; equal(count($data['items']),1); equal($data['items'][0]['id'],$updated['id']); equal($data['items'][0]['price'],null);
 equal(provider_rest('GET',$base.'/provider-mappings?after='.$updated['id'])->get_data()['data']['items'],[]); equal(provider_rest('GET',$base.'/provider-mappings?limit=101')->get_status(),400);
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'manager','state'=>'active']); wp_set_current_user($viewer);
 foreach (['/market-data','/provider-mappings'] as $route) equal(provider_rest('GET',$base.$route)->get_status(),403);
 equal(provider_rest('POST',$base.'/assets/'.$asset.'/provider-mappings',$input)->get_status(),403);
 wp_set_current_user($owner);
 [$other,$w2,$asset2,$mapping2]=provider_context(); equal(provider_rest('POST',$base.'/assets/'.$asset2.'/provider-mappings',$input)->get_status(),404);
 equal(provider_rest('POST',$base.'/assets/'.$asset.'/provider-mappings',$input)->get_status(),409);
});

test('Owner REST refresh is bounded, idempotent and workspace scoped', function () use ($owner,$viewer,$tracker) {
 wp_set_current_user($owner); [$s,$w,$asset,$mapping]=provider_context(); $target='workspaces/'.$w.'/provider-mappings/'.$mapping.'/refresh';
 equal(provider_rest('POST',$target,[])->get_status(),400); equal(provider_rest('POST',$target,['unexpected'=>true],'rest-bad')->get_status(),400);
 $response=provider_http_mock(provider_http_response(provider_body()),fn()=>provider_rest('POST',$target,[],'rest-refresh'),$calls); equal($calls,1); equal($response->get_status(),200); equal($response->get_data()['data']['state'],'completed');
 $again=provider_http_mock(new WP_Error('fixture','Do not resend'),fn()=>provider_rest('POST',$target,[],'rest-refresh'),$calls); equal($calls,0); equal($again->get_data()['data'],$response->get_data()['data']);
 $rows=provider_rest('GET','workspaces/'.$w.'/provider-mappings')->get_data()['data']['items']; decimal($rows[0]['price'],'320'); equal($rows[0]['session_date'],'2026-01-02');
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'active']); wp_set_current_user($viewer);
 $denied=provider_http_mock(provider_http_response(provider_body()),fn()=>provider_rest('POST',$target,[],'rest-denied'),$calls); equal($calls,0); equal($denied->get_status(),403);
 wp_set_current_user($owner); [$other,$w2,$asset2,$mapping2]=provider_context(); equal(provider_rest('POST','workspaces/'.$w2.'/provider-mappings/'.$mapping.'/refresh',[],'foreign-refresh')->get_status(),404);
});

use GainerInteractive\IGTradingJournal\Infrastructure\RecurringQuotes;

test('Schema 10 enrollment repairs from 9 and retains quote evidence', function () use ($db) {
 $before=$db->row('SELECT COUNT(*) AS total FROM '.$db->table('provider_quotes'))['total'];
 update_option('tgit_schema_version','9'); Installer::install(); equal(get_option('tgit_schema_version'),'10');
 $db->query('DROP TABLE '.$db->table('provider_schedules')); Installer::install();
 equal($db->row('SHOW COLUMNS FROM '.$db->table('provider_schedules').' LIKE %s',['frequency'])['Field'],'frequency'); equal($db->row('SELECT COUNT(*) AS total FROM '.$db->table('provider_quotes'))['total'],$before);
});

test('Recurring enrollment is append-only, owner authorized and revision checked', function () use ($db,$owner,$viewer,$tracker) {
 wp_set_current_user($owner); [$s,$w,$asset,$mapping]=provider_context();
 $target='workspaces/'.$w.'/provider-mappings/'.$mapping.'/schedule';
 $response=provider_rest('POST',$target,['frequency'=>'once','expected_schedule_id'=>0]); equal($response->get_status(),200); $schedule=$response->get_data()['data']; equal($schedule['frequency'],'once'); equal($schedule['queued'],true);
 equal(provider_rest('POST',$target,['frequency'=>'twice','expected_schedule_id'=>0])->get_status(),409);
 rejects(fn()=>$s->save_schedule($w,$mapping,['frequency'=>'hourly','expected_schedule_id'=>(int)$schedule['id']]));
 $off=$s->save_schedule($w,$mapping,['frequency'=>'off','expected_schedule_id'=>(int)$schedule['id']]); equal($off['frequency'],'off'); equal($db->object('provider_schedules',$w,(int)$schedule['id'])['frequency'],'once');
 equal(RecurringQuotes::queue($w,(int)$schedule['id']),false); equal(RecurringQuotes::queue($w,(int)$off['id']),false);
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'manager','state'=>'active']); wp_set_current_user($viewer); equal(provider_rest('POST',$target,['frequency'=>'once','expected_schedule_id'=>(int)$off['id']])->get_status(),403); wp_set_current_user($owner); QuoteRefresh::deactivate();
});

test('Recurring jobs deduplicate, skip catch-up and stop after disable or owner revocation', function () use ($owner,$viewer,$tracker) {
 [$s,$w,$asset,$mapping]=provider_context(); $schedule=$s->save_schedule($w,$mapping,['frequency'=>'twice','expected_schedule_id'=>0]); $id=(int)$schedule['id'];
 equal(RecurringQuotes::queue($w,$id),true); equal(RecurringQuotes::queue($w,$id),true);
 $at=time(); provider_http_mock(provider_http_response(provider_body()),fn()=>RecurringQuotes::job($w,$id,$at),$calls); equal($calls,1);
 provider_http_mock(provider_http_response(provider_body()),fn()=>RecurringQuotes::job($w,$id,$at),$calls); equal($calls,0);
 provider_http_mock(provider_http_response(provider_body()),fn()=>RecurringQuotes::job($w,$id,$at-10800),$calls); equal($calls,0);
 provider_http_mock(provider_http_response(provider_body()),fn()=>RecurringQuotes::job($w,$id,$at+3600),$calls); equal($calls,0);
 $s->save_schedule($w,$mapping,['frequency'=>'off','expected_schedule_id'=>$id]); provider_http_mock(provider_http_response(provider_body()),fn()=>RecurringQuotes::job($w,$id,$at),$calls); equal($calls,0);
 $latest=$s->schedule_config($w,$mapping); $active=$s->save_schedule($w,$mapping,['frequency'=>'once','expected_schedule_id'=>(int)$latest['id']]);
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'owner','state'=>'active']); $tracker->set_member($w,['wp_user_id'=>$owner,'role'=>'owner','state'=>'revoked']);
 provider_http_mock(provider_http_response(provider_body()),fn()=>RecurringQuotes::job($w,(int)$active['id'],$at),$calls); equal($calls,0); equal(RecurringQuotes::queue($w,(int)$active['id']),false);
 RecurringQuotes::boot(); equal((bool)wp_next_scheduled('tgit_quote_schedule_scan'),true); QuoteRefresh::deactivate(); equal(wp_next_scheduled('tgit_quote_schedule_scan'),false);
});

test('Schedule audit rollback preserves the prior enrollment and scan recovers missing jobs', function () use ($db) {
 global $wpdb; [$s,$w,$asset,$mapping,$input]=provider_context();
 $schedule=$s->save_schedule($w,$mapping,['frequency'=>'once','expected_schedule_id'=>0]); $id=(int)$schedule['id'];
 $trigger=$wpdb->prefix.'schedule_fault'; $db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture fault'");
 try { try { $s->save_schedule($w,$mapping,['frequency'=>'off','expected_schedule_id'=>$id]); throw new LogicException('Audit failure accepted'); } catch(RuntimeException $error) {} } finally { $db->query('DROP TRIGGER '.$trigger); }
 equal((int)$s->schedule_config($w,$mapping)['id'],$id); QuoteRefresh::deactivate(); RecurringQuotes::scan();
 $at=GainerInteractive\IGTradingJournal\Domain\QuoteSchedule::next(new DateTimeImmutable('now',new DateTimeZone('UTC')),'once'); equal(wp_next_scheduled('tgit_scheduled_quote_refresh',[$w,$id,$at]),$at);
 $s->save_mapping($w,$asset,array_replace($input,['expected_mapping_id'=>$mapping,'enabled'=>false])); equal(RecurringQuotes::queue($w,$id),false); QuoteRefresh::deactivate();
});

function valuation_body(string $date, string $price='320'): string {
 return wp_json_encode(['Global Quote'=>['01. symbol'=>'FIXTURE','05. price'=>$price,'08. previous close'=>'315','07. latest trading day'=>$date]]);
}

test('Provider holdings honor explicit source, staleness and immutable manual reports', function () use ($tracker,$db) {
 [$s,$w,$asset,$mapping]=provider_context(); $date=(new DateTimeImmutable('now',new DateTimeZone('America/New_York')))->format('Y-m-d');
 $account=$tracker->create_object($w,'accounts',['name'=>'Synthetic holdings','native_currency'=>'USD']);
 $tracker->opening_balance($w,['account_id'=>(int)$account['id'],'asset_id'=>$asset,'kind'=>'lot','effective_date'=>'2026-01-02','acquired_on'=>'2026-01-01','quantity'=>'2','basis_status'=>'complete','amount'=>'500','source_note'=>'Synthetic statement'],'provider-opening');
 $tracker->record_observation($w,['kind'=>'price','asset_id'=>$asset,'currency'=>'USD','value'=>'100','effective_date'=>'2026-01-02','expires_on'=>$date,'source'=>'Synthetic manual','reason'=>'Fixture'],'provider-manual');
 $report=$tracker->generate_report($w,['type'=>'holdings','as_of'=>$date],'provider-report-before'); $cash=$tracker->list_objects($w,'accounts'); $transactions=$tracker->list_objects($w,'transactions');
 $old=(new DateTimeImmutable($date))->modify('-4 days')->format('Y-m-d'); $request=$s->reserve($w,$mapping,provider_fingerprint('valuation'),'old-price'); $s->dispatch($w,$request['id']); $s->complete_alpha_quote($w,$request['id'],valuation_body($old));
 $position=$tracker->holdings($w,0,100,'alpha_vantage')['items'][0]; equal($position['price_status'],'stale'); equal($position['market_value'],null); equal($position['unrealized_gain'],null);
 $request=$s->reserve($w,$mapping,provider_fingerprint('valuation'),'current-price'); $s->dispatch($w,$request['id']); $s->complete_alpha_quote($w,$request['id'],valuation_body($date));
 $position=$tracker->holdings($w,0,100,'alpha_vantage')['items'][0]; decimal($position['market_value'],'640'); decimal($position['unrealized_gain'],'140'); equal($position['price_status'],'complete'); equal($position['price_observation']['effective_date'],$date);
 decimal($tracker->holdings($w)['items'][0]['market_value'],'200'); equal($tracker->holdings($w,0,100,'fmp')['items'][0]['price_status'],'missing'); equal($tracker->holdings($w,0,100,'fmp')['items'][0]['market_value'],null);
 $summary=$tracker->stock_summary($w,'alpha_vantage'); decimal($summary['currency_groups'][0]['market_value'],'640'); decimal($summary['currency_groups'][0]['unrealized_gain'],'140');
 equal($tracker->report_run($w,(int)$report['id']),$report); equal($tracker->list_objects($w,'accounts'),$cash); equal($tracker->list_objects($w,'transactions'),$transactions);
 file_put_contents(dirname(__DIR__).'/tmp/provider-valuation-fixtures.json',wp_json_encode(['workspace'=>$w,'asset'=>$asset]));
});

test('Provider values reject superseded mappings and preserve unknown basis', function () use ($tracker) {
 [$s,$w,$asset,$mapping,$input]=provider_context(); $date=(new DateTimeImmutable('now',new DateTimeZone('America/New_York')))->format('Y-m-d'); $account=$tracker->create_object($w,'accounts',['name'=>'Unresolved basis','native_currency'=>'USD']);
 $tracker->opening_balance($w,['account_id'=>(int)$account['id'],'asset_id'=>$asset,'kind'=>'lot','effective_date'=>'2026-01-02','acquired_on'=>'2026-01-01','quantity'=>'1','basis_status'=>'unresolved','source_note'=>'Synthetic incomplete statement'],'unknown-provider-opening');
 $request=$s->reserve($w,$mapping,provider_fingerprint('unknown-valuation'),'current'); $s->dispatch($w,$request['id']); $s->complete_alpha_quote($w,$request['id'],valuation_body($date));
 $position=$tracker->holdings($w,0,100,'alpha_vantage')['items'][0]; decimal($position['market_value'],'320'); equal($position['unrealized_gain'],null);
 equal($tracker->stock_summary($w,'alpha_vantage')['currency_groups'][0]['unrealized_gain'],null);
 $s->save_mapping($w,$asset,array_replace($input,['expected_mapping_id'=>$mapping,'enabled'=>false])); equal($tracker->holdings($w,0,100,'alpha_vantage')['items'][0]['price_status'],'missing');
});

test('Stock valuation REST defaults manual, validates sources and enforces membership', function () use ($owner,$viewer) {
 [$s,$w,$asset,$mapping]=provider_context(); wp_set_current_user($owner);
 $base='workspaces/'.$w; equal(provider_rest('GET',$base.'/holdings')->get_data()['data']['price_source'],'manual');
 equal(provider_rest('GET',$base.'/stock-summary?price_source=fmp')->get_status(),200); equal(provider_rest('GET',$base.'/stock-summary?price_source=unknown')->get_status(),400); equal(provider_rest('GET',$base.'/holdings?price_source=unknown')->get_status(),400);
 wp_set_current_user($viewer); equal(provider_rest('GET',$base.'/stock-summary')->get_status(),403); equal(provider_rest('GET',$base.'/holdings?price_source=fmp')->get_status(),403); wp_set_current_user($owner);
});

test('Stock summary traverses all authorized asset pages rather than a single holdings page', function () use ($tracker) {
 $w=(int)$tracker->create_workspace(['name'=>'Summary pagination fixture'])['id'];
 for ($i=0;$i<100;$i++) $tracker->create_object($w,'assets',['symbol'=>'PAGE'.$i,'exchange'=>'FIXTURE','asset_class'=>'stock','quote_currency'=>'USD']);
 $asset=$tracker->create_object($w,'assets',['symbol'=>'LAST','exchange'=>'FIXTURE','asset_class'=>'stock','quote_currency'=>'USD']); $account=$tracker->create_object($w,'accounts',['name'=>'Last-page holding','native_currency'=>'USD']);
 $tracker->opening_balance($w,['account_id'=>(int)$account['id'],'asset_id'=>(int)$asset['id'],'kind'=>'lot','effective_date'=>'2026-01-02','acquired_on'=>'2026-01-01','quantity'=>'2','basis_status'=>'complete','amount'=>'100','source_note'=>'Synthetic statement'],'summary-page-opening');
 $date=(new DateTimeImmutable('now',new DateTimeZone('America/New_York')))->format('Y-m-d'); $tracker->record_observation($w,['kind'=>'price','asset_id'=>(int)$asset['id'],'currency'=>'USD','value'=>'75','effective_date'=>$date,'expires_on'=>$date,'source'=>'Synthetic close','reason'=>'Pagination fixture'],'summary-page-price');
 equal($tracker->holdings($w,0,100)['items'],[]); $group=$tracker->stock_summary($w)['currency_groups'][0]; decimal($group['market_value'],'150'); decimal($group['unrealized_gain'],'50'); equal($group['positions'],1);
});
