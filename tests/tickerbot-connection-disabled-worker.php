<?php
/** Disabled transport retry fixture; synthetic identity file, disposable site only. */
declare(strict_types=1);
$root=dirname(__DIR__);require $root.'/tmp/wordpress/wp-load.php';
if(!defined('TGIT_DISPOSABLE_TEST_SITE')||TGIT_DISPOSABLE_TEST_SITE!==true)throw new RuntimeException('Disposable site required.');
require_once $root.'/ig-trading-journal.php';$input=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$s=new GainerInteractive\IGTradingJournal\Application\TickerbotStore(new GainerInteractive\IGTradingJournal\Infrastructure\Database($wpdb),(int)$input['owner'],wp_generate_uuid4(),$input['scope']);
$send=fn()=>throw new LogicException('Disabled fixture sent HTTP');
echo json_encode(['new'=>GainerInteractive\IGTradingJournal\Infrastructure\TickerbotConnectionCheck::run($s,(int)$input['workspace'],wp_generate_uuid4(),$send),'prior'=>GainerInteractive\IGTradingJournal\Infrastructure\TickerbotConnectionCheck::run($s,(int)$input['workspace'],$input['key'],$send)],JSON_THROW_ON_ERROR);
