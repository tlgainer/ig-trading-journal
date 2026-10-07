<?php
/** Explicit recurring enrollment with synthetic intercepted provider responses. */
use GainerInteractive\IGTradingJournal\Infrastructure\RecurringFundamentals;
use GainerInteractive\IGTradingJournal\Infrastructure\Installer;
use GainerInteractive\IGTradingJournal\Infrastructure\QuoteRefresh;
use GainerInteractive\IGTradingJournal\Application\MarketData;
use GainerInteractive\IGTradingJournal\Domain\FundamentalSchedule;
if (!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE !== true) throw new RuntimeException('Disposable site required.');

test('Schema 12 repairs weekly enrollment from 11 and preserves saved evidence', function () use ($db) {
 [$s,$w,$asset,$mapping,$ids]=saved_metric_context(); $before=$s->fundamentals($w,$asset);
 $db->query('DROP TABLE '.$db->table('fundamental_schedules')); update_option('tgit_schema_version','11'); Installer::install(); equal(get_option('tgit_schema_version'),Installer::VERSION); Installer::install(); equal($s->fundamentals($w,$asset),$before);
 equal($db->row('SHOW TABLE STATUS LIKE %s',[$db->table('fundamental_schedules')])['Engine'],'InnoDB');
});

test('Weekly enrollment is owner-only, dataset-scoped and append-only with expected revisions', function () use ($owner,$viewer,$tracker,$db) {
 wp_set_current_user($owner); [$s,$w,$asset,$mapping,$input]=provider_context(); $path='workspaces/'.$w.'/provider-mappings/'.$mapping.'/fundamentals/schedule';
 $body=['dataset'=>'OVERVIEW','frequency'=>'weekly','weekday'=>2,'expected_schedule_id'=>0];
 $response=provider_rest('POST',$path,$body); equal($response->get_status(),200); $first=$response->get_data()['data']; equal($first['queued'],true);
 equal(provider_rest('POST',$path,$body)->get_status(),409);
 $other=$s->save_fundamental_schedule($w,$mapping,array_replace($body,['dataset'=>'CASH_FLOW'])); equal($other['dataset'],'CASH_FLOW');
 $off=$s->save_fundamental_schedule($w,$mapping,array_replace($body,['frequency'=>'off','expected_schedule_id'=>(int)$first['id']])); equal($off['frequency'],'off'); equal($db->object('fundamental_schedules',$w,(int)$first['id'])['frequency'],'weekly');
 equal(count(provider_rest('GET',$path)->get_data()['data']['items']),2);
 foreach([[],array_replace($body,['weekday'=>'2']),array_replace($body,['weekday'=>6]),array_replace($body,['frequency'=>'daily']),array_replace($body,['dataset'=>'quote'])] as $bad) equal(provider_rest('POST',$path,$bad)->get_status(),400);
 [$foreign,$w2,$asset2,$mapping2]=provider_context(); equal(provider_rest('GET','workspaces/'.$w.'/provider-mappings/'.$mapping2.'/fundamentals/schedule')->get_status(),404);
 $fmp=$s->save_mapping($w,$asset,array_replace($input,['provider'=>'fmp'])); rejects(fn()=>$s->save_fundamental_schedule($w,(int)$fmp['id'],$body));
 foreach(['manager','viewer'] as $role) { $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>$role,'state'=>'active']); wp_set_current_user($viewer); equal(provider_rest('GET',$path)->get_status(),403); equal(provider_rest('POST',$path,$body)->get_status(),403); wp_set_current_user($owner); }
});

