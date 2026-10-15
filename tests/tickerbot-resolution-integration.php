<?php
/** Explicit mapping commands on the disposable site; no live provider access. */
declare(strict_types=1);
if(!defined('TGIT_DISPOSABLE_TEST_SITE')||TGIT_DISPOSABLE_TEST_SITE!==true)throw new RuntimeException('Disposable site required.');
use GainerInteractive\IGTradingJournal\Infrastructure\TickerbotResolution;

function tbr_response(array $rows,int $status=200):array{return ['response'=>['code'=>$status],'headers'=>['retry-after'=>'120'],'body'=>json_encode(['results'=>$rows,'next_cursor'=>null,'count'=>count($rows)]),'cookies'=>[]];}

test('Tickerbot mapping verification binds inputs counts once and atomically saves selective exact identity',function()use($db){
 [$s,$w]=tbs_context();$asset=tbi_asset($w,'AAPL');$key=wp_generate_uuid4();$before=$s->readiness($w);$calls=0;$send=function($url)use(&$calls){$calls++;equal($url,'https://api.tickerbot.io/v2/tickers?search=AAPL&asset_class=stocks&limit=50');return tbr_response([tb_row()+['name'=>'synthetic-secret-not-retained']]);};
 $result=TickerbotResolution::run($s,$w,$asset,'AAPL',0,$key,$send);equal($result['state'],'mapped');equal(TickerbotResolution::run($s,$w,$asset,'AAPL',0,$key,$send),$result);equal($calls,1);equal($s->usage($w)['attempts'],1);equal($s->readiness($w)['next_due_at'],$before['next_due_at']);equal($s->mapping_current($w,$asset)['mapping']['provider_symbol'],'AAPL');equal(array_keys($result),['state','mapping_id','checked_at']);
 tbs_denied(fn()=>TickerbotResolution::run($s,$w,$asset,'MSFT',0,$key,$send));tbs_denied(fn()=>TickerbotResolution::run($s,$w,$asset,'AAPL',(int)$result['mapping_id'],$key,$send));equal($calls,1);equal(count($db->rows('SELECT id FROM '.$db->table('tickerbot_quotes').' WHERE workspace_id=%d',[$w])),0);equal($s->cadence($w),null);
});

test('Tickerbot mismatched or duplicate catalogue identities remain unmapped',function(){
 foreach([[tb_row(),tb_row()],[array_replace(tb_row(),['currency_name'=>'eur'])],[array_replace(tb_row(),['exchange'=>'NYSE','exchange_mic'=>'XNYS'])],[array_replace(tb_row(),['asset_type'=>'ETF'])],[]]as$rows){[$s,$w]=tbs_context();$asset=tbi_asset($w,'AAPL');$result=TickerbotResolution::run($s,$w,$asset,'AAPL',0,wp_generate_uuid4(),fn()=>tbr_response($rows));equal($result['state'],$rows?'ambiguous':'unsupported');equal($s->mapping_current($w,$asset)['mapping'],null);equal($s->readiness($w)['latest_run']['state'],'failed');equal($s->usage($w)['attempts'],1);}
});

test('Tickerbot mapping rejects a wrong stock candidate and non-USD crypto pair before HTTP while verifying USD crypto',function()use($tracker){
 [$s,$w]=tbs_context();$stock=tbi_asset($w,'AAPL');equal(TickerbotResolution::run($s,$w,$stock,'MSFT',0,wp_generate_uuid4(),fn()=>throw new LogicException('Wrong symbol sent'))['state'],'unsupported_identity');equal($s->usage($w)['attempts'],0);
 $crypto=(int)$tracker->create_object($w,'assets',['symbol'=>'BTC','exchange'=>'BLOFIN','asset_class'=>'crypto','quote_currency'=>'EUR'])['id'];equal(TickerbotResolution::run($s,$w,$crypto,'X:BTCUSD',0,wp_generate_uuid4(),fn()=>throw new LogicException('EUR inferred as USD'))['state'],'unsupported_identity');equal($s->usage($w)['attempts'],0);
 $usd=(int)$tracker->create_object($w,'assets',['symbol'=>'BTC','exchange'=>'BLOFIN','asset_class'=>'crypto','quote_currency'=>'USD'])['id'];$row=array_replace(tb_row('X:BTCUSD'),['asset_class'=>'crypto']);equal(TickerbotResolution::run($s,$w,$usd,'X:BTCUSD',0,wp_generate_uuid4(),fn()=>tbr_response([$row]))['state'],'mapped');equal($s->usage($w)['attempts'],1);
});

test('Tickerbot mapping audit failure rolls back mapping and receipt while retaining counted uncertainty',function()use($db,$wpdb){
 [$s,$w]=tbs_context();$asset=tbi_asset($w,'AAPL');$key=wp_generate_uuid4();$trigger='fixture_tb_resolution_'.bin2hex(random_bytes(4));$db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW BEGIN IF NEW.action = 'tickerbot.resolution.finish' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic'; END IF; END");$prior=$wpdb->suppress_errors(true);$calls=0;$send=function()use(&$calls){$calls++;return tbr_response([tb_row()]);};
 try{$failed=false;try{TickerbotResolution::run($s,$w,$asset,'AAPL',0,$key,$send);}catch(RuntimeException $e){$failed=true;}equal($failed,true);}finally{$db->query('DROP TRIGGER '.$trigger);$wpdb->suppress_errors($prior);}
 equal($s->mapping_current($w,$asset)['mapping'],null);equal(TickerbotResolution::run($s,$w,$asset,'AAPL',0,$key,$send)['state'],'uncertain');equal($s->usage($w)['attempts'],1);equal($calls,1);
});

