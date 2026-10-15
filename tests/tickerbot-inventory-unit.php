<?php
/** Read-only inventory planning fixtures. */
declare(strict_types=1);
require_once __DIR__.'/../src/Application/TickerbotInventory.php';
use GainerInteractive\IGTradingJournal\Application\TickerbotInventory;

test('Tickerbot inventory requires compatible workspace identity and never guesses mappings',function(){
 $asset=array_replace(tb_asset(),['workspace_id'=>1]);
 $mapping=['id'=>9,'workspace_id'=>1,'asset_id'=>1,'asset_class'=>'stock','exchange'=>'XNAS','currency'=>'USD','provider_symbol'=>'AAPL'];
 $row=TickerbotInventory::entry($asset,$mapping,['holding','watchlist']);equal($row['state'],'mapped');equal($row['mapping_id'],9);equal(isset($row['quantity']),false);
 foreach(['workspace_id'=>2,'asset_id'=>2,'exchange'=>'XNYS','currency'=>'EUR','asset_class'=>'crypto','provider_symbol'=>'../AAPL'] as $key=>$value) equal(TickerbotInventory::entry($asset,array_replace($mapping,[$key=>$value]),['holding'])['state'],'needs_mapping');
 equal(TickerbotInventory::entry($asset,null,['holding'])['provider_symbol'],null);
});
test('Tickerbot inventory excludes unsupported stablecoin quote assumptions and stale crypto symbols',function(){
 $asset=array_replace(tb_asset(1,'BTC','crypto'),['workspace_id'=>1]);
 $mapping=['id'=>9,'workspace_id'=>1,'asset_id'=>1,'asset_class'=>'crypto','exchange'=>'XNAS','currency'=>'USD','provider_symbol'=>'X:BTCUSD'];
 equal(TickerbotInventory::entry($asset,$mapping,['holding'])['state'],'mapped');
 equal(TickerbotInventory::entry(array_replace($asset,['quote_currency'=>'USDT']),null,['holding'])['state'],'unsupported');
 equal(TickerbotInventory::entry(array_replace($asset,['symbol'=>'ETH']),$mapping,['holding'])['state'],'needs_mapping');
 equal(TickerbotInventory::entry(array_replace($asset,['asset_class'=>'option']),null,['holding'])['state'],'unsupported');
});
test('Tickerbot inventory deduplicates canonical batches without losing local instrument rows',function(){
 $rows=[];for($i=0;$i<51;$i++)$rows[]=['asset_id'=>$i+1,'state'=>'mapped','provider_symbol'=>'S'.$i];
 $rows[]=$rows[0];$rows[]=['asset_id'=>99,'state'=>'needs_mapping','provider_symbol'=>null];
 $plan=TickerbotInventory::plan($rows);equal(count($plan['items']),53);equal(count($plan['symbols']),51);equal(array_map('count',$plan['batches']),[50,1]);equal($plan['projected_monthly_attempts'],744);equal($plan['planned_trades_included'],false);
 equal(TickerbotInventory::plan([])['batches'],[]);rejects(fn()=>TickerbotInventory::plan([],60));
});

test('Tickerbot inventory reports planned eligibility only for explicitly supplied origins',function(){
 equal(TickerbotInventory::plan([['state'=>'needs_mapping','origins'=>['planned_trade']]])['planned_trades_included'],true);
 equal(TickerbotInventory::plan([['state'=>'unsupported','origins'=>['watchlist']]])['planned_trades_included'],false);
});