test('Weekly jobs deduplicate, reuse completed slots, skip catch-up and stop after disable', function () use ($owner,$db) {
 [$s,$w,$asset,$mapping]=provider_context(); $body=['dataset'=>'OVERVIEW','frequency'=>'weekly','weekday'=>3,'expected_schedule_id'=>0]; $row=$s->save_fundamental_schedule($w,$mapping,$body); $id=(int)$row['id'];
 equal(RecurringFundamentals::queue($w,$id),true); equal(RecurringFundamentals::queue($w,$id),true);
 $at=FundamentalSchedule::next(new DateTimeImmutable('now',new DateTimeZone('UTC')),'weekly',3); equal(wp_next_scheduled('tgit_scheduled_fundamental_refresh',[$w,$id,$at]),$at);
 $slot=time()-30; fundamental_http_mock(provider_http_response(fundamental_overview()),'OVERVIEW',fn()=>RecurringFundamentals::job($w,$id,$slot),$calls); equal($calls,1);
 fundamental_http_mock(new WP_Error('fixture','No resend'),'OVERVIEW',fn()=>RecurringFundamentals::job($w,$id,$slot),$calls); equal($calls,0); equal(count($s->fundamentals($w,$asset)),1);
 fundamental_http_mock(new WP_Error('fixture','No catchup'),'OVERVIEW',fn()=>RecurringFundamentals::job($w,$id,time()-7201),$calls); equal($calls,0);
 fundamental_http_mock(new WP_Error('fixture','No future send'),'OVERVIEW',fn()=>RecurringFundamentals::job($w,$id,time()+3600),$calls); equal($calls,0);
 $s->save_fundamental_schedule($w,$mapping,array_replace($body,['frequency'=>'off','expected_schedule_id'=>$id])); equal(RecurringFundamentals::queue($w,$id),false);
 fundamental_http_mock(new WP_Error('fixture','No disabled send'),'OVERVIEW',fn()=>RecurringFundamentals::job($w,$id,time()-20),$calls); equal($calls,0);
 equal($db->rows('SELECT * FROM '.$db->table('transactions').' WHERE workspace_id = %d',[$w]),[]);
});

test('Weekly scan recovers queueing, preserves enrollment on deactivation and rejects revoked owners', function () use ($owner,$viewer,$tracker,$db) {
 [$s,$w,$asset,$mapping,$input]=provider_context(); $body=['dataset'=>'INCOME_STATEMENT','frequency'=>'weekly','weekday'=>4,'expected_schedule_id'=>0]; $row=$s->save_fundamental_schedule($w,$mapping,$body); $id=(int)$row['id'];
 RecurringFundamentals::boot(); equal((bool)wp_next_scheduled('tgit_fundamental_schedule_scan'),true); RecurringFundamentals::scan();
 $at=FundamentalSchedule::next(new DateTimeImmutable('now',new DateTimeZone('UTC')),'weekly',4); equal(wp_next_scheduled('tgit_scheduled_fundamental_refresh',[$w,$id,$at]),$at);
 QuoteRefresh::deactivate(); equal(wp_next_scheduled('tgit_fundamental_schedule_scan'),false); equal(wp_next_scheduled('tgit_scheduled_fundamental_refresh',[$w,$id,$at]),false); equal($db->object('fundamental_schedules',$w,$id),$row);
 RecurringFundamentals::scan(); equal(wp_next_scheduled('tgit_scheduled_fundamental_refresh',[$w,$id,$at]),$at);
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'owner','state'=>'active']); $tracker->set_member($w,['wp_user_id'=>$owner,'role'=>'owner','state'=>'revoked']); equal(RecurringFundamentals::queue($w,$id),false);
 fundamental_http_mock(new WP_Error('fixture','No revoked send'),'INCOME_STATEMENT',fn()=>RecurringFundamentals::job($w,$id,time()-10),$calls); equal($calls,0);
 [$s2,$w2,$asset2,$mapping2,$input2]=provider_context(); $new=$s2->save_fundamental_schedule($w2,$mapping2,$body); $s2->save_mapping($w2,$asset2,array_replace($input2,['expected_mapping_id'=>$mapping2,'enabled'=>false])); equal(RecurringFundamentals::queue($w2,(int)$new['id']),false);
});

test('Weekly enrollment audit failure rolls back revisions and preserves retry expectation', function () use ($db) {
 global $wpdb; [$s,$w,$asset,$mapping]=provider_context(); $body=['dataset'=>'CASH_FLOW','frequency'=>'weekly','weekday'=>5,'expected_schedule_id'=>0]; $trigger=$wpdb->prefix.'fundamental_schedule_fault';
 $db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture fault'");
 try { try { $s->save_fundamental_schedule($w,$mapping,$body); throw new LogicException('Audit fault accepted'); } catch(RuntimeException $error) {} } finally { $db->query('DROP TRIGGER '.$trigger); }
 equal($s->fundamental_schedule_config($w,$mapping,'CASH_FLOW'),null); equal($s->save_fundamental_schedule($w,$mapping,$body)['frequency'],'weekly');
});
