<?php
/** Tickerbot synthetic contracts; no HTTP, credentials or financial writes. */
declare(strict_types=1);
require_once __DIR__.'/../src/Infrastructure/TickerbotQuote.php';
require_once __DIR__.'/../src/Infrastructure/TickerbotCoverageSummary.php';
use GainerInteractive\IGTradingJournal\Infrastructure\TickerbotQuote;
use GainerInteractive\IGTradingJournal\Infrastructure\TickerbotCoverageSummary;

test('Tickerbot coverage retains only bounded counts null flags and response time without price inference',function(){
 $body=['ticker'=>'AAPL','as_of'=>'2026-10-14T12:00:00Z','name'=>'synthetic-secret','measured_fields'=>['not-retained'],'live_row'=>['columns'=>'3','populated'=>'2','null_fields'=>['price'],'extra'=>'not-retained']];$summary=TickerbotCoverageSummary::parse($body,'AAPL');equal($summary,['columns'=>3,'populated'=>2,'price_null'=>true,'price_asof_null'=>false,'as_of'=>'2026-10-14 12:00:00.000000']);equal(TickerbotCoverageSummary::validate($summary),$summary);equal(str_contains(json_encode($summary),'secret'),false);
 $body['as_of']=null;$body['live_row']=['columns'=>'0','populated'=>'0','null_fields'=>[]];equal(TickerbotCoverageSummary::parse($body,'AAPL')['as_of'],null);equal(TickerbotCoverageSummary::parse($body,'AAPL')['price_null'],false);
});

test('Tickerbot coverage rejects malformed counts null lists identity and timestamps',function(){
 $body=['ticker'=>'AAPL','live_row'=>['columns'=>'2','populated'=>'1','null_fields'=>['price']]];
 foreach([['ticker'=>'MSFT'],['as_of'=>'not-a-date'],['live_row'=>['columns'=>'2','populated'=>'3','null_fields'=>[]]],['live_row'=>['columns'=>'2','populated'=>'0','null_fields'=>['price','price']]],['live_row'=>['columns'=>'2','populated'=>'1','null_fields'=>['<script>']]],['live_row'=>['columns'=>'10001','populated'=>'10001','null_fields'=>[]]],['live_row'=>['columns'=>'2.0','populated'=>'1','null_fields'=>['price']]],['live_row'=>['columns'=>'2','populated'=>'1','null_fields'=>[]]]]as$patch){rejects(fn()=>TickerbotCoverageSummary::parse(array_replace($body,$patch),'AAPL'));}
 rejects(fn()=>TickerbotCoverageSummary::validate(['columns'=>1,'populated'=>1,'price_null'=>true,'price_asof_null'=>false,'as_of'=>null]));
});

