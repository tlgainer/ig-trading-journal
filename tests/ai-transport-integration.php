<?php
/** Intercept every HTTP attempt: no live credentials, data or model calls. */
use GainerInteractive\IGTradingJournal\Infrastructure\AiTransport;
use GainerInteractive\IGTradingJournal\Application\AiSpending;
if(!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE!==true) throw new RuntimeException('Disposable site required.');
function transport_mock(callable $reply, callable $scenario): void {
 $filter=function($prior,$args,$url) use($reply) { equal($url,'https://api.openai.com/v1/responses'); return $reply($args); };
 add_filter('pre_http_request',$filter,10,3); try { $scenario(); } finally { remove_filter('pre_http_request',$filter,10); }
}
function transport_reply(string $body, int $code=200): array { return ['headers'=>[],'body'=>$body,'response'=>['code'=>$code,'message'=>'Fixture'],'cookies'=>[]]; }
test('Disabled AI transport makes no attempt and retains the reserved budget',function() {
 $fixture=execution_context(); [$context,$credential,$plan,$catalog]=$fixture; [$db,$tracker,$w,$asset,$approval,$spending]=$context; $request=execution_reserve($fixture);
 transport_mock(function() { throw new LogicException('Disabled sender attempted HTTP'); },function() use($spending,$w,$request,$catalog) { equal(AiTransport::run($spending,$w,(int)$request['id'],$catalog)['state'],'disabled'); });
 equal($spending->request($w,(int)$request['id'])['state'],'reserved'); equal($spending->status($w)['reserved'],'0.022500000000');
});
define('TGIT_OPENAI_API_KEY','review-fixture-key'); define('TGIT_OPENAI_ENABLED',true);
test('AI transport sends exact approved envelope once and settles only verified receipts',function() {
 $fixture=execution_context(); [$context,$credential,$plan,$catalog]=$fixture; [$db,$tracker,$w,$asset,$approval,$spending]=$context; $request=execution_reserve($fixture); $calls=0;
 $response=response_fixture($context[8]['findings'][0]['source_ids']);
 transport_mock(function($args) use(&$calls,$plan,$response,$spending,$w,$request,$catalog) {
  ++$calls; equal(json_decode($args['body'],true),$plan['request']); equal($args['headers']['Authorization'],'Bearer review-fixture-key'); equal($args['redirection'],0); equal($args['sslverify'],true); equal($args['timeout'],30); equal($args['limit_response_size'],524288); equal($args['blocking'],true); equal($args['cookies'],[]); equal((bool)preg_match('/^tgit-[a-f0-9]{64}$/D',$args['headers']['X-Client-Request-Id']),true);
  equal(AiTransport::run($spending,$w,(int)$request['id'],$catalog)['state'],'dispatched');
  return transport_reply(receipt_body($response));
 },function() use($spending,$w,$request,$catalog) { $status=AiTransport::run($spending,$w,(int)$request['id'],$catalog); equal($status['state'],'settled'); equal($status['review_available'],true); equal(AiTransport::run($spending,$w,(int)$request['id'],$catalog)['state'],'settled'); foreach(['body','credential','model','usage','prompt'] as $key) equal(array_key_exists($key,$status),false); });
 equal($calls,1); equal($spending->status($w)['reserved'],'0.000000000000');
});
test('AI delivery uncertainty, HTTP errors and malformed bodies retain holds without resends',function() {
 foreach([new WP_Error('fixture_timeout','private fixture error'),'throw',transport_reply('{"error":"private fixture error"}',429),transport_reply('invalid')] as $reply) {
  $fixture=execution_context(); [$context,$credential,$plan,$catalog]=$fixture; [$db,$tracker,$w,$asset,$approval,$spending]=$context; $request=execution_reserve($fixture); $calls=0;
  transport_mock(function() use(&$calls,$reply) { ++$calls; if($reply==='throw') throw new RuntimeException('private fixture error'); return $reply; },function() use($spending,$w,$request,$catalog) { equal(AiTransport::run($spending,$w,(int)$request['id'],$catalog)['state'],'uncertain'); equal(AiTransport::run($spending,$w,(int)$request['id'],$catalog)['state'],'uncertain'); });
  equal($calls,1); equal($spending->status($w)['reserved'],'0.022500000000');
 }
});
test('AI send gates reject stale counts, invalid catalog and foreign workspace before HTTP',function() {
 global $owner; $fixture=execution_context(); [$context,$credential,$plan,$catalog]=$fixture; [$db,$tracker,$w,$asset,$approval,$spending]=$context; $request=execution_reserve($fixture); $id=(int)$request['id']; $calls=0;
 transport_mock(function() use(&$calls) { ++$calls; throw new LogicException('Unsafe send'); },function() use($db,$tracker,$w,$spending,$catalog,$id,$owner) {
  $later=new AiSpending($db,$owner,wp_generate_uuid4(),ai_now()->modify('+300 seconds')); rejects(fn()=>AiTransport::run($later,$w,$id,$catalog));
  provider_conflict(fn()=>AiTransport::run($spending,$w,$id,[]));
  $foreign=(int)$tracker->create_workspace(['name'=>'Foreign sender workspace'])['id']; try { AiTransport::run($spending,$foreign,$id,$catalog); throw new LogicException('Foreign sender accepted'); } catch(OutOfBoundsException $error) {}
 }); equal($calls,0); equal($spending->request($w,$id)['state'],'reserved');
});
test('AI receipt audit failure after delivery preserves hold and never permits another send',function() {
 $fixture=execution_context(); [$context,$credential,$plan,$catalog]=$fixture; [$db,$tracker,$w,$asset,$approval,$spending]=$context; $request=execution_reserve($fixture); $id=(int)$request['id']; $response=response_fixture($context[8]['findings'][0]['source_ids']); $calls=0; $trigger=$db->table('audit_events').'_transport_fault';
 $db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW BEGIN IF NEW.action IN ('ai_response_received','ai_uncertain') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture fault'; END IF; END");
 try { transport_mock(function() use(&$calls,$response) { ++$calls; return transport_reply(receipt_body($response)); },function() use($spending,$w,$id,$catalog) { equal(AiTransport::run($spending,$w,$id,$catalog)['state'],'uncertain'); equal(AiTransport::run($spending,$w,$id,$catalog)['state'],'dispatched'); }); } finally { $db->query('DROP TRIGGER '.$trigger); }
 equal($calls,1); equal($spending->status($w)['reserved'],'0.022500000000'); equal($spending->received($w,$id),null); equal($spending->receive($w,$id,receipt_body($response))['state'],'settled');
});
