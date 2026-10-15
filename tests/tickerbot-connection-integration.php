<?php
/** Counted connection commands on the disposable site; synthetic HTTP only. */
declare(strict_types=1);
if(!defined('TGIT_DISPOSABLE_TEST_SITE')||TGIT_DISPOSABLE_TEST_SITE!==true)throw new RuntimeException('Disposable site required.');
use GainerInteractive\IGTradingJournal\Infrastructure\TickerbotConnectionCheck;

function tbc_response(int $status=200):array{return ['response'=>['code'=>$status],'headers'=>['retry-after'=>'120'],'body'=>'{"ticker":"AAPL","data":[]}','cookies'=>[]];}

test('Tickerbot explicit connection counts once preserves quote due time and stores only selective proof',function()use($db){
 [$s,$w]=tbs_context();$key=wp_generate_uuid4();$before=$s->readiness($w);$calls=0;$send=function($url,$options)use(&$calls){$calls++;equal($url,'https://api.tickerbot.io/v2/tickers/AAPL');equal($options['redirection'],0);return tbc_response();};
 $result=TickerbotConnectionCheck::run($s,$w,$key,$send);equal($result['state'],'connected');equal(TickerbotConnectionCheck::run($s,$w,$key,$send),$result);equal($calls,1);equal($s->usage($w)['attempts'],1);equal($s->readiness($w)['next_due_at'],$before['next_due_at']);equal($s->cadence($w),null);
 $proof=$s->readiness($w,hash('sha256',TGIT_TICKERBOT_API_KEY))['connection'];equal($proof['credential_matches'],true);equal(array_keys($proof),['state','checked_at','credential_matches']);equal($s->readiness($w,hash('sha256','different-synthetic-key'))['connection']['credential_matches'],false);
 equal(count($db->rows('SELECT id FROM '.$db->table('tickerbot_quotes').' WHERE workspace_id=%d',[$w])),0);equal(count($db->rows('SELECT id FROM '.$db->table('tickerbot_plans').' WHERE workspace_id=%d',[$w])),0);equal(TickerbotConnectionCheck::run($s,$w,wp_generate_uuid4(),$send)['state'],'cooldown');equal($calls,1);
});

test('Tickerbot overlapping connection posts and unrelated commands cannot send another request',function(){
 [$s,$w]=tbs_context();$key=wp_generate_uuid4();$calls=0;$send=function()use($s,$w,$key,&$calls){$calls++;equal(TickerbotConnectionCheck::run($s,$w,$key,fn()=>throw new LogicException('Repeated send'))['state'],'uncertain');equal(TickerbotConnectionCheck::run($s,$w,wp_generate_uuid4(),fn()=>throw new LogicException('Overlapping send'))['state'],'busy');return tbc_response();};equal(TickerbotConnectionCheck::run($s,$w,$key,$send)['state'],'connected');equal($calls,1);
});

test('Tickerbot connection honors budget and provider pauses and never retries failed HTTP',function(){
 [$s,$w]=tbs_context(0);equal(TickerbotConnectionCheck::run($s,$w,wp_generate_uuid4(),fn()=>throw new LogicException('Zero cap sent'))['state'],'budget_blocked');equal($s->usage($w)['attempts'],0);
 foreach([401=>'authentication',402=>'monthly_quota',403=>'capability',429=>'rate_limit',503=>'provider_error']as$status=>$state){[$s,$w]=tbs_context();$key=wp_generate_uuid4();$calls=0;$send=function()use($status,&$calls){$calls++;return tbc_response($status);};equal(TickerbotConnectionCheck::run($s,$w,$key,$send)['state'],$state);equal(TickerbotConnectionCheck::run($s,$w,$key,$send)['state'],$state);equal($calls,1);equal($s->usage($w)['attempts'],1);equal($s->usage($w)['paused_until']!==null,$status!==503);}
});

test('Tickerbot connection receipt audit failure preserves counted uncertainty without resend',function()use($db,$wpdb){
 [$s,$w]=tbs_context();$key=wp_generate_uuid4();$trigger='fixture_tb_connection_'.bin2hex(random_bytes(4));$db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW BEGIN IF NEW.action = 'tickerbot.connection.record' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic'; END IF; END");$prior=$wpdb->suppress_errors(true);$calls=0;$send=function()use(&$calls){$calls++;return tbc_response();};
 try{$failed=false;try{TickerbotConnectionCheck::run($s,$w,$key,$send);}catch(RuntimeException $e){$failed=true;}equal($failed,true);}finally{$db->query('DROP TRIGGER '.$trigger);$wpdb->suppress_errors($prior);}
 equal(TickerbotConnectionCheck::run($s,$w,$key,$send)['state'],'uncertain');equal($calls,1);equal($s->usage($w)['attempts'],1);equal($s->readiness($w)['connection'],null);
});

