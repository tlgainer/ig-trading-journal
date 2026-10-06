<?php
/** Sanitized journal/media fixtures; included only by the disposable integration suite. */
use GainerInteractive\IGTradingJournal\Application\Journal;
use GainerInteractive\IGTradingJournal\Application\Media;
use GainerInteractive\IGTradingJournal\Infrastructure\PrivateImages;
if (!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE !== true) throw new RuntimeException('Disposable site required.');
$jt = new Journal($db, $owner, wp_generate_uuid4());
$jm = new Media($db, $owner, wp_generate_uuid4());
$jw = $tracker->create_workspace(['name' => 'Journal fixtures'])['id'];
$ja = $tracker->create_object($jw, 'assets', ['symbol' => 'JRTEST', 'exchange' => 'FIXTURE', 'asset_class' => 'stock', 'quote_currency' => 'USD']);
$jaccount = $tracker->create_object($jw, 'accounts', ['name' => 'Journal cash', 'native_currency' => 'USD']);
$tracker->post($jw, ['account_id' => (int) $jaccount['id'], 'action' => 'deposit', 'effective_date' => '2026-01-01', 'state' => 'posted', 'amount' => '100', 'currency' => 'USD'], 'journal-cash');
$jbuy = ['account_id' => (int) $jaccount['id'], 'asset_id' => (int) $ja['id'], 'action' => 'buy', 'effective_date' => '2026-01-02', 'state' => 'draft', 'quantity' => '1', 'unit_price' => '10', 'fees' => '0', 'currency' => 'USD'];
$jfill = $tracker->post($jw, $jbuy, 'journal-draft-fill')['transaction'];
$jsfacts = ['name' => 'Fixture strategy', 'status' => 'active', 'description' => '<p>Original thesis</p>', 'rules' => '<strong>Follow the plan</strong>', 'tags' => ['fixture']];
$jstrategy = $jt->save_strategy($jw, 0, $jsfacts, 'journal-strategy');
$jfacts = ['asset_id' => (int) $ja['id'], 'title' => 'Optional image journal', 'state' => 'open', 'strategy_version_id' => $jstrategy['version_id'], 'transaction_ids' => [(int) $jfill['id']], 'journal' => ['thesis' => '<p>Manual idea</p>', 'notes' => 'Calm', 'premarket_low' => '0.123456789012345678', 'confluences' => ['Level held'], 'confluence_text' => 'Manual checklist', 'tags' => ['example']]];
$jtrade = $jt->save_trade($jw, 0, $jfacts, 'journal-create');
$jtradeid = (int) $jtrade['trade']['id'];
$jlimits = ['max_images' => 20, 'max_file_bytes' => 10485760, 'max_pixels' => 40000000, 'quota_bytes' => 1073741824, 'trash_days' => 30, 'expected_revision' => 0];