test('Tickerbot mapping detects changed local identity during delivery and fences overlapping posts',function()use($db){
 [$s,$w]=tbs_context();$asset=tbi_asset($w,'AAPL');$key=wp_generate_uuid4();$calls=0;$send=function()use($s,$w,$asset,$key,$db,&$calls){$calls++;equal(TickerbotResolution::run($s,$w,$asset,'AAPL',0,$key,fn()=>throw new LogicException('Duplicate send'))['state'],'uncertain');equal(TickerbotResolution::run($s,$w,$asset,'AAPL',0,wp_generate_uuid4(),fn()=>throw new LogicException('Overlapping send'))['state'],'busy');$db->query('UPDATE '.$db->table('assets').' SET exchange=%s WHERE workspace_id=%d AND id=%d',['XNYS',$w,$asset]);return tbr_response([tb_row()]);};
 tbs_denied(fn()=>TickerbotResolution::run($s,$w,$asset,'AAPL',0,$key,$send));equal($s->mapping_current($w,$asset)['mapping'],null);equal(TickerbotResolution::run($s,$w,$asset,'AAPL',0,$key,$send)['state'],'uncertain');equal($calls,1);equal($s->usage($w)['attempts'],1);
});

test('Tickerbot mapping respects shared budget provider pauses and owner asset isolation',function()use($tracker,$db,$viewer,$owner){
 [$s,$w]=tbs_context(0);$asset=tbi_asset($w,'AAPL');equal(TickerbotResolution::run($s,$w,$asset,'AAPL',0,wp_generate_uuid4(),fn()=>throw new LogicException('Zero cap sent'))['state'],'budget_blocked');equal($s->usage($w)['attempts'],0);
 [$s,$w,$scope,$now]=tbs_context();$asset=tbi_asset($w,'AAPL');$key=wp_generate_uuid4();$calls=0;$send=function()use(&$calls){$calls++;return tbr_response([],429);};equal(TickerbotResolution::run($s,$w,$asset,'AAPL',0,$key,$send)['state'],'rate_limit');equal(TickerbotResolution::run($s,$w,$asset,'AAPL',0,$key,$send)['state'],'rate_limit');equal($calls,1);equal($s->usage($w)['paused_until']!==null,true);
 $foreign=(int)$tracker->create_workspace(['name'=>'Foreign mapping'])['id'];$other=tbi_asset($foreign,'AAPL');tbs_denied(fn()=>TickerbotResolution::run($s,$w,$other,'AAPL',0,wp_generate_uuid4(),$send));$untrusted=new \GainerInteractive\IGTradingJournal\Application\TickerbotStore($db,$viewer,wp_generate_uuid4(),$scope,$now);tbs_denied(fn()=>$untrusted->mapping_current($w,$asset));equal($calls,1);
});

test('Tickerbot mapping routes reject browser identity proof and schema 25 preserves mapping receipts',function()use($tracker,$db,$owner){
 [$s,$w]=tbs_context();$asset=tbi_asset($w,'AAPL');$result=TickerbotResolution::run($s,$w,$asset,'AAPL',0,wp_generate_uuid4(),fn()=>tbr_response([tb_row()]));update_option('tgit_schema_version','24');\GainerInteractive\IGTradingJournal\Infrastructure\Installer::install();equal(get_option('tgit_schema_version'),\GainerInteractive\IGTradingJournal\Infrastructure\Installer::VERSION);\GainerInteractive\IGTradingJournal\Infrastructure\Installer::install();equal($s->mapping_current($w,$asset)['mapping']['id'],$result['mapping_id']);equal($s->usage($w)['attempts'],1);
 $request=new WP_REST_Request('POST','/tgit/v1/workspaces/'.$w.'/assets/'.$asset.'/tickerbot-mapping');$request->set_url_params(['workspace'=>(string)$w,'asset_id'=>(string)$asset]);$request->set_header('Content-Type','application/json');$request->set_header('Idempotency-Key',wp_generate_uuid4());$request->set_body(json_encode(['provider_symbol'=>'AAPL','expected_mapping_id'=>0,'identity'=>tb_row()]));wp_set_current_user($owner);equal(is_wp_error(\GainerInteractive\IGTradingJournal\Http\Controller::dispatch($request,'tickerbot_resolve')),true);
});

test('Tickerbot disabled mapping transport preserves same-key receipt reads without another request',function()use($owner){
 [$s,$w,$scope]=tbs_context();$asset=tbi_asset($w,'AAPL');$key=wp_generate_uuid4();$result=TickerbotResolution::run($s,$w,$asset,'AAPL',0,$key,fn()=>tbr_response([tb_row()]));$file=dirname(__DIR__).'/tmp/tb-resolution-'.bin2hex(random_bytes(6)).'.json';file_put_contents($file,json_encode(['owner'=>$owner,'scope'=>$scope,'workspace'=>$w,'asset'=>$asset,'key'=>$key,'mapping'=>$result['mapping_id']]));
 try{$pipes=[];$process=proc_open([PHP_BINARY,'-d','extension_dir='.dirname(__DIR__).'/tmp/php81/ext','-d','extension=mysqli',__DIR__.'/tickerbot-resolution-disabled-worker.php',$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Cannot launch disabled fixture');fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);equal(proc_close($process),0);$read=json_decode($output,true,512,JSON_THROW_ON_ERROR);equal($read['new']['state'],'disabled');equal($read['prior']['state'],'mapped');equal($s->usage($w)['attempts'],1);}finally{unlink($file);}
});
