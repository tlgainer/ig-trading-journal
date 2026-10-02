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
 $service->post((int) $argv[3], $task['payload'], $task['key']);
 echo 'posted';
} catch (\InvalidArgumentException $error) {
 echo 'rejected';
} catch (\Throwable $error) {
 fwrite(STDERR, get_class($error) . ': ' . $error->getMessage()); exit(1);
}