test('Additive schema upgrade retains ledger rows and safely repeats', function () use ($db) {
 $before = $db->row('SELECT COUNT(*) AS count FROM ' . $db->table('transactions'));
 update_option('tgit_schema_version', '1'); \GainerInteractive\IGTradingJournal\Infrastructure\Installer::install();
 equal(get_option('tgit_schema_version'), \GainerInteractive\IGTradingJournal\Infrastructure\Installer::VERSION);
 \GainerInteractive\IGTradingJournal\Infrastructure\Installer::install();
 equal($db->row('SELECT COUNT(*) AS count FROM ' . $db->table('transactions')), $before);
 update_option('tgit_schema_version', '99');
 try { \GainerInteractive\IGTradingJournal\Infrastructure\Installer::install(); throw new LogicException('Unknown schema accepted.'); } catch (RuntimeException $e) {} finally { update_option('tgit_schema_version', \GainerInteractive\IGTradingJournal\Infrastructure\Installer::VERSION); }
});
test('Trade collection metadata stays scoped and excludes journal prose', function () use ($jt, $jw, $jtradeid) {
 $page = $jt->listing((int) $jw, 'trades', 0, 1); $row = $page['items'][0];
 equal((int) $row['id'], $jtradeid); equal($row['symbol'], 'JRTEST'); equal($row['exchange'], 'FIXTURE');
 equal($row['tags'], ['example']); equal($row['strategy_name'], 'Fixture strategy'); equal((int) $row['image_count'], 0);
 equal(isset($row['journal_payload']), false); equal(isset($row['strategy_payload']), false);
 $detail = $jt->trade((int) $jw, $jtradeid); $latest = end($detail['revisions']);
 equal($row['updated_at'], $latest['created_at']); equal($page['next_cursor'], (string) $jtradeid);
});
test('Journal persists with zero images and never changes cash or FIFO', function () use ($jt, $jm, $tracker, $jw, $jtradeid, $jfacts) {
 equal(count($jm->gallery($jw, $jtradeid)['items']), 0);
 $before = $tracker->list_objects($jw, 'accounts'); $lots = $tracker->holdings($jw);
 $input = array_replace($jfacts, ['expected_revision' => 1, 'journal' => ['notes' => '<strong>Review</strong>', 'planned_stop' => '0.000000000000000001']]);
 $saved = $jt->save_trade($jw, $jtradeid, $input, 'journal-edit');
 equal(count($saved['revisions']), 2); equal($saved['journal']['fields']['planned_stop'], '0.000000000000000001');
 equal($jt->save_trade($jw, $jtradeid, $input, 'journal-edit'), $saved);
 equal($tracker->list_objects($jw, 'accounts'), $before);
 $afterLots = $tracker->holdings($jw); unset($afterLots['as_of'], $lots['as_of']); equal($afterLots, $lots);
 try { $jt->save_trade($jw, $jtradeid, $input, 'journal-stale'); } catch (UnexpectedValueException $e) { return; }
 throw new RuntimeException('Stale journal overwritten.');
});
test('Strategy editing preserves the captured version and strips stored XSS and remote embeds', function () use ($jt, $jw, $jstrategy, $jsfacts, $jtradeid) {
 $bad = '<script>alert(1)</script><img src="https://example.invalid/tracker" onerror="alert(1)"><a href="javascript:alert(1)">Bad</a><strong>Safe</strong>';
 $jt->save_strategy($jw, (int) $jstrategy['strategy']['id'], array_replace($jsfacts, ['expected_revision' => 1, 'description' => $bad, 'rules' => $bad]), 'strategy-edit');
 $versions = $jt->strategy($jw, (int) $jstrategy['strategy']['id'])['versions']; equal(count($versions), 2);
 $safe = json_decode($versions[1]['payload'], true);
 foreach (['<script', '<img', 'onerror', 'javascript:'] as $unsafe) equal(str_contains($safe['description'] . $safe['rules'], $unsafe), false);
 equal(str_contains($versions[1]['payload'], '<strong>Safe'), true);
 equal((int) $jt->trade($jw, $jtradeid)['strategy_version']['id'], $jstrategy['version_id']);
 equal(json_decode($versions[0]['payload'], true)['description'], $jsfacts['description']);
});
test('A fill belongs to one trade and cross-workspace references are rejected', function () use ($jt, $jw, $jfacts, $w2, $jstrategy, $jtradeid) {
 try { $jt->save_trade($jw, 0, $jfacts, 'duplicate-fill'); throw new LogicException('Fill linked twice.'); } catch (UnexpectedValueException $e) {}
 try { $jt->trade($w2, $jtradeid); throw new LogicException('Foreign trade read.'); } catch (OutOfBoundsException $e) {}
 try { $jt->save_trade($w2, 0, array_replace($jfacts, ['transaction_ids' => []]), 'foreign-trade'); throw new LogicException('Foreign asset or strategy accepted.'); } catch (OutOfBoundsException $e) {}
});
test('Linked draft promotion preserves the trade and journals its posted fill identity', function () use ($tracker, $jt, $jm, $jw, $jtradeid, $jfill) {
 $posted = $tracker->promote_draft($jw, (int) $jfill['id'], ['expected_revision' => 1], 'journal-fill-post')['transaction'];
 $trade = $jt->trade($jw, $jtradeid); equal(count($trade['fills']), 1); equal((int) $trade['fills'][0]['id'], (int) $posted['id']); equal(count($trade['revisions']), 3);
 equal((int) $trade['journal']['transaction_ids'][0], (int) $posted['id']); equal(count($jm->gallery($jw, $jtradeid)['items']), 0);
});
test('Contributors edit journals but viewers and revoked members cannot write or access media', function () use ($db, $tracker, $viewer, $jw, $jfacts, $owner) {
 $tracker->set_member($jw, ['wp_user_id' => $viewer, 'role' => 'contributor', 'state' => 'active']);
 $writer = new Journal($db, $viewer, wp_generate_uuid4()); $writer->save_trade($jw, 0, array_replace($jfacts, ['transaction_ids' => []]), 'contributor-journal');
 try { $writer->save_strategy($jw, 0, ['name' => 'Denied', 'status' => 'active', 'description' => '', 'rules' => '', 'tags' => []], 'contributor-strategy'); throw new LogicException('Contributor changed strategy library.'); } catch (DomainException $e) {}
 $tracker->set_member($jw, ['wp_user_id' => $viewer, 'role' => 'viewer', 'state' => 'active']);
 equal(count($writer->listing($jw, 'trades', 0, 100)['items']), 2);
 try { $writer->save_trade($jw, 0, array_replace($jfacts, ['transaction_ids' => []]), 'viewer-journal'); throw new LogicException('Viewer journal write.'); } catch (DomainException $e) {}
 $tracker->set_member($jw, ['wp_user_id' => $viewer, 'role' => 'viewer', 'state' => 'revoked']);
 try { $writer->listing($jw, 'trades', 0, 100); } catch (DomainException $e) { return; }
 throw new RuntimeException('Revoked member retained journal access.');
});
test('Media requires owner-configured quota and enforces file and count bounds', function () use ($jm, $jw, $jtradeid, $jlimits) {
 $input = ['filename' => 'fixture.png', 'size' => 10, 'hash' => str_repeat('a', 64)];
 rejects(fn() => $jm->reserve($jw, $jtradeid, $input, 'unconfigured-media'));
 $jm->save_settings($jw, $jlimits, 'media-settings');
 foreach ([array_replace($input, ['filename' => 'script.svg']), array_replace($input, ['size' => 10485761])] as $bad) rejects(fn() => $jm->reserve($jw, $jtradeid, $bad, wp_generate_uuid4()));
});
// Generate only sanitized geometric fixtures outside public WordPress files.
$jfixture = dirname(__DIR__) . '/tmp/journal-fixture.png';
$jimage = imagecreatetruecolor(96, 48); imagefilledrectangle($jimage, 0, 0, 95, 47, imagecolorallocate($jimage, 30, 90, 150)); imagepng($jimage, $jfixture); imagedestroy($jimage);
$jdescriptor = ['filename' => 'journal-fixture.png', 'size' => filesize($jfixture), 'hash' => hash_file('sha256', $jfixture)];
$jreserved = $jm->reserve($jw, $jtradeid, $jdescriptor, 'media-reserve');
test('Reservations are idempotent, trash stays within quota and metadata is revision checked', function () use ($jm, $jw, $jtradeid, $jdescriptor, $jreserved, $jlimits) {
 equal($jm->reserve($jw, $jtradeid, $jdescriptor, 'media-reserve'), $jreserved);
 $row = $jm->change($jw, (int) $jreserved['id'], 'update', ['expected_revision' => 1, 'caption' => '<script>caption</script>', 'alt_text' => 'Blue fixture', 'sort_order' => 2], 'media-caption');
 equal(str_contains($row['caption'], '<script>'), false); equal((int) $row['revision'], 2);
 try { $jm->change($jw, (int) $row['id'], 'delete', ['expected_revision' => 1], 'stale-delete'); throw new LogicException('Stale delete accepted.'); } catch (UnexpectedValueException $e) {}
 $trash = $jm->change($jw, (int) $row['id'], 'delete', ['expected_revision' => 2], 'media-delete');
 equal($trash['state'], 'deleted'); equal((int) $jm->settings($jw)['used_bytes'], 11534336);
 $restored = $jm->change($jw, (int) $row['id'], 'restore', ['expected_revision' => 3], 'media-restore'); equal($restored['state'], 'reserved');
 rejects(fn() => $jm->save_settings($jw, array_replace($jlimits, ['expected_revision' => 1, 'quota_bytes' => 1]), 'quota-too-small'));
});
test('Image validation rejects disguised files, unsafe paths and decode limits', function () use ($jw, $jfixture, $jlimits) {
 rejects(fn() => PrivateImages::normalize($jw, $jfixture, 'fake.jpg', $jlimits));
 rejects(fn() => PrivateImages::normalize($jw, $jfixture, 'fixture.png', array_replace($jlimits, ['max_pixels' => 1])));
 rejects(fn() => PrivateImages::path($jw, '../../uploads/public.png'));
 $output = PrivateImages::normalize($jw, $jfixture, 'fixture.png', $jlimits);
 equal($output['mime'], 'image/png'); equal($output['original']['width'], 96); equal($output['thumbnail']['height'], 48);
 foreach (['original', 'thumbnail'] as $variant) PrivateImages::remove($jw, $output[$variant]['key']);
 $prior = $_SERVER['DOCUMENT_ROOT'] ?? null; $_SERVER['DOCUMENT_ROOT'] = wp_slash(dirname(TGIT_PRIVATE_MEDIA_DIR));
 try { PrivateImages::root(); throw new LogicException('Storage under served root accepted.'); } catch (RuntimeException $e) {} finally { if (null === $prior) unset($_SERVER['DOCUMENT_ROOT']); else $_SERVER['DOCUMENT_ROOT'] = $prior; }
});
test('JPEG normalization strips EXIF and embedded metadata', function () use ($jw, $jlimits) {
 $path = dirname(__DIR__) . '/tmp/journal-exif.jpg'; $image = imagecreatetruecolor(16, 16); imagejpeg($image, $path); imagedestroy($image);
 $bytes = file_get_contents($path); $metadata = "Exif\0\0PRIVATE_GEOLOCATION_FIXTURE"; $bytes = substr($bytes, 0, 2) . "\xff\xe1" . pack('n', strlen($metadata) + 2) . $metadata . substr($bytes, 2); file_put_contents($path, $bytes);
 $output = PrivateImages::normalize($jw, $path, 'fixture.jpg', $jlimits);
 foreach (['original', 'thumbnail'] as $variant) { equal(str_contains(file_get_contents(PrivateImages::path($jw, $output[$variant]['key'])), 'PRIVATE_GEOLOCATION'), false); PrivateImages::remove($jw, $output[$variant]['key']); }
});
test('Journal REST rejects numeric levels, invalid revisions and foreign images', function () use ($owner, $jw, $jfacts, $jtradeid, $jreserved, $w2) {
 wp_set_current_user($owner);
 foreach ([array_replace($jfacts, ['journal' => ['planned_stop' => 1.5]]), array_replace($jfacts, ['expected_revision' => '3'])] as $input) {
  $request = new WP_REST_Request('POST', '/tgit/v1/workspaces/' . $jw . '/trades/' . $jtradeid); $request->set_header('Content-Type', 'application/json'); $request->set_header('Idempotency-Key', wp_generate_uuid4()); $request->set_body(wp_json_encode($input)); equal(rest_do_request($request)->get_status(), 400);
 }
 equal(rest_do_request(new WP_REST_Request('GET', '/tgit/v1/workspaces/' . $w2 . '/images/' . $jreserved['id'] . '/content'))->get_status(), 404);
});
require __DIR__ . '/media-maintenance.php';
require __DIR__ . '/journal-contracts.php';
require __DIR__ . '/fill-picker-integration.php';
// HTTP runner consumes these sanitized fixture references; no credentials or portfolio data.
file_put_contents(dirname(__DIR__) . '/tmp/journal-http-fixtures.json', wp_json_encode(['owner' => $owner, 'viewer' => $viewer, 'workspace' => $jw, 'foreign_workspace' => $w2, 'trade' => $jtradeid, 'image' => $jreserved['id'], 'image_revision' => 4, 'fixture' => $jfixture]));

// Create short-lived genuine WordPress cookie/nonce fixtures; only the ignored local file receives credentials.
$http = json_decode(file_get_contents(dirname(__DIR__) . '/tmp/journal-http-fixtures.json'), true);
$http['sessions'] = [];
foreach (['owner' => $owner, 'revoked' => $viewer] as $kind => $user) {
 wp_set_current_user($user); $cookie = wp_generate_auth_cookie($user, time() + HOUR_IN_SECONDS, 'logged_in');
 $_COOKIE[LOGGED_IN_COOKIE] = $cookie;
 $http['sessions'][$kind] = ['cookie_name' => LOGGED_IN_COOKIE, 'cookie_value' => $cookie, 'nonce' => wp_create_nonce('wp_rest')];
}
unset($_COOKIE[LOGGED_IN_COOKIE]); wp_set_current_user($owner);
file_put_contents(dirname(__DIR__) . '/tmp/journal-http-fixtures.json', wp_json_encode($http));

