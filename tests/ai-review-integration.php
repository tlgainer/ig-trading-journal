<?php
/** Retained isolated synthetic reviews and spending; no external transport. */
use GainerInteractive\IGTradingJournal\Application\AiReviews;
use GainerInteractive\IGTradingJournal\Application\AiEvidencePreview;
use GainerInteractive\IGTradingJournal\Application\AiSpending;
use GainerInteractive\IGTradingJournal\Application\Tracker;
use GainerInteractive\IGTradingJournal\Infrastructure\Database;
use GainerInteractive\IGTradingJournal\Infrastructure\Installer;
if(!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE!==true) throw new RuntimeException('Disposable site required.');
function review_context(string $state='settled'): array {
 global $wpdb,$db,$tracker,$owner;
 $original=[$wpdb,$db,$tracker]; $connection=clone $wpdb; $connection->result=null; $connection->prefix='fixture_rev_'.substr(str_replace('-','',wp_generate_uuid4()),0,10).'_';
 try {
  $wpdb=$connection; Installer::install(); $db=new Database($connection); $tracker=new Tracker($db,$owner,wp_generate_uuid4());
  [$market,$w,$asset,$mapping,$ids]=saved_metric_context(); $evidence=new AiEvidencePreview($db,$owner,wp_generate_uuid4()); $preview=$evidence->preview($w,$asset,$ids); $approval=$evidence->approve($w,$asset,$ids,$preview['fingerprint'],'review-approval');
  $spending=new AiSpending($db,$owner,wp_generate_uuid4(),ai_now()); $spending->configure($w,['enabled'=>true,'monthly_cap'=>'15','model'=>'fixture-text-model','expected_config_id'=>0],['fixture-text-model'=>ai_pricing()]); $spending->enroll($w,true,0);
  $request=$spending->reserve($w,ai_digest('review-fixture-key'),'review-reservation',$preview['fingerprint'],10000,2000);
  if($state!=='reserved') $spending->dispatch($w,(int)$request['id'],ai_digest('review-fixture-key'));
  if($state==='settled') $spending->reconcile($w,(int)$request['id'],['input_tokens'=>100,'cached_input_tokens'=>0,'output_tokens'=>20]);
  if($state==='uncertain') $spending->reconcile($w,(int)$request['id']);
  return [$db,$tracker,$w,$asset,(int)$approval['id'],$spending,(int)$request['id'],new AiReviews($db,$owner,wp_generate_uuid4()),review_response(array_values($ids))];
 } finally { [$wpdb,$db,$tracker]=$original; }
}
test('Schema 15 upgrades from 14 and repeated activation preserves approved evidence',function() use($db) {
 $before=$db->row('SELECT COUNT(*) AS total FROM '.$db->table('ai_evidence_bundles'))['total']; update_option('tgit_schema_version','14'); Installer::install(); equal(get_option('tgit_schema_version'),'15');
 equal($db->row('SHOW TABLE STATUS LIKE %s',[$db->table('ai_reviews')])['Engine'],'InnoDB'); Installer::install(); equal($db->row('SELECT COUNT(*) AS total FROM '.$db->table('ai_evidence_bundles'))['total'],$before);
});
test('AI review storage binds settled output to approved evidence without modifying spending or ledger',function() {
 [$db,$tracker,$w,$asset,$approval,$spending,$request,$reviews,$response]=review_context();
 $before=[]; foreach(['transactions','ai_requests','ai_request_events'] as $table) $before[$table]=$db->rows('SELECT * FROM '.$db->table($table).' WHERE workspace_id = %d',[$w]);
 $saved=$reviews->save($w,$approval,$request,$response); $read=$reviews->read($w,$saved['id']); equal($read['output']['summary'],$response['summary']); equal($read['approval_id'],$approval); equal($read['request_id'],$request);
 $response['findings'][0]['source_ids']=array_reverse($response['findings'][0]['source_ids']); equal($reviews->save($w,$approval,$request,$response),$saved);
 $response['summary']='Changed result'; provider_conflict(fn()=>$reviews->save($w,$approval,$request,$response));
 foreach($before as $table=>$rows) equal($db->rows('SELECT * FROM '.$db->table($table).' WHERE workspace_id = %d',[$w]),$rows);
 foreach(['credential_fingerprint','pricing_json','actor_id','output_json'] as $field) equal(array_key_exists($field,$read),false);
 $page=$reviews->history($w,$asset,0,1); equal($page['items'],[$saved]); equal($page['next_cursor'],(string)$saved['id']); equal($reviews->history($w,$asset,$saved['id'],1)['items'],[]);
 rejects(fn()=>$reviews->history($w,$asset,0,101));
});
test('AI review persistence rejects uncertain, unsent, mismatched and foreign requests',function() {
 foreach(['reserved','dispatched','uncertain'] as $state) { [$db,$tracker,$w,$asset,$approval,$spending,$request,$reviews,$response]=review_context($state); provider_conflict(fn()=>$reviews->save($w,$approval,$request,$response)); equal($reviews->history($w,$asset)['items'],[]); }
 [$db,$tracker,$w,$asset,$approval,$spending,$request,$reviews,$response]=review_context();
 $response['model']='different-model'; rejects(fn()=>$reviews->save($w,$approval,$request,$response)); $response['model']='fixture-text-model';
 $other=(int)$tracker->create_workspace(['name'=>'Foreign review scope'])['id'];
 try { $reviews->save($other,$approval,$request,$response); throw new LogicException('Foreign review accepted'); } catch(OutOfBoundsException $error) {}
 $bad=$spending->reserve($w,ai_digest('review-fixture-key'),'unmatched-reservation',ai_digest('other-evidence'),10000,2000); $spending->dispatch($w,(int)$bad['id'],ai_digest('review-fixture-key')); $spending->reconcile($w,(int)$bad['id'],['input_tokens'=>100,'cached_input_tokens'=>0,'output_tokens'=>20]);
 provider_conflict(fn()=>$reviews->save($w,$approval,(int)$bad['id'],$response));
});
test('AI review reads are owner scoped and preserve history after policy disable and author revocation',function() use($owner,$viewer) {
 [$db,$tracker,$w,$asset,$approval,$spending,$request,$reviews,$response]=review_context(); $saved=$reviews->save($w,$approval,$request,$response); $original=$reviews->read($w,$saved['id']);
 $status=$spending->status($w); $spending->configure($w,['enabled'=>false,'monthly_cap'=>'0','model'=>'','expected_config_id'=>$status['config_id']],[]); equal($reviews->read($w,$saved['id']),$original);
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'active']); $other=new AiReviews($db,$viewer,wp_generate_uuid4());
 try { $other->history($w,$asset); throw new LogicException('Viewer history read'); } catch(DomainException $error) {}
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'owner','state'=>'active']); equal($other->read($w,$saved['id']),$original); provider_conflict(fn()=>$other->save($w,$approval,$request,$response));
 $tracker->set_member($w,['wp_user_id'=>$owner,'role'=>'owner','state'=>'revoked']); equal($other->read($w,$saved['id']),$original);
 try { $reviews->read($w,$saved['id']); throw new LogicException('Revoked owner read'); } catch(DomainException $error) {}
});
test('AI review audit rollback leaves no partial output and corrupted history fails closed',function() {
 [$db,$tracker,$w,$asset,$approval,$spending,$request,$reviews,$response]=review_context(); $trigger=$db->table('audit_events').'_review_fault';
 $db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture fault'");
 try { try { $reviews->save($w,$approval,$request,$response); throw new LogicException('Audit fault accepted'); } catch(RuntimeException $error) {} } finally { $db->query('DROP TRIGGER '.$trigger); }
 equal($reviews->history($w,$asset)['items'],[]); equal($db->rows('SELECT * FROM '.$db->table('idempotency')." WHERE workspace_id = %d AND operation = 'ai.review.save'",[$w]),[]);
 equal($spending->request($w,$request)['state'],'settled'); $saved=$reviews->save($w,$approval,$request,$response); $original=$db->object('ai_reviews',$w,$saved['id']);
 try { $db->update_object('ai_reviews',$w,$saved['id'],['output_json'=>'{}']); provider_conflict(fn()=>$reviews->read($w,$saved['id'])); } finally { $db->update_object('ai_reviews',$w,$saved['id'],['output_json'=>$original['output_json']]); }
 equal($reviews->read($w,$saved['id'])['output']['summary'],$response['summary']);
});

