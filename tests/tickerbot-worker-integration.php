<?php
/** Bounded dispatcher on disposable storage and synthetic transport only. */
declare(strict_types=1);
if(!defined('TGIT_DISPOSABLE_TEST_SITE')||TGIT_DISPOSABLE_TEST_SITE!==true)throw new RuntimeException('Disposable site required.');
if(defined('TGIT_TICKERBOT_API_KEY')||defined('TGIT_TICKERBOT_ENABLED'))throw new RuntimeException('Worker fixture requires an isolated configuration without provider credentials.');
define('TGIT_TICKERBOT_API_KEY','synthetic-tickerbot-fixture');
define('TGIT_TICKERBOT_ENABLED',true);
use GainerInteractive\IGTradingJournal\Application\TickerbotStore;
use GainerInteractive\IGTradingJournal\Infrastructure\TickerbotRefresh;
use GainerInteractive\IGTradingJournal\Infrastructure\Installer;

function tbw_context(int $count=1,?string $jobScope=null):array {
 global $tracker,$db,$owner;
 [$s,$w,$scope,$now]=tbs_context(8000,(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM));
 if($jobScope!==null){$w=(int)$tracker->create_workspace(['name'=>'Synthetic queued worker'])['id'];$scope=$jobScope;$s=new TickerbotStore($db,$owner,wp_generate_uuid4(),$scope,$now);$s->configure($w);}
 $list=(int)$tracker->create_watchlist($w,['name'=>'Synthetic dispatch'],'worker-list')['id'];$ids=[];
 for($i=0;$i<$count;$i++){$id=tbi_asset($w,'S'.$i);$ids[]=$id;$tracker->add_watchlist_item($w,$list,['asset_id'=>$id,'status'=>'watch'],'worker-item-'.$i);$s->save_mapping($w,$id,'S'.$i,tb_row('S'.$i));}
 $s->select_scope($w,[$list],[],0);$run=$s->start_run($w,'worker');$s->prepare_dispatch($w,$run['run_id'],$run['lease_token']);
 return [$s,$w,$scope,$now,$run,$ids];
}
function tbw_response(string $url,array $missing=[]):array {
 $symbols=array_map('rawurldecode',explode(',',basename(parse_url($url,PHP_URL_PATH))));$rows=[];$now=gmdate('Y-m-d\TH:i:s\Z');
 foreach($symbols as $symbol)if(!in_array($symbol,$missing,true))$rows[$symbol]=array_replace(tb_row($symbol),['price_asof'=>$now]);
 $payload=count($symbols)===1&&!$missing?['ticker'=>$symbols[0],'data'=>$rows[$symbols[0]],'as_of'=>$now]:['requested'=>$symbols,'count'=>count($rows),'data'=>$rows,'not_found'=>array_values(array_intersect($symbols,$missing)),'as_of'=>$now];
 return ['headers'=>[],'body'=>json_encode($payload),'response'=>['code'=>200]];
}

test('Tickerbot worker freezes 51 symbols into two batches and retains partial success without ledger writes',function()use($db,$tracker){
 [$s,$w,$scope,$now,$run,$ids]=tbw_context(51);$calls=[];
 $transport=function($url,$options)use(&$calls,$s,$w,$run){$calls[]=$url;equal($s->next_dispatch($w,$run['run_id'],$run['lease_token'])['state'],'in_flight');return tbw_response($url,['S9']);};
 $one=TickerbotRefresh::step($s,$w,$run['run_id'],$run['lease_token'],$transport);equal($one['state'],'pending');
 $two=TickerbotRefresh::step($s,$w,$run['run_id'],$run['lease_token'],$transport);equal($two['state'],'failed');equal(count($calls),2);equal($s->usage($w)['attempts'],2);
 $batch=$db->rows('SELECT attempts,state FROM '.$db->table('tickerbot_batches').' WHERE workspace_id=%d ORDER BY ordinal',[$w]);equal(array_column($batch,'attempts'),['1','1']);equal(array_column($batch,'state'),['completed','failed']);
 equal(count($db->rows('SELECT id FROM '.$db->table('tickerbot_quote_receipts').' WHERE workspace_id=%d',[$w])),50);equal(count($tracker->list_objects($w,'transactions')),0);equal(count($tracker->list_objects($w,'accounts')),0);
 equal($s->latest_quote($w,$ids[9]),null);equal($s->latest_quote($w,$ids[0])['provider'],'tickerbot');
 tbs_denied(fn()=>TickerbotRefresh::step($s,$w,$run['run_id'],$run['lease_token'],$transport));equal(count($calls),2);
});

