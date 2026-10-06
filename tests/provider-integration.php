<?php
/** Disposable provider persistence/quota fixtures. No keys or external requests. */
use GainerInteractive\IGTradingJournal\Application\MarketData;
use GainerInteractive\IGTradingJournal\Application\Tracker;
use GainerInteractive\IGTradingJournal\Infrastructure\Installer;
if (!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE !== true) throw new RuntimeException('Disposable site required.');

function provider_context(): array {
 global $tracker, $db, $owner;
 $workspace = (int)$tracker->create_workspace(['name'=>'Provider synthetic fixture'])['id'];
 $asset = (int)$tracker->create_object($workspace,'assets',['symbol'=>'FIXTURE','exchange'=>'TESTEX','asset_class'=>'stock','quote_currency'=>'USD'])['id'];
 $service = new MarketData($db,$owner,wp_generate_uuid4());
 $input = ['provider'=>'alpha_vantage','provider_symbol'=>'FIXTURE','exchange'=>'TESTEX','currency'=>'USD','enabled'=>true,'evidence'=>'Synthetic identity confirmation','expected_mapping_id'=>0];
 $mapping = $service->save_mapping($workspace,$asset,$input);
 return [$service,$workspace,$asset,(int)$mapping['id'],$input];
}

function provider_body(string $price='320'): string {
 return wp_json_encode(['Global Quote'=>['01. symbol'=>'FIXTURE','05. price'=>$price,'08. previous close'=>'315','07. latest trading day'=>'2026-01-02']]);
}

function provider_conflict(callable $callback): void {
 try { $callback(); } catch (UnexpectedValueException $error) { return; }
 throw new RuntimeException('Expected provider conflict.');
}

function provider_fingerprint(string $label): string {
 // Synthetic server credential identity is isolated per retained fixture run.
 return hash('sha256', $label . ':' . $GLOBALS['owner']);
}

test('Schema 9 upgrades from 8, repairs a missing provider table and preserves manual evidence', function () use ($db) {
 $before=$db->row('SELECT COUNT(*) AS total FROM '.$db->table('market_observations'))['total'];
 update_option('tgit_schema_version','8'); Installer::install(); equal(get_option('tgit_schema_version'),'9');
 $db->query('DROP TABLE '.$db->table('provider_quotes')); Installer::install();
 equal($db->row('SHOW COLUMNS FROM '.$db->table('provider_quotes').' LIKE %s',['session_date'])['Field'],'session_date');
 equal($db->row('SELECT COUNT(*) AS total FROM '.$db->table('market_observations'))['total'],$before);
});

test('Provider mappings append revisions, enforce identity and reject foreign workspace assets', function () use ($tracker,$db) {
 [$s,$w,$asset,$mapping,$input]=provider_context();
 provider_conflict(fn()=>$s->save_mapping($w,$asset,$input));
 rejects(fn()=>$s->save_mapping($w,$asset,array_replace($input,['currency'=>'EUR','expected_mapping_id'=>$mapping])));
 rejects(fn()=>$s->save_mapping($w,$asset,array_replace($input,['exchange'=>'OTHER','expected_mapping_id'=>$mapping])));
 $other=(int)$tracker->create_workspace(['name'=>'Foreign provider fixture'])['id'];
 try { $s->save_mapping($other,$asset,$input); throw new RuntimeException('Foreign mapping accepted'); } catch (OutOfBoundsException $error) {}
 $new=$s->save_mapping($w,$asset,array_replace($input,['expected_mapping_id'=>$mapping,'enabled'=>false]));
 equal($db->object('provider_mappings',$w,$mapping)['enabled'],'1'); equal($new['enabled'],'0');
 provider_conflict(fn()=>$s->reserve($w,$mapping,provider_fingerprint('fixture'),'disabled-old'));
 provider_conflict(fn()=>$s->reserve($w,(int)$new['id'],provider_fingerprint('fixture'),'disabled-new'));
});

