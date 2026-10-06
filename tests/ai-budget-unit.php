<?php
/** Synthetic prices only; no model access or paid requests. */
declare(strict_types=1);
require_once __DIR__.'/../src/Domain/AiBudget.php';
use GainerInteractive\IGTradingJournal\Domain\AiBudget;

function ai_pricing(array $changes=[]): array {
 return array_replace(['model'=>'fixture-text-model','currency'=>'USD','input_per_million'=>'2.50','cached_input_per_million'=>'0.25','output_per_million'=>'10.00','verified_at'=>'2026-10-01 00:00:00','valid_until'=>'2026-10-20 00:00:00','source'=>'https://developers.openai.com/api/docs/pricing','responses'=>true,'structured_outputs'=>true,'access_confirmed'=>true],$changes);
}
function ai_now(): DateTimeImmutable { return new DateTimeImmutable('2026-10-06 12:00:00',new DateTimeZone('UTC')); }

test('AI text reservation includes full output bounds and conservatively prices input caching',function() {
 equal(AiBudget::estimate(ai_pricing(),10000,2000,ai_now()),'0.045000000000');
 equal(AiBudget::estimate(ai_pricing(['cached_input_per_million'=>'3.00']),10000,2000,ai_now()),'0.050000000000');
 foreach([[0,1],[1,0],[-1,1],[1000001,1],[1,1000001]] as [$input,$output]) rejects(fn()=>AiBudget::estimate(ai_pricing(),$input,$output,ai_now()));
});

test('AI pricing is exact, explicitly verified, dated and capability checked with no model fallback',function() {
 foreach(['model'=>'','currency'=>'EUR','input_per_million'=>'0','output_per_million'=>'0','cached_input_per_million'=>null,'responses'=>false,'structured_outputs'=>false,'access_confirmed'=>false,'source'=>'https://example.com/prices','verified_at'=>'2026-11-01 00:00:00','valid_until'=>'2026-10-06 12:00:00'] as $field=>$value) rejects(fn()=>AiBudget::pricing(ai_pricing([$field=>$value]),ai_now()));
 foreach(['1e3',1.2,'-1','0.0000000000001'] as $value) rejects(fn()=>AiBudget::pricing(ai_pricing(['input_per_million'=>$value]),ai_now()));
 foreach(['2026-02-30 00:00:00','2026-11-15 00:00:00','invalid'] as $date) rejects(fn()=>AiBudget::pricing(ai_pricing(['valid_until'=>$date]),ai_now()));
 $price=ai_pricing(); unset($price['output_per_million']); rejects(fn()=>AiBudget::pricing($price,ai_now()));
 rejects(fn()=>AiBudget::pricing(ai_pricing(['tools'=>'web_search']),ai_now()));
 equal(AiBudget::pricing(ai_pricing(['model'=>'chosen-model-2026-10-01']),ai_now())['model'],'chosen-model-2026-10-01');
});

test('AI charges use reported cached input and total output once, retaining dispatch prices',function() {
 $usage=['input_tokens'=>10000,'cached_input_tokens'=>8000,'output_tokens'=>2000];
 equal(AiBudget::charge(ai_pricing(),$usage,ai_now()),'0.027000000000');
 equal(AiBudget::charge(ai_pricing(),['input_tokens'=>0,'cached_input_tokens'=>0,'output_tokens'=>0],ai_now()),'0.000000000000');
 $later=new DateTimeImmutable('2026-11-01 00:00:00',new DateTimeZone('UTC'));
 rejects(fn()=>AiBudget::pricing(ai_pricing(),$later));
 equal(AiBudget::charge(ai_pricing(),$usage,ai_now()),'0.027000000000');
});

test('AI unknown or malformed usage fails closed instead of refunding uncertain delivery',function() {
 $usage=['input_tokens'=>100,'cached_input_tokens'=>0,'output_tokens'=>20];
 foreach([[],array_diff_key($usage,['cached_input_tokens'=>true]),array_replace($usage,['cached_input_tokens'=>101]),array_replace($usage,['output_tokens'=>-1]),array_replace($usage,['input_tokens'=>'100']),array_replace($usage,['output_tokens'=>1.2]),array_replace($usage,['tool_calls'=>1])] as $bad) rejects(fn()=>AiBudget::charge(ai_pricing(),$bad,ai_now()));
});