test('Tickerbot worker persists jittered backoff and caps timeout/server retries at three counted attempts',function()use($db,$owner){
 [$s,$w,$scope,$now,$run]=tbw_context();$calls=0;$transport=function()use(&$calls){$calls++;return $calls===1?new WP_Error('fixture_timeout','Synthetic timeout'):['headers'=>[],'body'=>'{}','response'=>['code'=>503]];};
 for($i=1;$i<=3;$i++){
  $result=TickerbotRefresh::step($s,$w,$run['run_id'],$run['lease_token'],$transport);equal($calls,$i);
  if($i<3){equal($result['state'],'waiting');$next=new DateTimeImmutable($result['next_due_at'],new DateTimeZone('UTC'));$seconds=$next->getTimestamp()-$now->getTimestamp();equal($seconds>=15&&$seconds<=35,true);equal(TickerbotRefresh::step($s,$w,$run['run_id'],$run['lease_token'],$transport)['state'],'waiting');equal($calls,$i);$now=$next;$s=new TickerbotStore($db,$owner,wp_generate_uuid4(),$scope,$now);}else equal($result['state'],'failed');
 }
 equal($s->usage($w)['attempts'],3);equal(count($db->rows('SELECT id FROM '.$db->table('tickerbot_batch_attempts').' WHERE workspace_id=%d',[$w])),3);
 tbs_denied(fn()=>TickerbotRefresh::step($s,$w,$run['run_id'],$run['lease_token'],$transport));equal($calls,3);
});

test('Tickerbot worker stops on quota permission and rate pauses without sending the remaining batch',function()use($db){
 foreach([401=>'authentication',402=>'monthly_quota',403=>'capability',429=>'rate_limit'] as $status=>$state){
  [$s,$w,$scope,$now,$run]=tbw_context(51);$calls=0;$transport=function()use(&$calls,$status){$calls++;return ['headers'=>['retry-after'=>'120'],'body'=>'provider details must not persist','response'=>['code'=>$status]];};
  equal(TickerbotRefresh::step($s,$w,$run['run_id'],$run['lease_token'],$transport)['state'],$state);equal($calls,1);equal($s->usage($w)['attempts'],1);equal($s->usage($w)['paused_until']>$now->format('Y-m-d H:i:s'),true);
  $rows=$db->rows('SELECT state FROM '.$db->table('tickerbot_batches').' WHERE workspace_id=%d ORDER BY ordinal',[$w]);equal(array_column($rows,'state'),['failed','pending']);
 }
});

