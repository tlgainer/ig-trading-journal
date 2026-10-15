<?php
/** Durable Tickerbot state on the explicitly disposable database; no HTTP. */
declare(strict_types=1);
if(!defined('TGIT_DISPOSABLE_TEST_SITE')||TGIT_DISPOSABLE_TEST_SITE!==true)throw new RuntimeException('Disposable site required.');
use GainerInteractive\IGTradingJournal\Application\TickerbotStore;
use GainerInteractive\IGTradingJournal\Infrastructure\Installer;

function tbs_context(int $cap=8000,string $clock='2026-10-14T12:00:00Z'):array {
 global $tracker,$db,$owner;
 $w=(int)$tracker->create_workspace(['name'=>'Tickerbot fixture'])['id'];$scope='fixture-'.wp_generate_uuid4();$now=new DateTimeImmutable($clock);
 $service=new TickerbotStore($db,$owner,wp_generate_uuid4(),$scope,$now);$service->configure($w,$cap);
 return [$service,$w,$scope,$now];
}
function tbs_denied(callable $work):void {try{$work();}catch(DomainException|OutOfBoundsException|InvalidArgumentException $e){return;}throw new RuntimeException('Expected scoped operation to fail.');}

test('Schema 19 upgrades from 18 and repeated install preserves account enrollment',function()use($db){
 [$s,$w]=tbs_context();$before=$s->usage($w);update_option('tgit_schema_version','18');Installer::install();equal(get_option('tgit_schema_version'),'19');Installer::install();equal($s->usage($w),$before);equal(count($db->rows('SELECT id FROM '.$db->table('tickerbot_policy_revisions').' WHERE workspace_id=%d',[$w])),1);
});
test('Tickerbot shared budget counts failures, warns and suspends optional calls',function()use($tracker,$db,$owner,$viewer){
 [$s,$w,$scope,$now]=tbs_context(5);$other=(int)$tracker->create_workspace(['name'=>'Same account fixture'])['id'];$s->configure($other,5);
 $run=$s->start_run($w,'budget');equal($s->start_run($w,'join')['state'],'joined');equal(isset($s->start_run($other,'foreign')['run_id']),false);
 for($i=0;$i<4;$i++){$a=$s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'quotes',['AAPL']);equal($a['state'],'admitted');$s->finish_attempt($w,$run['run_id'],$run['lease_token'],$a['attempt_id'],'provider_error');}
 equal($s->usage($w)['warning'],true);equal($s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'history')['state'],'optional_paused');
 $a=$s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'quotes',['AAPL']);$s->finish_attempt($w,$run['run_id'],$run['lease_token'],$a['attempt_id'],'completed');equal($s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'connection')['state'],'budget_blocked');
 equal($s->usage($other)['attempts'],5);$s->finish_run($w,$run['run_id'],$run['lease_token'],'completed');equal($s->start_run($w,'new')['state'],'cooldown');equal($s->start_run($w,'budget')['state'],'completed');
 tbs_denied(fn()=>$s->configure($other,6));$untrusted=new TickerbotStore($db,$viewer,wp_generate_uuid4(),$scope,$now);tbs_denied(fn()=>$untrusted->usage($w));
 equal(count($tracker->list_objects($w,'transactions')),0);
});
test('Tickerbot expired workers are fenced and UTC due times skip missed intervals',function()use($db,$owner){
 [$s,$w,$scope,$now]=tbs_context();equal($s->start_run($w,'not-yet','scheduled')['state'],'not_due');$run=$s->start_run($w,'old');
 $later=new TickerbotStore($db,$owner,wp_generate_uuid4(),$scope,$now->modify('+6 minutes'));$fresh=$later->start_run($w,'fresh');equal($fresh['state'],'running');
 tbs_denied(fn()=>$s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'connection'));tbs_denied(fn()=>$s->renew($w,$run['run_id'],$run['lease_token']));
 $later->finish_run($w,$fresh['run_id'],$fresh['lease_token'],'completed');equal($later->start_run($w,'old')['state'],'expired');
 $tomorrow=new TickerbotStore($db,$owner,wp_generate_uuid4(),$scope,$now->modify('+1 day'));$scheduled=$tomorrow->start_run($w,'skip','scheduled');equal($scheduled['state'],'running');$tomorrow->finish_run($w,$scheduled['run_id'],$scheduled['lease_token'],'completed');equal($tomorrow->start_run($w,'skip-again','scheduled')['state'],'not_due');
});
test('Tickerbot Retry-After and monthly quota pauses survive run completion',function()use($db,$owner){
 foreach(['rate_limit','monthly_quota','authentication','capability']as$state){[$s,$w,$scope,$now]=tbs_context();$run=$s->start_run($w,str_replace('_','-',$state));$a=$s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'connection');$s->finish_attempt($w,$run['run_id'],$run['lease_token'],$a['attempt_id'],$state,120);equal($s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'connection')['state'],'paused');$s->finish_run($w,$run['run_id'],$run['lease_token'],'failed');equal($s->usage($w)['attempts'],1);equal($s->usage($w)['paused_until']!==null,true);$s->configure($w,8000,7200,0,true);equal($s->usage($w)['paused_until'],null);}
});
test('Tickerbot rolling-minute rate limit spans UTC month boundaries',function()use($db,$owner){
 [$s,$w,$scope,$now]=tbs_context(8000,'2026-10-31T23:59:50Z');$run=$s->start_run($w,'minute');
 for($i=0;$i<45;$i++)$s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'connection');equal($s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'connection')['state'],'rate_limit');
 $november=new TickerbotStore($db,$owner,wp_generate_uuid4(),$scope,$now->modify('+20 seconds'));equal($november->usage($w)['attempts'],0);equal($november->usage($w)['recent_attempts'],45);equal($november->reserve_attempt($w,$run['run_id'],$run['lease_token'],'connection')['state'],'rate_limit');
});
test('Tickerbot verified mappings and quote receipts preserve history and reject mismatches',function()use($tracker,$db){
 [$s,$w]=tbs_context();$asset=$tracker->create_object($w,'assets',['symbol'=>'AAPL','asset_class'=>'stock','exchange'=>'XNAS','quote_currency'=>'USD']);$id=(int)$asset['id'];$map=$s->save_mapping($w,$id,'AAPL',tb_row());
 $run=$s->start_run($w,'quotes');$a=$s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'quotes',['AAPL']);$mapping=array_replace(tb_asset($id),['id'=>$id]);$quote=tb_parse(['ticker'=>'AAPL','as_of'=>'2026-10-14T12:00:00Z','data'=>tb_row()],[$mapping])['accepted'][$id];$quote['fetched_at']='2026-10-14 12:00:00.000000';
 $first=$s->record_quote($w,$run['run_id'],$run['lease_token'],$a['attempt_id'],$map,$quote);equal($s->record_quote($w,$run['run_id'],$run['lease_token'],$a['attempt_id'],$map,$quote),$first);decimal($s->latest_quote($w,$id)['price'],$quote['price']);
 tbs_denied(fn()=>$s->record_quote($w,$run['run_id'],$run['lease_token'],$a['attempt_id'],$map,array_replace($quote,['currency'=>'EUR'])));
 tbs_denied(fn()=>$s->record_quote($w,$run['run_id'],$run['lease_token'],$a['attempt_id'],$map,array_replace($quote,['price'=>'0'])));
 $s->finish_attempt($w,$run['run_id'],$run['lease_token'],$a['attempt_id'],'completed');$b=$s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'quotes',['AAPL']);equal($s->record_quote($w,$run['run_id'],$run['lease_token'],$b['attempt_id'],$map,$quote),$first);
 equal(count($db->rows('SELECT id FROM '.$db->table('tickerbot_quote_receipts').' WHERE workspace_id=%d',[$w])),2);equal(count($db->rows('SELECT id FROM '.$db->table('tickerbot_quotes').' WHERE workspace_id=%d',[$w])),1);
 $new=$s->save_mapping($w,$id,'AAPL',tb_row(),$map);equal($s->latest_quote($w,$id),null);tbs_denied(fn()=>$s->record_quote($w,$run['run_id'],$run['lease_token'],$b['attempt_id'],$map,$quote));
 tbs_denied(fn()=>$s->save_mapping($w,$id,'AAPL',array_replace(tb_row(),['currency_name'=>'eur']),$new));equal(count($tracker->list_objects($w,'transactions')),0);
});