test('Tickerbot connection run rejects quote families duplicate admissions and foreign receipts',function()use($db,$owner,$viewer){
 [$s,$w,$scope,$now]=tbs_context();$run=$s->start_run($w,'purpose','connection');tbs_denied(fn()=>$s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'quotes',['AAPL']));$a=$s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'connection');equal($s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'connection')['state'],'in_flight');tbs_denied(fn()=>$s->record_connection($w,$run['run_id'],$run['lease_token'],$a['attempt_id'],'connected',str_repeat('a',64)));
 $untrusted=new \GainerInteractive\IGTradingJournal\Application\TickerbotStore($db,$viewer,wp_generate_uuid4(),$scope,$now);tbs_denied(fn()=>$untrusted->connection_outcome($w,$run['run_id']));$s->finish_attempt($w,$run['run_id'],$run['lease_token'],$a['attempt_id'],'transport_error');$s->record_connection($w,$run['run_id'],$run['lease_token'],$a['attempt_id'],'transport_error',str_repeat('a',64));$s->finish_run($w,$run['run_id'],$run['lease_token'],'failed');
});

test('Tickerbot local setup rejects stale revisions atomically and migration 24 preserves receipts',function()use($db){
 [$s,$w]=tbs_context();$before=$s->usage($w);$failed=false;try{$s->configure($w,4000,7200,0,false,0);}catch(UnexpectedValueException $e){$failed=true;}equal($failed,true);equal($s->usage($w),$before);$s->configure($w,4000,86400,0,false,1);equal($s->readiness($w)['settings_revision'],2);equal($s->cadence($w),null);
 $result=TickerbotConnectionCheck::run($s,$w,wp_generate_uuid4(),fn()=>tbc_response());update_option('tgit_schema_version','23');\GainerInteractive\IGTradingJournal\Infrastructure\Installer::install();equal(get_option('tgit_schema_version'),\GainerInteractive\IGTradingJournal\Infrastructure\Installer::VERSION);\GainerInteractive\IGTradingJournal\Infrastructure\Installer::install();equal($s->readiness($w)['connection']['state'],$result['state']);equal($s->usage($w)['attempts'],1);
});

test('Tickerbot disabled transport blocks new work but permits saved same-key outcome reads',function()use($owner){
 [$s,$w,$scope]=tbs_context();$key=wp_generate_uuid4();TickerbotConnectionCheck::run($s,$w,$key,fn()=>tbc_response());$file=dirname(__DIR__).'/tmp/tb-connection-'.bin2hex(random_bytes(6)).'.json';file_put_contents($file,json_encode(['owner'=>$owner,'scope'=>$scope,'workspace'=>$w,'key'=>$key]));
 try{$pipes=[];$process=proc_open([PHP_BINARY,'-d','extension_dir='.dirname(__DIR__).'/tmp/php81/ext','-d','extension=mysqli',__DIR__.'/tickerbot-connection-disabled-worker.php',$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Cannot launch disabled fixture');fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);equal(proc_close($process),0);$result=json_decode($output,true,512,JSON_THROW_ON_ERROR);equal($result['new']['state'],'disabled');equal($result['prior']['state'],'connected');equal($s->usage($w)['attempts'],1);}finally{unlink($file);}
});

test('Tickerbot owner setup route rejects injected server controls and stale saves before HTTP',function()use($tracker,$db,$owner,$viewer){
 $w=(int)$tracker->create_workspace(['name'=>'Connection route'])['id'];$request=new WP_REST_Request('POST','/tgit/v1/workspaces/'.$w.'/tickerbot/settings');$request->set_url_params(['workspace'=>(string)$w]);$request->set_header('Content-Type','application/json');$request->set_body(json_encode(['monthly_cap'=>8000,'interval_seconds'=>7200,'expected_revision'=>0]));wp_set_current_user($owner);$result=\GainerInteractive\IGTradingJournal\Http\Controller::dispatch($request,'tickerbot_settings');equal($result instanceof WP_REST_Response,true);equal(is_wp_error(\GainerInteractive\IGTradingJournal\Http\Controller::dispatch($request,'tickerbot_settings')),true);
 $request->set_body(json_encode(['api_key'=>'synthetic-injection','monthly_cap'=>8000,'interval_seconds'=>7200,'expected_revision'=>1]));equal(is_wp_error(\GainerInteractive\IGTradingJournal\Http\Controller::dispatch($request,'tickerbot_settings')),true);
 $request->set_body('{}');$request->set_header('Idempotency-Key','invalid');equal(is_wp_error(\GainerInteractive\IGTradingJournal\Http\Controller::dispatch($request,'tickerbot_connection')),true);wp_set_current_user($viewer);equal(is_wp_error(\GainerInteractive\IGTradingJournal\Http\Controller::dispatch($request,'tickerbot_connection')),true);wp_set_current_user($owner);
});