test('Tickerbot worker admissions recheck saved intent mappings budget and concurrent batch ownership',function()use($db){
 [$s,$w,$scope,$now,$run]=tbw_context();$next=$s->next_dispatch($w,$run['run_id'],$run['lease_token']);
 $a=$s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'quotes',$next['symbols'],$next['batch_id']);equal($a['state'],'admitted');equal($s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'quotes',$next['symbols'],$next['batch_id'])['state'],'in_flight');equal($s->usage($w)['attempts'],1);
 rejects(fn()=>$s->finish_attempt($w,$run['run_id'],$run['lease_token'],$a['attempt_id'],'provider_error',null,[999999=>'unsupported']));tbs_denied(fn()=>$s->finish_attempt($w,$run['run_id'],$run['lease_token'],$a['attempt_id'],'completed'));$s->finish_attempt($w,$run['run_id'],$run['lease_token'],$a['attempt_id'],'invalid_response');equal($s->next_dispatch($w,$run['run_id'],$run['lease_token'])['state'],'failed');
 [$s,$w,$scope,$now,$run,$ids]=tbw_context();$s->save_mapping($w,$ids[0],'S0',tb_row('S0'),(int)$s->inventory($w)['items'][0]['mapping_id']);tbs_denied(fn()=>TickerbotRefresh::step($s,$w,$run['run_id'],$run['lease_token'],fn()=>throw new LogicException('Must not send')));equal($s->usage($w)['attempts'],0);
 [$s,$w,$scope,$now,$run]=tbw_context();$s->select_scope($w,[],[],1);tbs_denied(fn()=>TickerbotRefresh::step($s,$w,$run['run_id'],$run['lease_token'],fn()=>throw new LogicException('Must not send')));equal($s->usage($w)['attempts'],0);
 [$s,$w,$scope,$now,$run]=tbw_context();$db->query('UPDATE '.$db->table('tickerbot_accounts').' SET monthly_cap=0 WHERE controller_workspace_id=%d',[$w]);equal(TickerbotRefresh::step($s,$w,$run['run_id'],$run['lease_token'],fn()=>throw new LogicException('Must not send'))['state'],'budget_blocked');equal($s->usage($w)['attempts'],0);
});

test('Tickerbot uncertain dispatch is counted and cannot be automatically resent after lease expiry',function()use($db,$owner){
 [$s,$w,$scope,$now,$run]=tbw_context();$next=$s->next_dispatch($w,$run['run_id'],$run['lease_token']);$s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'quotes',$next['symbols'],$next['batch_id']);
 equal(TickerbotRefresh::step($s,$w,$run['run_id'],$run['lease_token'],fn()=>throw new LogicException('Must not resend'))['state'],'in_flight');
 $later=new TickerbotStore($db,$owner,wp_generate_uuid4(),$scope,$now->modify('+6 minutes'));tbs_denied(fn()=>TickerbotRefresh::step($later,$w,$run['run_id'],$run['lease_token'],fn()=>throw new LogicException('Expired send')));equal($later->usage($w)['attempts'],1);
});

test('Tickerbot identical marks retain each receipt response time and migration 21 repairs additively',function()use($db){
 [$s,$w,$scope,$now,$run,$ids]=tbw_context();$map=(int)$s->inventory($w)['items'][0]['mapping_id'];$quote=['asset_id'=>$ids[0],'provider'=>'tickerbot','provider_symbol'=>'S0','price'=>'100','currency'=>'USD','price_source'=>'fair_value','price_asof'=>$now->format('Y-m-d H:i:s.u'),'response_as_of'=>$now->format('Y-m-d H:i:s.u'),'fetched_at'=>$now->format('Y-m-d H:i:s.u'),'source_time_semantics'=>'provider_fetch_time'];
 $first=null;
 for($i=0;$i<2;$i++){$a=$s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'quotes',['S0']);$quote['response_as_of']=$now->modify('+'.$i.' seconds')->format('Y-m-d H:i:s.u');$quote['fetched_at']=$quote['response_as_of'];$id=$s->record_quote($w,$run['run_id'],$run['lease_token'],$a['attempt_id'],$map,$quote);if($first===null)$first=$id;else equal($id,$first);$s->finish_attempt($w,$run['run_id'],$run['lease_token'],$a['attempt_id'],'completed');}
 $cached=$s->latest_quote($w,$ids[0]);equal($cached['first_response_as_of'],$now->format('Y-m-d H:i:s.u'));equal($cached['response_as_of'],$now->modify('+1 seconds')->format('Y-m-d H:i:s.u'));equal($cached['last_received_at'],$quote['fetched_at']);
 update_option('tgit_schema_version','20');Installer::install();equal(get_option('tgit_schema_version'),'21');Installer::install();equal($s->latest_quote($w,$ids[0]),$cached);equal(TickerbotRefresh::queue($w,$run['run_id'],time()+1),false);
});



