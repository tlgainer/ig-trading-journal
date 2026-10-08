<?php
/** Persistent spending fixtures in a fresh disposable plugin-table prefix. */
use GainerInteractive\IGTradingJournal\Application\AiSpending;
use GainerInteractive\IGTradingJournal\Application\Tracker;
use GainerInteractive\IGTradingJournal\Infrastructure\Database;
use GainerInteractive\IGTradingJournal\Infrastructure\Installer;
use GainerInteractive\IGTradingJournal\Domain\Decimal;
if(!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE!==true) throw new RuntimeException('Disposable site required.');

test('Schema 13 repairs AI storage from 12 without changing saved provider evidence',function() use($db) {
 $before=$db->row('SELECT COUNT(*) AS total FROM '.$db->table('fundamental_snapshots'))['total'];
 update_option('tgit_schema_version','12'); Installer::install(); equal(get_option('tgit_schema_version'),Installer::VERSION);
 $db->query('DROP TABLE '.$db->table('ai_enrollments')); Installer::install();
 foreach(['ai_pools','ai_configs','ai_enrollments','ai_requests','ai_request_events'] as $table) equal($db->row('SHOW TABLE STATUS LIKE %s',[$db->table($table)])['Engine'],'InnoDB');
 equal($db->row('SELECT COUNT(*) AS total FROM '.$db->table('fundamental_snapshots'))['total'],$before);
});

// Keep every run's synthetic history; no DELETE/purge or production connection.
$ai_connection=clone $wpdb; $ai_connection->prefix='fixture_ai_'.substr(str_replace('-','',wp_generate_uuid4()),0,10).'_';
$ai_original_connection=$wpdb;
try { $wpdb=$ai_connection; Installer::install(); } finally { $wpdb=$ai_original_connection; }
$ai_db=new Database($ai_connection); $ai_tracker=new Tracker($ai_db,$owner,wp_generate_uuid4());
$ai_controller=(int)$ai_tracker->create_workspace(['name'=>'Synthetic AI controller'])['id'];
$ai_service=new AiSpending($ai_db,$owner,wp_generate_uuid4(),ai_now());
function ai_digest(string $label): string { return hash('sha256',$label); }
function ai_conflict(callable $callback): void { provider_conflict($callback); }
function ai_settings(string $cap, bool $enabled=true, array $pricing=[]): array {
 global $ai_service,$ai_controller;
 $status=$ai_service->status($ai_controller);
 return $ai_service->configure($ai_controller,['enabled'=>$enabled,'monthly_cap'=>$cap,'model'=>'fixture-text-model','expected_config_id'=>$status['config_id']],['fixture-text-model'=>ai_pricing($pricing)]);
}
function ai_workspace(): int {
 global $ai_tracker,$ai_service;
 $w=(int)$ai_tracker->create_workspace(['name'=>'Synthetic AI request workspace'])['id']; $ai_service->enroll($w,true,0); return $w;
}
function ai_room(string $extra='0.045'): void {
 global $ai_service,$ai_controller;
 $status=$ai_service->status($ai_controller); ai_settings(bcadd(Decimal::add($status['spent'],$status['reserved']),$extra,12));
}
function ai_reserve(int $workspace,string $key,string $credential='fixture-key'): array {
 global $ai_service;
 return $ai_service->reserve($workspace,ai_digest($credential),$key,ai_digest('approved-evidence'),10000,2000);
}

