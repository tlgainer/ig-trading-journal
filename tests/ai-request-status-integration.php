<?php
/** Read-only operation recovery; all external HTTP is denied. */
test('Saved operation status resolves lifecycle without counting, sending or modifying history',function() {
 $fixture=execution_context(); $context=$fixture[0]; [$db,$tracker,$w,$asset,$approval,$spending]=$context; $key=wp_generate_uuid4(); $id=(int)execution_reserve($fixture,$key)['id'];
 $check=function($state) use($context,$db,$w,$approval,$key,$id) {
  $before=[]; foreach(['ai_requests','ai_request_events','ai_response_receipts','ai_execution_manifests','ai_configs'] as $table) $before[$table]=$db->rows('SELECT * FROM '.$db->table($table).' WHERE workspace_id=%d',[$w]);
  publication_rest_context($context,function() use($w,$approval,$key,$id,$state) { $path='workspaces/'.$w.'/ai-evidence/'.$approval.'/generation?operation_key='.$key; $response=provider_rest('GET',$path); equal($response->get_status(),200); equal($response->get_data()['data'],['state'=>$state,'request_id'=>$id]); equal(provider_rest('GET',$path)->get_data()['data'],['state'=>$state,'request_id'=>$id]); });
  foreach($before as $table=>$rows) equal($db->rows('SELECT * FROM '.$db->table($table).' WHERE workspace_id=%d',[$w]),$rows);
 };
 $check('reserved'); $spending->dispatch($w,$id,$fixture[1],$fixture[3],true); $check('dispatched'); $spending->reconcile($w,$id,null); $check('uncertain');
 $response=response_fixture($context[8]['findings'][0]['source_ids']); $spending->receive($w,$id,receipt_body($response)); $check('settled');
});
test('Operation lookup rejects invalid identity, foreign scope, another owner and viewers',function() {
 global $owner,$viewer; $fixture=execution_context(); $context=$fixture[0]; [$db,$tracker,$w,$asset,$approval,$spending]=$context; $key=wp_generate_uuid4(); $id=(int)execution_reserve($fixture,$key)['id'];
 publication_rest_context($context,function() use($w,$approval,$key,$tracker,$owner,$viewer) {
  $base='workspaces/'.$w.'/ai-evidence/'.$approval.'/generation'; $path=$base.'?operation_key='.$key;
  equal(provider_rest('GET',$base)->get_status(),400); equal(provider_rest('GET',$base.'?operation_key=invalid')->get_status(),400); equal(provider_rest('GET',$base.'?operation_key='.wp_generate_uuid4())->get_data()['data'],['state'=>'not_reserved']);
  $foreign=(int)$tracker->create_workspace(['name'=>'Foreign operation lookup'])['id']; equal(provider_rest('GET','workspaces/'.$foreign.'/ai-evidence/'.$approval.'/generation?operation_key='.$key)->get_status(),404);
  $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'owner','state'=>'active']); wp_set_current_user($viewer); equal(provider_rest('GET',$path)->get_status(),409);
  $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'active']); equal(provider_rest('GET',$path)->get_status(),403); wp_set_current_user($owner);
 }); equal($spending->request($w,$id)['state'],'reserved');
});
test('Operation lookup detects concurrent generation and preserves cancelled reservations',function() {
 $fixture=execution_context(); $context=$fixture[0]; [$db,$tracker,$w,$asset,$approval,$spending]=$context; $key=wp_generate_uuid4(); $id=(int)execution_reserve($fixture,$key)['id'];
 $spending->generation_operation($w,$approval,$key,2000,function() use($context,$w,$approval,$key) { publication_rest_context($context,function() use($w,$approval,$key) { equal(provider_rest('GET','workspaces/'.$w.'/ai-evidence/'.$approval.'/generation?operation_key='.$key)->get_data()['data'],['state'=>'in_progress']); }); return []; });
 $spending->cancel_unsent($w,$id); publication_rest_context($context,function() use($w,$approval,$key,$id) { equal(provider_rest('GET','workspaces/'.$w.'/ai-evidence/'.$approval.'/generation?operation_key='.$key)->get_data()['data'],['state'=>'cancelled','request_id'=>$id]); });
});