test('Saved AI review REST is owner-only, private, scoped and paginated without write endpoints',function() use($owner,$viewer) {
 global $wpdb;
 [$db,$tracker,$w,$asset,$approval,$spending,$request,$reviews,$response]=review_context();
 $response['summary']='Synthetic summary <img src=x onerror="window.reviewInjected=true">'; $first=$reviews->save($w,$approval,$request,$response);
 $next=$spending->reserve($w,ai_digest('review-fixture-key'),'second-history-request',$first['evidence_fingerprint'],10000,2000); $spending->dispatch($w,(int)$next['id'],ai_digest('review-fixture-key')); $spending->reconcile($w,(int)$next['id'],['input_tokens'=>100,'cached_input_tokens'=>0,'output_tokens'=>20]);
 $response['response_id']='fixture_response_2'; $response['summary']='Second synthetic interpretation'; $second=$reviews->save($w,$approval,(int)$next['id'],$response);
 $original=$wpdb; $connection=clone $wpdb; $connection->result=null; $connection->prefix=substr($db->table('ai_reviews'),0,-strlen('tgit_ai_reviews'));
 try {
  $wpdb=$connection; wp_set_current_user($owner); $base='workspaces/'.$w; $path=$base.'/assets/'.$asset.'/ai-reviews';
  $page=provider_rest('GET',$path.'?limit=1'); equal($page->get_status(),200); equal($page->get_data()['data']['items'],[$first]); equal($page->get_data()['data']['next_cursor'],(string)$first['id']); equal($page->get_headers()['Cache-Control'],'private, no-store, max-age=0');
  equal(provider_rest('GET',$path.'?limit=1&after='.$first['id'])->get_data()['data']['items'],[$second]); equal(provider_rest('GET',$path.'?limit=1&after='.$second['id'])->get_data()['data']['items'],[]);
  equal(provider_rest('GET',$path.'?limit=101')->get_status(),400); equal(provider_rest('GET',$path.'?after=-1')->get_status(),400);
  $read=provider_rest('GET',$base.'/ai-reviews/'.$first['id']); equal($read->get_status(),200); equal($read->get_data()['data']['output']['summary'],'Synthetic summary <img src=x onerror="window.reviewInjected=true">');
  foreach(['credential_fingerprint','pricing_json','actor_id'] as $field) equal(array_key_exists($field,$read->get_data()['data']),false);
  equal(provider_rest('POST',$path,review_response())->get_status(),404); equal(provider_rest('POST',$base.'/ai-reviews/'.$first['id'],review_response())->get_status(),404);
  $other=(int)$tracker->create_workspace(['name'=>'Foreign review history'])['id']; $otherAsset=(int)$tracker->create_object($other,'assets',['symbol'=>'OTHER','exchange'=>'TESTEX','asset_class'=>'stock','quote_currency'=>'USD'])['id']; equal(provider_rest('GET','workspaces/'.$other.'/ai-reviews/'.$first['id'])->get_status(),404); equal(provider_rest('GET','workspaces/'.$other.'/assets/'.$asset.'/ai-reviews')->get_status(),404);
  $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'active']); wp_set_current_user($viewer); equal(provider_rest('GET',$path)->get_status(),403); equal(provider_rest('GET',$base.'/ai-reviews/'.$first['id'])->get_status(),403);
  wp_set_current_user($owner);
  file_put_contents(dirname(__DIR__).'/tmp/ai-review-browser-fixtures.json',wp_json_encode(['workspace'=>$w,'other_workspace'=>$other,'other_asset'=>$otherAsset,'asset'=>$asset,'approval'=>$approval,'first'=>$first['id'],'second'=>$second['id'],'prefix'=>$connection->prefix]));
 } finally { $original->result=null; $wpdb=$original; wp_set_current_user($owner); }
});
