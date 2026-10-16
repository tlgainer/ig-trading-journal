<?php
/** Exact cached-cohort totals; no provider or currency conversion inference. */
require_once __DIR__.'/../src/Domain/StockTotals.php';
test('Tickerbot totals reject mixed receipt cohorts while retaining covered native subtotals',function(){
 $base=['asset_class'=>'stock','quantity'=>'1','currency'=>'USD','price_status'=>'complete','market_value'=>'125.123456789123456789','unrealized_gain'=>'25.123456789123456789','price_observation'=>['cohort'=>'tickerbot-run:1','effective_date'=>'2026-10-15']];$other=$base;$other['price_observation']['cohort']='tickerbot-run:2';$g=\GainerInteractive\IGTradingJournal\Domain\StockTotals::calculate([$base,$other])[0];equal($g['market_value'],null);equal($g['status'],'partial');decimal($g['covered_market_value'],'250.246913578246913578');$other['price_observation']['cohort']='tickerbot-run:1';$g=\GainerInteractive\IGTradingJournal\Domain\StockTotals::calculate([$base,$other])[0];decimal($g['market_value'],'250.246913578246913578');equal($g['status'],'complete');
});
