<?php
/** Read-only preflight with denied external HTTP and synthetic approvals. */
test('AI preflight explains saved setup without returning evidence or changing spending',function() {
 $context=receipt_context(); [$db,$tracker,$w,$asset,$approval,$spending]=$context; $before=[];
 foreach(['ai_requests','ai_request_events','ai_evidence_bundles','ai_response_receipts'] as $table) $before[$table]=$db->rows('SELECT * FROM '.$db->table($table).' WHERE workspace_id=%d',[$w]);
 publication_rest_context($context,function() use($w,$asset,$approval) {
  $response=provider_rest('GET','workspaces/'.$w.'/ai-evidence/'.$approval.'/preflight'); equal($response->get_status(),200); $data=$response->get_data()['data'];
  equal(array_keys($data),['approval_id','asset_id','model','remaining','checks','blockers','can_generate']); equal($data['approval_id'],$approval); equal($data['asset_id'],$asset); equal($data['can_generate'],false); equal($data['checks']['original_owner'],true); equal(in_array('workflow_available',$data['blockers'],true),true);
 }); foreach($before as $table=>$rows) equal($db->rows('SELECT * FROM '.$db->table($table).' WHERE workspace_id=%d',[$w]),$rows);
});
test('AI preflight denies foreign approvals and viewers and flags a different original owner',function() {
 global $owner,$viewer; $context=receipt_context(); [$db,$tracker,$w,$asset,$approval]=$context;
 publication_rest_context($context,function() use($w,$approval,$tracker,$owner,$viewer) {
  $path='workspaces/'.$w.'/ai-evidence/'.$approval.'/preflight'; $foreign=(int)$tracker->create_workspace(['name'=>'Foreign preflight'])['id']; equal(provider_rest('GET','workspaces/'.$foreign.'/ai-evidence/'.$approval.'/preflight')->get_status(),404);
  $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'owner','state'=>'active']); wp_set_current_user($viewer); equal(provider_rest('GET',$path)->get_data()['data']['checks']['original_owner'],false);
  $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'active']); equal(provider_rest('GET',$path)->get_status(),403); wp_set_current_user($owner);
 });
});

test('AI preflight reports paused budget and rejects corrupted approved evidence',function() {
 $context=receipt_context(); [$db,$tracker,$w,$asset,$approval,$spending]=$context; $status=$spending->status($w);
 $spending->configure($w,['enabled'=>false,'monthly_cap'=>'0','model'=>'fixture-text-model','expected_config_id'=>$status['config_id']],[]);
 publication_rest_context($context,function() use($w,$approval) { $data=provider_rest('GET','workspaces/'.$w.'/ai-evidence/'.$approval.'/preflight')->get_data()['data']; equal($data['checks']['budget_available'],false); equal($data['checks']['policy_enabled'],false); });
 $original=$db->object('ai_evidence_bundles',$w,$approval);
 try { $db->update_object('ai_evidence_bundles',$w,$approval,['bundle_json'=>'{}']); publication_rest_context($context,function() use($w,$approval) { equal(provider_rest('GET','workspaces/'.$w.'/ai-evidence/'.$approval.'/preflight')->get_status(),409); }); }
 finally { $db->update_object('ai_evidence_bundles',$w,$approval,['bundle_json'=>$original['bundle_json']]); }
});