test('AI shared configuration and workspace enrollment are explicit, revisioned and owner scoped',function() use($ai_service,$ai_controller,$ai_db,$owner,$viewer) {
 $default=$ai_service->status($ai_controller); equal($default['configured'],false); equal($default['enabled'],false); decimal($default['monthly_cap'],'15');
 ai_conflict(fn()=>$ai_service->enroll($ai_controller,true,0));
 $first=ai_settings('15',false); $enrolled=$ai_service->enroll($ai_controller,true,0); ai_conflict(fn()=>ai_reserve($ai_controller,'disabled'));
 $second=ai_settings('15'); equal((int)$second['id']>(int)$first['id'],true); equal($ai_db->object('ai_configs',$ai_controller,(int)$first['id'])['enabled'],'0');
 ai_conflict(fn()=>$ai_service->configure($ai_controller,['enabled'=>false,'monthly_cap'=>'10','model'=>'','expected_config_id'=>0],[]));
 ai_conflict(fn()=>$ai_service->enroll($ai_controller,false,0));
 $other=ai_workspace();
 try { $ai_service->configure($other,['enabled'=>false,'monthly_cap'=>'100','model'=>'','expected_config_id'=>0],[]); throw new LogicException('Foreign controller accepted'); } catch(DomainException $error) {}
 $denied=new AiSpending($ai_db,$viewer,wp_generate_uuid4(),ai_now());
 try { $denied->status($ai_controller); throw new LogicException('Nonmember budget read'); } catch(DomainException $error) {}
 equal($ai_service->status($other)['can_configure'],false); equal($ai_service->status($other)['enrolled'],true);
 foreach(['credential_fingerprint','controller_workspace_id','actor_id','pricing_json'] as $field) equal(array_key_exists($field,$ai_service->status($other)),false);
});

test('AI reservations share one cap across workspaces and credential rotations, retaining exact retry identity',function() use($ai_service,$ai_db) {
 ai_room('0.09'); $w=ai_workspace(); $other=ai_workspace();
 $first=ai_reserve($w,'Exact-Key'); $second=ai_reserve($other,'second','rotated-credential');
 equal(ai_reserve($w,'Exact-Key'),$first); decimal($ai_service->status($w)['remaining'],'0');
 ai_conflict(fn()=>ai_reserve($w,'third','another-credential')); ai_conflict(fn()=>ai_reserve($w,'Exact-Key','rotated-credential'));
 ai_conflict(fn()=>$ai_service->reserve($w,ai_digest('fixture-key'),'Exact-Key',ai_digest('changed'),10000,2000));
 ai_conflict(fn()=>ai_reserve($w,'exact-key')); // Different bytes, new request; cap still full.
 try { $ai_service->request($other,(int)$first['id']); throw new LogicException('Foreign request exposed'); } catch(OutOfBoundsException $error) {}
 equal(count($ai_db->rows('SELECT * FROM '.$ai_db->table('ai_requests').' WHERE workspace_id = %d',[$w])),1);
 $ai_service->reconcile($w,(int)$first['id'],null,true); $ai_service->reconcile($other,(int)$second['id'],null,true);
 equal($ai_service->request($w,(int)$first['id'])['state'],'cancelled');
});

test('AI dispatch is single-claim and rejects stale policy, enrollment, credential, expiry and month',function() use($ai_service,$ai_controller,$ai_db,$owner) {
 ai_room(); $w=ai_workspace(); $request=ai_reserve($w,'stale-policy'); ai_room();
 ai_conflict(fn()=>$ai_service->dispatch($w,(int)$request['id'],ai_digest('fixture-key'))); $ai_service->reconcile($w,(int)$request['id'],null,true);
 $request=ai_reserve($w,'stale-enrollment'); $e=$ai_service->status($w)['enrollment_id']; $ai_service->enroll($w,false,$e);
 ai_conflict(fn()=>$ai_service->dispatch($w,(int)$request['id'],ai_digest('fixture-key'))); $ai_service->enroll($w,true,$ai_service->status($w)['enrollment_id']);
 ai_conflict(fn()=>$ai_service->dispatch($w,(int)$request['id'],ai_digest('fixture-key'))); $ai_service->reconcile($w,(int)$request['id'],null,true);
 $request=ai_reserve($w,'expired'); $later=new AiSpending($ai_db,$owner,wp_generate_uuid4(),ai_now()->modify('+11 minutes'));
 ai_conflict(fn()=>$later->dispatch($w,(int)$request['id'],ai_digest('fixture-key'))); $later->reconcile($w,(int)$request['id'],null,true);
 $request=ai_reserve($w,'claim'); ai_conflict(fn()=>$ai_service->dispatch($w,(int)$request['id'],ai_digest('rotated-key')));
 $claimed=$ai_service->dispatch($w,(int)$request['id'],ai_digest('fixture-key')); equal($claimed['state'],'dispatched');
 ai_conflict(fn()=>$ai_service->dispatch($w,(int)$request['id'],ai_digest('fixture-key'))); ai_conflict(fn()=>$ai_service->reconcile($w,(int)$request['id'],null,true));
 $ai_service->reconcile($w,(int)$request['id'],['input_tokens'=>0,'cached_input_tokens'=>0,'output_tokens'=>0]);
});

