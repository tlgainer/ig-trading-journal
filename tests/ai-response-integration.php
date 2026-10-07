<?php
/** Retained, sanitized response receipts; no external AI calls. */
use GainerInteractive\IGTradingJournal\Application\AiSpending;
use GainerInteractive\IGTradingJournal\Infrastructure\Installer;
if(!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE!==true) throw new RuntimeException('Disposable site required.');
function receipt_context(): array {
 $context=review_context('reserved'); [$db,$tracker,$w,$asset,$approval,$spending,$legacy,$reviews,$normalized]=$context;
 $spending->reconcile($w,$legacy,null,true); $fingerprint=$db->object('ai_evidence_bundles',$w,$approval)['fingerprint']; $credential=ai_digest('review-fixture-key');
 $catalog=ai_catalog_evidence(['credential_fingerprint'=>$credential]); $request=$spending->reserve($w,$credential,'receipt-request',$fingerprint,10000,2000,$approval,$catalog); $spending->dispatch($w,(int)$request['id'],$credential,$catalog);
 $context[6]=(int)$request['id']; $context[8]=response_fixture($normalized['findings'][0]['source_ids']); return $context;
}
function receipt_body(array $response): string { return json_encode($response,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }

test('AI receipts settle once, publish exact approved output and retain immutable retries',function() {
 [$db,$tracker,$w,$asset,$approval,$spending,$request,$reviews,$response]=receipt_context();
 $status=$spending->receive($w,$request,receipt_body($response)); equal($status['state'],'settled'); equal($status['review_available'],true); equal($spending->request($w,$request)['charge'],'0.000405000000');
 equal($spending->receive($w,$request,receipt_body($response)),$status); $saved=$reviews->publish_received($w,$request); equal($reviews->publish_received($w,$request),$saved); equal($saved['approval_id'],$approval);
 $changed=$response; $output=json_decode($changed['output'][1]['content'][0]['text'],true); $output['summary']='Changed output'; $changed['output'][1]['content'][0]['text']=json_encode($output); provider_conflict(fn()=>$spending->receive($w,$request,receipt_body($changed)));
 equal(count($db->rows('SELECT id FROM '.$db->table('ai_response_receipts').' WHERE workspace_id = %d AND request_id = %d',[$w,$request])),1);
 foreach(['result','usage','model','credential_fingerprint','summary'] as $key) equal(array_key_exists($key,$status),false);
});

test('Refused and incomplete replies settle billable usage while quarantining publication',function() {
 foreach(['refused','incomplete','invalid_output'] as $reason) {
  [$db,$tracker,$w,$asset,$approval,$spending,$request,$reviews,$response]=receipt_context();
  if($reason==='refused') $response['output'][1]['content']=[['type'=>'refusal','refusal'=>'Fixture refusal']];
  if($reason==='incomplete') { $response['status']='incomplete'; $response['incomplete_details']=['reason'=>'max_output_tokens']; }
  if($reason==='invalid_output') $response['output'][1]['content'][0]['text']='{"summary":"x","findings":[{"text":"x","source_ids":[999999]}]}';
  $status=$spending->receive($w,$request,receipt_body($response)); equal($status['state'],'settled'); equal($status['reason'],$reason); equal($status['review_available'],false); provider_conflict(fn()=>$reviews->publish_received($w,$request)); equal($reviews->history($w,$asset)['items'],[]);
 }
});

test('Unknown usage retains holds and later verified evidence recovers without resending',function() {
 [$db,$tracker,$w,$asset,$approval,$spending,$request,$reviews,$response]=receipt_context(); $unknown=$response; $unknown['usage']=null;
 $status=$spending->receive($w,$request,receipt_body($unknown)); equal($status['state'],'uncertain'); equal($spending->status($w)['reserved'],'0.045000000000'); equal($spending->receive($w,$request,receipt_body($unknown)),$status);
 provider_conflict(fn()=>$spending->reconcile($w,$request,['input_tokens'=>100,'cached_input_tokens'=>20,'output_tokens'=>20])); provider_conflict(fn()=>$reviews->publish_received($w,$request));
 $different=$response; $different['id']='resp_other'; provider_conflict(fn()=>$spending->receive($w,$request,receipt_body($different)));
 equal($spending->receive($w,$request,receipt_body($response))['state'],'settled'); equal($spending->status($w)['reserved'],'0.000000000000'); equal(count($db->rows('SELECT id FROM '.$db->table('ai_response_receipts').' WHERE workspace_id = %d AND request_id = %d',[$w,$request])),2);
 $reviews->publish_received($w,$request);
});

test('Receipt audit failure rolls back both cost and receipt, and damaged evidence fails closed',function() {
 [$db,$tracker,$w,$asset,$approval,$spending,$request,$reviews,$response]=receipt_context(); $trigger=$db->table('audit_events').'_receipt_fault';
 $db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW BEGIN IF NEW.action = 'ai_settled' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture fault'; END IF; END");
 try { try { $spending->receive($w,$request,receipt_body($response)); throw new LogicException('Receipt fault accepted'); } catch(RuntimeException $error) {} } finally { $db->query('DROP TRIGGER '.$trigger); }
 equal($spending->request($w,$request)['state'],'dispatched'); equal($spending->received($w,$request),null); equal($spending->status($w)['reserved'],'0.045000000000');
 $status=$spending->receive($w,$request,receipt_body($response)); $saved=$db->object('ai_response_receipts',$w,$status['receipt_id']);
 try { $db->update_object('ai_response_receipts',$w,$status['receipt_id'],['result_json'=>'{}']); provider_conflict(fn()=>$reviews->publish_received($w,$request)); } finally { $db->update_object('ai_response_receipts',$w,$status['receipt_id'],['result_json'=>$saved['result_json']]); }
 $reviews->publish_received($w,$request);
});

test('Receipt cost overruns and token-bound violations cannot publish or admit more spending',function() {
 [$db,$tracker,$w,$asset,$approval,$spending,$request,$reviews,$response]=receipt_context(); $response['usage']['input_tokens']=10000; $response['usage']['input_tokens_details']['cached_tokens']=0; $response['usage']['output_tokens']=5000; $response['usage']['total_tokens']=15000;
 $status=$spending->receive($w,$request,receipt_body($response)); equal($status['state'],'overrun'); equal($status['reason'],'token_bound_exceeded'); equal($status['review_available'],false); equal($spending->status($w)['overrun'],true); provider_conflict(fn()=>$reviews->publish_received($w,$request));
 provider_conflict(fn()=>$spending->reserve($w,ai_digest('review-fixture-key'),'after-overrun',ai_digest('new-evidence'),1,16));
 [$db,$tracker,$w,$asset,$approval,$spending,$request,$reviews,$response]=receipt_context(); $response['usage']['input_tokens']=11000; $response['usage']['input_tokens_details']['cached_tokens']=11000; $response['usage']['total_tokens']=11020;
 $status=$spending->receive($w,$request,receipt_body($response)); equal($status['state'],'settled'); equal($status['reason'],'token_bound_exceeded'); equal($status['review_available'],false); provider_conflict(fn()=>$reviews->publish_received($w,$request));
});

test('Schema 17 preserves receipts and workspace/authorizer isolation on repeat activation',function() use($owner,$viewer) {
 [$db,$tracker,$w,$asset,$approval,$spending,$request,$reviews,$response]=receipt_context(); $spending->receive($w,$request,receipt_body($response)); $before=$spending->received($w,$request);
 global $wpdb; $original=$wpdb; $connection=clone $wpdb; $connection->result=null; $connection->prefix=substr($db->table('ai_requests'),0,-strlen('tgit_ai_requests'));
 try { $wpdb=$connection; update_option('tgit_schema_version','16'); Installer::install(); Installer::install(); equal(get_option('tgit_schema_version'),Installer::VERSION); equal($spending->received($w,$request),$before); equal($db->row('SHOW TABLE STATUS LIKE %s',[$db->table('ai_response_receipts')])['Engine'],'InnoDB'); } finally { $original->result=null; $wpdb=$original; }
 $foreign=(int)$tracker->create_workspace(['name'=>'Foreign receipt workspace'])['id']; try { $spending->received($foreign,$request); throw new LogicException('Foreign receipt read'); } catch(OutOfBoundsException $error) {}
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'owner','state'=>'active']); $other=new AiSpending($db,$viewer,wp_generate_uuid4(),ai_now()); provider_conflict(fn()=>$other->receive($w,$request,receipt_body($response)));
 $tracker->set_member($w,['wp_user_id'=>$owner,'role'=>'owner','state'=>'revoked']); try { $spending->receive($w,$request,receipt_body($response)); throw new LogicException('Revoked owner receipt'); } catch(DomainException $error) {}
});
