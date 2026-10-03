<?php
/** Verify cached normalized content without GD on a disposable CLI runtime. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || count($argv) !== 5 || extension_loaded('gd')) exit(2);
require $argv[1] . '/wp-load.php';
if (!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE !== true) exit(2);
require_once dirname(__DIR__) . '/ig-trading-journal.php';
global $wpdb;
$service = new \GainerInteractive\IGTradingJournal\Application\Media(new \GainerInteractive\IGTradingJournal\Infrastructure\Database($wpdb), (int) $argv[2], wp_generate_uuid4());
$content = $service->content((int) $argv[3], (int) $argv[4], 'original');
$settings = $service->settings((int) $argv[3]);
if ($settings['storage_ready'] || $settings['storage_status'] !== 'gd_unavailable' || !is_file($content['path'])) exit(3);
echo 'available';