test('Tickerbot audit failure rolls back admission and preserves quota',function()use($db,$wpdb){
 [$s,$w]=tbs_context();$run=$s->start_run($w,'audit');$trigger='fixture_tb_audit_'.bin2hex(random_bytes(4));
 $db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW BEGIN IF NEW.action = 'tickerbot.attempt.admit' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic'; END IF; END");
 $prior=$wpdb->suppress_errors(true);
 try {try{$s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'connection');throw new LogicException('Audit failure accepted');}catch(RuntimeException $e){}}
 finally{$db->query('DROP TRIGGER '.$trigger);$wpdb->suppress_errors($prior);}
 equal($s->usage($w)['attempts'],0);
});

test('Tickerbot simultaneous last-slot admissions cannot overspend a shared cap',function()use($owner){
 [$s,$w,$scope,$now]=tbs_context(1);$run=$s->start_run($w,'race');$file=dirname(__DIR__).'/tmp/tb-race-'.bin2hex(random_bytes(6)).'.json';
 file_put_contents($file,json_encode(['owner'=>$owner,'scope'=>$scope,'clock'=>$now->format(DATE_ATOM),'workspace'=>$w,'run'=>$run['run_id'],'token'=>$run['lease_token']]));
 $processes=[];
 try{
  for($i=0;$i<2;$i++){$pipes=[];$cmd=[PHP_BINARY,'-d','extension_dir='.dirname(__DIR__).'/tmp/php81/ext','-d','extension=mysqli',__DIR__.'/tickerbot-store-race-worker.php',$file];$process=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Cannot launch race fixture');fclose($pipes[0]);$processes[]=[$process,$pipes];}
  $states=[];foreach($processes as[$process,$pipes]){$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);equal(proc_close($process),0);$states[]=json_decode($output,true,512,JSON_THROW_ON_ERROR)['state'];}sort($states);equal($states,['admitted','budget_blocked']);equal($s->usage($w)['attempts'],1);
 }finally{unlink($file);}
});
