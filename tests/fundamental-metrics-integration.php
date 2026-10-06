<?php
/** Snapshot-backed calculations only on the disposable site. */
use GainerInteractive\IGTradingJournal\Application\MarketData;
if (!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE !== true) throw new RuntimeException('Disposable site required.');

function saved_metric_context(): array {
 global $db;
 [$s,$w,$asset,$mapping]=provider_context(); $ids=[];
 foreach(metric_evidence() as $dataset=>$envelope) {
  $report=$envelope['reports'][0]; $body=wp_json_encode(['symbol'=>'FIXTURE','annualReports'=>[array_merge(['fiscalDateEnding'=>$report['fiscal_date_ending'],'reportedCurrency'=>$report['reported_currency']],$report['values'])],'quarterlyReports'=>[]]);
  $r=$s->reserve($w,$mapping,provider_fingerprint('metric-snapshot-'.$w),$dataset,false,$dataset); $s->dispatch($w,$r['id']); $snapshot=$s->complete_fundamentals($w,$r['id'],$body); $ids[$dataset]=(int)$snapshot['id'];
 }
 return [$s,$w,$asset,$mapping,$ids];
}

test('Saved statement metrics retain source fingerprints and never modify evidence or ledger facts', function () use ($db,$tracker,$viewer) {
 [$s,$w,$asset,$mapping,$ids]=saved_metric_context(); $before=$s->fundamentals($w,$asset);
 $result=$s->fundamental_metrics($w,$asset,$ids,'positive_outflow'); $metrics=$result['reports'][0]['metrics']; decimal($metrics['net_margin_percent']['value'],'-5'); decimal($metrics['free_cash_flow']['value'],'100'); equal($result['formula_version'],'fundamental-metrics-1'); equal(count($result['sources']),3);
 foreach($ids as $dataset=>$id) equal($result['sources'][$dataset]['fingerprint'],$db->object('fundamental_snapshots',$w,$id)['evidence_fingerprint']);
 equal($s->fundamental_metrics($w,$asset,array_reverse($ids,true),'positive_outflow'),$result); equal($s->fundamentals($w,$asset),$before); equal($db->rows('SELECT * FROM '.$db->table('transactions').' WHERE workspace_id = %d',[$w]),[]);
 equal($s->fundamental_metrics($w,$asset,$ids)['reports'][0]['metrics']['free_cash_flow']['status'],'unknown_capex_convention');
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'active']); $v=new MarketData($db,$viewer,wp_generate_uuid4()); equal($v->fundamental_metrics($w,$asset,$ids,'positive_outflow'),$result);
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'revoked']); try { $v->fundamental_metrics($w,$asset,$ids); throw new LogicException('Revoked metrics read'); } catch(DomainException $error) {}
});

test('Metric selections reject foreign snapshots, wrong datasets and mapping revisions', function () use ($db) {
 [$s,$w,$asset,$mapping,$ids]=saved_metric_context(); [$other,$foreign,$foreignAsset,$foreignMapping,$foreignIds]=saved_metric_context();
 try { $s->fundamental_metrics($w,$asset,array_replace($ids,['INCOME_STATEMENT'=>$foreignIds['INCOME_STATEMENT']])); throw new LogicException('Foreign snapshot used'); } catch(OutOfBoundsException $error) {}
 provider_conflict(fn()=>$s->fundamental_metrics($w,$asset,['CASH_FLOW'=>$ids['BALANCE_SHEET']]));
 foreach([[],['OVERVIEW'=>$ids['INCOME_STATEMENT']],['INCOME_STATEMENT'=>(string)$ids['INCOME_STATEMENT']],['INCOME_STATEMENT'=>0]] as $selection) rejects(fn()=>$s->fundamental_metrics($w,$asset,$selection));
 $input=['provider'=>'alpha_vantage','provider_symbol'=>'FIXTURE','exchange'=>'TESTEX','currency'=>'USD','enabled'=>true,'evidence'=>'Synthetic reverified identity','expected_mapping_id'=>$mapping]; $new=$s->save_mapping($w,$asset,$input);
 $r=$s->reserve($w,(int)$new['id'],provider_fingerprint('metric-revision-'.$w),'cash-new',false,'CASH_FLOW'); $s->dispatch($w,$r['id']); $snapshot=$s->complete_fundamentals($w,$r['id'],fundamental_statement());
 provider_conflict(fn()=>$s->fundamental_metrics($w,$asset,array_replace($ids,['CASH_FLOW'=>(int)$snapshot['id']])));
 // Saved evidence stays readable after mapping supersession; no current price identity is substituted.
 decimal($s->fundamental_metrics($w,$asset,$ids,'positive_outflow')['reports'][0]['metrics']['free_cash_flow']['value'],'100');
});

test('Damaged snapshot evidence blocks calculation without rewriting its original provenance', function () use ($db) {
 [$s,$w,$asset,$mapping,$ids]=saved_metric_context(); $id=$ids['INCOME_STATEMENT']; $original=$db->object('fundamental_snapshots',$w,$id);
 try { $db->update_object('fundamental_snapshots',$w,$id,['evidence_json'=>'{}']); provider_conflict(fn()=>$s->fundamental_metrics($w,$asset,$ids)); }
 finally { $db->update_object('fundamental_snapshots',$w,$id,['evidence_json'=>$original['evidence_json']]); }
 equal($db->object('fundamental_snapshots',$w,$id),$original);
});