test('AI settlement retains captured model prices and is idempotent after policy disable',function() use($ai_service,$ai_controller,$ai_db) {
 ai_room(); $w=ai_workspace(); $request=ai_reserve($w,'settlement'); $ai_service->dispatch($w,(int)$request['id'],ai_digest('fixture-key'));
 $status=$ai_service->status($ai_controller); $ai_service->configure($ai_controller,['enabled'=>false,'monthly_cap'=>'0','model'=>'different-model','expected_config_id'=>$status['config_id']],[]);
 rejects(fn()=>$ai_service->reconcile($w,(int)$request['id'],['input_tokens'=>'10000','cached_input_tokens'=>8000,'output_tokens'=>2000]));
 equal($ai_service->request($w,(int)$request['id'])['state'],'dispatched');
 $usage=['input_tokens'=>10000,'cached_input_tokens'=>8000,'output_tokens'=>2000]; $result=$ai_service->reconcile($w,(int)$request['id'],$usage);
 equal($result['state'],'settled'); decimal($result['charge'],'0.027'); equal($result['model'],'fixture-text-model');
 $count=count($ai_db->rows('SELECT * FROM '.$ai_db->table('ai_request_events').' WHERE workspace_id = %d AND request_id = %d',[$w,$request['id']]));
 equal($ai_service->reconcile($w,(int)$request['id'],array_reverse($usage,true)),$result);
 equal(count($ai_db->rows('SELECT * FROM '.$ai_db->table('ai_request_events').' WHERE workspace_id = %d AND request_id = %d',[$w,$request['id']])),$count);
 ai_conflict(fn()=>$ai_service->reconcile($w,(int)$request['id'],array_replace($usage,['output_tokens'=>2001])));
 equal(ai_reserve($w,'settlement')['state'],'settled'); ai_conflict(fn()=>ai_reserve($w,'new-disabled')); ai_room();
});

test('AI uncertain charges carry across the New York month and settle with expired dispatch prices',function() use($ai_db,$ai_controller,$owner) {
 $before=new DateTimeImmutable('2026-11-01 03:59:00',new DateTimeZone('UTC')); $after=new DateTimeImmutable('2026-11-01 04:01:00',new DateTimeZone('UTC'));
 $old=new AiSpending($ai_db,$owner,wp_generate_uuid4(),$before); $new=new AiSpending($ai_db,$owner,wp_generate_uuid4(),$after);
 $pricing=ai_pricing(['verified_at'=>'2026-10-30 00:00:00','valid_until'=>'2026-11-01 04:00:00']);
 $status=$old->status($ai_controller); $old->configure($ai_controller,['enabled'=>true,'monthly_cap'=>bcadd($status['spent'],'0.045',12),'model'=>'fixture-text-model','expected_config_id'=>$status['config_id']],['fixture-text-model'=>$pricing]);
 $w=ai_workspace(); $r=$old->reserve($w,ai_digest('fixture-key'),'month',ai_digest('approved-evidence'),10000,2000); $old->dispatch($w,(int)$r['id'],ai_digest('fixture-key')); $old->reconcile($w,(int)$r['id']);
 equal($new->status($w)['period'],'2026-11'); decimal($new->status($w)['reserved'],'0.045'); decimal($new->status($w)['spent'],'0');
 $status=$new->status($ai_controller); $new->configure($ai_controller,['enabled'=>true,'monthly_cap'=>'0.045','model'=>'fixture-text-model','expected_config_id'=>$status['config_id']],['fixture-text-model'=>ai_pricing(['verified_at'=>'2026-11-01 04:00:00','valid_until'=>'2026-11-20 00:00:00'])]);
 ai_conflict(fn()=>$new->reserve($w,ai_digest('rotated-key'),'new-month',ai_digest('approved-evidence'),10000,2000));
 equal($new->reserve($w,ai_digest('fixture-key'),'month',ai_digest('approved-evidence'),10000,2000)['state'],'uncertain');
 ai_conflict(fn()=>$new->dispatch($w,(int)$r['id'],ai_digest('fixture-key')));
 $new->reconcile($w,(int)$r['id'],['input_tokens'=>10000,'cached_input_tokens'=>8000,'output_tokens'=>2000]);
 decimal($new->status($w)['reserved'],'0'); decimal($new->status($w)['spent'],'0');
 $fresh=$new->reserve($w,ai_digest('rotated-key'),'new-month',ai_digest('approved-evidence'),10000,2000); $new->reconcile($w,(int)$fresh['id'],null,true);
 ai_room();
});

