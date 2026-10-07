<?php
/** Synthetic complete execution manifests; no external AI calls. */
use GainerInteractive\IGTradingJournal\Application\AiSpending;
use GainerInteractive\IGTradingJournal\Domain\AiPrompt;
use GainerInteractive\IGTradingJournal\Infrastructure\Installer;
if(!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE!==true) throw new RuntimeException('Disposable site required.');
function execution_context(): array {
 $context=review_context('reserved'); [$db,$tracker,$w,$asset,$approval,$spending,$legacy]=$context;
 $spending->reconcile($w,$legacy,null,true); $source=$db->object('ai_evidence_bundles',$w,$approval); $credential=ai_digest('review-fixture-key');
 $plan=AiPrompt::build($source['bundle_json'],$source['fingerprint'],'fixture-text-model',2000);
 $catalog=ai_catalog_evidence(['credential_fingerprint'=>$credential]);
 $execution=['context'=>array_replace(count_context($plan),['credential_fingerprint'=>$credential]),'body'=>'{"object":"response.input_tokens","input_tokens":1000}','http_status'=>200];
 return [$context,$credential,$plan,$catalog,$execution];
}
function execution_reserve(array $fixture, string $key='counted-request'): array {
 [$context,$credential,$plan,$catalog,$execution]=$fixture; [$db,$tracker,$w,$asset,$approval,$spending]=$context;
 return $spending->reserve($w,$credential,$key,$plan['evidence_fingerprint'],1000,2000,$approval,$catalog,$execution);
}
test('Complete manifests preserve approved bytes and bind one immutable budget reservation',function() {
 $fixture=execution_context(); [$context,$credential,$plan,$catalog,$execution]=$fixture; [$db,$tracker,$w,$asset,$approval,$spending]=$context;
 $request=execution_reserve($fixture); $id=(int)$request['id']; $manifest=$spending->manifest($w,$id);
 equal($manifest['plan'],$plan); equal($manifest['verified']['bound']['maximum_cost'],'0.022500000000'); equal(execution_reserve($fixture)['id'],$request['id']);
 $fixture[4]['body']='{ "input_tokens":1000, "object":"response.input_tokens" }'; equal(execution_reserve($fixture)['id'],$request['id']);
 provider_conflict(fn()=>$spending->reserve($w,$credential,'counted-request',$plan['evidence_fingerprint'],1000,2000,$approval,$catalog));
 $fixture[4]['body']='{"object":"response.input_tokens","input_tokens":1001}'; provider_conflict(fn()=>execution_reserve($fixture));
 equal(count($db->rows('SELECT id FROM '.$db->table('ai_execution_manifests').' WHERE workspace_id = %d AND request_id = %d',[$w,$id])),1);
 equal($spending->dispatch($w,$id,$credential,$catalog,true)['state'],'dispatched');
});
test('Dispatch requires complete execution evidence and rejects expired counts without spending',function() {
 global $owner; $fixture=execution_context(); [$context,$credential,$plan,$catalog]=$fixture; [$db,$tracker,$w,$asset,$approval,$spending,$legacy]=$context;
 $unbound=$spending->reserve($w,$credential,'unbound-claim',$plan['evidence_fingerprint'],1000,2000,$approval,$catalog);
 provider_conflict(fn()=>$spending->dispatch($w,(int)$unbound['id'],$credential,$catalog,true)); equal($spending->request($w,(int)$unbound['id'])['state'],'reserved'); $spending->reconcile($w,(int)$unbound['id'],null,true);
 $request=execution_reserve($fixture); $id=(int)$request['id'];
 $later=new AiSpending($db,$owner,wp_generate_uuid4(),ai_now()->modify('+300 seconds'));
 rejects(fn()=>$later->dispatch($w,$id,$credential,$catalog,true)); equal($spending->request($w,$id)['state'],'reserved'); equal($spending->status($w)['reserved'],'0.022500000000');
 equal($later->reserve($w,$credential,'counted-request',$plan['evidence_fingerprint'],1000,2000,$approval,$catalog,$fixture[4])['id'],$request['id']);
});
test('Count admission rejects contradictory bounds and untrusted response metadata',function() {
 $fixture=execution_context(); [$context,$credential,$plan,$catalog]=$fixture; [$db,$tracker,$w,$asset,$approval,$spending]=$context;
 provider_conflict(fn()=>$spending->reserve($w,$credential,'wrong-bound',$plan['evidence_fingerprint'],999,2000,$approval,$catalog,$fixture[4]));
 $fixture[4]['context']['credential_fingerprint']=str_repeat('b',64); rejects(fn()=>execution_reserve($fixture));
 equal($spending->status($w)['reserved'],'0.000000000000'); equal($db->rows('SELECT id FROM '.$db->table('ai_execution_manifests').' WHERE workspace_id = %d',[$w]),[]);
});
test('Damaged manifest bytes fail closed and audit faults roll back manifest and reservation together',function() {
 $fixture=execution_context(); [$context,$credential,$plan,$catalog]=$fixture; [$db,$tracker,$w,$asset,$approval,$spending]=$context;
 $trigger=$db->table('audit_events').'_manifest_fault';
 $db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW BEGIN IF NEW.action = 'ai_reserved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture fault'; END IF; END");
 try { try { execution_reserve($fixture); throw new LogicException('Fault accepted'); } catch(RuntimeException $error) {} } finally { $db->query('DROP TRIGGER '.$trigger); }
 equal($spending->status($w)['reserved'],'0.000000000000'); equal($db->rows('SELECT id FROM '.$db->table('ai_execution_manifests').' WHERE workspace_id = %d',[$w]),[]);
 $request=execution_reserve($fixture); $id=(int)$request['id']; $row=$db->row('SELECT * FROM '.$db->table('ai_execution_manifests').' WHERE workspace_id = %d AND request_id = %d',[$w,$id]);
 try { $db->update_object('ai_execution_manifests',$w,(int)$row['id'],['payload_json'=>'{}']); provider_conflict(fn()=>$spending->manifest($w,$id)); provider_conflict(fn()=>$spending->dispatch($w,$id,$credential,$catalog,true)); } finally { $db->update_object('ai_execution_manifests',$w,(int)$row['id'],['payload_json'=>$row['payload_json']]); }
 equal($spending->request($w,$id)['state'],'reserved'); equal($spending->manifest($w,$id)['plan'],$plan);
 $foreign=(int)$tracker->create_workspace(['name'=>'Foreign manifest workspace'])['id']; try { $spending->manifest($foreign,$id); throw new LogicException('Foreign manifest read'); } catch(OutOfBoundsException $error) {}
});
test('Schema 18 upgrades from 17 repeatedly without fabricating legacy manifests',function() {
 global $wpdb; $fixture=execution_context(); [$context]=$fixture; [$db,$tracker,$w,$asset,$approval,$spending,$legacy]=$context;
 $request=execution_reserve($fixture); $before=$spending->manifest($w,(int)$request['id']); $original=$wpdb; $connection=clone $wpdb; $connection->result=null; $connection->prefix=substr($db->table('ai_requests'),0,-strlen('tgit_ai_requests'));
 try { $wpdb=$connection; update_option('tgit_schema_version','17'); Installer::install(); Installer::install(); equal(get_option('tgit_schema_version'),Installer::VERSION); equal($spending->manifest($w,(int)$request['id']),$before); equal($spending->manifest($w,$legacy),null); equal($db->row('SHOW TABLE STATUS LIKE %s',[$db->table('ai_execution_manifests')])['Engine'],'InnoDB'); } finally { $original->result=null; $wpdb=$original; }
});
