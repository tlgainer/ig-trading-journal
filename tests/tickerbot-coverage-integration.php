<?php
/** Disposable mapping-bound coverage commands; synthetic HTTP only. */
declare(strict_types=1);
if(!defined('TGIT_DISPOSABLE_TEST_SITE')||TGIT_DISPOSABLE_TEST_SITE!==true)throw new RuntimeException('Disposable site required.');
use GainerInteractive\IGTradingJournal\Infrastructure\TickerbotCoverageCheck;

function tbcov_context(int $cap=8000):array {[$s,$w,$scope,$now]=tbs_context($cap);$asset=tbi_asset($w,'AAPL');$map=$s->save_mapping($w,$asset,'AAPL',tb_row());return [$s,$w,$asset,$map,$scope,$now];}
function tbcov_response(int $status=200,?array $live=null):array{return ['response'=>['code'=>$status],'headers'=>['retry-after'=>'120'],'body'=>json_encode(['ticker'=>'AAPL','as_of'=>'2026-10-14T12:00:00Z','name'=>'synthetic-secret-not-retained','spans'=>['not-retained'],'live_row'=>$live??['columns'=>3,'populated'=>2,'null_fields'=>['price']]]),'cookies'=>[]];}

test('Tickerbot coverage counts once retains selective null evidence and leaves prices due time and schedules untouched',function()use($db){
 [$s,$w,$asset,$map]=tbcov_context();$key=wp_generate_uuid4();$before=$s->readiness($w);$calls=0;$send=function($url)use(&$calls){$calls++;equal($url,'https://api.tickerbot.io/v2/tickers/AAPL/coverage');return tbcov_response();};$result=TickerbotCoverageCheck::run($s,$w,$asset,$map,$key,$send);equal($result['state'],'covered');equal($result['summary']['columns'],3);equal($result['summary']['price_null'],true);equal($result['summary']['price_asof_null'],false);equal(str_contains(json_encode($result),'secret'),false);equal(TickerbotCoverageCheck::run($s,$w,$asset,$map,$key,$send),$result);equal($calls,1);equal($s->usage($w)['attempts'],1);equal($s->readiness($w)['next_due_at'],$before['next_due_at']);equal($s->cadence($w),null);equal($s->coverage_current($w,$asset)['coverage']['matches_current_mapping'],true);equal(count($db->rows('SELECT id FROM '.$db->table('tickerbot_quotes').' WHERE workspace_id=%d',[$w])),0);
});

test('Tickerbot coverage fixed-input retries and overlapping posts cannot acquire another send capability',function(){
 [$s,$w,$asset,$map]=tbcov_context();$key=wp_generate_uuid4();$calls=0;$send=function()use($s,$w,$asset,$map,$key,&$calls){$calls++;equal(TickerbotCoverageCheck::run($s,$w,$asset,$map,$key,fn()=>throw new LogicException('Duplicate send'))['state'],'uncertain');equal(TickerbotCoverageCheck::run($s,$w,$asset,$map,wp_generate_uuid4(),fn()=>throw new LogicException('Overlapping send'))['state'],'busy');return tbcov_response();};equal(TickerbotCoverageCheck::run($s,$w,$asset,$map,$key,$send)['state'],'covered');tbs_denied(fn()=>TickerbotCoverageCheck::run($s,$w,$asset,$map+1,$key,$send));equal($calls,1);
});

test('Tickerbot coverage malformed data rate limits and zero budget cannot fabricate quote coverage',function(){
 [$s,$w,$asset,$map]=tbcov_context(0);equal(TickerbotCoverageCheck::run($s,$w,$asset,$map,wp_generate_uuid4(),fn()=>throw new LogicException('Zero cap sent'))['state'],'budget_blocked');equal($s->usage($w)['attempts'],0);
 foreach([[200,['columns'=>2,'populated'=>3,'null_fields'=>[]],'invalid_response'],[429,null,'rate_limit'],[404,null,'unsupported']]as[$status,$live,$state]){[$s,$w,$asset,$map]=tbcov_context();$key=wp_generate_uuid4();$calls=0;$send=function()use($status,$live,&$calls){$calls++;return tbcov_response($status,$live);};$result=TickerbotCoverageCheck::run($s,$w,$asset,$map,$key,$send);equal($result['state'],$state);equal($result['summary'],null);equal(TickerbotCoverageCheck::run($s,$w,$asset,$map,$key,$send)['state'],$state);equal($calls,1);equal($s->usage($w)['attempts'],1);equal($s->usage($w)['paused_until']!==null,$status===429);}
});

test('Tickerbot coverage audit failure preserves counted uncertainty without partial metadata publication',function()use($db,$wpdb){
 [$s,$w,$asset,$map]=tbcov_context();$key=wp_generate_uuid4();$trigger='fixture_tb_coverage_'.bin2hex(random_bytes(4));$db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW BEGIN IF NEW.action = 'tickerbot.coverage.finish' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic'; END IF; END");$prior=$wpdb->suppress_errors(true);$calls=0;$send=function()use(&$calls){$calls++;return tbcov_response();};
 try{$failed=false;try{TickerbotCoverageCheck::run($s,$w,$asset,$map,$key,$send);}catch(RuntimeException $e){$failed=true;}equal($failed,true);}finally{$db->query('DROP TRIGGER '.$trigger);$wpdb->suppress_errors($prior);}
 equal(TickerbotCoverageCheck::run($s,$w,$asset,$map,$key,$send)['state'],'uncertain');equal($calls,1);equal($s->usage($w)['attempts'],1);equal($s->coverage_current($w,$asset)['coverage'],null);
});