test('AI reservations recheck original enrollment owners and deny nonowner callers',function() use($ai_db,$ai_tracker,$ai_service,$owner,$viewer) {
 ai_room(); $w=ai_workspace(); $ai_tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'owner','state'=>'active']);
 $alternate=new Tracker($ai_db,$viewer,wp_generate_uuid4()); $alternate->set_member($w,['wp_user_id'=>$owner,'role'=>'owner','state'=>'revoked']);
 $service=new AiSpending($ai_db,$viewer,wp_generate_uuid4(),ai_now());
 try { $service->reserve($w,ai_digest('fixture-key'),'revoked-enroller',ai_digest('approved-evidence'),10000,2000); throw new LogicException('Revoked enrollment owner allowed'); } catch(DomainException $error) {}
 try { ai_reserve($w,'revoked-caller'); throw new LogicException('Revoked actor allowed'); } catch(DomainException $error) {}
 $service->enroll($w,true,$service->status($w)['enrollment_id']);
 $r=$service->reserve($w,ai_digest('fixture-key'),'new-owner',ai_digest('approved-evidence'),10000,2000); $service->reconcile($w,(int)$r['id'],null,true);
 foreach(['viewer','manager','contributor'] as $role) {
  $alternate->set_member($w,['wp_user_id'=>$owner,'role'=>$role,'state'=>'active']);
  try { $ai_service->status($w); throw new LogicException('Nonowner budget allowed'); } catch(DomainException $error) {}
 }
});

test('AI zero/lowered caps and revoked shared-policy owners stop new reservations',function() use($ai_db,$ai_tracker,$ai_service,$ai_controller,$owner,$viewer) {
 ai_room(); $w=ai_workspace(); $r=ai_reserve($w,'lowered'); ai_settings('0'); ai_conflict(fn()=>ai_reserve($w,'paused'));
 ai_settings('0.001'); ai_conflict(fn()=>ai_reserve($w,'lowered-cap')); $ai_service->reconcile($w,(int)$r['id'],null,true); ai_room();
 $ai_tracker->set_member($ai_controller,['wp_user_id'=>$viewer,'role'=>'owner','state'=>'active']);
 $alternate=new Tracker($ai_db,$viewer,wp_generate_uuid4()); $alternate->set_member($ai_controller,['wp_user_id'=>$owner,'role'=>'owner','state'=>'revoked']);
 try { ai_reserve($w,'revoked-policy'); throw new LogicException('Revoked shared authorizer allowed'); } catch(DomainException $error) {}
 $alternate->set_member($ai_controller,['wp_user_id'=>$owner,'role'=>'owner','state'=>'active']);
 $r=ai_reserve($w,'restored-policy'); $ai_service->reconcile($w,(int)$r['id'],null,true);
});

test('AI dispatch refuses a month change even before reservation and pricing expiry',function() use($ai_db,$ai_controller,$owner) {
 $old=new AiSpending($ai_db,$owner,wp_generate_uuid4(),new DateTimeImmutable('2026-11-01 03:59:55',new DateTimeZone('UTC')));
 $new=new AiSpending($ai_db,$owner,wp_generate_uuid4(),new DateTimeImmutable('2026-11-01 04:00:01',new DateTimeZone('UTC')));
 $status=$old->status($ai_controller); $old->configure($ai_controller,['enabled'=>true,'monthly_cap'=>bcadd($status['spent'],'0.045',12),'model'=>'fixture-text-model','expected_config_id'=>$status['config_id']],['fixture-text-model'=>ai_pricing(['verified_at'=>'2026-10-30 00:00:00','valid_until'=>'2026-11-20 00:00:00'])]);
 $w=ai_workspace(); $r=$old->reserve($w,ai_digest('fixture-key'),'boundary',ai_digest('approved-evidence'),10000,2000);
 ai_conflict(fn()=>$new->dispatch($w,(int)$r['id'],ai_digest('fixture-key'))); $new->reconcile($w,(int)$r['id'],null,true); ai_room();
});

