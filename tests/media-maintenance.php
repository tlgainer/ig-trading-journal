<?php
/** Bounded retention and concurrent storage fixtures; disposable site only. */
test('Retention jobs recheck owner membership and purge only expired workspace bytes', function () use ($jm, $db, $jw, $owner, $viewer, $jtradeid, $jdescriptor, $jfixture, $jlimits) {
 equal((bool) wp_next_scheduled('tgit_media_cleanup', [$jw, $owner]), true);
 $row = $jm->reserve($jw, $jtradeid, $jdescriptor, 'retention-image');
 $output = \GainerInteractive\IGTradingJournal\Infrastructure\PrivateImages::normalize($jw, $jfixture, 'fixture.png', $jlimits);
 $path = \GainerInteractive\IGTradingJournal\Infrastructure\PrivateImages::path($jw, $output['original']['key']);
 $db->update_object('media', $jw, (int) $row['id'], ['state' => 'ready', 'storage_key' => $output['original']['key'], 'thumb_key' => $output['thumbnail']['key'], 'bytes' => $output['original']['bytes'], 'thumb_bytes' => $output['thumbnail']['bytes'], 'reserved_bytes' => $output['original']['bytes'] + $output['thumbnail']['bytes'], 'mime' => 'image/png', 'revision' => 2]);
 $jm->change($jw, (int) $row['id'], 'delete', ['expected_revision' => 2], 'retention-delete');
 $db->update_object('media', $jw, (int) $row['id'], ['deleted_at' => gmdate('Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS)]);
 $abandoned = $jm->reserve($jw, $jtradeid, $jdescriptor, 'abandoned-image');
 $db->update_object('media', $jw, (int) $abandoned['id'], ['updated_at' => gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS)]);
 \GainerInteractive\IGTradingJournal\Infrastructure\MediaJobs::cleanup($jw, $viewer);
 equal($db->object('media', $jw, (int) $row['id'])['state'], 'deleted'); equal(is_file($path), true);
 \GainerInteractive\IGTradingJournal\Infrastructure\MediaJobs::cleanup($jw, $owner);
 equal($db->object('media', $jw, (int) $row['id'])['state'], 'purged'); equal(is_file($path), false); equal($db->object('media', $jw, (int) $abandoned['id'])['state'], 'purged');
 equal($jm->cleanup($jw, 'repeat-cleanup')['purged'], 0);
});
test('Concurrent reservations cannot over-allocate workspace quota', function () use ($tracker, $db, $owner, $ja, $jlimits, $jdescriptor) {
 $workspace = $tracker->create_workspace(['name' => 'Quota race'])['id'];
 $asset = $tracker->create_object($workspace, 'assets', ['symbol' => 'QUOTA', 'exchange' => 'FIXTURE', 'asset_class' => 'stock', 'quote_currency' => 'USD']);
 $journal = new \GainerInteractive\IGTradingJournal\Application\Journal($db, $owner, wp_generate_uuid4());
 $trade = $journal->save_trade($workspace, 0, ['asset_id' => (int) $asset['id'], 'title' => 'Quota race trade', 'state' => 'planned', 'journal' => [], 'transaction_ids' => []], 'quota-race-trade')['trade'];
 $media = new \GainerInteractive\IGTradingJournal\Application\Media($db, $owner, wp_generate_uuid4());
 $media->save_settings($workspace, array_replace($jlimits, ['quota_bytes' => 11534336]), 'quota-race-settings');
 $processes = [];
 foreach (['a', 'b'] as $key) {
  $task = ['operation' => 'reserve', 'id' => $trade['id'], 'payload' => $jdescriptor, 'key' => 'quota-race-' . $key];
  $command = [PHP_BINARY, '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=mysqli', '-d', 'extension=gd', __DIR__ . '/concurrency-worker.php', rtrim(ABSPATH, '/\\'), (string) $owner, (string) $workspace, wp_json_encode($task)];
  $pipes = []; $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  if (!is_resource($process)) throw new RuntimeException('Worker failed.'); fclose($pipes[0]); $processes[] = [$process, $pipes];
 }
 $results = [];
 foreach ($processes as [$process, $pipes]) { $results[] = trim(stream_get_contents($pipes[1])); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); if (proc_close($process) !== 0) throw new RuntimeException($error); }
 sort($results); equal($results, ['posted', 'rejected']); equal((int) $media->settings($workspace)['used_bytes'], 11534336);
});
