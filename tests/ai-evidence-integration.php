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

test('Schema 14 upgrades from 13 and retains saved fundamental history',function() use($db) {
 $before=$db->row('SELECT COUNT(*) AS total FROM '.$db->table('fundamental_snapshots'))['total'];
 update_option('tgit_schema_version','13'); GainerInteractive\IGTradingJournal\Infrastructure\Installer::install();
 equal(get_option('tgit_schema_version'),'14'); equal($db->row('SHOW TABLE STATUS LIKE %s',[$db->table('ai_evidence_bundles')])['Engine'],'InnoDB');
 GainerInteractive\IGTradingJournal\Infrastructure\Installer::install(); equal($db->row('SELECT COUNT(*) AS total FROM '.$db->table('fundamental_snapshots'))['total'],$before);
});

test('AI evidence approval stores exactly the reviewed bundle and retry reuses immutable history',function() use($db,$owner) {
 [$market,$w,$asset,$mapping,$ids]=saved_metric_context(); $service=new AiEvidencePreview($db,$owner,wp_generate_uuid4()); $preview=$service->preview($w,$asset,$ids);
 $saved=$service->approve($w,$asset,$ids,$preview['fingerprint'],'approval-first');
 equal($service->approve($w,$asset,array_reverse($ids,true),$preview['fingerprint'],'approval-first'),$saved);
 $read=$service->approved($w,$saved['id']); equal($read['bundle'],$preview['bundle']); equal($read['fingerprint'],$preview['fingerprint']);
 equal(count($db->rows('SELECT * FROM '.$db->table('ai_evidence_bundles').' WHERE workspace_id = %d',[$w])),1);
 provider_conflict(fn()=>$service->approve($w,$asset,$ids,str_repeat('b',64),'approval-first'));
 provider_conflict(fn()=>$service->approve($w,$asset,$ids,str_repeat('b',64),'approval-wrong'));
 equal(count($db->rows('SELECT * FROM '.$db->table('ai_evidence_bundles').' WHERE workspace_id = %d',[$w])),1);
 equal($db->rows('SELECT * FROM '.$db->table('ai_requests').' WHERE workspace_id = %d',[$w]),[]);
 [$foreign,$other,$otherAsset,$otherMapping,$otherIds]=saved_metric_context();
 try { $service->approved($other,$saved['id']); throw new LogicException('Foreign approval read'); } catch(OutOfBoundsException $error) {}
});

test('AI approvals survive journal revisions while new approvals must review the current thesis',function() use($db,$owner,$viewer,$tracker) {
 [$market,$w,$asset,$mapping,$ids]=saved_metric_context(); $service=new AiEvidencePreview($db,$owner,wp_generate_uuid4()); $journal=new Journal($db,$owner,wp_generate_uuid4());
 $input=['asset_id'=>$asset,'title'=>'Approval fixture','state'=>'planned','transaction_ids'=>[],'journal'=>['thesis'=>'Original thesis']];
 $trade=$journal->save_trade($w,0,$input,'approve-journal-create'); $id=(int)$trade['trade']['id']; $revision=(int)$trade['trade']['revision'];
 $preview=$service->preview($w,$asset,$ids,$id,$revision); $approval=$service->approve($w,$asset,$ids,$preview['fingerprint'],'approve-original',$id,$revision); $original=$service->approved($w,$approval['id']);
 $input['expected_revision']=$revision; $input['journal']['thesis']='New thesis'; $updated=$journal->save_trade($w,$id,$input,'approve-journal-edit');
 equal($service->approved($w,$approval['id']),$original);
 equal($service->approve($w,$asset,$ids,$preview['fingerprint'],'approve-original',$id,$revision),$approval);
 provider_conflict(fn()=>$service->approve($w,$asset,$ids,$preview['fingerprint'],'approve-stale',$id,$revision));
 provider_conflict(fn()=>$service->approve($w,$asset,$ids,$preview['fingerprint'],'approve-changed',$id,(int)$updated['trade']['revision']));
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'owner','state'=>'active']); $other=new AiEvidencePreview($db,$viewer,wp_generate_uuid4());
 provider_conflict(fn()=>$other->approve($w,$asset,$ids,$preview['fingerprint'],'approve-original',$id,$revision));
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'owner','state'=>'revoked']);
 try { $other->approved($w,$approval['id']); throw new LogicException('Revoked approval read'); } catch(DomainException $error) {}
});

test('AI approval audit failures roll back evidence and retry identity; corrupted bundles fail closed',function() use($db,$owner) {
 [$market,$w,$asset,$mapping,$ids]=saved_metric_context(); $service=new AiEvidencePreview($db,$owner,wp_generate_uuid4()); $preview=$service->preview($w,$asset,$ids);
 $trigger=$db->table('audit_events').'_approval_fault';
 $db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture fault'");
 try { try { $service->approve($w,$asset,$ids,$preview['fingerprint'],'approve-retry'); throw new LogicException('Audit failure accepted'); } catch(RuntimeException $error) {} }
 finally { $db->query('DROP TRIGGER '.$trigger); }
 equal($db->rows('SELECT * FROM '.$db->table('ai_evidence_bundles').' WHERE workspace_id = %d',[$w]),[]);
 equal($db->rows('SELECT * FROM '.$db->table('idempotency')." WHERE workspace_id = %d AND operation = 'ai.evidence.approve'",[$w]),[]);
 $saved=$service->approve($w,$asset,$ids,$preview['fingerprint'],'approve-retry'); $original=$db->object('ai_evidence_bundles',$w,$saved['id']);
 try { $db->update_object('ai_evidence_bundles',$w,$saved['id'],['bundle_json'=>'{}']); provider_conflict(fn()=>$service->approved($w,$saved['id'])); }
 finally { $db->update_object('ai_evidence_bundles',$w,$saved['id'],['bundle_json'=>$original['bundle_json']]); }
 equal($service->approved($w,$saved['id'])['bundle'],$preview['bundle']);
});
