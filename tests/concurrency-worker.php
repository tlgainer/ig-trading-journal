<?php
/** Child worker for the disposable-site integration test, never a web endpoint. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || count($argv) !== 5) { exit(2); }
require $argv[1] . '/wp-load.php';
if (!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE !== true) { exit(2); }
if (!class_exists(\GainerInteractive\IGTradingJournal\Application\Tracker::class)) { require dirname(__DIR__) . '/ig-trading-journal.php'; }
global $wpdb;
$wpdb->suppress_errors(true);
$task = json_decode($argv[4], true, 512, JSON_THROW_ON_ERROR);
$service = new \GainerInteractive\IGTradingJournal\Application\Tracker(new \GainerInteractive\IGTradingJournal\Infrastructure\Database($wpdb), (int) $argv[2], wp_generate_uuid4());
try {
 if (($task['operation'] ?? '') === 'ai_reserve') {
  if(!preg_match('/^fixture_ai_[a-f0-9]{10}_$/D',$task['prefix'])) throw new RuntimeException('Invalid isolated prefix');
  $ai_connection=clone $wpdb; $ai_connection->prefix=$task['prefix'];
  (new \GainerInteractive\IGTradingJournal\Application\AiSpending(new \GainerInteractive\IGTradingJournal\Infrastructure\Database($ai_connection),(int)$argv[2],wp_generate_uuid4(),new DateTimeImmutable($task['now'],new DateTimeZone('UTC'))))->reserve((int)$argv[3],$task['credential'],$task['key'],$task['fingerprint'],10000,2000);
  echo 'posted'; exit(0);
 }
 if (($task['operation'] ?? 'post') === 'provider_reserve') { (new \GainerInteractive\IGTradingJournal\Application\MarketData(new \GainerInteractive\IGTradingJournal\Infrastructure\Database($wpdb), (int) $argv[2], wp_generate_uuid4()))->reserve((int) $argv[3], (int) $task['id'], $task['fingerprint'], $task['key'], false); } elseif (($task['operation'] ?? 'post') === 'reserve') { (new \GainerInteractive\IGTradingJournal\Application\Media(new \GainerInteractive\IGTradingJournal\Infrastructure\Database($wpdb), (int) $argv[2], wp_generate_uuid4()))->reserve((int) $argv[3], (int) $task['id'], $task['payload'], $task['key']); } elseif (($task['operation'] ?? 'post') === 'promote') { $service->promote_draft((int) $argv[3], (int) $task['id'], $task['payload'], $task['key']); } else { $service->post((int) $argv[3], $task['payload'], $task['key']); }
 echo 'posted';
} catch (\InvalidArgumentException | \UnexpectedValueException $error) {
 echo 'rejected';
} catch (\Throwable $error) {
 fwrite(STDERR, get_class($error) . ': ' . $error->getMessage()); exit(1);
}
