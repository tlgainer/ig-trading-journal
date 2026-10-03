<?php
/** Additional journal contracts on sanitized disposable data. */
test('Checklist states and original sheet text survive journal revisions', function () use ($jt, $jw, $ja) {
 $facts = ['asset_id' => (int) $ja['id'], 'title' => 'Checklist fixture', 'state' => 'planned', 'transaction_ids' => [], 'journal' => ['confluences' => [['label' => 'Level held', 'checked' => true], ['label' => 'Volume', 'checked' => false]], 'original_confluences' => '<sheet-original>Level > 10 & volume</sheet-original>']];
 $result = $jt->save_trade($jw, 0, $facts, 'checklist-create'); equal($result['journal']['fields']['confluences'], $facts['journal']['confluences']); equal($result['journal']['fields']['original_confluences'], $facts['journal']['original_confluences']);
 rejects(fn() => $jt->save_trade($jw, 0, array_replace($facts, ['journal' => ['confluences' => [['label' => 'Bad', 'checked' => 1]]]]), 'checklist-bad'));
});
test('A linked draft cannot change its asset or become a cash fill', function () use ($tracker, $jt, $jw, $jbuy, $ja) {
 $draft = $tracker->post($jw, $jbuy, 'linked-edit-draft')['transaction'];
 $jt->save_trade($jw, 0, ['asset_id' => (int) $ja['id'], 'title' => 'Linked draft edit', 'state' => 'planned', 'transaction_ids' => [(int) $draft['id']], 'journal' => []], 'linked-edit-trade');
 $asset = $tracker->create_object($jw, 'assets', ['symbol' => 'OTHER', 'exchange' => 'FIXTURE', 'asset_class' => 'stock', 'quote_currency' => 'USD']);
 rejects(fn() => $tracker->edit_draft($jw, (int) $draft['id'], ['expected_revision' => 1, 'transaction' => array_replace($jbuy, ['asset_id' => (int) $asset['id']])], 'linked-asset-denied'));
 equal((int) $tracker->transaction($jw, (int) $draft['id'])['transaction']['asset_id'], (int) $ja['id']);
});
test('JPEG, PNG and WebP normalize; oversized decoded headers are rejected before allocation', function () use ($jw, $jlimits, $jfixture) {
 $path = dirname(__DIR__) . '/tmp/journal-fixture.webp'; $image = imagecreatetruecolor(32, 32); imagewebp($image, $path); imagedestroy($image);
 $output = \GainerInteractive\IGTradingJournal\Infrastructure\PrivateImages::normalize($jw, $path, 'fixture.webp', $jlimits); equal($output['mime'], 'image/webp');
 foreach (['original', 'thumbnail'] as $variant) \GainerInteractive\IGTradingJournal\Infrastructure\PrivateImages::remove($jw, $output[$variant]['key']);
 $bytes = substr_replace(file_get_contents($jfixture), pack('N2', 100000, 100000), 16, 8); $bytes = substr_replace($bytes, hash('crc32b', substr($bytes, 12, 17), true), 29, 4);
 $bomb = dirname(__DIR__) . '/tmp/oversized-header.png'; file_put_contents($bomb, $bytes);
 rejects(fn() => \GainerInteractive\IGTradingJournal\Infrastructure\PrivateImages::normalize($jw, $bomb, 'oversized.png', $jlimits));
 $memory_bytes = substr_replace(file_get_contents($jfixture), pack('N2', 2500, 2500), 16, 8); $memory_bytes = substr_replace($memory_bytes, hash('crc32b', substr($memory_bytes, 12, 17), true), 29, 4);
 $memory_image = dirname(__DIR__) . '/tmp/memory-header.png'; file_put_contents($memory_image, $memory_bytes);
 $previous_limit = ini_get('memory_limit');
 try {
  ini_set('memory_limit', '64M');
  try { \GainerInteractive\IGTradingJournal\Infrastructure\PrivateImages::normalize($jw, $memory_image, 'memory.png', $jlimits); throw new RuntimeException('Memory limit should reject this image.'); }
  catch (InvalidArgumentException $error) { equal(str_contains($error->getMessage(), 'Not enough PHP memory'), true); }
 } finally { ini_set('memory_limit', $previous_limit); }
});
test('Pending image replacement reuses its slot and requires the current revision', function () use ($jm, $db, $jw, $jtradeid, $jdescriptor) {
 $row = $jm->reserve($jw, $jtradeid, $jdescriptor, 'replace-reserve'); $db->update_object('media', $jw, (int) $row['id'], ['state' => 'failed', 'revision' => 2]);
 $before = count($jm->gallery($jw, $jtradeid)['items']);
 $input = ['expected_revision' => 2, 'filename' => 'corrected.png', 'size' => $jdescriptor['size'], 'hash' => $jdescriptor['hash']];
 $replacement = $jm->retry($jw, (int) $row['id'], $input, 'replace-retry'); equal($replacement['state'], 'reserved'); equal((int) $replacement['revision'], 3); equal(count($jm->gallery($jw, $jtradeid)['items']), $before);
 equal($jm->retry($jw, (int) $row['id'], $input, 'replace-retry'), $replacement);
 try { $jm->retry($jw, (int) $row['id'], $input, 'replace-stale'); } catch (UnexpectedValueException $e) { return; } throw new RuntimeException('Stale replacement accepted.');
});
test('Journal revision pages are bounded, stable and workspace scoped', function () use ($tracker, $db, $owner, $w2) {
 $workspace = $tracker->create_workspace(['name' => 'Revision pages'])['id'];
 $asset = $tracker->create_object($workspace, 'assets', ['symbol' => 'PAGES', 'exchange' => 'FIXTURE', 'asset_class' => 'stock', 'quote_currency' => 'USD']);
 $journal = new \GainerInteractive\IGTradingJournal\Application\Journal($db, $owner, wp_generate_uuid4());
 $input = ['asset_id' => (int) $asset['id'], 'title' => 'History pages', 'state' => 'planned', 'journal' => [], 'transaction_ids' => []];
 $trade = $journal->save_trade($workspace, 0, $input, 'history-create')['trade'];
 for ($revision = 1; $revision <= 24; $revision++) $journal->save_trade($workspace, (int) $trade['id'], $input + ['expected_revision' => $revision], 'history-edit-' . $revision);
 $current = $journal->trade($workspace, (int) $trade['id']); equal(count($current['revisions']), 20); equal((int) $current['revisions'][0]['revision'], 6);
 $older = $journal->history($workspace, 'trades', (int) $trade['id'], (int) $current['revisions_cursor'], 20); equal(array_map('intval', array_column($older['items'], 'revision')), [1, 2, 3, 4, 5]); equal($older['next_cursor'], null);
 rejects(fn() => $journal->history($workspace, 'trades', (int) $trade['id'], 26, 21));
 try { $journal->history($w2, 'trades', (int) $trade['id'], 26, 20); } catch (OutOfBoundsException $e) { return; } throw new RuntimeException('Foreign revision history read.');
});
