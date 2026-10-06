<?php
/** Every fundamental HTTP attempt is intercepted; no real credential or network. */
use GainerInteractive\IGTradingJournal\Infrastructure\FundamentalRefresh;
use GainerInteractive\IGTradingJournal\Infrastructure\QuoteRefresh;
if (!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE !== true) throw new RuntimeException('Disposable site required.');

function fundamental_http_mock($response, string $dataset, callable $callback, &$calls) {
 $calls=0;
 $filter=static function($pre,$args,$url) use($response,$dataset,&$calls) {
  ++$calls; equal(parse_url($url,PHP_URL_SCHEME),'https'); equal(parse_url($url,PHP_URL_HOST),'www.alphavantage.co'); equal(parse_url($url,PHP_URL_PATH),'/query');
  equal($args['redirection'],0); equal($args['sslverify'],true); equal($args['limit_response_size'],2097152); equal($args['timeout'],20); equal($args['headers']['Accept'],'application/json');
  parse_str(parse_url($url,PHP_URL_QUERY),$query); equal($query['function'],$dataset); equal($query['symbol'],'FIXTURE'); equal($query['apikey'],TGIT_ALPHA_VANTAGE_API_KEY);
  return is_callable($response)?$response():$response;
 };
 add_filter('pre_http_request',$filter,10,3); try { return $callback(); } finally { remove_filter('pre_http_request',$filter,10); }
}

test('Fundamental worker requires separate explicit enablement and rejects unsupported datasets', function () use ($db,$owner) {
 [$s,$w,$asset,$mapping,$input]=provider_context(); equal(FundamentalRefresh::enabled(),false);
 $result=fundamental_http_mock(provider_http_response(fundamental_overview()),'OVERVIEW',fn()=>FundamentalRefresh::run($w,$owner,$mapping,'OVERVIEW','disabled',false),$calls); equal($calls,0); equal($result['state'],'disabled');
 rejects(fn()=>FundamentalRefresh::schedule($w,$owner,$mapping,'OVERVIEW',time()+3600));
 define('TGIT_FUNDAMENTALS_ENABLED',true); equal(FundamentalRefresh::enabled(),true);
 $result=fundamental_http_mock(provider_http_response('{}'),'UNKNOWN',fn()=>FundamentalRefresh::run($w,$owner,$mapping,'UNKNOWN','unsupported',false),$calls); equal($calls,0); equal($result['state'],'blocked');
 $fmp=$s->save_mapping($w,$asset,array_replace($input,['provider'=>'fmp'])); equal(FundamentalRefresh::run($w,$owner,(int)$fmp['id'],'OVERVIEW','wrong-provider',false)['state'],'blocked');
 equal($db->row('SELECT COUNT(*) AS total FROM '.$db->table('provider_requests').' WHERE workspace_id = %d',[$w])['total'],'0');
});

test('One-shot fundamental jobs deduplicate and deactivation preserves saved snapshots', function () use ($owner,$tracker,$viewer) {
 [$s,$w,$asset,$mapping]=provider_context(); $at=time()+3600; $args=[$w,$owner,$mapping,'OVERVIEW',$at];
 FundamentalRefresh::schedule(...$args); FundamentalRefresh::schedule(...$args); equal(wp_next_scheduled('tgit_fundamental_refresh',$args),$at);
 foreach([time()-1,time()+604801] as $invalid) rejects(fn()=>FundamentalRefresh::schedule($w,$owner,$mapping,'OVERVIEW',$invalid));
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'active']);
 try { FundamentalRefresh::schedule($w,$viewer,$mapping,'OVERVIEW',$at); throw new LogicException('Viewer scheduled fundamentals'); } catch(DomainException $error) {}
 fundamental_http_mock(provider_http_response(fundamental_overview()),'OVERVIEW',fn()=>FundamentalRefresh::job(...$args),$calls); equal($calls,1);
 fundamental_http_mock(provider_http_response(fundamental_overview()),'OVERVIEW',fn()=>FundamentalRefresh::job(...$args),$calls); equal($calls,0);
 $snapshots=$s->fundamentals($w,$asset); equal(count($snapshots),1); QuoteRefresh::deactivate(); equal(wp_next_scheduled('tgit_fundamental_refresh',$args),false); equal($s->fundamentals($w,$asset),$snapshots);
});

test('All supported fundamental endpoints save exact snapshots once and never touch posted facts', function () use ($db,$owner) {
 [$s,$w,$asset,$mapping]=provider_context();
 foreach(['OVERVIEW','INCOME_STATEMENT','BALANCE_SHEET','CASH_FLOW'] as $dataset) {
  $body=$dataset==='OVERVIEW'?fundamental_overview():fundamental_statement(); $key='fetch-'.$dataset;
  $result=fundamental_http_mock(provider_http_response($body),$dataset,fn()=>FundamentalRefresh::run($w,$owner,$mapping,$dataset,$key,false),$calls); equal($calls,1); equal($result['state'],'completed'); equal($result['snapshot']['dataset'],$dataset);
  $again=fundamental_http_mock(new WP_Error('fixture','Never resend'),$dataset,fn()=>FundamentalRefresh::run($w,$owner,$mapping,$dataset,$key,false),$calls); equal($calls,0); equal($again,$result); equal(str_contains(wp_json_encode($result),TGIT_ALPHA_VANTAGE_API_KEY),false);
 }
 equal(count($s->fundamentals($w,$asset)),4); equal($s->quotes($w,$asset),[]); equal($db->rows('SELECT * FROM '.$db->table('transactions').' WHERE workspace_id = %d',[$w]),[]);
});

