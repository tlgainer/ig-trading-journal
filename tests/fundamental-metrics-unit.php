<?php
/** Exact statement metric fixtures; no external services or portfolio data. */
declare(strict_types=1);
require_once __DIR__ . '/../src/Domain/FundamentalMetrics.php';
use GainerInteractive\IGTradingJournal\Domain\FundamentalMetrics;

function metric_evidence(array $changes = []): array {
 $facts=['INCOME_STATEMENT'=>['totalRevenue'=>'1000','grossProfit'=>'400','operatingIncome'=>'150','netIncome'=>'-50'], 'BALANCE_SHEET'=>['totalCurrentAssets'=>'250','totalCurrentLiabilities'=>'100','totalAssets'=>'800','totalLiabilities'=>'400','shortTermDebt'=>'10','longTermDebt'=>'90','cashAndCashEquivalentsAtCarryingValue'=>'150'], 'CASH_FLOW'=>['operatingCashflow'=>'120','capitalExpenditures'=>'20']];
 $result=[]; foreach($facts as $dataset=>$values) $result[$dataset]=['provider'=>'alpha_vantage','provider_symbol'=>'FIXTURE','dataset'=>$dataset,'reports'=>[['period_type'=>'annual','fiscal_date_ending'=>'2025-12-31','reported_currency'=>'USD','values'=>array_replace($values,$changes[$dataset]??[])]]];
 return $result;
}
function metric_report(array $evidence, ?string $sign='positive_outflow'): array { return FundamentalMetrics::calculate($evidence,$sign)['reports'][0]['metrics']; }

test('Fundamental metrics preserve exact margins, losses, liquidity and reported debt coverage', function () {
 $metrics=metric_report(metric_evidence());
 foreach(['gross_margin_percent'=>'40','operating_margin_percent'=>'15','net_margin_percent'=>'-5','current_ratio'=>'2.5','liabilities_to_assets'=>'0.5','total_reported_debt'=>'100','net_reported_debt'=>'-50','free_cash_flow'=>'100'] as $name=>$value) { decimal($metrics[$name]['value'],$value); equal($metrics[$name]['status'],'complete'); }
 equal($metrics['net_margin_percent']['unit'],'percent'); equal($metrics['current_ratio']['unit'],'multiple'); equal($metrics['free_cash_flow']['unit'],'currency');
});

test('Free cash flow requires verified expense signs and retains negative operating cash', function () {
 equal(metric_report(metric_evidence(),null)['free_cash_flow']['status'],'unknown_capex_convention');
 decimal(metric_report(metric_evidence(['CASH_FLOW'=>['capitalExpenditures'=>'-20']]),'negative_outflow')['free_cash_flow']['value'],'100');
 equal(metric_report(metric_evidence(['CASH_FLOW'=>['capitalExpenditures'=>'-20']]))['free_cash_flow']['status'],'incompatible_sign');
 equal(metric_report(metric_evidence(),'negative_outflow')['free_cash_flow']['status'],'incompatible_sign');
 decimal(metric_report(metric_evidence(['CASH_FLOW'=>['operatingCashflow'=>'-120']]))['free_cash_flow']['value'],'-140');
 decimal(metric_report(metric_evidence(['CASH_FLOW'=>['capitalExpenditures'=>'0']]))['free_cash_flow']['value'],'120');
});

test('Missing inputs and nonpositive denominators never become fabricated zero metrics', function () {
 $metrics=metric_report(metric_evidence(['INCOME_STATEMENT'=>['totalRevenue'=>'0'], 'BALANCE_SHEET'=>['shortTermDebt'=>null,'totalCurrentLiabilities'=>'0']]));
 equal($metrics['gross_margin_percent']['value'],null); equal($metrics['gross_margin_percent']['status'],'nonpositive_denominator'); equal($metrics['current_ratio']['value'],null); equal($metrics['total_reported_debt']['value'],null); equal($metrics['net_reported_debt']['value'],null);
 $evidence=metric_evidence(); unset($evidence['CASH_FLOW'],$evidence['BALANCE_SHEET']); $metrics=metric_report($evidence); decimal($metrics['net_margin_percent']['value'],'-5'); equal($metrics['current_ratio']['status'],'missing_input'); equal($metrics['free_cash_flow']['status'],'missing_input');
 decimal(metric_report(metric_evidence(['INCOME_STATEMENT'=>['netIncome'=>'0']]))['net_margin_percent']['value'],'0');
});

