<?php
/** Disposable inventory/enrollment fixtures; no provider traffic. */
declare(strict_types=1);
if(!defined('TGIT_DISPOSABLE_TEST_SITE')||TGIT_DISPOSABLE_TEST_SITE!==true)throw new RuntimeException('Disposable site required.');
use GainerInteractive\IGTradingJournal\Application\TickerbotStore;
use GainerInteractive\IGTradingJournal\Application\TickerbotInventory;
use GainerInteractive\IGTradingJournal\Application\Journal;
use GainerInteractive\IGTradingJournal\Infrastructure\Installer;

function tbi_asset(int $workspace,string $symbol):int {
 global $tracker;
 return (int)$tracker->create_object($workspace,'assets',['symbol'=>$symbol,'exchange'=>'XNAS','asset_class'=>'stock','quote_currency'=>'USD'])['id'];
}

test('Tickerbot saved scope is explicit revisioned owner scoped and follows planned state',function()use($db,$tracker,$owner,$viewer){
 [$s,$w,$scope,$now]=tbs_context();$asset=tbi_asset($w,'AAPL');$list=(int)$tracker->create_watchlist($w,['name'=>'Enabled ideas'],'scope-list')['id'];
 $tracker->add_watchlist_item($w,$list,['asset_id'=>$asset,'status'=>'sell'],'scope-item');
 $j=new Journal($db,$owner,wp_generate_uuid4());$idea=tbi_asset($w,'IDEA');$facts=['asset_id'=>$idea,'title'=>'Synthetic plan','state'=>'planned','journal'=>[],'transaction_ids'=>[]];$trade=$j->save_trade($w,0,$facts,'scope-trade');$id=(int)$trade['trade']['id'];
 equal($s->inventory($w)['items'],[]);equal($s->scope_selection($w)['revision'],0);
 $saved=$s->select_scope($w,[$list,$list],[$id],0);equal($saved['watchlists'],[$list]);equal($saved['worker_enabled'],false);
 $inventory=$s->inventory($w);equal(count($inventory['items']),2);equal($inventory['planned_trades_included'],true);equal($inventory['scope_revision'],1);
 try{$s->select_scope($w,[],[],0);throw new LogicException('Stale selection accepted');}catch(UnexpectedValueException $error){}
 $foreign=(int)$tracker->create_workspace(['name'=>'Foreign inventory'])['id'];$foreignList=(int)$tracker->create_watchlist($foreign,['name'=>'Foreign ideas'],'foreign-list')['id'];
 tbs_denied(fn()=>$s->select_scope($w,[$foreignList],[],1));tbs_denied(fn()=>TickerbotInventory::load($db,$owner,$w,[],7200,[$id+999999]));
 $untrusted=new TickerbotStore($db,$viewer,wp_generate_uuid4(),$scope,$now);tbs_denied(fn()=>$untrusted->scope_selection($w));tbs_denied(fn()=>$untrusted->select_scope($w,[],[],1));
 rejects(fn()=>$s->select_scope($w,[(string)$list],[],1));rejects(fn()=>TickerbotInventory::load($db,$owner,$w,[],7200,[(string)$id]));
 $j->save_trade($w,$id,array_replace($facts,['state'=>'open','expected_revision'=>1]),'scope-open');
 equal(count($s->inventory($w)['items']),1);equal($s->inventory($w)['planned_trades_included'],false);rejects(fn()=>$s->select_scope($w,[],[$id],1));
 $s->select_scope($w,[],[],1);equal($s->inventory($w)['items'],[]);equal(count($db->rows('SELECT id FROM '.$db->table('tickerbot_scope_revisions').' WHERE workspace_id=%d',[$w])),2);equal($s->usage($w)['attempts'],0);
});

test('Tickerbot inventory scans beyond first asset and watchlist pages and excludes closed lots',function()use($tracker,$db){
 [$s,$w]=tbs_context();$account=(int)$tracker->create_object($w,'accounts',['name'=>'Inventory account','native_currency'=>'USD'])['id'];
 $list=(int)$tracker->create_watchlist($w,['name'=>'Full inventory'],'inventory-list')['id'];$ids=[];
 for($i=0;$i<101;$i++){$id=tbi_asset($w,'I'.$i);$ids[]=$id;$tracker->add_watchlist_item($w,$list,['asset_id'=>$id,'status'=>'hold'],'inventory-item-'.$i);}
 $holding=end($ids);$closed=tbi_asset($w,'CLOSED');
 foreach([$holding,$closed] as $id)$tracker->opening_balance($w,['account_id'=>$account,'asset_id'=>$id,'kind'=>'lot','effective_date'=>'2026-01-02','acquired_on'=>'2025-01-01','quantity'=>'1','basis_status'=>'complete','amount'=>'10','source_note'=>'Synthetic inventory statement'],'inventory-lot-'.$id);
 $tracker->post($w,['account_id'=>$account,'asset_id'=>$closed,'action'=>'sell','effective_date'=>'2026-01-03','state'=>'posted','quantity'=>'1','unit_price'=>'10','fees'=>'0','currency'=>'USD'],'inventory-close');
 $before=$db->rows('SELECT * FROM '.$db->table('lots').' WHERE workspace_id=%d ORDER BY id',[$w]);
 $default=$s->inventory($w);equal(array_column($default['items'],'asset_id'),[$holding]);
 $s->select_scope($w,[$list],[],0);$all=$s->inventory($w);equal(count($all['items']),101);equal($all['items'][100]['asset_id'],$holding);equal($all['items'][100]['origins'],['holding','watchlist']);equal($all['symbols'],[]);
 $s->save_mapping($w,$holding,'I100',array_replace(tb_row('I100'),['exchange_mic'=>'XNAS']));
 $all=$s->inventory($w);equal($all['symbols'],['I100']);equal($all['projected_monthly_attempts'],372);
 equal($db->rows('SELECT * FROM '.$db->table('lots').' WHERE workspace_id=%d ORDER BY id',[$w]),$before);equal($s->usage($w)['attempts'],0);
});

test('Tickerbot scope audit failures roll back and migration 20 preserves revisions',function()use($db,$wpdb){
 [$s,$w]=tbs_context();$trigger='fixture_tb_scope_'.bin2hex(random_bytes(4));
 $db->query('CREATE TRIGGER '.$trigger.' BEFORE INSERT ON '.$db->table('audit_events')." FOR EACH ROW BEGIN IF NEW.action = 'tickerbot.scope.select' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic'; END IF; END");
 $prior=$wpdb->suppress_errors(true);
 try{try{$s->select_scope($w,[],[],0);throw new LogicException('Audit failure accepted');}catch(RuntimeException $error){}}
 finally{$db->query('DROP TRIGGER '.$trigger);$wpdb->suppress_errors($prior);}
 equal($s->scope_selection($w)['revision'],0);$s->select_scope($w,[],[],0);$before=$s->scope_selection($w);
 update_option('tgit_schema_version','19');Installer::install();equal(get_option('tgit_schema_version'),Installer::VERSION);Installer::install();equal($s->scope_selection($w),$before);
});