function tb_asset(int $id=1, string $symbol='AAPL', string $class='stock'): array {
 return ['id'=>$id,'symbol'=>$symbol,'asset_class'=>$class,'exchange'=>'XNAS','quote_currency'=>'USD','provider_symbol'=>$class==='crypto'?'X:'.$symbol.'USD':$symbol];
}
function tb_row(string $ticker='AAPL'): array {
 return ['ticker'=>$ticker,'asset_class'=>'stocks','asset_type'=>'CS','exchange'=>'NASDAQ','exchange_mic'=>'XNAS','currency_name'=>'usd','price'=>'123.123456789012345678','price_source'=>'fair_value','price_asof'=>'2026-10-14T12:00:00Z'];
}
function tb_parse(array $payload,array $assets): array {
 return TickerbotQuote::parse(json_encode($payload,JSON_THROW_ON_ERROR),$assets,new DateTimeImmutable('2026-10-14T12:01:00Z'));
}
test('Tickerbot retains exact decimals and honest fair-value timestamps', function () {
 $quote=tb_parse(['ticker'=>'AAPL','as_of'=>'2026-10-14T12:00:01Z','data'=>tb_row()],[tb_asset()])['accepted'][1];
 equal($quote['price'],'123.123456789012345678');equal($quote['source_time_semantics'],'provider_fetch_time');equal($quote['price_asof'],'2026-10-14 12:00:00.000000');equal($quote['response_as_of'],'2026-10-14 12:00:01.000000');
 equal(array_key_exists('quantity',$quote),false);
 $row=tb_row();$row['price_asof']=null;$quote=tb_parse(['ticker'=>'AAPL','data'=>$row],[tb_asset()])['accepted'][1];equal($quote['price_asof'],null);equal($quote['source_time_semantics'],'unknown');equal($quote['response_as_of'],null);
});
test('Tickerbot distinguishes crypto BTC from the BTC equity and rejects currency mismatch', function () {
	 equal(TickerbotQuote::identity(tb_asset(),tb_row('MSFT'),'MSFT'),false);
 $asset=tb_asset(1,'BTC','crypto');$row=tb_row('BTC');equal(TickerbotQuote::identity($asset,$row,'BTC'),false);
 $row=array_replace(tb_row('X:BTCUSD'),['asset_class'=>'crypto','price_source'=>'exchange_rate']);equal(TickerbotQuote::identity($asset,$row,'X:BTCUSD'),true);
 $row['currency_name']='usdt';equal(TickerbotQuote::identity($asset,$row,'X:BTCUSD'),false);$row['currency_name']=null;equal(TickerbotQuote::identity($asset,$row,'X:BTCUSD'),false);
 $row=array_replace(tb_row('X:ETHUSD'),['asset_class'=>'crypto']);equal(TickerbotQuote::identity($asset,$row,'X:ETHUSD'),false);
});
test('Tickerbot partial failures retain valid symbols and reject malformed marks independently', function () {
 foreach (['0','-1','NaN','1e999'] as $price) {
  $bad=array_replace(tb_row('BAD'),['price'=>$price]);$result=tb_parse(['requested'=>['AAPL','BAD','NONE'],'count'=>2,'data'=>['AAPL'=>tb_row(),'BAD'=>$bad],'not_found'=>['NONE']],[tb_asset(),tb_asset(2,'BAD'),tb_asset(3,'NONE')]);
  equal(count($result['accepted']),1);equal($result['issues'],[2=>'invalid_quote',3=>'unsupported']);
 }
 foreach(['2026-02-30T00:00:00Z','2026-10-14T25:00:00Z','2027-01-01T00:00:00Z'] as $time){$row=tb_row();$row['price_asof']=$time;equal(tb_parse(['ticker'=>'AAPL','data'=>$row],[tb_asset()])['issues'],[1=>'invalid_quote']);}
});
test('Tickerbot rejects ambiguous batches, unknown sources and unsafe canonical paths', function () {
 rejects(fn()=>tb_parse(['requested'=>['AAPL'],'count'=>0,'data'=>[],'not_found'=>[]],[tb_asset()]));
 rejects(fn()=>tb_parse(['ticker'=>'OTHER','data'=>tb_row()],[tb_asset()]));
 foreach(['AAPL/BTC','AAPL?apikey=x','BTC,ETH','https://host','../AAPL'] as $symbol)rejects(fn()=>TickerbotQuote::symbol($symbol));
 $row=tb_row();$row['price_source']='published_rate';equal(tb_parse(['ticker'=>'AAPL','data'=>$row],[tb_asset()])['issues'],[1=>'invalid_quote']);
 $row=tb_row();$row['exchange_mic']='XNYS';equal(tb_parse(['ticker'=>'AAPL','data'=>$row],[tb_asset()])['issues'],[1=>'identity_mismatch']);
});
require_once __DIR__.'/../src/Domain/TickerbotSchedule.php';
require_once __DIR__.'/../src/Domain/TickerbotAge.php';

test('Tickerbot source age uses the elapsed interval without blessing unknown future or invalid times',function(){
 $now=new DateTimeImmutable('2026-10-15T12:00:00Z');$age=\GainerInteractive\IGTradingJournal\Domain\TickerbotAge::class;
 equal($age::inspect(null,$now,7200)['state'],'unknown');equal($age::inspect('2026-02-30 12:00:00',$now,7200)['state'],'unknown');equal($age::inspect('2026-10-15 12:00:00.000001',$now,7200)['state'],'future');equal($age::inspect('2026-10-15 08:00:00',$now,7200)['state'],'recent');equal($age::inspect('2026-10-15 07:59:59',$now,7200)['state'],'stale');equal($age::inspect('2026-10-15 08:00:00',$now,3600)['state'],'stale');equal($age::inspect('2026-10-15 08:00:00',$now,86400)['state'],'recent');
});
test('Tickerbot source age compares absolute UTC time across New York daylight transitions',function(){
 $now=new DateTimeImmutable('2026-11-01T02:30:00-05:00');$age=\GainerInteractive\IGTradingJournal\Domain\TickerbotAge::inspect('2026-11-01 05:30:00',$now,3600);equal($age['seconds'],7200);equal($age['state'],'recent');
});