test('Statement periods and currencies stay separate even when fiscal dates match', function () {
 $evidence=metric_evidence(); $evidence['BALANCE_SHEET']['reports'][0]['reported_currency']='EUR'; $evidence['CASH_FLOW']['reports'][0]['period_type']='quarterly';
 $result=FundamentalMetrics::calculate($evidence,'positive_outflow'); equal(count($result['reports']),3);
 foreach($result['reports'] as $report) {
  if($report['reported_currency']==='EUR') { decimal($report['metrics']['current_ratio']['value'],'2.5'); equal($report['metrics']['net_margin_percent']['value'],null); }
  if($report['period_type']==='quarterly') { decimal($report['metrics']['free_cash_flow']['value'],'100'); equal($report['metrics']['net_margin_percent']['value'],null); }
 }
});

test('Negative balance-sheet inputs block incompatible ratios without hiding negative net cash', function () {
 foreach(['shortTermDebt','longTermDebt','cashAndCashEquivalentsAtCarryingValue','totalCurrentAssets','totalLiabilities'] as $field) {
  $metrics=metric_report(metric_evidence(['BALANCE_SHEET'=>[$field=>'-1']])); $metric=in_array($field,['shortTermDebt','longTermDebt'],true)?'total_reported_debt':($field==='cashAndCashEquivalentsAtCarryingValue'?'net_reported_debt':($field==='totalCurrentAssets'?'current_ratio':'liabilities_to_assets'));
  equal($metrics[$metric]['value'],null); equal($metrics[$metric]['status'],'incompatible_sign');
 }
});

test('Metric arithmetic preserves fractional precision and does not round input facts', function () {
 $metrics=metric_report(metric_evidence(['INCOME_STATEMENT'=>['totalRevenue'=>'0.000000000000000002','grossProfit'=>'0.000000000000000001'], 'CASH_FLOW'=>['operatingCashflow'=>'10000000000000000000.123456789123456789','capitalExpenditures'=>'0.000000000000000001']]));
 decimal($metrics['gross_margin_percent']['value'],'50'); decimal($metrics['free_cash_flow']['value'],'10000000000000000000.123456789123456788');
 equal(FundamentalMetrics::calculate(metric_evidence()),FundamentalMetrics::calculate(array_reverse(metric_evidence(),true)));
});

test('Metric boundary rejects mixed identities, overview facts, malformed values and ambiguous periods', function () {
 $evidence=metric_evidence(); $evidence['CASH_FLOW']['provider_symbol']='OTHER'; rejects(fn()=>FundamentalMetrics::calculate($evidence));
 $evidence=metric_evidence(); $evidence['CASH_FLOW']['provider']='other'; rejects(fn()=>FundamentalMetrics::calculate($evidence));
 rejects(fn()=>FundamentalMetrics::calculate(['OVERVIEW'=>[]])); rejects(fn()=>FundamentalMetrics::calculate(metric_evidence(),'guessed'));
 foreach([1.2,true,'NaN','1e3','--1','0.0000000000000000001'] as $value) rejects(fn()=>metric_report(metric_evidence(['INCOME_STATEMENT'=>['netIncome'=>$value]])));
 foreach(['2026-02-30','not-a-date'] as $date) { $evidence=metric_evidence(); $evidence['CASH_FLOW']['reports'][0]['fiscal_date_ending']=$date; rejects(fn()=>FundamentalMetrics::calculate($evidence)); }
 $evidence=metric_evidence(); $evidence['CASH_FLOW']['reports'][]=$evidence['CASH_FLOW']['reports'][0]; rejects(fn()=>FundamentalMetrics::calculate($evidence));
 equal(FundamentalMetrics::calculate([])['reports'],[]);
});
