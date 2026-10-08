<?php
/** Synthetic owner recovery; all external HTTP is denied. */
if(!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE!==true) throw new RuntimeException('Disposable site required.');
test('Unsent cancellation releases a hold exactly once and preserves immutable request evidence',function() {
 $fixture=execution_context(); $context=$fixture[0]; [$db,$tracker,$w,$asset,$approval,$spending]=$context; $id=(int)execution_reserve($fixture)['id'];
 $before=$db->object('ai_requests',$w,$id); $manifest=$spending->manifest($w,$id);
 publication_rest_context($context,function() use($w,$id) {
  $path='workspaces/'.$w.'/ai-requests'; $rows=provider_rest('GET',$path)->get_data()['data']['items']; $row=array_values(array_filter($rows,fn($r)=>$r['id']===$id))[0]; equal($row['can_cancel'],true);
  $result=provider_rest('POST',$path.'/'.$id.'/cancel',[]); equal($result->get_status(),200); equal($result->get_data()['data'],['request_id'=>$id,'state'=>'cancelled']);
  equal(provider_rest('POST',$path.'/'.$id.'/cancel',[])->get_data()['data'],$result->get_data()['data']);
 });
 equal($spending->status($w)['reserved'],'0.000000000000'); equal($spending->can_cancel_unsent($w,$id),false); equal($db->object('ai_requests',$w,$id),$before); equal($spending->manifest($w,$id),$manifest);
 equal(count($db->rows('SELECT id FROM '.$db->table('ai_request_events')." WHERE workspace_id=%d AND request_id=%d AND state='cancelled'",[$w,$id])),1);
 provider_conflict(fn()=>$spending->dispatch($w,$id,$fixture[1],$fixture[3],true));
});
test('Cancellation rejects overrides, foreign scope, other owners, viewers and a dispatch race',function() {
 global $owner,$viewer; $fixture=execution_context(); $context=$fixture[0]; [$db,$tracker,$w,$asset,$approval,$spending]=$context; $id=(int)execution_reserve($fixture)['id'];
 publication_rest_context($context,function() use($w,$id,$tracker,$spending,$fixture,$owner,$viewer) {
  $path='workspaces/'.$w.'/ai-requests/'.$id.'/cancel'; equal(provider_rest('POST',$path,['charge'=>'0'])->get_status(),400);
  $foreign=(int)$tracker->create_workspace(['name'=>'Foreign cancellation'])['id']; equal(provider_rest('POST','workspaces/'.$foreign.'/ai-requests/'.$id.'/cancel',[])->get_status(),404);
  $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'owner','state'=>'active']); wp_set_current_user($viewer); equal(provider_rest('POST',$path,[])->get_status(),409);
  $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'active']); equal(provider_rest('POST',$path,[])->get_status(),403); wp_set_current_user($owner);
  equal($spending->can_cancel_unsent($w,$id),true); $spending->dispatch($w,$id,$fixture[1],$fixture[3],true); equal(provider_rest('POST',$path,[])->get_status(),409); equal($spending->can_cancel_unsent($w,$id),false);
 }); equal($spending->status($w)['reserved'],'0.022500000000');
});
test('Cancellation audit failure rolls back the reservation release',function() {
 $fixture=execution_context(); [$db,$tracker,$w,$asset,$approval,$spending]=$fixture[0]; $id=(int)execution_reserve($fixture)['id']; $trigger=$db->table('audit_events').'_cancel_fault';
 $db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW BEGIN IF NEW.action='ai_cancelled' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture fault'; END IF; END");
 try { try { $spending->cancel_unsent($w,$id); throw new LogicException('Fault accepted'); } catch(RuntimeException $error) {} } finally { $db->query('DROP TRIGGER '.$trigger); }
 equal($spending->request($w,$id)['state'],'reserved'); equal($spending->status($w)['reserved'],'0.022500000000'); equal($spending->cancel_unsent($w,$id)['state'],'cancelled');
});
