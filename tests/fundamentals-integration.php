<?php
/** Synthetic immutable snapshot fixtures on the disposable database only. */
use GainerInteractive\IGTradingJournal\Application\MarketData;
use GainerInteractive\IGTradingJournal\Infrastructure\Installer;
if (!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE !== true) throw new RuntimeException('Disposable site required.');

test('Schema 11 upgrades typed requests from 10, repairs storage and preserves quote reservations', function () use ($db) {
 [$s,$w,$asset,$mapping]=provider_context(); $request=$s->reserve($w,$mapping,provider_fingerprint('fundamental-migration'),'old-quote');
 $before=$db->object('provider_requests',$w,$request['id']); $db->query('ALTER TABLE '.$db->table('provider_requests').' DROP COLUMN dataset');
 $db->query('DROP TABLE '.$db->table('fundamental_snapshots')); update_option('tgit_schema_version','10'); Installer::install();
 equal(get_option('tgit_schema_version'),'11'); $after=$db->object('provider_requests',$w,$request['id']); equal($after['dataset'],'quote'); unset($before['dataset'],$after['dataset']); equal($after,$before);
 equal($db->row('SHOW COLUMNS FROM '.$db->table('fundamental_snapshots').' LIKE %s',['evidence_fingerprint'])['Field'],'evidence_fingerprint');
 Installer::install(); equal($s->reserve($w,$mapping,provider_fingerprint('fundamental-migration'),'old-quote')['id'],$request['id']);
 $db->query('ALTER TABLE '.$db->table('provider_requests')." MODIFY COLUMN dataset varchar(20) NOT NULL DEFAULT 'quote'"); update_option('tgit_schema_version','10');
 try { try { Installer::install(); throw new LogicException('Incompatible dataset column accepted'); } catch(RuntimeException $error) { equal(get_option('tgit_schema_version'),'10'); } }
 finally { $db->query('ALTER TABLE '.$db->table('provider_requests')." MODIFY COLUMN dataset varchar(30) NOT NULL DEFAULT 'quote'"); Installer::install(); }
});

test('Fundamental snapshots preserve exact facts, retry once and append later restatements', function () use ($db) {
 [$s,$w,$asset,$mapping]=provider_context(); $digest=provider_fingerprint('fundamental-history');
 $before=$db->rows('SELECT * FROM '.$db->table('transactions').' WHERE workspace_id = %d',[$w]);
 $r=$s->reserve($w,$mapping,$digest,'income-one',false,'INCOME_STATEMENT');
 provider_conflict(fn()=>$s->reserve($w,$mapping,$digest,'income-one',false,'quote'));
 provider_conflict(fn()=>$s->complete_fundamentals($w,$r['id'],fundamental_statement()));
 $s->dispatch($w,$r['id']); provider_conflict(fn()=>$s->complete_alpha_quote($w,$r['id'],provider_body()));
 $first=$s->complete_fundamentals($w,$r['id'],fundamental_statement()); equal($s->complete_fundamentals($w,$r['id'],fundamental_statement()),$first);
 equal($first['published_at'],null); equal($first['previous_snapshot_id'],null); equal($first['quote_currency'],'USD');
 $evidence=json_decode($first['evidence_json'],true,512,JSON_THROW_ON_ERROR); equal($evidence['reports'][1]['reported_currency'],'EUR'); equal($evidence['reports'][0]['values']['netIncome'],'-200.5'); equal(hash('sha256',$first['evidence_json']),$first['evidence_fingerprint']);
 $changed=fundamental_statement(['annualReports'=>[['fiscalDateEnding'=>'2025-12-31','reportedCurrency'=>'USD','netIncome'=>'-100']]]);
 provider_conflict(fn()=>$s->complete_fundamentals($w,$r['id'],$changed));
 $r2=$s->reserve($w,$mapping,$digest,'income-two',false,'INCOME_STATEMENT'); $s->dispatch($w,$r2['id']); $second=$s->complete_fundamentals($w,$r2['id'],$changed); equal($second['previous_snapshot_id'],$first['id']);
 equal($db->object('fundamental_snapshots',$w,(int)$first['id']),$first); equal(count($s->fundamentals($w,$asset)),2); equal(count($s->fundamentals($w,$asset,(int)$first['id'],1)),1);
 equal($s->quotes($w,$asset),[]); equal($db->rows('SELECT * FROM '.$db->table('transactions').' WHERE workspace_id = %d',[$w]),$before);
 Installer::install(); equal($db->object('fundamental_snapshots',$w,(int)$first['id']),$first);
});

