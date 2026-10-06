<?php
/** Disposable watchlist and authored-research fixtures. */
if (!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE !== true) throw new RuntimeException('Disposable site required.');

test('Schema 7 watchlist/research tables repair from version 6', function () use ($db) {
 update_option('tgit_schema_version', '6');
 \GainerInteractive\IGTradingJournal\Infrastructure\Installer::install();
 equal(get_option('tgit_schema_version'), '10');
 equal($db->row('SHOW COLUMNS FROM ' . $db->table('watchlist_items') . ' LIKE %s', ['target_buy'])['Field'], 'target_buy');
 equal($db->row('SHOW COLUMNS FROM ' . $db->table('research_note_revisions') . ' LIKE %s', ['content'])['Field'], 'content');
});

test('Manual watchlist targets, tags and statuses retain revision history', function () use ($tracker) {
 $workspace = (int) $tracker->create_workspace(['name' => 'Watchlist fixture'])['id'];
 $asset = $tracker->create_object($workspace, 'assets', ['symbol' => 'WLT', 'exchange' => 'NYSE', 'asset_class' => 'stock', 'quote_currency' => 'USD']);
 $list = $tracker->create_watchlist($workspace, ['name' => 'Long ideas'], 'watchlist-create');
 equal($tracker->create_watchlist($workspace, ['name' => 'Long ideas'], 'watchlist-create'), $list);
 $input = ['asset_id' => (int) $asset['id'], 'target_buy' => '90', 'target_sell' => '120', 'thesis' => 'Manual valuation', 'tags' => ['quality', 'income'], 'status' => 'watch'];
 $item = $tracker->add_watchlist_item($workspace, (int) $list['id'], $input, 'watchlist-add');
 equal($tracker->add_watchlist_item($workspace, (int) $list['id'], $input, 'watchlist-add'), $item);
 $rows = $tracker->watchlist_items($workspace, (int) $list['id']); equal(count($rows), 1); decimal($rows[0]['target_buy'], '90'); equal($rows[0]['tags'], ['quality', 'income']);
 try { $tracker->add_watchlist_item($workspace, (int) $list['id'], array_replace($input, ['target_buy' => '80']), 'watchlist-duplicate'); throw new RuntimeException('Expected duplicate.'); } catch (UnexpectedValueException $error) {}
 $replacement = array_replace($input, ['target_buy' => '85', 'status' => 'buy', 'thesis' => '<script>alert(1)</script>Manual']);
 $revised = $tracker->revise_watchlist_item($workspace, (int) $list['id'], (int) $item['id'], ['expected_revision' => 1, 'replacement' => $replacement], 'watchlist-revise');
 equal((int) $revised['revision'], 2); equal(str_contains($revised['thesis'], '<'), false);
 equal(count($tracker->watchlist_item_revisions($workspace, (int) $list['id'], (int) $item['id'])), 2);
 try { $tracker->revise_watchlist_item($workspace, (int) $list['id'], (int) $item['id'], ['expected_revision' => 1, 'replacement' => $input], 'watchlist-stale'); throw new RuntimeException('Expected stale revision.'); } catch (UnexpectedValueException $error) {}
});

test('Authored research stays scoped, sanitized and revisioned', function () use ($tracker, $owner, $viewer) {
 $workspace = (int) $tracker->create_workspace(['name' => 'Research fixture'])['id'];
 $asset = $tracker->create_object($workspace, 'assets', ['symbol' => 'RSR', 'exchange' => 'NASDAQ', 'asset_class' => 'stock', 'quote_currency' => 'USD']);
 $note = $tracker->create_research_note($workspace, ['asset_id' => (int) $asset['id'], 'content' => 'First thesis'], 'research-create');
 $updated = $tracker->revise_research_note($workspace, (int) $note['id'], ['expected_revision' => 1, 'content' => '<script>bad</script>Updated thesis', 'reason' => 'New filing'], 'research-update');
 equal((int) $updated['revision'], 2); equal(str_contains($updated['content'], '<'), false);
 equal(count($tracker->research_note_revisions($workspace, (int) $note['id'])), 2);
 equal(count($tracker->research_notes($workspace, (int) $asset['id'])), 1);
 $other = (int) $tracker->create_workspace(['name' => 'Other research fixture'])['id'];
 try { $tracker->create_research_note($other, ['asset_id' => (int) $asset['id'], 'content' => 'Foreign'], 'research-foreign'); throw new RuntimeException('Expected foreign asset rejection.'); } catch (OutOfBoundsException $error) {}
 $request = new WP_REST_Request('GET', '/tgit/v1/workspaces/' . $workspace . '/research-notes');
 wp_set_current_user($viewer); equal(rest_do_request($request)->get_status(), 403);
 wp_set_current_user($owner); $response = rest_do_request($request); equal($response->get_status(), 200); equal(count($response->get_data()['data']['items']), 1);
});
