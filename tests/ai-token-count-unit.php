<?php
/** Deterministic count receipts; never call OpenAI. */
declare(strict_types=1);
require_once __DIR__.'/../src/Domain/AiTokenCount.php';
use GainerInteractive\IGTradingJournal\Domain\AiTokenCount;
function count_context(array $plan): array { return ['credential_fingerprint'=>str_repeat('a',64),'count_fingerprint'=>$plan['count_fingerprint'],'request_fingerprint'=>$plan['request_fingerprint'],'counted_at'=>'2026-10-06 12:00:00']; }
function counted_fixture(?array $plan=null, ?array $context=null, string $body='{"object":"response.input_tokens","input_tokens":1000}', int $status=200, ?DateTimeImmutable $now=null): array {
 $plan=$plan??prompt_fixture(); return AiTokenCount::verify($plan,$context??count_context($plan),$body,$status,str_repeat('a',64),ai_pricing(),$now??ai_now());
}
test('Verified complete counts bind exact request and conservative full output cost',function() {
 $bound=counted_fixture(); equal($bound['bound']['input_tokens'],1000); equal($bound['bound']['output_tokens'],2000); equal($bound['bound']['maximum_cost'],'0.022500000000'); equal($bound['bound']['expires_at'],'2026-10-06 12:05:00');
 equal($bound['fingerprint'],hash('sha256',json_encode($bound['bound'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)));
 equal(counted_fixture(null,null,'{ "input_tokens":1000, "object":"response.input_tokens" }'),$bound);
});
test('Count receipts reject wrong credentials, envelopes, model prices and edited prompts',function() {
 $plan=prompt_fixture(); $context=count_context($plan);
 foreach(['credential_fingerprint'=>str_repeat('b',64),'count_fingerprint'=>str_repeat('b',64),'request_fingerprint'=>str_repeat('b',64),'unknown'=>true] as $key=>$value) rejects(fn()=>counted_fixture($plan,array_replace($context,[$key=>$value])));
 foreach(['instructions','store','max_output_tokens'] as $key) { $bad=$plan; $bad['request'][$key]=$key==='store'?true:($key==='max_output_tokens'?3000:'Changed instructions'); rejects(fn()=>counted_fixture($bad,$context)); }
 $bad=$plan; $bad['count_request']['text']['format']['strict']=false; rejects(fn()=>counted_fixture($bad,$context));
 rejects(fn()=>AiTokenCount::verify($plan,$context,'{"object":"response.input_tokens","input_tokens":1000}',200,str_repeat('a',64),array_replace(ai_pricing(),['model'=>'other-model']),ai_now()));
});
test('Token counts fail closed for stale, future, contradictory and ambiguous response evidence',function() {
 foreach(['{"object":"response.input_tokens","input_tokens":0}','{"object":"response.input_tokens","input_tokens":"1000"}','{"object":"response.input_tokens","input_tokens":1.5}','{"object":"response.input_tokens","input_tokens":true}','{"object":"response.input_tokens","input_tokens":1000001}','{"object":"other","input_tokens":1000}','{"object":"response.input_tokens","input_tokens":1000,"model":"fixture-text-model"}','{"object":"response.input_tokens","input_tokens":1,"input_tokens":1000}','invalid',str_repeat(' ',4097)] as $body) rejects(fn()=>counted_fixture(null,null,$body));
 rejects(fn()=>counted_fixture(null,null,'{"object":"response.input_tokens","input_tokens":1000}',500));
 foreach(['2026-10-06 11:55:00','2026-10-06 12:00:01','2026-02-30 12:00:00','invalid'] as $at) rejects(fn()=>counted_fixture(null,array_replace(count_context(prompt_fixture()),['counted_at'=>$at])));
 equal(counted_fixture(null,null,'{"object":"response.input_tokens","input_tokens":1000}',200,ai_now()->modify('+299 seconds'))['bound']['input_tokens'],1000);
 rejects(fn()=>counted_fixture(null,null,'{"object":"response.input_tokens","input_tokens":1000}',200,ai_now()->modify('+300 seconds')));
});