test('Fundamental completion rejects future periods, provider errors and changed mappings', function () use ($db) {
 [$s,$w,$asset,$mapping,$input]=provider_context(); $digest=provider_fingerprint('fundamental-invalid');
 rejects(fn()=>$s->reserve($w,$mapping,$digest,'unsupported',false,'UNKNOWN'));
 $r=$s->reserve($w,$mapping,$digest,'overview',false,'OVERVIEW'); $s->dispatch($w,$r['id']);
 rejects(fn()=>$s->complete_fundamentals($w,$r['id'],fundamental_overview(['Symbol'=>'OTHER'])));
 rejects(fn()=>$s->complete_fundamentals($w,$r['id'],fundamental_overview(['LatestQuarter'=>'2099-12-31'])));
 rejects(fn()=>$s->complete_fundamentals($w,$r['id'],'{"Information":"synthetic-sensitive-message"}'));
 equal($s->fundamentals($w,$asset),[]); equal($db->object('provider_requests',$w,$r['id'])['state'],'dispatched');
 $s->save_mapping($w,$asset,array_replace($input,['expected_mapping_id'=>$mapping])); provider_conflict(fn()=>$s->complete_fundamentals($w,$r['id'],fundamental_overview()));
});

test('Snapshot reads require membership and completion rechecks original owner and mapping context', function () use ($db,$tracker,$viewer,$owner) {
 [$s,$w,$asset,$mapping]=provider_context(); $r=$s->reserve($w,$mapping,provider_fingerprint('fundamental-privacy'),'cash',false,'CASH_FLOW'); $s->dispatch($w,$r['id']);
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'viewer','state'=>'active']); $v=new MarketData($db,$viewer,wp_generate_uuid4()); equal($v->fundamentals($w,$asset),[]);
 try { $v->complete_fundamentals($w,$r['id'],fundamental_statement()); throw new LogicException('Viewer completed snapshot'); } catch(DomainException $error) {}
 $other=(int)$tracker->create_workspace(['name'=>'Foreign fundamentals fixture'])['id']; try { $s->fundamentals($other,$asset); throw new LogicException('Foreign asset accepted'); } catch(OutOfBoundsException $error) {}
 $tracker->set_member($w,['wp_user_id'=>$viewer,'role'=>'owner','state'=>'active']); try { $v->complete_fundamentals($w,$r['id'],fundamental_statement()); throw new LogicException('Other owner completed request'); } catch(DomainException $error) {}
 $tracker->set_member($w,['wp_user_id'=>$owner,'role'=>'owner','state'=>'revoked']); try { $s->complete_fundamentals($w,$r['id'],fundamental_statement()); throw new LogicException('Revoked owner completed'); } catch(DomainException $error) {}
 equal($db->object('provider_requests',$w,$r['id'])['state'],'dispatched');
 rejects(fn()=>$v->fundamentals($w,$asset,-1)); rejects(fn()=>$v->fundamentals($w,$asset,0,101));
});

test('Quotes and fundamentals consume one shared allowance with on-demand headroom', function () {
 [$s,$w,$asset,$mapping]=provider_context(); $digest=provider_fingerprint('fundamental-shared-budget');
 for($i=0;$i<20;$i++) $s->reserve($w,$mapping,$digest,'mixed-'.$i,true,$i%2?'OVERVIEW':'quote');
 provider_conflict(fn()=>$s->reserve($w,$mapping,$digest,'scheduled-over',true,'CASH_FLOW'));
 for($i=20;$i<25;$i++) $s->reserve($w,$mapping,$digest,'mixed-'.$i,false,'BALANCE_SHEET');
 provider_conflict(fn()=>$s->reserve($w,$mapping,$digest,'quote-over',false));
 provider_conflict(fn()=>$s->reserve($w,$mapping,$digest,'fundamental-over',false,'OVERVIEW'));
});

test('Fundamental audit failure rolls back snapshot and completion together without losing quota', function () use ($db) {
 global $wpdb;
 [$s,$w,$asset,$mapping]=provider_context(); $r=$s->reserve($w,$mapping,provider_fingerprint('fundamental-audit'),'snapshot',false,'OVERVIEW'); $s->dispatch($w,$r['id']);
 $trigger=$wpdb->prefix.'fundamental_fault'; $db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture fault'");
 try { try { $s->complete_fundamentals($w,$r['id'],fundamental_overview()); throw new LogicException('Failed audit accepted'); } catch(RuntimeException $error) {} } finally { $db->query('DROP TRIGGER '.$trigger); }
 equal($s->fundamentals($w,$asset),[]); equal($db->object('provider_requests',$w,$r['id'])['state'],'dispatched');
 $s->complete_fundamentals($w,$r['id'],fundamental_overview()); equal(count($s->fundamentals($w,$asset)),1);
 $quote=$s->reserve($w,$mapping,provider_fingerprint('fundamental-audit'),'actual-quote',false); $s->dispatch($w,$quote['id']); provider_conflict(fn()=>$s->complete_fundamentals($w,$quote['id'],fundamental_overview()));
});
