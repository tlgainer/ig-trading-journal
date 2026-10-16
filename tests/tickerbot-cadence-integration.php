<?php
/** Explicit cadence and recovery on disposable state and synthetic HTTP only. */
declare(strict_types=1);
if(!defined('TGIT_DISPOSABLE_TEST_SITE')||TGIT_DISPOSABLE_TEST_SITE!==true)throw new RuntimeException('Disposable site required.');
use GainerInteractive\IGTradingJournal\Application\TickerbotStore;
use GainerInteractive\IGTradingJournal\Infrastructure\Installer;
use GainerInteractive\IGTradingJournal\Infrastructure\RecurringTickerbot;
use GainerInteractive\IGTradingJournal\Infrastructure\TickerbotRefresh;
use GainerInteractive\IGTradingJournal\Infrastructure\QuoteRefresh;

test('Tickerbot cadence is explicit revisioned scoped and bound to reviewed settings and selection',function()use($db,$owner,$viewer){
 [$s,$w,$scope,$now]=tbs_context();equal($s->cadence($w),null);equal(RecurringTickerbot::queue($w),false);
 $off=$s->save_cadence($w,false,0);tbs_denied(fn()=>$s->cadence_tick($w,$off['id']));$on=$s->save_cadence($w,true,1);equal($on['enabled'],true);equal($s->cadence_tick($w,$on['id'])['state'],'not_due');
 try{$s->save_cadence($w,false,1);throw new LogicException('Stale consent accepted');}catch(UnexpectedValueException $error){}
 $other=new TickerbotStore($db,$viewer,wp_generate_uuid4(),$scope,$now);tbs_denied(fn()=>$other->save_cadence($w,true,2));tbs_denied(fn()=>$other->cadence($w));
 $s->configure($w);tbs_denied(fn()=>$s->cadence_tick($w,$on['id']));$on=$s->save_cadence($w,true,2);$s->select_scope($w,[],[],0);tbs_denied(fn()=>$s->cadence_tick($w,$on['id']));
 $on=$s->save_cadence($w,true,3);$later=new TickerbotStore($db,$owner,wp_generate_uuid4(),$scope,$now->modify('+1 day'));$due=$later->cadence_tick($w,$on['id']);equal($due['state'],'due');$run=$later->start_run($w,$due['key'],'scheduled',$on['id']);equal($run['state'],'running');$later->prepare_dispatch($w,$run['run_id'],$run['lease_token']);
 equal((int)$db->row('SELECT cadence_id FROM '.$db->table('tickerbot_run_cadences').' WHERE workspace_id=%d AND run_id=%d',[$w,$run['run_id']])['cadence_id'],$on['id']);
 $later->save_cadence($w,false,4);tbs_denied(fn()=>$later->prepare_dispatch($w,$run['run_id'],$run['lease_token']));equal($later->usage($w)['attempts'],0);
});

test('Tickerbot cadence resumes only saved plans and expires uncertainty without refund or catch-up',function()use($db,$owner){
 [$s,$w,$scope,$now,$run]=tbw_context();$cadence=$s->save_cadence($w,true,0);$status=$s->cadence_tick($w,$cadence['id']);equal($status,['state'=>'resume','run_id'=>$run['run_id']]);
 $next=$s->next_dispatch($w,$run['run_id'],$run['lease_token']);$a=$s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'quotes',$next['symbols'],$next['batch_id']);equal($s->cadence_tick($w,$cadence['id']),['state'=>'in_flight']);
 $lateNow=$now->modify('+6 minutes');$later=new TickerbotStore($db,$owner,wp_generate_uuid4(),$scope,$lateNow);$expired=$later->cadence_tick($w,$cadence['id']);equal($expired['state'],'expired');equal($expired['next_due_at'],$lateNow->modify('+7200 seconds')->format('Y-m-d H:i:s'));equal($later->usage($w)['attempts'],1);
 equal($db->object('tickerbot_attempts',$w,$a['attempt_id'])['state'],'admitted');equal($db->object('tickerbot_runs',$w,$run['run_id'])['state'],'failed');equal($later->cadence_tick($w,$cadence['id'])['state'],'not_due');
 tbs_denied(fn()=>TickerbotRefresh::step($later,$w,$run['run_id'],$run['lease_token'],fn()=>throw new LogicException('Must not resend')));
 $future=new TickerbotStore($db,$owner,wp_generate_uuid4(),$scope,$lateNow->modify('+7200 seconds'));equal($future->cadence_tick($w,$cadence['id'])['state'],'due');
 [$s,$w]=tbs_context();$c=$s->save_cadence($w,true,0);$s->start_run($w,'unprepared');equal($s->cadence_tick($w,$c['id'])['state'],'in_flight');
});

