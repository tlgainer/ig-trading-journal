<?php
/** Synthetic two-process capacity admission race; disposable site only. */
declare(strict_types=1);
$root=dirname(__DIR__);require $root.'/tmp/wordpress/wp-load.php';
if(!defined('TGIT_DISPOSABLE_TEST_SITE')||TGIT_DISPOSABLE_TEST_SITE!==true)throw new RuntimeException('Disposable site required.');
require_once $root.'/ig-trading-journal.php';$input=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);$workspace=(int)$input['workspaces'][(int)$argv[2]];
$store=new GainerInteractive\IGTradingJournal\Application\TickerbotStore(new GainerInteractive\IGTradingJournal\Infrastructure\Database($wpdb),(int)$input['owner'],wp_generate_uuid4(),$input['scope'],new DateTimeImmutable($input['clock']));
try{$store->save_cadence($workspace,true,0);$state='saved';}catch(DomainException $error){$state='blocked';}
echo json_encode(['state'=>$state],JSON_THROW_ON_ERROR);