test('AI damaged captured pricing blocks dispatch and settlement without erasing reservation history',function() use($ai_db,$ai_service) {
 ai_room(); $w=ai_workspace(); $r=ai_reserve($w,'damaged-pricing'); $original=$ai_db->object('ai_requests',$w,(int)$r['id']);
 $ai_db->query('UPDATE '.$ai_db->table('ai_requests').' SET pricing_json = %s WHERE workspace_id = %d AND id = %d',['{}',$w,$r['id']]);
 ai_conflict(fn()=>$ai_service->dispatch($w,(int)$r['id'],ai_digest('fixture-key'))); equal($ai_service->request($w,(int)$r['id'])['state'],'reserved');
 $ai_db->query('UPDATE '.$ai_db->table('ai_requests').' SET pricing_json = %s WHERE workspace_id = %d AND id = %d',[$original['pricing_json'],$w,$r['id']]);
 $ai_service->dispatch($w,(int)$r['id'],ai_digest('fixture-key')); $ai_db->query('UPDATE '.$ai_db->table('ai_requests').' SET pricing_fingerprint = %s WHERE workspace_id = %d AND id = %d',[str_repeat('0',64),$w,$r['id']]);
 ai_conflict(fn()=>$ai_service->reconcile($w,(int)$r['id'],['input_tokens'=>0,'cached_input_tokens'=>0,'output_tokens'=>0])); equal($ai_service->request($w,(int)$r['id'])['state'],'dispatched');
 $ai_db->query('UPDATE '.$ai_db->table('ai_requests').' SET pricing_fingerprint = %s WHERE workspace_id = %d AND id = %d',[$original['pricing_fingerprint'],$w,$r['id']]);
 $ai_service->reconcile($w,(int)$r['id'],['input_tokens'=>0,'cached_input_tokens'=>0,'output_tokens'=>0]);
});

test('AI audit failures roll back configuration, reservations, dispatch and settlement without losing holds',function() use($ai_db,$ai_service,$ai_controller) {
 ai_room(); $w=ai_workspace(); $before=$ai_service->status($ai_controller); $trigger=$ai_db->table('audit_events').'_fault';
 $fault=function(callable $callback) use($ai_db,$trigger) {
  $ai_db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$ai_db->table('audit_events')." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture fault'");
  try { try { $callback(); throw new LogicException('Audit failure accepted'); } catch(RuntimeException $error) {} } finally { $ai_db->query('DROP TRIGGER '.$trigger); }
 };
 $fault(fn()=>ai_settings('100')); equal($ai_service->status($ai_controller),$before);
 $fault(fn()=>ai_reserve($w,'audit')); equal($ai_db->rows('SELECT * FROM '.$ai_db->table('ai_requests').' WHERE workspace_id = %d',[$w]),[]);
 $r=ai_reserve($w,'audit'); $fault(fn()=>$ai_service->dispatch($w,(int)$r['id'],ai_digest('fixture-key'))); equal($ai_service->request($w,(int)$r['id'])['state'],'reserved');
 $ai_service->dispatch($w,(int)$r['id'],ai_digest('fixture-key'));
 $usage=['input_tokens'=>10000,'cached_input_tokens'=>8000,'output_tokens'=>2000]; $fault(fn()=>$ai_service->reconcile($w,(int)$r['id'],$usage)); equal($ai_service->request($w,(int)$r['id'])['state'],'dispatched');
 $ai_service->reconcile($w,(int)$r['id'],$usage);
});

