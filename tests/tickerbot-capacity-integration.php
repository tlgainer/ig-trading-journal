<?php
/** Scalar capacity guards on the explicitly disposable database. */
declare(strict_types=1);
if(!defined('TGIT_DISPOSABLE_TEST_SITE')||TGIT_DISPOSABLE_TEST_SITE!==true)throw new RuntimeException('Disposable site required.');
use GainerInteractive\IGTradingJournal\Infrastructure\Installer;

function tbp_seed($store,int $workspace):array {
 global $tracker;
 $id=tbi_asset($workspace,'S0');$list=(int)$tracker->create_watchlist($workspace,['name'=>'Capacity scope'],'capacity-list')['id'];$tracker->add_watchlist_item($workspace,$list,['asset_id'=>$id,'status'=>'watch'],'capacity-item');$store->save_mapping($workspace,$id,'S0',tb_row('S0'));$store->select_scope($workspace,[$list],[],0);return [$id,$list];
}

test('Tickerbot capacity prevents combined overcommit and releases forecasts without refunding usage',function()use($tracker,$db){
 [$s,$w]=tbs_context(600);tbp_seed($s,$w);$first=$s->save_cadence($w,true,0);$other=(int)$tracker->create_workspace(['name'=>'Shared capacity'])['id'];$s->configure($other,600);tbp_seed($s,$other);
 equal($s->usage($w)['projected_recurring_calls'],372);$run=$s->start_run($w,'capacity-counted');$a=$s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'connection');$s->finish_attempt($w,$run['run_id'],$run['lease_token'],$a['attempt_id'],'completed');$s->finish_run($w,$run['run_id'],$run['lease_token'],'failed');equal($s->usage($other)['projection_headroom'],228);tbs_denied(fn()=>$s->save_cadence($other,true,0));equal($s->cadence($other),null);
 // Separate workspace batches use the same canonical symbol but are not shared HTTP batches.
 $s->save_cadence($w,false,1);$s->save_cadence($other,true,0);equal($s->usage($w)['projected_recurring_calls'],372);equal($s->usage($w)['attempts'],1);
 tbs_denied(fn()=>$s->configure($w,300));equal($s->usage($w)['soft_cap'],600);$s->configure($w,0);equal($s->usage($w)['projection_over_cap'],true);equal($s->usage($other)['projected_recurring_calls'],372);
 $s->save_cadence($other,false,1);equal($s->usage($w)['projected_recurring_calls'],0);$s->configure($w,300);equal($s->usage($w)['soft_cap'],300);
 equal(count($db->rows('SELECT id FROM '.$db->table('tickerbot_capacity_revisions').' WHERE workspace_id=%d',[$w])),2);
});

test('Tickerbot recurring plan refresh catches inventory growth before HTTP under shared capacity',function()use($tracker,$db){
 [$s,$w,$scope,$now]=tbs_context(800);[$id,$list]=tbp_seed($s,$w);$c=$s->save_cadence($w,true,0);$other=(int)$tracker->create_workspace(['name'=>'Other forecast'])['id'];$s->configure($other,800);tbp_seed($s,$other);$s->save_cadence($other,true,0);
 for($i=1;$i<=50;$i++){$asset=tbi_asset($w,'S'.$i);$tracker->add_watchlist_item($w,$list,['asset_id'=>$asset,'status'=>'watch'],'capacity-grow-'.$i);$s->save_mapping($w,$asset,'S'.$i,tb_row('S'.$i));}
 $db->query('UPDATE '.$db->table('tickerbot_settings').' SET next_due_at=%s WHERE workspace_id=%d',[$now->format('Y-m-d H:i:s'),$w]);$due=$s->cadence_tick($w,$c['id']);$run=$s->start_run($w,$due['key'],'scheduled',$c['id']);
 tbs_denied(fn()=>$s->prepare_dispatch($w,$run['run_id'],$run['lease_token']));equal($s->usage($w)['projected_recurring_calls'],744);equal(count($db->rows('SELECT id FROM '.$db->table('tickerbot_plans').' WHERE workspace_id=%d',[$w])),0);equal($s->usage($w)['attempts'],0);
 $s->save_cadence($other,false,1);$s->prepare_dispatch($w,$run['run_id'],$run['lease_token']);equal($s->usage($w)['projected_recurring_calls'],744);equal($s->usage($w)['projection_headroom'],56);equal(count($db->rows('SELECT id FROM '.$db->table('tickerbot_capacity_revisions').' WHERE workspace_id=%d',[$w])),2);
});

