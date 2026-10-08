<?php
/** Intercept every count HTTP request; no live fee or access assumption. */
use GainerInteractive\IGTradingJournal\Infrastructure\AiCounts;
if(!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE!==true) throw new RuntimeException('Disposable site required.');
function counting_mock(callable $reply, callable $scenario): void {
 $filter=function($prior,$args,$url) use($reply) { equal($url,'https://api.openai.com/v1/responses/input_tokens'); return $reply($args); };
 add_filter('pre_http_request',$filter,10,3); try { $scenario(); } finally { remove_filter('pre_http_request',$filter,10); }
}
test('Counting projects exact approved input and creates a verified complete reservation',function() {
 $fixture=execution_context(); [$context,$credential,$plan,$catalog]=$fixture; [$db,$tracker,$w,$asset,$approval,$spending]=$context; $calls=0; $before=$spending->status($w);
 counting_mock(function($args) use(&$calls,$plan) { ++$calls; equal(json_decode($args['body'],true),$plan['count_request']); equal($args['headers']['Authorization'],'Bearer review-fixture-key'); equal($args['redirection'],0); equal($args['sslverify'],true); equal($args['timeout'],30); equal($args['limit_response_size'],4096); return transport_reply('{"object":"response.input_tokens","input_tokens":1000}'); },function() use($spending,$w,$approval,$catalog,$credential,$plan,&$result) {
  $result=AiCounts::run($spending,$w,$approval,2000,$catalog,count_policy($credential)); equal($result['state'],'counted'); equal($result['verified']['bound']['request_fingerprint'],$plan['request_fingerprint']);
 }); equal($calls,1); equal($spending->status($w),$before);
 $request=$spending->reserve($w,$credential,'count-transport-result',$plan['evidence_fingerprint'],1000,2000,$approval,$catalog,$result['execution']); equal($spending->manifest($w,(int)$request['id'])['verified'],$result['verified']);
});
test('Count preflight denies missing cost proof, catalog and revoked consent before HTTP',function() {
 $fixture=execution_context(); [$context,$credential,$plan,$catalog]=$fixture; [$db,$tracker,$w,$asset,$approval,$spending]=$context; $calls=0;
 counting_mock(function() use(&$calls) { ++$calls; throw new LogicException('Unsafe count'); },function() use($spending,$w,$approval,$catalog,$credential) {
  rejects(fn()=>AiCounts::run($spending,$w,$approval,2000,$catalog,[])); provider_conflict(fn()=>AiCounts::run($spending,$w,$approval,2000,[],count_policy($credential)));
  $status=$spending->status($w); $spending->enroll($w,false,$status['enrollment_id']); provider_conflict(fn()=>AiCounts::run($spending,$w,$approval,2000,$catalog,count_policy($credential)));
 }); equal($calls,0); equal($spending->status($w)['reserved'],'0.000000000000');
});
test('Counting rejects changed context after HTTP and unavailable or ambiguous count replies',function() {
 foreach(['changed','timeout','ambiguous','error'] as $case) {
  $fixture=execution_context(); [$context,$credential,$plan,$catalog]=$fixture; [$db,$tracker,$w,$asset,$approval,$spending]=$context; $calls=0;
  counting_mock(function() use(&$calls,$case,$spending,$w) { ++$calls; if($case==='changed') { $status=$spending->status($w); $spending->enroll($w,false,$status['enrollment_id']); } if($case==='timeout') return new WP_Error('fixture_timeout','private error'); return transport_reply($case==='ambiguous'?'{"object":"response.input_tokens","input_tokens":1,"input_tokens":1000}':'{"object":"response.input_tokens","input_tokens":1000}',$case==='error'?500:200); },function() use($spending,$w,$approval,$catalog,$credential) { equal(AiCounts::run($spending,$w,$approval,2000,$catalog,count_policy($credential)),['state'=>'unavailable']); });
  equal($calls,1); equal($spending->status($w)['reserved'],'0.000000000000');
 }
});
test('Count preflight respects a paused or insufficient monthly budget',function() {
 $fixture=execution_context(); [$context,$credential,$plan,$catalog]=$fixture; [$db,$tracker,$w,$asset,$approval,$spending]=$context; $status=$spending->status($w);
 $spending->configure($w,['enabled'=>true,'monthly_cap'=>'0.001','model'=>'fixture-text-model','expected_config_id'=>$status['config_id']],['fixture-text-model'=>ai_pricing()]);
 provider_conflict(fn()=>$spending->prepare_count($w,$approval,$credential,2000,$catalog)); equal($spending->status($w)['reserved'],'0.000000000000');
});
