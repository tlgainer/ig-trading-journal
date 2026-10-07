<?php
/** Synthetic summary projection; no accounts, credentials or network calls. */
declare(strict_types=1);
require_once __DIR__.'/../src/Domain/AiEvidence.php';
use GainerInteractive\IGTradingJournal\Domain\AiEvidence;
use GainerInteractive\IGTradingJournal\Domain\FundamentalMetrics;
function ai_evidence_metrics(array $evidence=[]): array {
 $metrics=FundamentalMetrics::calculate($evidence?:metric_evidence());
 $metrics['sources']=[]; foreach(array_keys($evidence?:metric_evidence()) as $i=>$dataset) $metrics['sources'][$dataset]=['snapshot_id'=>$i+1,'fingerprint'=>str_repeat('a',64),'retrieved_at'=>'2026-10-06 12:00:00'];
 return $metrics;
}
function ai_evidence_asset(): array { return ['symbol'=>'FIXTURE','exchange'=>'TESTEX','quote_currency'=>'USD','account'=>'PRIVATE-EXCLUDED']; }

test('AI evidence preserves exact losses and unavailable metrics, excluding unrelated data',function() {
 $metrics=ai_evidence_metrics(); $metrics['secret']='EXCLUDED'; $thesis=['trade_id'=>7,'revision'=>2,'text'=>'A selected idea','notes'=>'EXCLUDED'];
 $result=AiEvidence::build(ai_evidence_asset(),$metrics,$thesis); $bundle=$result['bundle'];
 decimal($bundle['reports'][0]['metrics']['net_margin_percent']['value'],'-5'); equal($bundle['reports'][0]['metrics']['free_cash_flow']['value'],null); equal($bundle['reports'][0]['metrics']['free_cash_flow']['status'],'unknown_capex_convention');
 equal($bundle['thesis'],['trade_id'=>7,'revision'=>2,'text'=>'A selected idea']); equal(str_contains(json_encode($bundle),'EXCLUDED'),false);
 equal(AiEvidence::build(ai_evidence_asset(),array_replace($metrics,['sources'=>array_reverse($metrics['sources'],true)]),$thesis),$result);
 equal(AiEvidence::build(ai_evidence_asset(),$metrics)['bundle']['thesis'],null);
 equal(strlen($result['fingerprint']),64);
 equal(AiEvidence::build(ai_evidence_asset(),$metrics,array_replace($thesis,['text'=>'Changed idea']))['fingerprint']!==$result['fingerprint'],true);
});

test('AI evidence bounds reporting history by distinct dates while retaining ambiguous currencies',function() {
 $metrics=ai_evidence_metrics(); $base=$metrics['reports'][0]; $metrics['reports']=[];
 foreach(range(2020,2025) as $year) { $metrics['reports'][]=array_replace($base,['fiscal_date_ending'=>$year.'-12-31']); $metrics['reports'][]=array_replace($base,['period_type'=>'quarterly','fiscal_date_ending'=>$year.'-12-31']); }
 $result=AiEvidence::build(ai_evidence_asset(),$metrics); equal(count($result['bundle']['reports']),6); equal($result['bundle']['omitted_reports'],6);
 equal(AiEvidence::build(ai_evidence_asset(),array_replace($metrics,['reports'=>array_reverse($metrics['reports'])])),$result);
 $metrics['reports'][]=array_replace($base,['reported_currency'=>'EUR']); $result=AiEvidence::build(ai_evidence_asset(),$metrics); equal(count($result['bundle']['reports']),7);
});

test('AI evidence rejects malformed provenance, values, versions and oversized or invalid thesis text',function() {
 $metrics=ai_evidence_metrics();
 foreach(['formula_version'=>'unknown','reports'=>[],'sources'=>[]] as $field=>$value) rejects(fn()=>AiEvidence::build(ai_evidence_asset(),array_replace($metrics,[$field=>$value])));
 $bad=$metrics; $bad['sources']['CASH_FLOW']['retrieved_at']='2026-02-30 12:00:00'; rejects(fn()=>AiEvidence::build(ai_evidence_asset(),$bad));
 $bad=$metrics; $bad['reports'][0]['metrics']['net_margin_percent']['value']=-5; rejects(fn()=>AiEvidence::build(ai_evidence_asset(),$bad));
 $bad=$metrics; $bad['reports'][]=$bad['reports'][0]; rejects(fn()=>AiEvidence::build(ai_evidence_asset(),$bad));
 foreach([str_repeat('x',8001),"invalid\x00text","\xff"] as $text) rejects(fn()=>AiEvidence::build(ai_evidence_asset(),$metrics,['trade_id'=>1,'revision'=>1,'text'=>$text]));
 rejects(fn()=>AiEvidence::build(ai_evidence_asset(),$metrics,['trade_id'=>1,'revision'=>0,'text'=>'idea']));
});