test('AI fractional cent costs round upward and never disappear into storage zero',function() {
 $pricing=ai_pricing(['input_per_million'=>'0.000000000001','cached_input_per_million'=>'0','output_per_million'=>'0.000000000001']);
 equal(AiBudget::estimate($pricing,1,1,ai_now()),'0.000000000001');
 equal(AiBudget::charge($pricing,['input_tokens'=>1,'cached_input_tokens'=>0,'output_tokens'=>0],ai_now()),'0.000000000001');
 rejects(fn()=>AiBudget::estimate(ai_pricing(['input_per_million'=>str_repeat('9',26),'output_per_million'=>str_repeat('9',26)]),1000000,1000000,ai_now()));
});

test('AI budget admission honors zero pause, exact remaining funds and lowered caps',function() {
 equal(AiBudget::admission('15.00','5','2','8'),['allowed'=>true,'remaining'=>'8.000000000000','warning'=>'none']);
 equal(AiBudget::admission('15','5','2','8.000000000001')['allowed'],false);
 equal(AiBudget::admission('0','0','0','0.01'),['allowed'=>false,'remaining'=>'0.000000000000','warning'=>'paused']);
 equal(AiBudget::admission('10','12','1','0.01'),['allowed'=>false,'remaining'=>'0.000000000000','warning'=>'limit_reached']);
 equal(AiBudget::admission('15','0','0','0')['allowed'],false);
 foreach(['-1','1e2','0.0000000000001'] as $value) rejects(fn()=>AiBudget::admission('15','0',$value,'1'));
});

test('AI budget warnings include in-flight estimates at exact eighty and ninety percent',function() {
 foreach([['11.999999999999','0','none'],['11','1','80_percent'],['12','1.5','90_percent'],['14','1','limit_reached']] as [$spent,$reserved,$warning]) equal(AiBudget::admission('15',$spent,$reserved,'0.01')['warning'],$warning);
});

test('AI month rollover retains uncertain prior holds in the new admission calculation',function() {
 equal(AiBudget::admission('15','0','14.99','0.02')['allowed'],false);
 equal(AiBudget::admission('15','0','14.99','0.01')['allowed'],true);
});

test('AI settlement keeps unknown charges reserved and detects overrun without hiding the reported cost',function() {
 equal(AiBudget::settlement('0.045',null),['state'=>'uncertain','charge'=>null,'retained'=>'0.045000000000','released'=>'0.000000000000']);
 equal(AiBudget::settlement('0.045','0.027'),['state'=>'settled','charge'=>'0.027000000000','retained'=>'0.000000000000','released'=>'0.018000000000']);
 equal(AiBudget::settlement('0.045','0.05'),['state'=>'overrun','charge'=>'0.050000000000','retained'=>'0.000000000000','released'=>'0.000000000000']);
 equal(AiBudget::settlement('0.045','0')['released'],'0.045000000000');
 rejects(fn()=>AiBudget::settlement('0',null)); rejects(fn()=>AiBudget::settlement('1','-1'));
});

test('AI New York monthly boundaries follow DST and the local month instead of UTC month',function() {
 equal(AiBudget::period(new DateTimeImmutable('2026-11-01 03:59:59',new DateTimeZone('UTC'))),['period'=>'2026-10','timezone'=>'America/New_York','starts_at'=>'2026-10-01 04:00:00','resets_at'=>'2026-11-01 04:00:00']);
 equal(AiBudget::period(new DateTimeImmutable('2026-11-01 04:00:00',new DateTimeZone('UTC'))),['period'=>'2026-11','timezone'=>'America/New_York','starts_at'=>'2026-11-01 04:00:00','resets_at'=>'2026-12-01 05:00:00']);
 equal(AiBudget::period(new DateTimeImmutable('2026-03-15 12:00:00',new DateTimeZone('UTC')))['resets_at'],'2026-04-01 04:00:00');
 equal(AiBudget::period(new DateTimeImmutable('2026-12-31 23:00:00',new DateTimeZone('America/New_York')))['resets_at'],'2027-01-01 05:00:00');
});