test('Concurrent AI workspaces cannot both reserve the final shared spending allowance',function() use($ai_connection,$ai_db,$ai_service,$owner) {
 $first=ai_workspace(); $second=ai_workspace();
 for($round=0;$round<10;$round++) {
  ai_room(); $processes=[];
  foreach([$first,$second] as $workspace) {
   $task=['operation'=>'ai_reserve','prefix'=>$ai_connection->prefix,'key'=>'race-'.$round,'now'=>ai_now()->format('Y-m-d H:i:s'),'credential'=>ai_digest('fixture-key'),'fingerprint'=>ai_digest('approved-evidence')];
   $command=[PHP_BINARY,'-d','extension_dir='.ini_get('extension_dir'),'-d','extension=mysqli',__DIR__.'/concurrency-worker.php',rtrim(ABSPATH,'/\\'),(string)$owner,(string)$workspace,wp_json_encode($task)];
   $pipes=[]; $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); if(!is_resource($process)) throw new RuntimeException('Worker failed'); fclose($pipes[0]); $processes[]=[$process,$pipes];
  }
  $results=[]; foreach($processes as [$process,$pipes]) { $results[]=trim(stream_get_contents($pipes[1])); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); if(proc_close($process)!==0) throw new RuntimeException('AI worker failed: '.$error); }
  sort($results); equal($results,['posted','rejected']);
  foreach([$first,$second] as $workspace) { $r=$ai_db->row('SELECT id FROM '.$ai_db->table('ai_requests').' WHERE workspace_id = %d AND request_key = %s',[$workspace,hash('sha256','race-'.$round)]); if($r) $ai_service->reconcile($workspace,(int)$r['id'],null,true); }
 }
});

test('AI verified overrun retains its full charge and pauses new spending without altering history',function() use($ai_service,$ai_db,$ai_controller) {
 ai_room(); $w=ai_workspace(); $r=ai_reserve($w,'overrun'); $ai_service->dispatch($w,(int)$r['id'],ai_digest('fixture-key'));
 $before=$ai_db->object('ai_requests',$w,(int)$r['id']); $result=$ai_service->reconcile($w,(int)$r['id'],['input_tokens'=>10000,'cached_input_tokens'=>0,'output_tokens'=>3000]);
 equal($result['state'],'overrun'); decimal($result['charge'],'0.055'); equal($ai_service->status($w)['overrun'],true);
 ai_settings('100'); ai_conflict(fn()=>ai_reserve($w,'after-overrun')); equal($ai_db->object('ai_requests',$w,(int)$r['id']),$before);
 equal($ai_db->rows('SELECT * FROM '.$ai_db->table('transactions').' WHERE workspace_id = %d',[$w]),[]);
});