test('Provider request lifecycle saves exact evidence once without touching ledger/manual prices', function () use ($db) {
 [$s,$w,$asset,$mapping]=provider_context();
 $r=$s->reserve($w,$mapping,provider_fingerprint('lifecycle'),'once'); equal($s->reserve($w,$mapping,provider_fingerprint('lifecycle'),'once'),$r);
 equal(array_keys($r),['id','mapping_id','state']);
 provider_conflict(fn()=>$s->complete_alpha_quote($w,$r['id'],provider_body()));
 $s->dispatch($w,$r['id']); provider_conflict(fn()=>$s->dispatch($w,$r['id']));
 $quote=$s->complete_alpha_quote($w,$r['id'],provider_body('320.123456789123456789')); decimal($quote['price'],'320.123456789123456789'); equal($quote['currency'],'USD'); equal($quote['exchange'],'TESTEX');
 equal($s->complete_alpha_quote($w,$r['id'],provider_body('320.123456789123456789')),$quote);
 provider_conflict(fn()=>$s->complete_alpha_quote($w,$r['id'],provider_body('321')));
 equal(count($s->quotes($w,$asset)),1);
 equal($db->row('SELECT COUNT(*) AS total FROM '.$db->table('transactions').' WHERE workspace_id = %d',[$w])['total'],'0');
 equal($db->row('SELECT COUNT(*) AS total FROM '.$db->table('market_observations').' WHERE workspace_id = %d',[$w])['total'],'0');
 $upper=$s->reserve($w,$mapping,provider_fingerprint('lifecycle'),'CASE'); $lower=$s->reserve($w,$mapping,provider_fingerprint('lifecycle'),'case'); equal($upper['id'] === $lower['id'],false);
});

test('Provider quotas are shared across workspaces and preserve five on-demand requests', function () {
 [$s,$w,$asset,$mapping]=provider_context(); [$other,$w2,$asset2,$mapping2]=provider_context(); $digest=provider_fingerprint('shared-quota');
 for($i=0;$i<20;$i++) { ($i%2?$other:$s)->reserve($i%2?$w2:$w,$i%2?$mapping2:$mapping,$digest,'scheduled-'.$i); }
 provider_conflict(fn()=>$s->reserve($w,$mapping,$digest,'scheduled-over'));
 for($i=0;$i<5;$i++) $other->reserve($w2,$mapping2,$digest,'manual-'.$i,false);
 provider_conflict(fn()=>$s->reserve($w,$mapping,$digest,'manual-over',false));
 equal($s->reserve($w,$mapping,provider_fingerprint('independent-credential'),'different-key')['state'],'reserved');
});

test('Expired reservations cannot dispatch and uncertain requests keep consuming quota', function () use ($db) {
 [$s,$w,$asset,$mapping]=provider_context(); $digest=provider_fingerprint('rolling-quota'); $old='2025-01-01 00:00:00';
 $expired=$s->reserve($w,$mapping,$digest,'expired');
 $db->update_object('provider_requests',$w,$expired['id'],['created_at'=>$old]);
 provider_conflict(fn()=>$s->dispatch($w,$expired['id']));
 for($i=0;$i<20;$i++) { $r=$s->reserve($w,$mapping,$digest,'uncertain-'.$i); $s->dispatch($w,$r['id']); $db->update_object('provider_requests',$w,$r['id'],['created_at'=>$old,'dispatched_at'=>$old]); }
 provider_conflict(fn()=>$s->reserve($w,$mapping,$digest,'uncertain-over'));
 $s->fail($w,$r['id']); $s->fail($w,$r['id']);
 equal($s->reserve($w,$mapping,$digest,'known-old-failure')['state'],'reserved');
});

test('FMP quota uses its own 250-request allowance with on-demand headroom', function () {
 [$s,$w,$asset,$mapping,$input]=provider_context();
 $fmp=$s->save_mapping($w,$asset,array_replace($input,['provider'=>'fmp'])); $digest=provider_fingerprint('fmp-quota');
 for($i=0;$i<245;$i++) $s->reserve($w,(int)$fmp['id'],$digest,'fmp-scheduled-'.$i);
 provider_conflict(fn()=>$s->reserve($w,(int)$fmp['id'],$digest,'fmp-scheduled-over'));
 for($i=0;$i<5;$i++) $s->reserve($w,(int)$fmp['id'],$digest,'fmp-manual-'.$i,false);
 provider_conflict(fn()=>$s->reserve($w,(int)$fmp['id'],$digest,'fmp-manual-over',false));
});

