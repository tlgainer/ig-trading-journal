<?php
/** Scoped transaction picker contracts on deterministic disposable fixtures. */
test('Fill candidates paginate eligible asset transactions and revalidate corrected sources without ledger changes', function () use ($tracker, $jt, $db, $owner, $viewer, $ja, $w2) {
 $workspace = (int) $tracker->create_workspace(['name' => 'Fill picker fixtures'])['id'];
 $asset = $tracker->create_object($workspace, 'assets', ['symbol' => 'PICK', 'exchange' => 'FIXTURE', 'asset_class' => 'stock', 'quote_currency' => 'USD']);
 $other = $tracker->create_object($workspace, 'assets', ['symbol' => 'OTHER', 'exchange' => 'FIXTURE', 'asset_class' => 'stock', 'quote_currency' => 'USD']);
 $account = $tracker->create_object($workspace, 'accounts', ['name' => 'Picker cash', 'native_currency' => 'USD']);
 $base = ['account_id' => (int) $account['id'], 'asset_id' => (int) $asset['id'], 'action' => 'buy', 'effective_date' => '2026-01-02', 'state' => 'draft', 'quantity' => '1.123456789012345678', 'unit_price' => '10', 'fees' => '0', 'currency' => 'USD'];
 $linked = $tracker->post($workspace, $base, 'picker-linked')['transaction'];
 $free = $tracker->post($workspace, $base, 'picker-free')['transaction'];
 $assigned = $tracker->post($workspace, $base, 'picker-other-trade')['transaction'];
 $tracker->post($workspace, array_replace($base, ['asset_id' => (int) $other['id']]), 'picker-other-asset');
 $facts = ['asset_id' => (int) $asset['id'], 'title' => 'Picker trade', 'state' => 'planned', 'transaction_ids' => [(int) $linked['id']], 'journal' => []];
 $trade = $jt->save_trade($workspace, 0, $facts, 'picker-trade'); $id = (int) $trade['trade']['id'];
 $jt->save_trade($workspace, 0, array_replace($facts, ['title' => 'Assigned elsewhere', 'transaction_ids' => [(int) $assigned['id']]]), 'picker-assigned');
 $tracker->post($workspace, ['account_id' => (int) $account['id'], 'action' => 'deposit', 'effective_date' => '2026-01-01', 'state' => 'posted', 'amount' => '100', 'currency' => 'USD'], 'picker-deposit');
 $source = $tracker->post($workspace, array_replace($base, ['state' => 'posted', 'quantity' => '1']), 'picker-posted')['transaction'];
 $before = $jt->fill_candidates($workspace, $id, 0, 0, 100);
 equal(array_map('intval', array_column($before['items'], 'id')), [(int) $linked['id'], (int) $free['id'], (int) $source['id']]);
 equal($before['items'][0]['quantity'], '1.123456789012345678'); equal($before['items'][0]['account_name'], 'Picker cash');
 $corrected = $tracker->correct_cash($workspace, (int) $source['id'], ['expected_revision' => 1, 'reason' => 'Verified fixture price', 'replacement' => array_replace($base, ['state' => 'posted', 'quantity' => '1', 'unit_price' => '11'])], 'picker-correction');
 $cash = $tracker->list_objects($workspace, 'accounts'); $holdings = $tracker->holdings($workspace);
 $page = $jt->fill_candidates($workspace, $id, 0, 0, 1); equal((int) $page['items'][0]['id'], (int) $linked['id']); equal($page['next_cursor'], (string) $linked['id']);
 $page = $jt->fill_candidates($workspace, $id, 0, (int) $page['next_cursor'], 100); equal(array_map('intval', array_column($page['items'], 'id')), [(int) $free['id'], (int) $corrected['replacement']['id']]);
 try { $jt->fill_candidates($workspace, $id, (int) $ja['id'], 0, 100); throw new LogicException('Foreign asset accepted.'); } catch (OutOfBoundsException $e) {}
 try { $jt->fill_candidates($w2, $id, 0, 0, 100); throw new LogicException('Foreign trade accepted.'); } catch (OutOfBoundsException $e) {}
 rejects(fn() => $jt->fill_candidates($workspace, $id, 0, 0, 101));
 try { $jt->save_trade($workspace, $id, array_replace($facts, ['transaction_ids' => [(int) $source['id']], 'expected_revision' => 1]), 'picker-stale-source'); throw new LogicException('Corrected source linked.'); } catch (UnexpectedValueException $e) {}
 equal((int) $jt->trade($workspace, $id)['trade']['revision'], 1);
 $saved = $jt->save_trade($workspace, $id, array_replace($facts, ['transaction_ids' => [(int) $linked['id'], (int) $free['id']], 'expected_revision' => 1]), 'picker-links-save'); equal(count($saved['fills']), 2);
 equal($tracker->list_objects($workspace, 'accounts'), $cash);
 $afterHoldings = $tracker->holdings($workspace);
 // The response timestamp advances independently of the financial facts.
 unset($afterHoldings['as_of'], $holdings['as_of']); equal($afterHoldings, $holdings);
 $tracker->set_member($workspace, ['wp_user_id' => $viewer, 'role' => 'viewer', 'state' => 'active']);
 $reader = new \GainerInteractive\IGTradingJournal\Application\Journal($db, $viewer, wp_generate_uuid4()); equal(count($reader->fill_candidates($workspace, $id, 0, 0, 100)['items']), 3);
 wp_set_current_user($owner); $route = '/tgit/v1/workspaces/' . $workspace . '/trades/' . $id . '/fill-candidates';
 equal(rest_do_request(new WP_REST_Request('GET', $route))->get_status(), 200);
 $invalid = new WP_REST_Request('GET', $route); $invalid->set_param('limit', 101); equal(rest_do_request($invalid)->get_status(), 400);
 $tracker->set_member($workspace, ['wp_user_id' => $viewer, 'role' => 'viewer', 'state' => 'revoked']);
 try { $reader->fill_candidates($workspace, $id, 0, 0, 100); throw new LogicException('Revoked reader accepted.'); } catch (DomainException $e) {}
 wp_set_current_user($viewer); equal(rest_do_request(new WP_REST_Request('GET', $route))->get_status(), 403); wp_set_current_user($owner);
});
