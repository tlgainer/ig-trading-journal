<?php
/** Read-only evidence preview fixtures; no external processing. */
use GainerInteractive\IGTradingJournal\Application\AiEvidencePreview;
use GainerInteractive\IGTradingJournal\Application\Journal;
if(!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE!==true) throw new RuntimeException('Disposable site required.');

test('AI evidence preview reads immutable scoped snapshots without changing ledger or AI spending',function() use($db,$owner) {
 [$market,$w,$asset,$mapping,$ids]=saved_metric_context(true); $preview=new AiEvidencePreview($db,$owner,wp_generate_uuid4());
 $snapshots=$market->fundamentals($w,$asset); $before=[];
 foreach(['transactions','audit_events','ai_configs','ai_enrollments','ai_requests','ai_request_events'] as $table) $before[$table]=$db->rows('SELECT * FROM '.$db->table($table).' WHERE workspace_id = %d',[$w]);
 $result=$preview->preview($w,$asset,$ids); equal(count($result['bundle']['reports']),2); equal($result['bundle']['thesis'],null);
 equal($preview->preview($w,$asset,array_reverse($ids,true)),$result); equal($market->fundamentals($w,$asset),$snapshots);
 foreach($before as $table=>$rows) equal($db->rows('SELECT * FROM '.$db->table($table).' WHERE workspace_id = %d',[$w]),$rows);
});

test('AI evidence preview includes only the selected plain thesis at its expected current revision',function() use($db,$owner) {
 [$market,$w,$asset,$mapping,$ids]=saved_metric_context(); $preview=new AiEvidencePreview($db,$owner,wp_generate_uuid4()); $journal=new Journal($db,$owner,wp_generate_uuid4());
 $input=['asset_id'=>$asset,'title'=>'Synthetic review','state'=>'planned','transaction_ids'=>[],'journal'=>['thesis'=>'<p>Selected <strong>idea</strong> &amp; evidence</p>','notes'=>'PRIVATE-NOTES','emotions'=>'PRIVATE-EMOTIONS']];
 $saved=$journal->save_trade($w,0,$input,'evidence-create'); $trade=(int)$saved['trade']['id']; $revision=(int)$saved['trade']['revision'];
 $first=$preview->preview($w,$asset,$ids,$trade,$revision); equal($first['bundle']['thesis']['text'],'Selected idea & evidence'); equal(str_contains(wp_json_encode($first),'PRIVATE-'),false);
 $row=$db->row('SELECT * FROM '.$db->table('trade_journals').' WHERE workspace_id = %d AND trade_id = %d AND revision = %d',[$w,$trade,$revision]);
 try { $db->update_object('trade_journals',$w,(int)$row['id'],['payload'=>'{invalid']); provider_conflict(fn()=>$preview->preview($w,$asset,$ids,$trade,$revision)); }
 finally { $db->update_object('trade_journals',$w,(int)$row['id'],['payload'=>$row['payload']]); }
 equal($db->object('trade_journals',$w,(int)$row['id']),$row);
 $input['expected_revision']=$revision; $input['journal']['thesis']='Updated idea'; $next=$journal->save_trade($w,$trade,$input,'evidence-edit');
 provider_conflict(fn()=>$preview->preview($w,$asset,$ids,$trade,$revision));
 $second=$preview->preview($w,$asset,$ids,$trade,(int)$next['trade']['revision']); equal($second['fingerprint']!==$first['fingerprint'],true); equal($first['bundle']['thesis']['text'],'Selected idea & evidence');
 foreach([[null,1],[$trade,null],[0,1]] as [$id,$rev]) rejects(fn()=>$preview->preview($w,$asset,$ids,$id,$rev));
});

test('AI evidence previews reject foreign evidence and non-owner membership',function() use($db,$owner,$viewer,$tracker) {
 [$market,$w,$asset,$mapping,$ids]=saved_metric_context(); [$foreign,$other,$otherAsset,$otherMapping,$otherIds]=saved_metric_context();
 $preview=new AiEvidencePreview($db,$owner,wp_generate_uuid4());
 try { $preview->preview($w,$otherAsset,$ids); throw new LogicException('Foreign asset used'); } catch(OutOfBoundsException $error) {}
 try { $preview->preview($w,$asset,['INCOME_STATEMENT'=>$otherIds['INCOME_STATEMENT']]); throw new LogicException('Foreign source used'); } catch(OutOfBoundsException $error) {}
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'manager','state'=>'active']); $denied=new AiEvidencePreview($db,$viewer,wp_generate_uuid4());
 try { $denied->preview($w,$asset,$ids); throw new LogicException('Manager preview accepted'); } catch(DomainException $error) {}
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'owner','state'=>'revoked']);
 try { $denied->preview($w,$asset,$ids); throw new LogicException('Revoked preview accepted'); } catch(DomainException $error) {}
});
