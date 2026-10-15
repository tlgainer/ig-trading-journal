<?php
/** Isolated synthetic transport tests; never load into WordPress. */
declare(strict_types=1);
if (defined('ABSPATH')) throw new RuntimeException('Isolated process required.');
require_once __DIR__.'/../src/Domain/Decimal.php';
require_once __DIR__.'/../src/Infrastructure/ProviderJson.php';
require_once __DIR__.'/../src/Infrastructure/QuoteProvider.php';
require_once __DIR__.'/../src/Infrastructure/TickerbotQuote.php';
require_once __DIR__.'/../src/Infrastructure/TickerbotCoverageSummary.php';
require_once __DIR__.'/../src/Infrastructure/TickerbotProvider.php';
use GainerInteractive\IGTradingJournal\Infrastructure\TickerbotProvider;
function is_wp_error($response): bool {return $response instanceof stdClass;}
function wp_remote_retrieve_response_code(array $response): int {return $response['status'];}
function wp_remote_retrieve_header(array $response,string $name) {return $response['headers'][$name]??'';}
function wp_remote_retrieve_body(array $response): string {return $response['body'];}
function wp_json_encode($value): string {return json_encode($value,JSON_THROW_ON_ERROR);}
function tb_check(bool $value): void {if(!$value)throw new RuntimeException('Synthetic transport assertion failed.');}
$mode=$argv[1]??'enabled';define('TGIT_TICKERBOT_ENABLED',$mode!=='disabled');define('TGIT_TICKERBOT_API_KEY',$mode==='invalid-key'?"synthetic\nkey":'synthetic-tickerbot-key');
$calls=[];$families=[];
$send=static function(string $url,array $options)use(&$calls):array {
 tb_check(str_starts_with($url,'https://api.tickerbot.io/v2/'));tb_check(!str_contains($url,'synthetic'));tb_check($options['headers']['Authorization']==='Bearer synthetic-tickerbot-key');tb_check($options['sslverify']===true&&$options['redirection']===0&&$options['cookies']===[]);$calls[]=$url;
 $symbols=array_map('rawurldecode',explode(',',substr(parse_url($url,PHP_URL_PATH),strlen('/v2/tickers/'))));$rows=[];
 foreach($symbols as $symbol)$rows[$symbol]=['ticker'=>$symbol,'asset_class'=>'stocks','asset_type'=>'CS','exchange'=>'NASDAQ','exchange_mic'=>'XNAS','currency_name'=>'usd','price'=>'1.123456789012345678','price_source'=>'trade','price_asof'=>gmdate('Y-m-d\TH:i:s\Z')];
 $body=count($symbols)===1?['ticker'=>$symbols[0],'data'=>$rows[$symbols[0]]]:['requested'=>$symbols,'data'=>$rows,'not_found'=>[],'count'=>count($symbols)];
 return ['status'=>200,'body'=>json_encode($body),'headers'=>[]];
};
$provider=new TickerbotProvider(static function(string $family)use(&$families):bool{$families[]=$family;return true;},$send);
if($mode!=='enabled'){tb_check($provider->test_connection()['state']==='disabled');tb_check($calls===[]&&$families===[]);echo "PASS $mode admits no HTTP.\n";exit;}
$assets=[];for($i=1;$i<=51;$i++)$assets[]=['id'=>$i,'symbol'=>'T'.$i,'provider_symbol'=>'T'.$i,'asset_class'=>'stock','exchange'=>'XNAS','quote_currency'=>'USD','note'=>'not-sent','quantity'=>'not-sent'];
$result=$provider->quotes($assets);tb_check(count($calls)===2&&count($families)===2&&count($result['accepted'])===51);tb_check($result['accepted'][1]['price']==='1.123456789012345678');tb_check(!str_contains(json_encode($calls),'not-sent'));
$denied=new TickerbotProvider(static fn()=>false,$send);tb_check($denied->test_connection()['state']==='budget_blocked');tb_check(count($calls)===2);
foreach([401=>'authentication',402=>'monthly_quota',403=>'capability',429=>'rate_limit']as$status=>$state){$sent=0;$p=new TickerbotProvider(static fn()=>true,static function()use($status,&$sent){$sent++;return ['status'=>$status,'body'=>'synthetic-secret-body','headers'=>['retry-after'=>'120']];});$result=$p->quotes($assets);tb_check($sent===1&&$result['batches'][0]['state']===$state&&$result['issues'][51]==='not_attempted');tb_check(!str_contains(json_encode($result),'synthetic-secret'));if($status===429)tb_check($result['batches'][0]['retry_after_seconds']===120);}
$p=new TickerbotProvider(static fn()=>true,static function(){throw new RuntimeException('synthetic-secret-in-exception');});tb_check(!str_contains(json_encode($p->test_connection()),'synthetic-secret'));
$partialCalls=0;$p=new TickerbotProvider(static fn()=>true,static function($url,$options)use(&$partialCalls,$send){$partialCalls++;return $partialCalls===1?$send($url,$options):['status'=>404,'body'=>'no','headers'=>[]];});$partial=$p->quotes($assets);tb_check(count($partial['accepted'])===50&&$partial['issues'][51]==='unsupported');
tb_check($provider->test_connection()['state']==='connected');
$catalogRow=['ticker'=>'AAPL','asset_class'=>'stocks','asset_type'=>'CS','currency_name'=>'usd','exchange'=>'NASDAQ','exchange_mic'=>'XNAS'];
$p=new TickerbotProvider(static fn()=>true,static function($url)use($catalogRow){tb_check(str_contains($url,'/tickers?search=AAPL&asset_class=stocks&limit=50'));return ['status'=>200,'body'=>json_encode(['results'=>[$catalogRow]]),'headers'=>[]];});
$asset=['id'=>1,'symbol'=>'AAPL','asset_class'=>'stock','quote_currency'=>'USD','exchange'=>'XNAS'];tb_check($p->resolve($asset,'AAPL')['state']==='mapped');
$p=new TickerbotProvider(static fn()=>true,static fn()=>['status'=>200,'body'=>json_encode(['ticker'=>'AAPL','as_of'=>null,'live_row'=>['columns'=>1,'populated'=>1,'null_fields'=>[],'extra'=>'ignored']]),'headers'=>[]]);$coverage=$p->coverage('AAPL');tb_check($coverage['state']==='covered'&&$coverage['summary']['columns']===1&&!isset($coverage['live_row']));
$p=new TickerbotProvider(static fn()=>true,static fn()=>new stdClass());tb_check($p->test_connection()['state']==='transport_error');
$p=new TickerbotProvider(static fn()=>true,static fn()=>['status'=>200,'body'=>'not-json-synthetic-secret','headers'=>[]]);tb_check($p->test_connection()['state']==='invalid_response');
$before=count($calls);$duplicate=$assets[0];$duplicate['id']=999;$deduplicated=$provider->quotes([$assets[0],$duplicate]);tb_check(count($calls)===$before+1&&count($deduplicated['accepted'])===2);
echo "PASS 51-symbol batches, admission, bearer/TLS bounds, precision, safe errors, stop conditions, Retry-After and partial batch retention.\n";
