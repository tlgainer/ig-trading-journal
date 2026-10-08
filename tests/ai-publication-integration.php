<?php
/** Owner publication from stored synthetic receipts; no external calls. */
if(!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE!==true) throw new RuntimeException('Disposable site required.');
function publication_rest_context(array $context, callable $scenario): void {
 global $wpdb,$owner; $original=$wpdb; $connection=clone $wpdb; $connection->result=null; $connection->prefix=substr($context[0]->table('ai_requests'),0,-strlen('tgit_ai_requests'));
 $deny=function() { throw new LogicException('Publication attempted external HTTP'); }; add_filter('pre_http_request',$deny);
 try { $wpdb=$connection; wp_set_current_user($owner); $scenario(); } finally { remove_filter('pre_http_request',$deny); $original->result=null; $wpdb=$original; wp_set_current_user($owner); }
}

test('Owner REST publication stores one immutable review without spending, ledger or HTTP changes',function() {
 $context=receipt_context(); [$db,$tracker,$w,$asset,$approval,$spending,$request,$reviews,$response]=$context; $spending->receive($w,$request,receipt_body($response)); $before=[];
 foreach(['ai_requests','ai_request_events','ai_response_receipts','transactions'] as $table) $before[$table]=$db->rows('SELECT * FROM '.$db->table($table).' WHERE workspace_id=%d',[$w]);
 publication_rest_context($context,function() use($w,$request,$reviews,$asset,&$saved) {
  $path='workspaces/'.$w.'/ai-requests'; $page=provider_rest('GET',$path)->get_data()['data']; $row=array_values(array_filter($page['items'],fn($row)=>$row['id']===$request))[0]; equal($row['can_publish'],true); equal($row['review_id'],null);
  $result=provider_rest('POST',$path.'/'.$request.'/publish',[]); equal($result->get_status(),200); $saved=$result->get_data()['data']; equal($saved['request_id'],$request);
  equal(provider_rest('POST',$path.'/'.$request.'/publish',[])->get_data()['data'],$saved);
  $row=array_values(array_filter(provider_rest('GET',$path)->get_data()['data']['items'],fn($row)=>$row['id']===$request))[0]; equal($row['can_publish'],false); equal($row['review_id'],$saved['id']); equal(count($reviews->history($w,$asset)['items']),1);
 }); foreach($before as $table=>$rows) equal($db->rows('SELECT * FROM '.$db->table($table).' WHERE workspace_id=%d',[$w]),$rows);
});

test('Publication rejects client output, foreign scope and another owner or viewer',function() {
 global $owner,$viewer; $context=receipt_context(); [$db,$tracker,$w,$asset,$approval,$spending,$request,$reviews,$response]=$context; $spending->receive($w,$request,receipt_body($response));
 publication_rest_context($context,function() use($tracker,$w,$request,$viewer,$owner) {
  $path='workspaces/'.$w.'/ai-requests/'.$request.'/publish'; equal(provider_rest('POST',$path,['summary'=>'Injected output'])->get_status(),400); equal(provider_rest('POST',$path,['model'=>'other-model'])->get_status(),400);
  $foreign=(int)$tracker->create_workspace(['name'=>'Foreign publication scope'])['id']; equal(provider_rest('POST','workspaces/'.$foreign.'/ai-requests/'.$request.'/publish',[])->get_status(),404);
  $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'owner','state'=>'active']); wp_set_current_user($viewer); equal(provider_rest('POST',$path,[])->get_status(),409);
  $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'active']); equal(provider_rest('POST',$path,[])->get_status(),403); wp_set_current_user($owner);
 }); equal($reviews->history($w,$asset)['items'],[]);
});

test('Refused, invalid, pending and overrun stored output never grants publication',function() {
 foreach(['refused','invalid','pending','overrun'] as $case) {
  $context=receipt_context(); [$db,$tracker,$w,$asset,$approval,$spending,$request,$reviews,$response]=$context;
  if($case==='refused') $response['output'][1]['content']=[['type'=>'refusal','refusal'=>'Fixture refusal']];
  if($case==='invalid') $response['output'][1]['content'][0]['text']='{"summary":"x","findings":[{"text":"x","source_ids":[999999]}]}';
  if($case==='overrun') { $response['usage']['input_tokens']=10000; $response['usage']['input_tokens_details']['cached_tokens']=0; $response['usage']['output_tokens']=5000; $response['usage']['total_tokens']=15000; }
  if($case!=='pending') $spending->receive($w,$request,receipt_body($response));
  equal($reviews->publication_status($w,$request),['can_publish'=>false,'review_id'=>null]);
  publication_rest_context($context,function() use($w,$request) { equal(provider_rest('POST','workspaces/'.$w.'/ai-requests/'.$request.'/publish',[])->get_status(),409); }); equal($reviews->history($w,$asset)['items'],[]);
 }
});

test('Publication audit failure rolls back review and safely retries without changing charges',function() {
 $context=receipt_context(); [$db,$tracker,$w,$asset,$approval,$spending,$request,$reviews,$response]=$context; $spending->receive($w,$request,receipt_body($response)); $before=$spending->request($w,$request); $trigger=$db->table('audit_events').'_publication_fault';
 $db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW BEGIN IF NEW.action = 'ai.review.saved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture fault'; END IF; END");
 try { publication_rest_context($context,function() use($w,$request) { equal(provider_rest('POST','workspaces/'.$w.'/ai-requests/'.$request.'/publish',[])->get_status(),500); }); } finally { $db->query('DROP TRIGGER '.$trigger); }
 equal($reviews->history($w,$asset)['items'],[]); equal($spending->request($w,$request),$before);
 publication_rest_context($context,function() use($w,$request) { equal(provider_rest('POST','workspaces/'.$w.'/ai-requests/'.$request.'/publish',[])->get_status(),200); }); equal(count($reviews->history($w,$asset)['items']),1); equal($spending->request($w,$request),$before);
});

test('Publication UI fixture retains completed receipts with processing switched off',function() {
 $context=receipt_context(); [$db,$tracker,$w,$asset,$approval,$spending,$request,$reviews,$response]=$context; $spending->receive($w,$request,receipt_body($response)); $status=$spending->status($w);
 $spending->configure($w,['enabled'=>false,'monthly_cap'=>'15','model'=>'fixture-text-model','expected_config_id'=>$status['config_id']],[]);
 file_put_contents(dirname(__DIR__).'/tmp/ai-publication-browser-fixtures.json',wp_json_encode(['workspace'=>$w,'asset'=>$asset,'request'=>$request,'prefix'=>substr($db->table('ai_requests'),0,-strlen('tgit_ai_requests'))]));
 equal($reviews->publication_status($w,$request)['can_publish'],true);
});