test('Tickerbot batch audit failure preserves counted in-flight uncertainty without a resend',function()use($db,$wpdb){
 [$s,$w,$scope,$now,$run]=tbw_context();$trigger='fixture_tb_batch_'.bin2hex(random_bytes(4));$calls=0;
 $db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW BEGIN IF NEW.action = 'tickerbot.batch.finish' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic'; END IF; END");$prior=$wpdb->suppress_errors(true);
 try{try{TickerbotRefresh::step($s,$w,$run['run_id'],$run['lease_token'],function()use(&$calls){$calls++;return ['headers'=>[],'body'=>'{}','response'=>['code'=>503]];});throw new LogicException('Batch audit fault accepted');}catch(RuntimeException $error){}}
 finally{$db->query('DROP TRIGGER '.$trigger);$wpdb->suppress_errors($prior);}
 equal($calls,1);equal($s->usage($w)['attempts'],1);equal($s->next_dispatch($w,$run['run_id'],$run['lease_token'])['state'],'in_flight');
 equal(TickerbotRefresh::step($s,$w,$run['run_id'],$run['lease_token'],fn()=>throw new LogicException('Must not resend'))['state'],'in_flight');
 equal($db->row('SELECT state FROM '.$db->table('tickerbot_attempts').' WHERE workspace_id=%d',[$w])['state'],'admitted');
});

test('Tickerbot queued job uses server context IDs and current owner authorization with safe deactivation',function()use($db,$owner){
 define('TGIT_TICKERBOT_WORKER_ENABLED',true);define('TGIT_TICKERBOT_ACCOUNT_SCOPE','synthetic-worker-job-'.bin2hex(random_bytes(6)));
 [$s,$w,$scope,$now,$run,$ids]=tbw_context(1,TGIT_TICKERBOT_ACCOUNT_SCOPE);$calls=0;
 $fixture=function($preempt,$options,$url)use(&$calls){if(!str_starts_with($url,'https://api.tickerbot.io/v2/'))return $preempt;$calls++;return tbw_response($url);};
 add_filter('pre_http_request',$fixture,10,3);
 try{
  equal(TickerbotRefresh::queue($w,$run['run_id'],time()+5),true);$event=wp_get_scheduled_event('tgit_tickerbot_batch',[$w,$run['run_id']]);equal($event->args,[$w,$run['run_id']]);wp_unschedule_event($event->timestamp,'tgit_tickerbot_batch',$event->args);
  do_action('tgit_tickerbot_batch',$w,$run['run_id']);equal($calls,1);equal($s->latest_quote($w,$ids[0])['provider'],'tickerbot');do_action('tgit_tickerbot_batch',$w,$run['run_id']);equal($calls,1);
  [$revoked,$rw,$rs,$rn,$rr]=tbw_context(1,TGIT_TICKERBOT_ACCOUNT_SCOPE);$db->query('UPDATE '.$db->table('memberships').' SET state=%s WHERE workspace_id=%d AND wp_user_id=%d',['revoked',$rw,$owner]);do_action('tgit_tickerbot_batch',$rw,$rr['run_id']);equal($calls,1);
  equal(TickerbotRefresh::queue($w,$run['run_id'],time()+5),true);$before=$s->usage($w);\GainerInteractive\IGTradingJournal\Infrastructure\QuoteRefresh::deactivate();equal(wp_next_scheduled('tgit_tickerbot_batch',[$w,$run['run_id']]),false);equal($s->usage($w),$before);

 }finally{remove_filter('pre_http_request',$fixture,10);wp_clear_scheduled_hook('tgit_tickerbot_batch',[$w,$run['run_id']]);}
});
