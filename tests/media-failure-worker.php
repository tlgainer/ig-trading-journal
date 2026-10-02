<?php
/** Inject a scoped database failure only on the disposable site's selected media audit. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || count($argv) !== 5 || !in_array($argv[2], ['add', 'remove'], true) || !ctype_digit($argv[3]) || !ctype_digit($argv[4])) exit(2);
require $argv[1] . '/wp-load.php';
if (!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE !== true) exit(2);
global $wpdb;
$workspace = (int) $argv[3]; $media = (int) $argv[4];
$name = 'tgit_fixture_media_' . $media;
$table = $wpdb->prefix . 'tgit_audit_events';
if ($argv[2] === 'add') $sql = 'ALTER TABLE ' . $table . ' ADD CONSTRAINT ' . $name . " CHECK (workspace_id <> $workspace OR entity_id <> $media OR entity <> 'media' OR action <> 'media.ready')";
else $sql = 'ALTER TABLE ' . $table . ' DROP CHECK ' . $name;
if ($wpdb->query($sql) === false) { fwrite(STDERR, 'Fixture failure injection failed.'); exit(1); }
