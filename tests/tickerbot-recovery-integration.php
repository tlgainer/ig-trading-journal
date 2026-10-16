<?php
/** Synthetic shared pause recovery; no provider calls. */
declare(strict_types=1);
if(!defined('TGIT_DISPOSABLE_TEST_SITE')||TGIT_DISPOSABLE_TEST_SITE!==true)throw new RuntimeException('Disposable site required.');

function tbr_pause($s,int $w,string $kind='authentication'):array {
 $r=$s->start_run($w,wp_generate_uuid4());$a=$s->reserve_attempt($w,$r['run_id'],$r['lease_token'],'connection');$s->finish_attempt($w,$r['run_id'],$r['lease_token'],$a['attempt_id'],$kind,120);$s->finish_run($w,$r['run_id'],$r['lease_token'],'failed');return $r;
}
test('Tickerbot reviewed recovery preserves counts policy due time and schedules',function()use($db){
 [$s,$w]=tbs_context();tbr_pause($s,$w);$before=$s->readiness($w);equal($before['recovery_state'],'ready');tbs_denied(fn()=>$s->recover_pause($w,1,false));equal($s->recover_pause($w,1,true)['pause_revision'],2);$after=$s->readiness($w);equal($after['recovery_state'],'not_paused');equal($s->usage($w)['attempts'],1);equal($after['next_due_at'],$before['next_due_at']);equal($s->cadence($w),null);equal(count($db->rows('SELECT id FROM '.$db->table('tickerbot_pause_recoveries').' WHERE workspace_id=%d',[$w])),1);tbs_denied(fn()=>$s->recover_pause($w,2,true));
});
test('Tickerbot same-time pause revisions fence stale review and retain longer waits',function()use($db,$owner){
 foreach([['authentication','rate_limit'],['rate_limit','authentication']]as[$first,$second]){
 [$s,$w,$scope,$now]=tbs_context();$r=$s->start_run($w,'two');$a=$s->reserve_attempt($w,$r['run_id'],$r['lease_token'],'connection');$b=$s->reserve_attempt($w,$r['run_id'],$r['lease_token'],'connection');$s->finish_attempt($w,$r['run_id'],$r['lease_token'],$a['attempt_id'],$first,120);$until=$s->usage($w)['paused_until'];$s->finish_attempt($w,$r['run_id'],$r['lease_token'],$b['attempt_id'],$second,120);$s->finish_run($w,$r['run_id'],$r['lease_token'],'failed');equal($s->usage($w)['paused_until'],'9999-12-31 23:59:59');equal($s->readiness($w)['pause_kind'],'authentication');$conflict=false;try{$s->recover_pause($w,1,true);}catch(UnexpectedValueException $e){$conflict=true;}equal($conflict,true);tbs_denied(fn()=>$s->recover_pause($w,2,true));$later=new \GainerInteractive\IGTradingJournal\Application\TickerbotStore($db,$owner,wp_generate_uuid4(),$scope,$now->modify('+121 seconds'));$later->recover_pause($w,2,true);equal($later->usage($w)['attempts'],2);}
});
test('Tickerbot recovery cannot bypass provider waits or an active lease',function()use($db,$owner){
 foreach(['rate_limit','monthly_quota']as$kind){[$s,$w,$scope,$now]=tbs_context();tbr_pause($s,$w,$kind);equal($s->readiness($w)['recovery_state'],'wait_for_provider');tbs_denied(fn()=>$s->recover_pause($w,1,true));if($kind==='rate_limit'){$later=new \GainerInteractive\IGTradingJournal\Application\TickerbotStore($db,$owner,wp_generate_uuid4(),$scope,$now->modify('+121 seconds'));equal($later->recover_pause($w,1,true)['state'],'recovered');equal($later->usage($w)['attempts'],1);}}
 [$s,$w]=tbs_context();$r=$s->start_run($w,'active');$a=$s->reserve_attempt($w,$r['run_id'],$r['lease_token'],'connection');$s->finish_attempt($w,$r['run_id'],$r['lease_token'],$a['attempt_id'],'capability');equal($s->readiness($w)['recovery_state'],'active_operation');tbs_denied(fn()=>$s->recover_pause($w,1,true));$s->finish_run($w,$r['run_id'],$r['lease_token'],'failed');equal($s->recover_pause($w,1,true)['state'],'recovered');
});
test('Tickerbot recovery requires controlling owner and retains zero budget',function()use($db,$tracker,$owner,$viewer){
 [$s,$w,$scope,$now]=tbs_context();tbr_pause($s,$w);$other=(int)$tracker->create_workspace(['name'=>'Other controller'])['id'];$s->configure($other,8000);equal($s->readiness($other)['recovery_state'],'controller_only');tbs_denied(fn()=>$s->recover_pause($other,1,true));$untrusted=new \GainerInteractive\IGTradingJournal\Application\TickerbotStore($db,$viewer,wp_generate_uuid4(),$scope,$now);tbs_denied(fn()=>$untrusted->recover_pause($w,1,true));$s->configure($w,0);$s->recover_pause($w,1,true);equal($s->usage($w)['attempts'],1);equal($s->readiness($w)['soft_cap'],0);
});
test('Tickerbot recovery audit failure rolls back evidence and pause clearing together',function()use($db,$wpdb){
 [$s,$w]=tbs_context();tbr_pause($s,$w);$before=$s->readiness($w);$trigger='fixture_tb_recover_'.bin2hex(random_bytes(4));$db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW BEGIN IF NEW.action = 'tickerbot.pause.recover' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic'; END IF; END");$prior=$wpdb->suppress_errors(true);try{$failed=false;try{$s->recover_pause($w,1,true);}catch(RuntimeException $e){$failed=true;}equal($failed,true);}finally{$db->query('DROP TRIGGER '.$trigger);$wpdb->suppress_errors($prior);}equal($s->readiness($w),$before);equal(count($db->rows('SELECT id FROM '.$db->table('tickerbot_pause_recoveries').' WHERE workspace_id=%d',[$w])),0);equal($s->usage($w)['attempts'],1);
});
test('Tickerbot schema 27 preserves legacy pauses without fabricating recovery reasons',function()use($db){
 [$s,$w,$scope]=tbs_context();tbr_pause($s,$w);$db->query('UPDATE '.$db->table('tickerbot_accounts').' SET pause_kind=NULL,pause_revision=0 WHERE scope_digest=%s',[hash('sha256','tickerbot/account/'.$scope)]);update_option('tgit_schema_version','26');\GainerInteractive\IGTradingJournal\Infrastructure\Installer::install();\GainerInteractive\IGTradingJournal\Infrastructure\Installer::install();equal(get_option('tgit_schema_version'),\GainerInteractive\IGTradingJournal\Infrastructure\Installer::VERSION);equal($s->readiness($w)['recovery_state'],'legacy_review');tbs_denied(fn()=>$s->recover_pause($w,0,true));equal($s->usage($w)['attempts'],1);
});

test('Tickerbot recovery route rejects forged fields invalid types and missing acknowledgment',function()use($owner){
 [$s,$w]=tbs_context();tbr_pause($s,$w);wp_set_current_user($owner);
 foreach([['expected_revision'=>1,'acknowledged'=>true,'clear_pause'=>true],['expected_revision'=>'1','acknowledged'=>true],['expected_revision'=>1,'acknowledged'=>'true'],['expected_revision'=>1,'acknowledged'=>false],['acknowledged'=>true]]as$body){$request=new WP_REST_Request('POST','/tgit/v1/workspaces/'.$w.'/tickerbot/pause-recovery');$request->set_url_params(['workspace'=>(string)$w]);$request->set_header('Content-Type','application/json');$request->set_body(json_encode($body));equal(is_wp_error(\GainerInteractive\IGTradingJournal\Http\Controller::dispatch($request,'tickerbot_recover_pause')),true);}
 equal($s->readiness($w)['pause_revision'],1);equal($s->usage($w)['attempts'],1);
});
