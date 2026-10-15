<?php
/** Read-only setup evidence on the disposable site. */
declare(strict_types=1);
if(!defined('TGIT_DISPOSABLE_TEST_SITE')||TGIT_DISPOSABLE_TEST_SITE!==true)throw new RuntimeException('Disposable site required.');

test('Tickerbot readiness reads never enroll an unconfigured workspace or expose account scope',function()use($tracker,$db,$owner,$viewer){
 $w=(int)$tracker->create_workspace(['name'=>'Unconfigured readiness'])['id'];$scope='unconfigured-'.wp_generate_uuid4();$s=new \GainerInteractive\IGTradingJournal\Application\TickerbotStore($db,$owner,wp_generate_uuid4(),$scope);
 equal($s->readiness($w),['enrolled'=>false]);equal($db->row('SELECT id FROM '.$db->table('tickerbot_accounts').' WHERE scope_digest=%s',[hash('sha256','tickerbot/account/'.$scope)]),null);
 $foreign=new \GainerInteractive\IGTradingJournal\Application\TickerbotStore($db,$viewer,wp_generate_uuid4(),$scope);tbs_denied(fn()=>$foreign->readiness($w));
});

test('Tickerbot readiness distinguishes reviewed stale and off enrollment without rewriting evidence',function()use($db){
 [$s,$w]=tbs_context();tbp_seed($s,$w);equal($s->readiness($w)['recurring_review'],'off');$s->save_cadence($w,true,0);$before=$s->usage($w);equal($s->readiness($w)['recurring_review'],'reviewed');equal($s->readiness($w)['latest_run'],null);
 $run=$s->start_run($w,'readiness-run');$a=$s->reserve_attempt($w,$run['run_id'],$run['lease_token'],'connection');$s->finish_attempt($w,$run['run_id'],$run['lease_token'],$a['attempt_id'],'completed');$s->finish_run($w,$run['run_id'],$run['lease_token'],'completed');$snapshot=$s->usage($w);$read=$s->readiness($w);equal($read['attempts'],1);equal($read['latest_run']['state'],'completed');equal(array_keys($read['latest_run']),['state','cause','started_at','completed_at']);equal($s->usage($w),$snapshot);
 $s->configure($w);equal($s->readiness($w)['recurring_review'],'needs_review');$s->save_cadence($w,false,1);equal($s->readiness($w)['recurring_review'],'off');
});

test('Tickerbot readiness isolates run details while allowing only scalar shared budget totals',function()use($db,$tracker,$owner){
 [$s,$w,$scope,$now]=tbs_context();$other=(int)$tracker->create_workspace(['name'=>'Other readiness'])['id'];$s->configure($other);$run=$s->start_run($other,'foreign-readiness');$s->finish_run($other,$run['run_id'],$run['lease_token'],'failed');equal($s->readiness($w)['latest_run'],null);
 $wrong=new \GainerInteractive\IGTradingJournal\Application\TickerbotStore($db,$owner,wp_generate_uuid4(),'different-'.wp_generate_uuid4(),$now);equal($wrong->readiness($w),['enrolled'=>false]);
});

test('Tickerbot status route is owner-only private and sends no external request',function()use($tracker,$db,$owner,$viewer){
 $w=(int)$tracker->create_workspace(['name'=>'Readiness route'])['id'];$request=new WP_REST_Request('GET','/tgit/v1/workspaces/'.$w.'/tickerbot/status');$request->set_url_params(['workspace'=>(string)$w]);$tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'active']);$calls=0;$fixture=function()use(&$calls){$calls++;return new WP_Error('fixture_blocked','No external request allowed');};add_filter('pre_http_request',$fixture,1);
 try{
  wp_set_current_user($owner);$response=\GainerInteractive\IGTradingJournal\Http\Controller::dispatch($request,'tickerbot_status');equal($response instanceof WP_REST_Response,true);$data=$response->get_data()['data'];equal($data['access_verified'],false);equal(array_keys($data),['credential_configured','account_scope_configured','transport_enabled','worker_enabled','scheduler_enabled','access_verified','saved']);equal($calls,0);
  wp_set_current_user($viewer);equal(is_wp_error(\GainerInteractive\IGTradingJournal\Http\Controller::dispatch($request,'tickerbot_status')),true);wp_set_current_user(0);equal(is_wp_error(\GainerInteractive\IGTradingJournal\Http\Controller::permission($request)),true);
 }finally{wp_set_current_user($owner);remove_filter('pre_http_request',$fixture,1);}
});
