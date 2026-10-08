<?php
/** Owner REST generation; synthetic intercepted HTTP only. */
function generation_rest_context(array $context, callable $scenario): void {
 global $wpdb,$owner; $original=$wpdb; $connection=clone $wpdb; $connection->result=null; $connection->prefix=substr($context[0]->table('ai_requests'),0,-strlen('tgit_ai_requests'));
 try { $wpdb=$connection; wp_set_current_user($owner); $scenario(); } finally { $original->result=null; $wpdb=$original; wp_set_current_user($owner); }
}
test('Owner enablement validates server evidence and generation sends once with separate publication',function() {
 $fixture=execution_context(); $context=$fixture[0]; [$db,$tracker,$w,$asset,$approval,$spending,$legacy,$reviews]=$context; $key=wp_generate_uuid4(); $response=response_fixture($context[8]['findings'][0]['source_ids']); $calls=[];
 generation_mock(function($args,$url) use(&$calls,$response) { $calls[]=$url; return transport_reply(str_ends_with($url,'input_tokens')?'{"object":"response.input_tokens","input_tokens":1000}':receipt_body($response)); },function() use($context,$w,$approval,$spending,$key,$reviews,$asset,&$calls) {
  generation_rest_context($context,function() use($w,$approval,$spending,$key,$reviews,$asset,&$calls) {
   $base='workspaces/'.$w; $config=$spending->status($w)['config_id'];
   $enabled=provider_rest('POST',$base.'/ai-settings',['enabled'=>true,'monthly_cap'=>'15','model'=>'fixture-text-model','expected_config_id'=>$config]); equal($enabled->get_status(),200); equal($enabled->get_data()['data']['enabled'],true); equal($calls,[]);
   $preflight=provider_rest('GET',$base.'/ai-evidence/'.$approval.'/preflight')->get_data()['data']; equal($preflight['can_generate'],true); $body=['expected_config_id'=>$preflight['config_id']]; $path=$base.'/ai-evidence/'.$approval.'/generate';
   $result=provider_rest('POST',$path,$body,$key); equal($result->get_status(),200); $saved=$result->get_data()['data']; equal($saved['state'],'settled'); equal(array_keys($saved),['state','request_id']); equal(count($calls),2); equal($reviews->history($w,$asset)['items'],[]);
   equal(provider_rest('POST',$path,$body,$key)->get_data()['data'],$saved); equal(provider_rest('GET',$base.'/ai-evidence/'.$approval.'/generation?operation_key='.$key)->get_data()['data'],$saved); equal(count($calls),2);
   $off=provider_rest('POST',$base.'/ai-settings',['enabled'=>false,'monthly_cap'=>'15','model'=>'fixture-text-model','expected_config_id'=>$preflight['config_id']]); equal($off->get_status(),200); equal($off->get_data()['data']['enabled'],false);
   equal(provider_rest('POST',$path,$body,$key)->get_data()['data'],$saved); equal(count($calls),2); equal(provider_rest('POST',$path,['expected_config_id'=>$off->get_data()['data']['config_id']],$key)->get_status(),409);
   equal(provider_rest('POST',$base.'/ai-requests/'.$saved['request_id'].'/publish',[])->get_status(),200); equal(count($reviews->history($w,$asset)['items']),1); equal(count($calls),2);
  });
 });
});
test('Owner generation rejects injected controls, stale policy and unauthorized context before HTTP',function() {
 global $owner,$viewer; $fixture=execution_context(); $context=$fixture[0]; [$db,$tracker,$w,$asset,$approval,$spending]=$context;
 generation_mock(function() { throw new LogicException('Denied generation attempted HTTP'); },function() use($context,$tracker,$w,$approval,$spending,$owner,$viewer) {
  generation_rest_context($context,function() use($tracker,$w,$approval,$spending,$owner,$viewer) {
   $path='workspaces/'.$w.'/ai-evidence/'.$approval.'/generate'; $config=$spending->status($w)['config_id']; $body=['expected_config_id'=>$config];
   foreach(['prompt'=>'injected','model'=>'other','pricing'=>[],'output_tokens'=>1000000,'approval_id'=>$approval] as $field=>$value) equal(provider_rest('POST',$path,array_replace($body,[$field=>$value]),wp_generate_uuid4())->get_status(),400);
   equal(provider_rest('POST',$path,$body)->get_status(),400); equal(provider_rest('POST',$path,$body,'invalid')->get_status(),400); equal(provider_rest('POST',$path,['expected_config_id'=>true],wp_generate_uuid4())->get_status(),400);
   $foreign=(int)$tracker->create_workspace(['name'=>'Foreign owner generation'])['id']; $spending->enroll($foreign,true,0); equal(provider_rest('POST','workspaces/'.$foreign.'/ai-evidence/'.$approval.'/generate',$body,wp_generate_uuid4())->get_status(),404);
   $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'owner','state'=>'active']); wp_set_current_user($viewer); equal(provider_rest('POST',$path,$body,wp_generate_uuid4())->get_status(),409);
   $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'active']); equal(provider_rest('POST',$path,$body,wp_generate_uuid4())->get_status(),403); wp_set_current_user($owner);
   $changed=$spending->configure($w,['enabled'=>true,'monthly_cap'=>'16','model'=>'fixture-text-model','expected_config_id'=>$config],['fixture-text-model'=>ai_pricing()]); equal(provider_rest('POST',$path,$body,wp_generate_uuid4())->get_status(),409);
   $spending->enroll($w,false,$spending->status($w)['enrollment_id']); equal(provider_rest('POST',$path,['expected_config_id'=>(int)$changed['id']],wp_generate_uuid4())->get_status(),409);
  });
 }); equal($spending->status($w)['reserved'],'0.000000000000');
});
test('Owner count failure creates no reservation and explicit same-key retry is bounded',function() {
 $fixture=execution_context(); $context=$fixture[0]; [$db,$tracker,$w,$asset,$approval,$spending]=$context; $key=wp_generate_uuid4(); $calls=0;
 generation_mock(function($args,$url) use(&$calls) { ++$calls; if(!str_ends_with($url,'input_tokens')) throw new LogicException('Failed count sent response'); return new WP_Error('fixture_count_failed','private diagnostic'); },function() use($context,$w,$approval,$spending,$key,&$calls) {
  generation_rest_context($context,function() use($w,$approval,$spending,$key,&$calls) { $path='workspaces/'.$w.'/ai-evidence/'.$approval; $body=['expected_config_id'=>$spending->status($w)['config_id']]; equal(provider_rest('POST',$path.'/generate',$body,$key)->get_data()['data'],['state'=>'unavailable']); equal(provider_rest('GET',$path.'/generation?operation_key='.$key)->get_data()['data'],['state'=>'not_reserved']); equal($calls,1); equal(provider_rest('POST',$path.'/generate',$body,$key)->get_data()['data'],['state'=>'unavailable']); equal($calls,2); });
 }); equal($spending->status($w)['reserved'],'0.000000000000');
});
test('Owner uncertain delivery retains the hold and same-key POST never resends',function() {
 $fixture=execution_context(); $context=$fixture[0]; [$db,$tracker,$w,$asset,$approval,$spending]=$context; $key=wp_generate_uuid4(); $calls=0;
 generation_mock(function($args,$url) use(&$calls) { ++$calls; return str_ends_with($url,'input_tokens')?transport_reply('{"object":"response.input_tokens","input_tokens":1000}'):new WP_Error('fixture_uncertain','private diagnostic'); },function() use($context,$w,$approval,$spending,$key,&$calls) {
  generation_rest_context($context,function() use($w,$approval,$spending,$key,&$calls) { $path='workspaces/'.$w.'/ai-evidence/'.$approval; $body=['expected_config_id'=>$spending->status($w)['config_id']]; $result=provider_rest('POST',$path.'/generate',$body,$key)->get_data()['data']; equal($result['state'],'uncertain'); equal(provider_rest('POST',$path.'/generate',$body,$key)->get_data()['data'],$result); equal(provider_rest('GET',$path.'/generation?operation_key='.$key)->get_data()['data'],$result); equal($calls,2); });
 }); equal($spending->status($w)['reserved'],'0.022500000000');
});
test('Owner reviewed policy is rechecked atomically at reservation admission',function() {
 $fixture=execution_context(); [$db,$tracker,$w,$asset,$approval,$spending]=$fixture[0]; $old=$spending->status($w)['config_id'];
 $spending->configure($w,['enabled'=>true,'monthly_cap'=>'16','model'=>'fixture-text-model','expected_config_id'=>$old],['fixture-text-model'=>ai_pricing()]);
 provider_conflict(fn()=>$spending->reserve($w,$fixture[1],wp_generate_uuid4(),$fixture[2]['evidence_fingerprint'],1000,2000,$approval,$fixture[3],$fixture[4],$old)); equal($spending->status($w)['reserved'],'0.000000000000');
});
test('Owner generation browser fixture starts with shared processing and consent off',function() {
 global $owner; $fixture=execution_context(); $context=$fixture[0]; [$db,$tracker,$w,$asset,$approval,$spending]=$context; $status=$spending->status($w);
 $spending->configure($w,['enabled'=>false,'monthly_cap'=>'15','model'=>'fixture-text-model','expected_config_id'=>$status['config_id']],[]); $spending->enroll($w,false,$status['enrollment_id']);
 file_put_contents(dirname(__DIR__).'/tmp/ai-generation-browser-fixtures.json',wp_json_encode(['workspace'=>$w,'asset'=>$asset,'approval'=>$approval,'owner'=>$owner,'prefix'=>substr($db->table('ai_requests'),0,-strlen('tgit_ai_requests')),'catalog'=>TGIT_OPENAI_MODEL_EVIDENCE,'policies'=>TGIT_OPENAI_COUNT_EVIDENCE,'response'=>response_fixture($context[8]['findings'][0]['source_ids'])]));
 file_put_contents(dirname(__DIR__).'/tmp/ai-generation-http-calls.jsonl',''); equal($spending->status($w)['enabled'],false); equal($spending->status($w)['enrolled'],false);
});