// Public controls use their own retained synthetic table prefix.
test('AI Settings REST prepares disabled policy with private projection, revisions and explicit consent',function() use($owner,$viewer) {
 global $wpdb;
 $original=$wpdb; $connection=clone $wpdb; $connection->result=null; $connection->prefix='fixture_ais_'.substr(str_replace('-','',wp_generate_uuid4()),0,10).'_';
 try {
  $wpdb=$connection; Installer::install(); wp_set_current_user($owner);
  $t=new Tracker(new Database($wpdb),$owner,wp_generate_uuid4()); $w=(int)$t->create_workspace(['name'=>'Synthetic settings'])['id'];
  $other=(int)$t->create_workspace(['name'=>'Synthetic other settings'])['id']; $base='workspaces/'.$w;
  $initial=provider_rest('GET',$base.'/ai-settings'); equal($initial->get_status(),200); equal($initial->get_data()['data']['processing_available'],true);
  $input=['enabled'=>false,'monthly_cap'=>'10.50','model'=>'fixture-text-model','expected_config_id'=>0];
  $saved=provider_rest('POST',$base.'/ai-settings',$input); equal($saved->get_status(),200); $data=$saved->get_data()['data']; decimal($data['monthly_cap'],'10.5'); equal($data['model'],'fixture-text-model');
  foreach(['allowed','credential_fingerprint','pricing_json','controller_workspace_id','actor_id'] as $field) equal(array_key_exists($field,$data),false);
  equal(provider_rest('POST',$base.'/ai-settings',$input)->get_status(),409);
  equal(provider_rest('POST',$base.'/ai-settings',array_replace($input,['enabled'=>true,'expected_config_id'=>$data['config_id']]))->get_status(),400);
  equal(provider_rest('POST',$base.'/ai-settings',array_replace($input,['monthly_cap'=>10.5]))->get_status(),400);
  equal(provider_rest('POST',$base.'/ai-settings',['enabled'=>false])->get_status(),400);
  equal(provider_rest('POST',$base.'/ai-settings',array_replace($input,['pricing'=>[]]))->get_status(),400);
  equal(provider_rest('POST','workspaces/'.$other.'/ai-settings',$input)->get_status(),403);
  $enroll=provider_rest('POST',$base.'/ai-enrollment',['enabled'=>true,'expected_enrollment_id'=>0]); equal($enroll->get_status(),200); equal($enroll->get_data()['data']['enrolled'],true);
  equal(provider_rest('POST',$base.'/ai-enrollment',['enabled'=>'true','expected_enrollment_id'=>0])->get_status(),400);
  equal(provider_rest('POST',$base.'/ai-enrollment',['enabled'=>false,'expected_enrollment_id'=>0])->get_status(),409);
  $t->set_member($w,['wp_user_id'=>$viewer,'role'=>'manager','state'=>'active']); wp_set_current_user($viewer);
  foreach(['ai-settings','ai-enrollment'] as $route) equal(provider_rest('POST',$base.'/'.$route,$input)->get_status(),403);
  equal(provider_rest('GET',$base.'/ai-settings')->get_status(),403);
  wp_set_current_user($owner); $t->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'active']);
  $fixtureSpending=new AiSpending(new Database($wpdb),$owner,wp_generate_uuid4()); $setup=$fixtureSpending->status($w);
  $fixtureSpending->configure($w,['enabled'=>true,'monthly_cap'=>'10.50','model'=>'fixture-text-model','expected_config_id'=>$setup['config_id']],['fixture-text-model'=>ai_pricing()]);
  foreach(['browser-request-one','browser-request-two'] as $key) { $savedRequest=$fixtureSpending->reserve($w,ai_digest('fixture-only-key'),$key,str_repeat('a',64),1000,2000); $fixtureSpending->reconcile($w,(int)$savedRequest['id'],null,true); }
  $setup=$fixtureSpending->status($w); $fixtureSpending->configure($w,['enabled'=>false,'monthly_cap'=>'10.50','model'=>'fixture-text-model','expected_config_id'=>$setup['config_id']],[]);
  $history=provider_rest('GET',$base.'/ai-requests?limit=1'); equal($history->get_status(),200); $page=$history->get_data()['data']; equal(count($page['items']),1); equal(is_int($page['next_cursor']),true);
  equal(array_keys($page['items'][0]),['id','approval_id','model','created_at','maximum_cost','state','charge','can_publish','review_id','can_cancel']); equal($page['items'][0]['state'],'cancelled'); equal($page['items'][0]['can_publish'],false); equal($page['items'][0]['review_id'],null);
  $next=provider_rest('GET',$base.'/ai-requests?limit=1&after='.$page['next_cursor']); equal(count($next->get_data()['data']['items']),1); equal($next->get_data()['data']['next_cursor'],null);
  equal(provider_rest('GET',$base.'/ai-requests?limit=101')->get_status(),400); equal(provider_rest('GET',$base.'/ai-requests?after=-1')->get_status(),400); equal(provider_rest('GET',$base.'/ai-requests?after=abc')->get_status(),400);
  equal(provider_rest('GET','workspaces/'.$other.'/ai-requests')->get_data()['data']['items'],[]);
  $ready=provider_rest('GET',$base.'/ai-settings')->get_data()['data']['readiness']; equal($ready['credential_configured'],false); equal($ready['workflow_available'],true);
  wp_set_current_user($viewer); equal(provider_rest('GET',$base.'/ai-requests')->get_status(),403); wp_set_current_user($owner);
  file_put_contents(dirname(__DIR__).'/tmp/ai-settings-browser-fixtures.json',wp_json_encode(['workspace'=>$w,'prefix'=>$connection->prefix]));
 } finally { $original->result=null; $wpdb=$original; wp_set_current_user($owner); }
});
