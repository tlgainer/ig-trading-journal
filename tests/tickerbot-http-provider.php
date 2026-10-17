<?php
/** Disposable synthetic provider hook shared by the local router and CLI cron fixture. */
if(!defined('TGIT_DISPOSABLE_TEST_SITE')||TGIT_DISPOSABLE_TEST_SITE!==true||wp_get_environment_type()!=='development')throw new RuntimeException('Disposable environment required.');
$file=getenv('TGIT_TEST_TICKERBOT_FIXTURE');
if(!$file||!is_file($file))throw new RuntimeException('Synthetic fixture file required.');
$tb_fixture=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
if(!preg_match('/^synthetic-worker-job-[a-f0-9]+$/D',$tb_fixture['scope']??'')||!is_int($tb_fixture['workspace']??null))throw new RuntimeException('Synthetic account scope required.');
foreach(['TGIT_TICKERBOT_API_KEY'=>'synthetic-tickerbot-fixture','TGIT_TICKERBOT_ACCOUNT_SCOPE'=>$tb_fixture['scope'],'TGIT_TICKERBOT_ENABLED'=>true,'TGIT_TICKERBOT_WORKER_ENABLED'=>getenv('TGIT_TEST_TICKERBOT_WORKER_OFF')!=='1','TGIT_TICKERBOT_SCHEDULER_ENABLED'=>getenv('TGIT_TEST_TICKERBOT_WORKER_OFF')!=='1']as$name=>$value){if(defined($name))throw new RuntimeException('Fixture requires isolated server settings.');define($name,$value);}
add_filter('pre_http_request',function($prior,$args,$url){
 $path=parse_url($url,PHP_URL_PATH);
 if(parse_url($url,PHP_URL_HOST)==='api.tickerbot.io'&&in_array($path,['/v2/tickers/AAPL','/v2/tickers/MSFT','/v2/tickers/AAPL,MSFT'],true)){
  file_put_contents(dirname(__DIR__).'/tmp/tickerbot-package-http-calls.jsonl',json_encode(['path'=>$path])."\n",FILE_APPEND);
  $symbols=explode(',',basename($path));$rows=[];
  foreach($symbols as$symbol)$rows[$symbol]=['ticker'=>$symbol,'currency_name'=>'usd','asset_class'=>'stocks','asset_type'=>'Common Stock','exchange'=>'NASDAQ','exchange_mic'=>'XNAS','price'=>'150.123456789123456789','price_source'=>'fair_value','price_asof'=>gmdate('Y-m-d\TH:i:s\Z')];
  $payload=count($symbols)===1?['ticker'=>$symbols[0],'as_of'=>gmdate('Y-m-d\TH:i:s\Z'),'data'=>$rows[$symbols[0]]]:['requested'=>$symbols,'count'=>count($rows),'as_of'=>gmdate('Y-m-d\TH:i:s\Z'),'data'=>$rows,'not_found'=>[]];
  return ['response'=>['code'=>200],'headers'=>[],'cookies'=>[],'body'=>json_encode($payload)];
 }

 if(false!==$prior)return $prior;
 return new WP_Error('synthetic_external_block','External HTTP is blocked by the disposable package fixture.');
},PHP_INT_MAX,3);