test('Tickerbot coverage rechecks mapping identity during delivery and labels old evidence historical',function()use($db){
 [$s,$w,$asset,$map]=tbcov_context();$key=wp_generate_uuid4();$send=function()use($db,$w,$asset){$db->query('UPDATE '.$db->table('assets').' SET exchange=%s WHERE workspace_id=%d AND id=%d',['XNYS',$w,$asset]);return tbcov_response();};tbs_denied(fn()=>TickerbotCoverageCheck::run($s,$w,$asset,$map,$key,$send));equal($s->coverage_current($w,$asset)['coverage'],null);equal($s->coverage_current($w,$asset)['eligible'],false);equal($s->usage($w)['attempts'],1);
 [$s,$w,$asset,$map]=tbcov_context();TickerbotCoverageCheck::run($s,$w,$asset,$map,wp_generate_uuid4(),fn()=>tbcov_response());$s->save_mapping($w,$asset,'AAPL',tb_row(),$map);$old=$s->coverage_current($w,$asset)['coverage'];equal($old['matches_current_mapping'],false);equal($old['summary']['price_null'],true);
});

test('Tickerbot coverage endpoint families foreign assets and owner membership remain isolated',function()use($tracker,$db,$owner,$viewer){
 [$s,$w,$asset,$map,$scope,$now]=tbcov_context();$run=$s->start_run($w,'purpose','coverage');$s->bind_coverage($w,$run['run_id'],$run['lease_token'],$asset,$map);tbs_denied(fn()=>$s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'catalogue'));tbs_denied(fn()=>$s->prepare_dispatch($w,$run['run_id'],$run['lease_token']));$a=$s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'coverage');equal($s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'coverage')['state'],'in_flight');$s->finish_attempt($w,$run['run_id'],$run['lease_token'],$a['attempt_id'],'transport_error');$s->finish_coverage($w,$run['run_id'],$run['lease_token'],$a['attempt_id'],'transport_error',null);$s->finish_run($w,$run['run_id'],$run['lease_token'],'failed');
 $foreign=(int)$tracker->create_workspace(['name'=>'Foreign coverage'])['id'];$other=tbi_asset($foreign,'AAPL');tbs_denied(fn()=>$s->coverage_target($w,$other,$map));$untrusted=new \GainerInteractive\IGTradingJournal\Application\TickerbotStore($db,$viewer,wp_generate_uuid4(),$scope,$now);tbs_denied(fn()=>$untrusted->coverage_current($w,$asset));
});

test('Tickerbot coverage route rejects browser proof and schema 26 preserves selective receipts',function()use($owner){
 [$s,$w,$asset,$map]=tbcov_context();TickerbotCoverageCheck::run($s,$w,$asset,$map,wp_generate_uuid4(),fn()=>tbcov_response());update_option('tgit_schema_version','25');\GainerInteractive\IGTradingJournal\Infrastructure\Installer::install();equal(get_option('tgit_schema_version'),\GainerInteractive\IGTradingJournal\Infrastructure\Installer::VERSION);\GainerInteractive\IGTradingJournal\Infrastructure\Installer::install();equal($s->coverage_current($w,$asset)['coverage']['summary']['price_null'],true);equal($s->usage($w)['attempts'],1);
 $request=new WP_REST_Request('POST','/tgit/v1/workspaces/'.$w.'/assets/'.$asset.'/tickerbot-coverage');$request->set_url_params(['workspace'=>(string)$w,'asset_id'=>(string)$asset]);$request->set_header('Content-Type','application/json');$request->set_header('Idempotency-Key',wp_generate_uuid4());$request->set_body(json_encode(['expected_mapping_id'=>$map,'summary'=>['columns'=>100]]));wp_set_current_user($owner);equal(is_wp_error(\GainerInteractive\IGTradingJournal\Http\Controller::dispatch($request,'tickerbot_check_coverage')),true);
});

test('Tickerbot disabled coverage transport allows saved same-key metadata reads without resending',function()use($owner){
 [$s,$w,$asset,$map,$scope]=tbcov_context();$key=wp_generate_uuid4();TickerbotCoverageCheck::run($s,$w,$asset,$map,$key,fn()=>tbcov_response());$file=dirname(__DIR__).'/tmp/tb-coverage-'.bin2hex(random_bytes(6)).'.json';file_put_contents($file,json_encode(['owner'=>$owner,'scope'=>$scope,'workspace'=>$w,'asset'=>$asset,'mapping'=>$map,'key'=>$key]));
 try{$pipes=[];$process=proc_open([PHP_BINARY,'-d','extension_dir='.dirname(__DIR__).'/tmp/php81/ext','-d','extension=mysqli',__DIR__.'/tickerbot-coverage-disabled-worker.php',$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Cannot launch disabled fixture');fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);equal(proc_close($process),0);$read=json_decode($output,true,512,JSON_THROW_ON_ERROR);equal($read['new']['state'],'disabled');equal($read['prior']['state'],'covered');equal($read['prior']['summary']['price_null'],true);equal($s->usage($w)['attempts'],1);}finally{unlink($file);}
});
