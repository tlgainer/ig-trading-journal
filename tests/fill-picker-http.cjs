/* Read-only picker endpoint through real cookie/nonce HTTP authentication. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const fixture = JSON.parse(fs.readFileSync('tmp/journal-http-fixtures.json', 'utf8'));
(async () => {
 const get = async (workspace, trade, query = '', session = 'owner') => {
  const identity = fixture.sessions[session], url = new URL('http://127.0.0.1:19308'); url.searchParams.set('rest_route', `/tgit/v1/workspaces/${workspace}/trades/${trade}/fill-candidates`);
  for (const [key, value] of new URLSearchParams(query)) url.searchParams.set(key, value);
  const response = await fetch(url, { headers: identity ? { Cookie: `${identity.cookie_name}=${identity.cookie_value}`, 'X-WP-Nonce': identity.nonce } : {} }); return { status: response.status, cache: response.headers.get('cache-control'), body: await response.json() };
 };
 const result = await get(fixture.workspace, fixture.trade, 'limit=1'); assert.equal(result.status, 200); assert(result.cache.includes('no-store')); assert.equal(result.body.data.items.length, 1);
 const row = result.body.data.items[0]; assert(['draft', 'posted'].includes(row.state)); assert(['buy', 'sell'].includes(row.action)); assert.equal(typeof row.quantity, 'string'); assert.equal(row.account_name, 'Journal cash'); assert.equal(result.body.data.next_cursor, String(row.id));
 assert.equal((await get(fixture.workspace, fixture.trade, 'limit=101')).status, 400);
 assert.equal((await get(fixture.foreign_workspace, fixture.trade)).status, 404);
 assert.equal((await get(fixture.workspace, fixture.trade, '', 'revoked')).status, 403);
 assert.equal((await get(fixture.workspace, fixture.trade, '', 'anonymous')).status, 401);
 console.log('PASS Real HTTP fill picker: bounded cursor, exact decimals, workspace scope, no-store and revoked/anonymous denial.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