test('Known recent failures still count and changed mappings block in-flight completions', function () use ($db) {
 [$s,$w,$asset,$mapping,$input]=provider_context(); $digest=provider_fingerprint('failed-current');
 for($i=0;$i<20;$i++) { $r=$s->reserve($w,$mapping,$digest,'failed-'.$i); $s->dispatch($w,$r['id']); $s->fail($w,$r['id']); }
 provider_conflict(fn()=>$s->reserve($w,$mapping,$digest,'failure-over'));
 $r=$s->reserve($w,$mapping,provider_fingerprint('mapping-change'),'in-flight'); $s->dispatch($w,$r['id']);
 $s->save_mapping($w,$asset,array_replace($input,['expected_mapping_id'=>$mapping,'enabled'=>false]));
 provider_conflict(fn()=>$s->complete_alpha_quote($w,$r['id'],provider_body())); equal($s->quotes($w,$asset),[]);
 equal($db->object('provider_requests',$w,$r['id'])['state'],'dispatched');
});

test('Provider operations recheck owner authorization and isolate quote history', function () use ($db,$tracker,$owner,$viewer) {
 [$s,$w,$asset,$mapping]=provider_context(); $digest=provider_fingerprint('revocation');
 $r=$s->reserve($w,$mapping,$digest,'before-revoke');
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'active']);
 $v=new MarketData($db,$viewer,wp_generate_uuid4()); equal($v->quotes($w,$asset),[]);
 try { $v->reserve($w,$mapping,$digest,'denied'); throw new RuntimeException('Viewer reserved'); } catch (DomainException $error) {}
 [$other,$w2,$asset2,$mapping2]=provider_context();
 try { $other->quotes($w2,$asset); throw new RuntimeException('Foreign asset read'); } catch (OutOfBoundsException $error) {}
 $s->dispatch($w,$r['id']);
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'owner','state'=>'active']);
 $tracker->set_member($w,['wp_user_id'=>$owner,'role'=>'owner','state'=>'revoked']);
 foreach([fn()=>$s->dispatch($w,$r['id']),fn()=>$s->complete_alpha_quote($w,$r['id'],provider_body()),fn()=>$s->quotes($w,$asset)] as $callback) {
  try { $callback(); throw new RuntimeException('Revoked owner accessed provider operation'); } catch (DomainException $error) {}
 }
});

test('Provider quote audit failure rolls back evidence while keeping the dispatch reservation', function () use ($db) {
 global $wpdb;
 [$s,$w,$asset,$mapping]=provider_context(); $r=$s->reserve($w,$mapping,provider_fingerprint('audit-rollback'),'quote'); $s->dispatch($w,$r['id']);
 $trigger=$wpdb->prefix.'provider_fault';
 $db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture fault'");
 try { try { $s->complete_alpha_quote($w,$r['id'],provider_body()); throw new LogicException('Audit failure accepted'); } catch (RuntimeException $error) {} }
 finally { $db->query('DROP TRIGGER '.$trigger); }
 equal($s->quotes($w,$asset),[]); equal($db->object('provider_requests',$w,$r['id'])['state'],'dispatched');
 equal(count($s->quotes($w,$asset)),0); $s->complete_alpha_quote($w,$r['id'],provider_body()); equal(count($s->quotes($w,$asset)),1);
});

test('Concurrent workspaces cannot both reserve the final shared provider request', function () use ($db,$owner) {
 [$s,$w,$asset,$mapping]=provider_context(); [$other,$w2,$asset2,$mapping2]=provider_context(); $digest=provider_fingerprint('concurrent-quota');
 for($i=0;$i<24;$i++) $s->reserve($w,$mapping,$digest,'fill-'.$i,false);
 $processes=[];
 foreach([[$w,$mapping,'first'],[$w2,$mapping2,'second']] as [$workspace,$map,$key]) {
  $task=['operation'=>'provider_reserve','id'=>$map,'fingerprint'=>$digest,'key'=>$key];
  $command=[PHP_BINARY,'-d','extension_dir='.ini_get('extension_dir'),'-d','extension=mysqli',__DIR__.'/concurrency-worker.php',rtrim(ABSPATH,'/\\'),(string)$owner,(string)$workspace,wp_json_encode($task)];
  $pipes=[]; $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); if(!is_resource($process)) throw new RuntimeException('Worker failed'); fclose($pipes[0]); $processes[]=[$process,$pipes];
 }
 $results=[]; foreach($processes as [$process,$pipes]) { $results[]=trim(stream_get_contents($pipes[1])); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); if(proc_close($process)!==0) throw new RuntimeException('Provider worker failed: '.$error); }
 sort($results); equal($results,['posted','rejected']);
});
