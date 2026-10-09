<?php
/** Synthetic public catalogue, fixed transport and workspace access checks. */
use GainerInteractive\IGTradingJournal\Infrastructure\TickerCatalog;

test('Ticker catalogue normalizes symbols and rejects malformed rows without inferring asset metadata', function () {
 $rows=TickerCatalog::normalize('{"0":{"ticker":"nvda","title":"NVIDIA CORP","cik_str":1045810},"1":{"ticker":"BRK-B","title":"Berkshire"},"2":{"ticker":"NVDA","title":"duplicate"},"3":{"ticker":"<script>","title":"bad"}}');
 equal($rows,[['symbol'=>'NVDA','title'=>'NVIDIA CORP'],['symbol'=>'BRK-B','title'=>'Berkshire']]);
 foreach (['null','{}','{"0":{"ticker":"AAPL","title":""}}'] as $bad) { $failed=false; try { TickerCatalog::normalize($bad); } catch (RuntimeException $error) { $failed=true; } equal($failed,true); }
});
test('Ticker catalogue uses bounded fixed HTTPS transport and cache without sending customer data', function () {
 delete_transient('tgit_public_tickers_v1'); $calls=0;
 $mock=function($prior,$args,$url) use(&$calls) {
  equal($url,TickerCatalog::URL); equal($args['redirection'],0); equal($args['sslverify'],true); equal($args['cookies'],[]); equal($args['limit_response_size'],2097153); ++$calls;
  return ['response'=>['code'=>200],'headers'=>[],'body'=>'{"0":{"ticker":"AAPL","title":"Apple Inc."}}','cookies'=>[]];
 };
 add_filter('pre_http_request',$mock,1,3);
 try { equal(TickerCatalog::read()[0]['symbol'],'AAPL'); equal(TickerCatalog::read()[0]['title'],'Apple Inc.'); equal($calls,1); }
 finally { remove_filter('pre_http_request',$mock,1); delete_transient('tgit_public_tickers_v1'); }
 $failure=fn()=>new WP_Error('fixture_unavailable','Synthetic outage'); add_filter('pre_http_request',$failure,1);
 try { $failed=false; try { TickerCatalog::read(); } catch (RuntimeException $error) { $failed=true; } equal($failed,true); } finally { remove_filter('pre_http_request',$failure,1); }
});
test('Ticker catalogue route requires explicit workspace membership and never creates assets', function () use($owner,$viewer,$w1,$w2) {
 $request=new WP_REST_Request('GET','/tgit/v1/workspaces/'.$w1.'/ticker-catalog'); $request->set_url_params(['workspace'=>(string)$w1]);
 wp_set_current_user($owner); equal(GainerInteractive\IGTradingJournal\Http\Controller::permission($request),true);
 wp_set_current_user($viewer); $foreign=new WP_REST_Request('GET','/tgit/v1/workspaces/'.$w2.'/ticker-catalog'); $foreign->set_url_params(['workspace'=>(string)$w2]);
 $denied=GainerInteractive\IGTradingJournal\Http\Controller::permission($foreign); equal(is_wp_error($denied),true);
 wp_set_current_user(0); equal(is_wp_error(GainerInteractive\IGTradingJournal\Http\Controller::permission($request)),true);
 wp_set_current_user($owner);
});


test('Coin catalogue retains distinct IDs for repeated symbols and validates input', function () {
 $rows=GainerInteractive\IGTradingJournal\Infrastructure\CoinCatalog::normalize('[{"id":"bitcoin","symbol":"btc","name":"Bitcoin"},{"id":"other-bitcoin","symbol":"BTC","name":"Other Bitcoin"},{"id":"bitcoin","symbol":"BTC","name":"Duplicate ID"},{"id":"bad","symbol":"<script>","name":"Bad"}]');
 equal($rows,[['id'=>'bitcoin','symbol'=>'BTC','title'=>'Bitcoin'],['id'=>'other-bitcoin','symbol'=>'BTC','title'=>'Other Bitcoin']]);
 foreach (['null','{}','[]','[{"id":"","symbol":"BTC","name":"Bad"}]'] as $bad) { $failed=false; try { GainerInteractive\IGTradingJournal\Infrastructure\CoinCatalog::normalize($bad); } catch (RuntimeException $error) {$failed=true;} equal($failed,true); }
});
test('Coin catalogue uses an isolated bounded fixed-source cache and manual outage fallback', function () {
 delete_transient('tgit_public_coins_v1'); $calls=0;
 $mock=function($prior,$args,$url) use(&$calls) {equal($url,GainerInteractive\IGTradingJournal\Infrastructure\CoinCatalog::URL); equal($args['redirection'],0); equal($args['sslverify'],true); equal($args['cookies'],[]); equal($args['limit_response_size'],2097153); ++$calls; return ['response'=>['code'=>200],'headers'=>[],'body'=>'[{"id":"bitcoin","symbol":"BTC","name":"Bitcoin"}]','cookies'=>[]];};
 add_filter('pre_http_request',$mock,1,3);
 try {equal(GainerInteractive\IGTradingJournal\Infrastructure\CoinCatalog::read()[0]['id'],'bitcoin'); equal(GainerInteractive\IGTradingJournal\Infrastructure\CoinCatalog::read()[0]['symbol'],'BTC'); equal($calls,1);}
 finally {remove_filter('pre_http_request',$mock,1); delete_transient('tgit_public_coins_v1');}
 $failure=fn()=>new WP_Error('fixture_unavailable','Synthetic outage'); add_filter('pre_http_request',$failure,1);
 try {$failed=false;try {GainerInteractive\IGTradingJournal\Infrastructure\CoinCatalog::read();} catch (RuntimeException $error) {$failed=true;} equal($failed,true);} finally {remove_filter('pre_http_request',$failure,1);}
});
test('Coin catalogue route requires workspace membership and hides it from anonymous users', function () use($owner,$viewer,$w1,$w2) {
 $request=new WP_REST_Request('GET','/tgit/v1/workspaces/'.$w1.'/coin-catalog');$request->set_url_params(['workspace'=>(string)$w1]);
 wp_set_current_user($owner);equal(GainerInteractive\IGTradingJournal\Http\Controller::permission($request),true);
 wp_set_current_user($viewer);$request->set_url_params(['workspace'=>(string)$w2]);equal(is_wp_error(GainerInteractive\IGTradingJournal\Http\Controller::permission($request)),true);
 wp_set_current_user(0);equal(is_wp_error(GainerInteractive\IGTradingJournal\Http\Controller::permission($request)),true);wp_set_current_user($owner);
});
