<?php
/** Child process for disposable Tickerbot admission races, synthetic context only. */
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/tmp/wordpress/wp-load.php';
if(!defined('TGIT_DISPOSABLE_TEST_SITE')||TGIT_DISPOSABLE_TEST_SITE!==true)throw new RuntimeException('Disposable site required.');
require_once $root.'/ig-trading-journal.php';
$input=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$store=new GainerInteractive\IGTradingJournal\Application\TickerbotStore(new GainerInteractive\IGTradingJournal\Infrastructure\Database($wpdb),(int)$input['owner'],wp_generate_uuid4(),$input['scope'],new DateTimeImmutable($input['clock']));
$result=$store->reserve_attempt((int)$input['workspace'],(int)$input['run'],$input['token'],'connection');
echo json_encode(['state'=>$result['state']],JSON_THROW_ON_ERROR);