test('Tickerbot cadence never exposes or rewrites a different workspace active run',function()use($db,$tracker,$owner){
 [$first,$w,$scope,$now]=tbs_context();$run=$first->start_run($w,'foreign-active');$other=(int)$tracker->create_workspace(['name'=>'Scoped scheduler'])['id'];$first->configure($other);$c=$first->save_cadence($other,true,0);
 equal($first->cadence_tick($other,$c['id']),['state'=>'busy']);tbs_denied(fn()=>$first->start_run($w,'foreign-enrollment','scheduled',$c['id']));
 $later=new TickerbotStore($db,$owner,wp_generate_uuid4(),$scope,$now->modify('+3 hours'));$due=$later->cadence_tick($other,$c['id']);equal($due['state'],'due');equal($later->start_run($other,$due['key'],'scheduled',$c['id'])['state'],'running');equal($db->object('tickerbot_runs',$w,$run['run_id'])['state'],'running');
});

test('Tickerbot cadence expiry audit failure rolls back pointer due time and outcome while preserving counts',function()use($db,$wpdb,$owner){
 [$s,$w,$scope,$now,$run]=tbw_context();$c=$s->save_cadence($w,true,0);$before=$db->row('SELECT next_due_at FROM '.$db->table('tickerbot_settings').' WHERE workspace_id=%d',[$w]);$late=new TickerbotStore($db,$owner,wp_generate_uuid4(),$scope,$now->modify('+6 minutes'));
 $trigger='fixture_tb_expire_'.bin2hex(random_bytes(4));$db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW BEGIN IF NEW.action = 'tickerbot.run.expire' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic'; END IF; END");$prior=$wpdb->suppress_errors(true);
 try{try{$late->cadence_tick($w,$c['id']);throw new LogicException('Expiry audit failure accepted');}catch(RuntimeException $error){}}
 finally{$db->query('DROP TRIGGER '.$trigger);$wpdb->suppress_errors($prior);}
 equal($db->object('tickerbot_runs',$w,$run['run_id'])['state'],'running');equal($db->row('SELECT next_due_at FROM '.$db->table('tickerbot_settings').' WHERE workspace_id=%d',[$w]),$before);equal($late->cadence_tick($w,$c['id'])['state'],'expired');
});


test('Tickerbot opt-out fences already prepared scheduled batches and new review waits for old lease expiry',function()use($db,$owner){
 [$s,$w,$scope,$now,$manual]=tbw_context();$s->finish_run($w,$manual['run_id'],$manual['lease_token'],'failed');$c=$s->save_cadence($w,true,0);$db->query('UPDATE '.$db->table('tickerbot_settings').' SET next_due_at=%s WHERE workspace_id=%d',[$now->format('Y-m-d H:i:s'),$w]);
 $due=$s->cadence_tick($w,$c['id']);$run=$s->start_run($w,$due['key'],'scheduled',$c['id']);$s->prepare_dispatch($w,$run['run_id'],$run['lease_token']);$next=$s->next_dispatch($w,$run['run_id'],$run['lease_token']);
 $s->save_cadence($w,false,1);tbs_denied(fn()=>$s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'quotes',$next['symbols'],$next['batch_id']));equal($s->usage($w)['attempts'],0);
 $new=$s->save_cadence($w,true,2);equal($s->cadence_tick($w,$new['id'])['state'],'in_flight');$later=new TickerbotStore($db,$owner,wp_generate_uuid4(),$scope,$now->modify('+6 minutes'));equal($later->cadence_tick($w,$new['id'])['state'],'expired');equal($later->cadence_tick($w,$new['id'])['state'],'not_due');
});

test('Tickerbot scheduler honors shared pauses and exhausted budget before creating another run',function()use($db){
 foreach(['monthly_quota','completed'] as $state){[$s,$w]=tbs_context(1);$run=$s->start_run($w,'pause');$a=$s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'connection');$s->finish_attempt($w,$run['run_id'],$run['lease_token'],$a['attempt_id'],$state);$s->finish_run($w,$run['run_id'],$run['lease_token'],'failed');$c=$s->save_cadence($w,true,0);equal($s->cadence_tick($w,$c['id'])['state'],$state==='completed'?'budget_blocked':'paused');equal(count($db->rows('SELECT id FROM '.$db->table('tickerbot_runs').' WHERE workspace_id=%d',[$w])),1);}
});

