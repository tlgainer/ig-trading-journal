<?php
/** Synthetic count-cost evidence, never an assertion of actual endpoint pricing. */
require_once __DIR__.'/../src/Domain/AiCountPolicy.php';
use GainerInteractive\IGTradingJournal\Domain\AiCountPolicy;
function count_policy(string $credential=''): array { return ['credential_fingerprint'=>$credential?:str_repeat('a',64),'model'=>'fixture-text-model','maximum_charge'=>'0','verified_at'=>'2026-10-01 00:00:00','valid_until'=>'2026-10-20 00:00:00','source'=>'https://developers.openai.com/api/docs/guides/token-counting','access_confirmed'=>true]; }
test('Count-cost policy requires fresh exact credential/model verification, never assumed free',function() {
 AiCountPolicy::verify(count_policy(),str_repeat('a',64),'fixture-text-model',ai_now());
 foreach(['credential_fingerprint'=>str_repeat('b',64),'model'=>'different-model','maximum_charge'=>'0.01','access_confirmed'=>false,'source'=>'https://example.invalid','verified_at'=>'2026-10-07 00:00:00','valid_until'=>'2026-11-20 00:00:00','extra'=>true] as $key=>$value) rejects(fn()=>AiCountPolicy::verify(array_replace(count_policy(),[$key=>$value]),str_repeat('a',64),'fixture-text-model',ai_now()));
 rejects(fn()=>AiCountPolicy::verify([],str_repeat('a',64),'fixture-text-model',ai_now()));
 rejects(fn()=>AiCountPolicy::verify(count_policy(),str_repeat('a',64),'fixture-text-model',new DateTimeImmutable('2026-10-20 00:00:00',new DateTimeZone('UTC'))));
});
