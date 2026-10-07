<?php
/** Deterministic Responses fixtures; never call OpenAI. */
declare(strict_types=1);
require_once __DIR__.'/../src/Domain/AiJson.php';
require_once __DIR__.'/../src/Domain/AiResponse.php';
use GainerInteractive\IGTradingJournal\Domain\AiJson;
use GainerInteractive\IGTradingJournal\Domain\AiResponse;

function response_fixture(array $ids=[1,2]): array {
 $content=['summary'=>'Synthetic saved-evidence interpretation.','findings'=>[['text'=>'Source coverage is limited.','source_ids'=>$ids]]];
 return ['id'=>'resp_fixture_1','object'=>'response','model'=>'fixture-text-model','status'=>'completed','error'=>null,'incomplete_details'=>null,'service_tier'=>'default','tools'=>[],
 'output'=>[['type'=>'reasoning','summary'=>[]],['type'=>'message','role'=>'assistant','status'=>'completed','content'=>[['type'=>'output_text','text'=>json_encode($content),'annotations'=>[]]]]],
 'usage'=>['input_tokens'=>100,'input_tokens_details'=>['cached_tokens'=>20,'cache_write_tokens'=>0],'output_tokens'=>20,'output_tokens_details'=>['reasoning_tokens'=>8],'total_tokens'=>120]];
}
function inspect_fixture(array $response): array { return AiResponse::inspect($response,'fixture-text-model',review_bundle()); }

test('Responses verify exact identity, complete text and cached totals without counting reasoning twice',function() {
 $result=inspect_fixture(response_fixture([2,1])); equal($result['reason'],'completed'); equal($result['usage'],['input_tokens'=>100,'cached_input_tokens'=>20,'output_tokens'=>20]); equal($result['review']['findings'][0]['source_ids'],[1,2]);
 equal(GainerInteractive\IGTradingJournal\Domain\AiBudget::charge(ai_pricing(),$result['usage'],ai_now()),'0.000405000000');
});
test('Responses never reconcile unverified HTTP, response identity or model aliases',function() {
 foreach([['model'=>'another-model'],['id'=>'bad id'],['object'=>'other']] as $change) { $result=inspect_fixture(array_replace(response_fixture(),$change)); equal($result['usage'],null); equal($result['review'],null); equal($result['response_id'],null); }
 equal(AiResponse::inspect(response_fixture(),'fixture-text-model',review_bundle(),500)['usage'],null);
});
test('Refusals, incomplete and invalid reviews preserve verified billable usage without publication',function() {
 $fixture=response_fixture(); foreach(['incomplete','failed','cancelled'] as $state) { $result=inspect_fixture(array_replace($fixture,['status'=>$state])); equal($result['reason'],'incomplete'); equal($result['usage']['output_tokens'],20); equal($result['review'],null); }
 $refusal=$fixture; $refusal['output'][1]['content']=[['type'=>'refusal','refusal'=>'Fixture refusal']]; equal(inspect_fixture($refusal)['reason'],'refused'); equal(inspect_fixture($refusal)['usage'],inspect_fixture($fixture)['usage']);
 foreach(['{invalid','{"summary":"a","summary":"b","findings":[]}',json_encode(['summary'=>'Unknown source','findings'=>[['text'=>'Claim','source_ids'=>[999]]]])] as $text) { $bad=$fixture; $bad['output'][1]['content'][0]['text']=$text; $result=inspect_fixture($bad); equal($result['reason'],'invalid_output'); equal($result['usage']['input_tokens'],100); equal($result['review'],null); }
 $bad=$fixture; $bad['output'][]=$bad['output'][1]; equal(inspect_fixture($bad)['reason'],'invalid_output');
 $bad=$fixture; $bad['output'][1]['content'][0]['annotations']=[['type'=>'url_citation']]; equal(inspect_fixture($bad)['review'],null);
});
test('Missing, contradictory, coerced or unsupported usage keeps the complete hold',function() {
 $fixture=response_fixture(); $changes=[['input_tokens'=>0,'input_tokens_details'=>['cached_tokens'=>0],'total_tokens'=>20],['output_tokens'=>0,'output_tokens_details'=>['reasoning_tokens'=>0],'total_tokens'=>100],['total_tokens'=>121],['input_tokens'=>'100'],['output_tokens'=>1.5],['input_tokens'=>true],['output_tokens'=>1000001],['input_tokens_details'=>['cached_tokens'=>101]],['input_tokens_details'=>['cached_tokens'=>20,'cache_write_tokens'=>1]],['output_tokens_details'=>['reasoning_tokens'=>21]],['input_tokens_details'=>[]],['audio_tokens'=>5]];
 foreach($changes as $change) { $bad=$fixture; $bad['usage']=array_replace($bad['usage'],$change); equal(inspect_fixture($bad)['usage'],null); equal(inspect_fixture($bad)['review'],null); }
 $bad=$fixture; unset($bad['usage']); equal(inspect_fixture($bad)['usage'],null);
});
test('Nonstandard tiers, tools, ongoing work and unsupported output cannot use text-only prices',function() {
 foreach([['service_tier'=>'priority'],['tools'=>[['type'=>'web_search']]],['status'=>'in_progress'],['status'=>'queued'],['output'=>[['type'=>'web_search_call']]]] as $change) { $result=inspect_fixture(array_replace(response_fixture(),$change)); equal($result['usage'],null); equal($result['reason'],'unsupported_billing'); }
});
test('AI JSON rejects duplicate escaped or nested keys and bounded malformed bodies',function() {
 equal(AiJson::decode('{"a":{"b":1},"c":[{"b":2},"x:y,z"]}'),['a'=>['b'=>1],'c'=>[['b'=>2],'x:y,z']]);
 foreach(['{"model":"a","model":"b"}','{"usage":{"input_tokens":1,"input_tokens":2}}','{"a":1,"\u0061":2}','[{}, {"x":1,"x":2}]','invalid','true'] as $body) rejects(fn()=>AiJson::decode($body));
 rejects(fn()=>AiJson::decode(str_repeat(' ',100),10)); equal(AiJson::decode('{"integer":100,"string":"100"}'),['integer'=>100,'string'=>'100']);
});