test('Tickerbot recurring hook recovers lost queued batches without page visits and honors opt-out and deactivation',function()use($db,$owner){
 define('TGIT_TICKERBOT_SCHEDULER_ENABLED',true);
 // Reuse the trusted synthetic server account while leaving prior revoked fixture rows isolated.
 $w=(int)(new \GainerInteractive\IGTradingJournal\Application\Tracker($db,$owner,wp_generate_uuid4()))->create_workspace(['name'=>'Recurring hook fixture'])['id'];$s=new TickerbotStore($db,$owner,wp_generate_uuid4(),TGIT_TICKERBOT_ACCOUNT_SCOPE);$s->configure($w);
 $asset=tbi_asset($w,'S0');$s->save_mapping($w,$asset,'S0',tb_row('S0'));$tracker=new \GainerInteractive\IGTradingJournal\Application\Tracker($db,$owner,wp_generate_uuid4());$list=(int)$tracker->create_watchlist($w,['name'=>'Recurring idea'],'recurring-list')['id'];$tracker->add_watchlist_item($w,$list,['asset_id'=>$asset,'status'=>'watch'],'recurring-item');$s->select_scope($w,[$list],[],0);$c=$s->save_cadence($w,true,0);
 // Reviewed active policy fixture; public switching readiness is covered separately.
 $account=$db->row('SELECT account_id FROM '.$db->table('tickerbot_settings').' WHERE workspace_id=%d',[$w]);$db->insert('pricing_policies',['workspace_id'=>$w,'revision'=>1,'source'=>'tickerbot','previous_source'=>'manual','account_id'=>$account['account_id'],'settings_revision'=>1,'scope_revision'=>1,'actor_id'=>$owner,'created_at'=>gmdate('Y-m-d H:i:s')]);
 $db->query('UPDATE '.$db->table('tickerbot_settings').' SET next_due_at=%s WHERE workspace_id=%d',['2026-01-01 00:00:00',$w]);$calls=0;
 $fixture=function($preempt,$options,$url)use(&$calls){if(!str_starts_with($url,'https://api.tickerbot.io/v2/'))return new WP_Error('fixture_blocked','Unexpected fixture URL');$calls++;return tbw_response($url);};add_filter('pre_http_request',$fixture,10,3);
 try{
  equal(RecurringTickerbot::queue($w),true);$event=wp_get_scheduled_event('tgit_tickerbot_schedule_tick',[$w]);equal($event->args,[$w]);equal($event->interval,60);wp_set_current_user(0);
  do_action('tgit_tickerbot_schedule_tick',$w);$active=$db->row('SELECT id FROM '.$db->table('tickerbot_runs').' WHERE workspace_id=%d ORDER BY id DESC LIMIT 1',[$w]);$id=(int)$active['id'];equal($calls,0);equal((bool)wp_next_scheduled('tgit_tickerbot_batch',[$w,$id]),true);
  wp_clear_scheduled_hook('tgit_tickerbot_batch',[$w,$id]);do_action('tgit_tickerbot_schedule_tick',$w);equal((bool)wp_next_scheduled('tgit_tickerbot_batch',[$w,$id]),true);do_action('tgit_tickerbot_batch',$w,$id);equal($calls,1);equal($s->latest_quote($w,$asset)['provider'],'tickerbot');do_action('tgit_tickerbot_schedule_tick',$w);equal($calls,1);
  $s->save_cadence($w,false,1);equal(RecurringTickerbot::queue($w),false);equal(wp_next_scheduled('tgit_tickerbot_schedule_tick',[$w]),false);do_action('tgit_tickerbot_schedule_tick',$w);equal($calls,1);$s->save_cadence($w,true,2);equal(RecurringTickerbot::queue($w),true);QuoteRefresh::deactivate();equal(wp_next_scheduled('tgit_tickerbot_schedule_tick',[$w]),false);
 }finally{wp_set_current_user($owner);remove_filter('pre_http_request',$fixture,10);wp_clear_scheduled_hook('tgit_tickerbot_batch',[$w,$id??0]);wp_clear_scheduled_hook('tgit_tickerbot_schedule_tick',[$w]);}
 update_option('tgit_schema_version','21');Installer::install();equal(get_option('tgit_schema_version'),Installer::VERSION);Installer::install();equal((int)$s->cadence($w)['revision'],3);
});
