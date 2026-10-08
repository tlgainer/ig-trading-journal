<?php
/** Synthetic count-to-delivery coordination; every HTTP request is intercepted. */
use GainerInteractive\IGTradingJournal\Infrastructure\AiGeneration;
use GainerInteractive\IGTradingJournal\Infrastructure\Database;
if(!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE!==true) throw new RuntimeException('Disposable site required.');
define('TGIT_OPENAI_MODEL_EVIDENCE',ai_catalog_evidence(['credential_fingerprint'=>hash('sha256','review-fixture-key')]));
define('TGIT_OPENAI_COUNT_EVIDENCE',['fixture-text-model'=>count_policy(hash('sha256','review-fixture-key'))]);
function generation_mock(callable $reply, callable $scenario): void {
 $filter=function($prior,$args,$url) use($reply) { if(!in_array($url,['https://api.openai.com/v1/responses/input_tokens','https://api.openai.com/v1/responses'],true)) throw new LogicException('Unexpected generation destination'); return $reply($args,$url); };
 add_filter('pre_http_request',$filter,10,3); try { $scenario(); } finally { remove_filter('pre_http_request',$filter,10); }
}
function generation_busy(callable $operation): void {
 try { $operation(); } catch(RuntimeException $error) { equal($error->getMessage(),'AI generation is already in progress.'); return; } throw new LogicException('Overlapping generation accepted');
}

test('Generation counts approved input, reserves and sends once without automatic publication',function() {
 $fixture=execution_context(); [$context,$credential,$plan]=$fixture; [$db,$tracker,$w,$asset,$approval,$spending,$legacy,$reviews]=$context; $calls=[];
 $response=response_fixture($context[8]['findings'][0]['source_ids']);
 generation_mock(function($args,$url) use(&$calls,$plan,$response) { $calls[]=$url; equal(json_decode($args['body'],true),str_ends_with($url,'input_tokens')?$plan['count_request']:$plan['request']); return transport_reply(str_ends_with($url,'input_tokens')?'{"object":"response.input_tokens","input_tokens":1000}':receipt_body($response)); },function() use($spending,$w,$approval,&$result) {
  $result=AiGeneration::run($spending,$w,$approval,'generation-success',2000); equal($result['state'],'settled'); equal(AiGeneration::run($spending,$w,$approval,'generation-success',2000),$result);
 }); equal(count($calls),2); equal(array_keys($result),['state','request_id']); equal($reviews->history($w,$asset)['items'],[]); equal($spending->status($w)['reserved'],'0.000000000000');
 $saved=$reviews->publish_received($w,$result['request_id']); equal($saved['approval_id'],$approval); equal($reviews->publish_received($w,$result['request_id']),$saved);
});

test('Generation blocks reentrant and separate-connection overlapping calls before a second count',function() {
 global $owner; $fixture=execution_context(); [$context]=$fixture; [$db,$tracker,$w,$asset,$approval,$spending]=$context; $calls=0;
 $peer=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST); $peer->prefix=substr($db->table('ai_requests'),0,-strlen('tgit_ai_requests')); $other=new GainerInteractive\IGTradingJournal\Application\AiSpending(new Database($peer),$owner,wp_generate_uuid4(),ai_now());
 try { generation_mock(function() use(&$calls,$spending,$other,$w,$approval) { ++$calls; generation_busy(fn()=>AiGeneration::run($spending,$w,$approval,'generation-overlap',2000)); generation_busy(fn()=>AiGeneration::run($other,$w,$approval,'generation-overlap',2000)); return new WP_Error('fixture_count_timeout','private error'); },function() use($spending,$w,$approval) { equal(AiGeneration::run($spending,$w,$approval,'generation-overlap',2000),['state'=>'unavailable']); }); } finally { $peer->close(); }
 equal($calls,1); equal($spending->generation_operation($w,$approval,'generation-overlap',2000,fn($prior)=>['released'=>$prior===null]),['released'=>true]);
});

test('Generation uncertain delivery retains spending and conflicting retries never recount or resend',function() {
 $fixture=execution_context(); [$context]=$fixture; [$db,$tracker,$w,$asset,$approval,$spending]=$context; $calls=0;
 generation_mock(function($args,$url) use(&$calls) { ++$calls; return str_ends_with($url,'input_tokens')?transport_reply('{"object":"response.input_tokens","input_tokens":1000}'):new WP_Error('fixture_send_timeout','private error'); },function() use($spending,$w,$approval,&$result) {
  $result=AiGeneration::run($spending,$w,$approval,'generation-uncertain',2000); equal($result['state'],'uncertain'); equal(AiGeneration::run($spending,$w,$approval,'generation-uncertain',2000),$result);
  provider_conflict(fn()=>AiGeneration::run($spending,$w,$approval,'generation-uncertain',2001)); provider_conflict(fn()=>AiGeneration::run($spending,$w,$approval+1,'generation-uncertain',2000));
 }); equal($calls,2); equal($spending->status($w)['reserved'],'0.022500000000');
});

test('Generation retries return abandoned reserved state without count or delivery',function() {
 $fixture=execution_context(); [$context]=$fixture; [$db,$tracker,$w,$asset,$approval,$spending]=$context; $request=execution_reserve($fixture,'generation-abandoned');
 generation_mock(function() { throw new LogicException('Reserved retry attempted HTTP'); },function() use($spending,$w,$approval,$request) { equal(AiGeneration::run($spending,$w,$approval,'generation-abandoned',2000),['state'=>'reserved','request_id'=>(int)$request['id']]); });
 rejects(fn()=>AiGeneration::run($spending,$w,$approval,'',2000)); rejects(fn()=>AiGeneration::run($spending,$w,$approval,'invalid-bound',0));
});

test('Generation denies foreign approval and revoked consent before HTTP and releases coordination',function() {
 $fixture=execution_context(); [$context]=$fixture; [$db,$tracker,$w,$asset,$approval,$spending]=$context; $foreign=(int)$tracker->create_workspace(['name'=>'Foreign generation workspace'])['id']; $spending->enroll($foreign,true,0);
 generation_mock(function() { throw new LogicException('Unauthorized generation attempted HTTP'); },function() use($spending,$w,$foreign,$approval) {
  try { AiGeneration::run($spending,$foreign,$approval,'foreign-generation',2000); throw new LogicException('Foreign approval accepted'); } catch(OutOfBoundsException $error) {}
  $status=$spending->status($w); $spending->enroll($w,false,$status['enrollment_id']); provider_conflict(fn()=>AiGeneration::run($spending,$w,$approval,'revoked-generation',2000));
 }); equal($spending->status($w)['reserved'],'0.000000000000');
});