test('Tickerbot stale or revoked owners cannot silently release another workspace forecast',function()use($tracker,$db,$owner){
 [$s,$w]=tbs_context(600);tbp_seed($s,$w);$s->save_cadence($w,true,0);$other=(int)$tracker->create_workspace(['name'=>'Capacity privacy'])['id'];$s->configure($other,600);tbp_seed($s,$other);
 $db->query('UPDATE '.$db->table('memberships').' SET state=%s WHERE workspace_id=%d AND wp_user_id=%d',['revoked',$w,$owner]);
 tbs_denied(fn()=>$s->save_cadence($w,false,1));tbs_denied(fn()=>$s->save_cadence($other,true,0));equal($s->usage($other)['projected_recurring_calls'],372);
 $db->query('UPDATE '.$db->table('memberships').' SET state=%s WHERE workspace_id=%d AND wp_user_id=%d',['active',$w,$owner]);$s->save_cadence($w,false,1);$s->save_cadence($other,true,0);equal($s->usage($other)['projected_recurring_calls'],372);
});

test('Tickerbot capacity audit failure rolls back enrollment forecast history and shared promise together',function()use($db,$wpdb){
 [$s,$w]=tbs_context(600);tbp_seed($s,$w);$before=$s->usage($w);$trigger='fixture_tb_capacity_'.bin2hex(random_bytes(4));$db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW BEGIN IF NEW.action = 'tickerbot.capacity.save' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic'; END IF; END");$prior=$wpdb->suppress_errors(true);
 try{try{$s->save_cadence($w,true,0);throw new LogicException('Capacity audit fault accepted');}catch(RuntimeException $error){}}
 finally{$db->query('DROP TRIGGER '.$trigger);$wpdb->suppress_errors($prior);}
 equal($s->usage($w),$before);equal($s->cadence($w),null);equal(count($db->rows('SELECT id FROM '.$db->table('tickerbot_capacity_revisions').' WHERE workspace_id=%d',[$w])),0);
});


test('Tickerbot one-off manual refresh uses actual admission rather than a recurring monthly forecast',function(){
 [$s,$w]=tbs_context(1,(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format(DATE_ATOM));tbp_seed($s,$w);$run=$s->start_run($w,'one-off');
 $result=\GainerInteractive\IGTradingJournal\Infrastructure\TickerbotRefresh::step($s,$w,$run['run_id'],$run['lease_token'],fn($url)=>tbw_response($url));equal($result['state'],'completed');equal($s->usage($w)['attempts'],1);equal($s->usage($w)['projected_recurring_calls'],0);equal($s->cadence($w),null);
});

test('Tickerbot legacy cadence requires explicit capacity review and migration 23 preserves claims',function()use($db,$owner){
 [$s,$w,$scope,$now]=tbs_context();$legacy=$db->insert('tickerbot_cadences',['workspace_id'=>$w,'revision'=>1,'enabled'=>1,'actor_id'=>$owner,'settings_revision'=>1,'scope_revision'=>0,'created_at'=>$now->format('Y-m-d H:i:s')]);update_option('tgit_schema_version','22');Installer::install();equal(get_option('tgit_schema_version'),Installer::VERSION);
 tbs_denied(fn()=>$s->cadence_tick($w,$legacy));equal($s->usage($w)['projected_recurring_calls'],0);$s->save_cadence($w,true,1);equal($s->cadence_tick($w,(int)$s->cadence($w)['id'])['state'],'not_due');$before=$s->usage($w);Installer::install();equal($s->usage($w),$before);
});

test('Tickerbot concurrent workspace enrollments cannot both claim the last shared capacity slot',function()use($db,$tracker,$owner){
 [$s,$w,$scope,$now]=tbs_context(600);tbp_seed($s,$w);$other=(int)$tracker->create_workspace(['name'=>'Capacity race'])['id'];$s->configure($other,600);tbp_seed($s,$other);$file=dirname(__DIR__).'/tmp/tb-capacity-'.bin2hex(random_bytes(6)).'.json';file_put_contents($file,json_encode(['owner'=>$owner,'scope'=>$scope,'clock'=>$now->format(DATE_ATOM),'workspaces'=>[$w,$other]]));$processes=[];
 try{
  for($i=0;$i<2;$i++){$pipes=[];$cmd=[PHP_BINARY,'-d','extension_dir='.dirname(__DIR__).'/tmp/php81/ext','-d','extension=mysqli',__DIR__.'/tickerbot-capacity-race-worker.php',$file,(string)$i];$process=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($process))throw new RuntimeException('Cannot launch capacity fixture');fclose($pipes[0]);$processes[]=[$process,$pipes];}
  $states=[];foreach($processes as[$process,$pipes]){$output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);equal(proc_close($process),0);$states[]=json_decode($output,true,512,JSON_THROW_ON_ERROR)['state'];}sort($states);equal($states,['blocked','saved']);equal($s->usage($w)['projected_recurring_calls'],372);equal($s->usage($w)['attempts'],0);
 }finally{unlink($file);}
});