test('Fundamental errors keep quota, redact diagnostics and never resend uncertain deliveries', function () use ($db,$owner) {
 [$s,$w,$asset,$mapping]=provider_context();
 $result=fundamental_http_mock(new WP_Error('fixture_timeout','Synthetic error with '.TGIT_ALPHA_VANTAGE_API_KEY),'OVERVIEW',fn()=>FundamentalRefresh::run($w,$owner,$mapping,'OVERVIEW','timeout',false),$calls); equal($calls,1); equal($result,['state'=>'uncertain']);
 $again=fundamental_http_mock(provider_http_response(fundamental_overview()),'OVERVIEW',fn()=>FundamentalRefresh::run($w,$owner,$mapping,'OVERVIEW','timeout',false),$calls); equal($calls,0); equal($again,$result);
 foreach([[302,'redirect','failed'],[429,'limit','failed'],[200,'{"Information":"synthetic-sensitive-message"}','invalid_response'],[200,'not-json','invalid_response']] as $index=>[$status,$body,$state]) {
  $result=fundamental_http_mock(provider_http_response($body,$status),'OVERVIEW',fn()=>FundamentalRefresh::run($w,$owner,$mapping,'OVERVIEW','error-'.$index,false),$calls); equal($calls,1); equal($result,['state'=>$state]);
  $again=fundamental_http_mock(provider_http_response(fundamental_overview()),'OVERVIEW',fn()=>FundamentalRefresh::run($w,$owner,$mapping,'OVERVIEW','error-'.$index,false),$calls); equal($calls,0); equal($again['state'],'failed');
 }
 equal($s->fundamentals($w,$asset),[]); equal($db->row('SELECT COUNT(*) AS total FROM '.$db->table('provider_requests').' WHERE workspace_id = %d',[$w])['total'],'5');
});

test('Fundamental worker blocks revoked and changed mappings before send and during completion', function () use ($db,$owner,$viewer,$tracker) {
 [$s,$w,$asset,$mapping,$input]=provider_context(); $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'active']);
 $result=fundamental_http_mock(provider_http_response(fundamental_overview()),'OVERVIEW',fn()=>FundamentalRefresh::run($w,$viewer,$mapping,'OVERVIEW','viewer',false),$calls); equal($calls,0); equal($result['state'],'blocked');
 $response=function() use($tracker,$w,$viewer,$owner) { $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'owner','state'=>'active']); $tracker->set_member($w,['wp_user_id'=>$owner,'role'=>'owner','state'=>'revoked']); return provider_http_response(fundamental_overview()); };
 $result=fundamental_http_mock($response,'OVERVIEW',fn()=>FundamentalRefresh::run($w,$owner,$mapping,'OVERVIEW','revoke-during-call',false),$calls); equal($calls,1); equal($result['state'],'blocked'); equal($db->rows('SELECT * FROM '.$db->table('fundamental_snapshots').' WHERE workspace_id = %d',[$w]),[]);
 $result=fundamental_http_mock(provider_http_response(fundamental_overview()),'OVERVIEW',fn()=>FundamentalRefresh::run($w,$owner,$mapping,'OVERVIEW','revoke-during-call',false),$calls); equal($calls,0); equal($result['state'],'blocked');
 [$s2,$w2,$asset2,$mapping2,$input2]=provider_context(); $s2->save_mapping($w2,$asset2,array_replace($input2,['enabled'=>false,'expected_mapping_id'=>$mapping2])); equal(FundamentalRefresh::run($w2,$owner,$mapping2,'OVERVIEW','disabled-mapping',false)['state'],'blocked');
});

test('Fundamental audit failure after delivery stays uncertain and does not resend', function () use ($db,$owner) {
 global $wpdb; [$s,$w,$asset,$mapping]=provider_context(); $trigger=$wpdb->prefix.'fundamental_transport_fault';
 $response=function() use($db,$trigger) { $db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture fault'"); return provider_http_response(fundamental_overview()); };
 try { $result=fundamental_http_mock($response,'OVERVIEW',fn()=>FundamentalRefresh::run($w,$owner,$mapping,'OVERVIEW','audit-delivery',false),$calls); equal($calls,1); equal($result['state'],'unavailable'); } finally { $db->query('DROP TRIGGER '.$trigger); }
 equal($s->fundamentals($w,$asset),[]);
 $again=fundamental_http_mock(provider_http_response(fundamental_overview()),'OVERVIEW',fn()=>FundamentalRefresh::run($w,$owner,$mapping,'OVERVIEW','audit-delivery',false),$calls); equal($calls,0); equal($again['state'],'uncertain');
});

test('Fundamental worker shares the existing quote pool and blocks the final exhausted allowance', function () use ($db,$owner) {
 [$s,$w,$asset,$mapping]=provider_context(); $digest=hash('sha256',TGIT_ALPHA_VANTAGE_API_KEY);
 $pool=$db->row('SELECT id FROM '.$db->table('provider_pools').' WHERE provider = %s AND credential_fingerprint = %s',['alpha_vantage',$digest]);
 $used=(int)$db->row('SELECT COUNT(*) AS total FROM '.$db->table('provider_requests').' WHERE pool_id = %d',[$pool['id']])['total'];
 for($index=$used;$index<25;$index++) $s->reserve($w,$mapping,$digest,'final-shared-'.$index,false,'quote');
 $result=fundamental_http_mock(provider_http_response(fundamental_overview()),'OVERVIEW',fn()=>FundamentalRefresh::run($w,$owner,$mapping,'OVERVIEW','quota-over',false),$calls); equal($calls,0); equal($result['state'],'blocked');
 equal((int)$db->row('SELECT COUNT(*) AS total FROM '.$db->table('provider_requests').' WHERE pool_id = %d',[$pool['id']])['total'],25);
});
