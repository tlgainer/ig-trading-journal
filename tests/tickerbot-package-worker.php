<?php
/** Invoke the real queued worker/recurring callback without a browser or live HTTP. */
if(PHP_SAPI!=='cli')throw new RuntimeException('CLI only.');
$site=getenv('TGIT_TEST_SITE_PATH');$plugin=getenv('TGIT_TEST_PLUGIN_PATH');if(!$site||!$plugin)throw new RuntimeException('Explicit disposable paths required.');
$_SERVER['HTTP_HOST']='localhost:19308';$_SERVER['REQUEST_URI']='/';require $site.'/wp-load.php';require __DIR__.'/tickerbot-http-provider.php';require_once getenv('TGIT_TEST_PLUGIN_PATH').'/ig-trading-journal.php';
$db=new GainerInteractive\IGTradingJournal\Infrastructure\Database($wpdb);$workspace=$tb_fixture['workspace'];
if (($argv[1]??'')==='prepare-repeat') { $db->query('UPDATE '.$db->table('tickerbot_settings').' SET last_manual_at=%s WHERE workspace_id=%d',[gmdate('Y-m-d H:i:s',time()-600),$workspace]);echo "PASS Synthetic repeat-fixture cooldown reset.\n";exit; }
if(($argv[1]??'')==='recurring'){
 $db->query('UPDATE '.$db->table('tickerbot_settings').' SET next_due_at=%s WHERE workspace_id=%d',[gmdate('Y-m-d H:i:s',time()-1),$workspace]);GainerInteractive\IGTradingJournal\Infrastructure\RecurringTickerbot::job($workspace);$run=$db->row('SELECT id FROM '.$db->table('tickerbot_runs').' WHERE workspace_id=%d AND cause=%s ORDER BY id DESC LIMIT 1',[$workspace,'scheduled']);
}else{$id=(int)($argv[1]??0);$run=$db->row('SELECT id FROM '.$db->table('tickerbot_runs').' WHERE workspace_id=%d AND id=%d',[$workspace,$id]);}
if(!$run)throw new RuntimeException('Owned fixture run missing.');$event=wp_get_scheduled_event('tgit_tickerbot_batch',[$workspace,(int)$run['id']]);if($event)wp_unschedule_event($event->timestamp,'tgit_tickerbot_batch',[$workspace,(int)$run['id']]);GainerInteractive\IGTradingJournal\Infrastructure\TickerbotRefresh::job($workspace,(int)$run['id']);$state=$db->row('SELECT state FROM '.$db->table('tickerbot_runs').' WHERE workspace_id=%d AND id=%d',[$workspace,$run['id']]);if($state['state']!=='completed')throw new RuntimeException('Synthetic queued run did not complete: '.$state['state']);echo "PASS Extracted-package queued callback completed without page visits or live HTTP.\n";
