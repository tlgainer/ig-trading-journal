/* Real multipart/cookie/nonce/private-stream tests. Run after the disposable integration fixture. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const crypto = require('node:crypto');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const fixture = JSON.parse(fs.readFileSync('tmp/journal-http-fixtures.json', 'utf8'));
const bytes = fs.readFileSync(fixture.fixture);
const base = 'http://127.0.0.1:19308';
let passed = 0;
const url = (suffix, workspace = fixture.workspace) => { const [route, query] = suffix.split('?'); const target = new URL(base); target.searchParams.set('rest_route', `/tgit/v1/workspaces/${workspace}/${route}`); for (const [key, value] of new URLSearchParams(query)) target.searchParams.set(key, value); return target; };
async function request(suffix, body, options = {}) {
 const session = fixture.sessions[options.session ?? 'owner'];
 const headers = { ...(options.session === 'anonymous' ? {} : { Cookie: `${session.cookie_name}=${session.cookie_value}`, 'X-WP-Nonce': session.nonce }), ...options.headers };
 if (body) { headers['Idempotency-Key'] = options.key || crypto.randomUUID(); if (!(body instanceof FormData)) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(body); } }
 const response = await fetch(url(suffix, options.workspace), { method: body ? 'POST' : 'GET', headers, ...(body ? { body } : {}) });
 if (options.binary) return response;
 const json = await response.json(); const correlation = json.correlation_id || json.data?.correlation_id; if (correlation) assert.equal(response.headers.get('x-correlation-id'), correlation); return { status: response.status, data: json.data, message: json.message };
}
const uploadBody = (name = 'journal-fixture.png', content = bytes) => { const body = new FormData(); body.append('file', new Blob([content], { type: 'image/png' }), name); return body; };
async function reserve(name, content = bytes) { const result = await request(`trades/${fixture.trade}/images`, { filename: name, size: content.length, hash: crypto.createHash('sha256').update(content).digest('hex') }); assert.equal(result.status, 200, result.message); return result.data; }
async function settings(changes) { const current = (await request('media-settings')).data; const input = {}; for (const name of ['max_images', 'max_file_bytes', 'max_pixels', 'quota_bytes', 'trash_days']) input[name] = Number(current.limits[name]); Object.assign(input, changes); input.expected_revision = Number(current.limits.revision); const result = await request('media-settings', input); assert.equal(result.status, 200, result.message); return result.data; }
async function test(name, callback) { await callback(); passed++; console.log(`PASS ${name}`); }
(async () => {
 let ready, failedImage;
 await test('Real multipart upload finalizes once and identical retries preserve the image', async () => {
  const key = crypto.randomUUID(); const result = await request(`images/${fixture.image}/upload`, uploadBody(), { key }); assert.equal(result.status, 200, result.message); ready = result.data; assert.equal(ready.state, 'ready'); assert.equal(ready.storage_key, undefined);
  const retry = await request(`images/${fixture.image}/upload`, uploadBody(), { key }); assert.deepEqual(retry.data, ready);
 });

 await test('Existing normalized images remain readable when GD is unavailable', async () => {
  const output = execFileSync('tmp/php81/php.exe', ['-d', 'extension_dir=tmp/php81/ext', '-d', 'extension=mysqli', 'tests/media-content-worker.php', path.resolve('tmp/wordpress'), String(fixture.owner), String(fixture.workspace), String(ready.id)]).toString(); assert.equal(output, 'available');
 });
 await test('Originals and thumbnails stream authenticated binary data with private headers', async () => {
  for (const variant of ['original', 'thumbnail']) {
   const response = await request(`images/${ready.id}/content?variant=${variant}`, null, { binary: true });
   assert.equal(response.status, 200); assert.equal(response.headers.get('content-type'), 'image/png'); assert.match(response.headers.get('cache-control'), /no-store/); assert.equal(response.headers.get('x-content-type-options'), 'nosniff');
   const content = Buffer.from(await response.arrayBuffer()); assert.equal(content.subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
  }
 });
 await test('Anonymous, revoked and foreign-workspace image requests fail; REST nonce is required', async () => {
  assert.equal((await request(`images/${ready.id}/content`, null, { session: 'anonymous' })).status, 401);
  assert.equal((await request(`images/${ready.id}/content`, null, { session: 'revoked' })).status, 403);
  assert.equal((await request(`images/${ready.id}/content`, null, { workspace: fixture.foreign_workspace })).status, 404);
  assert.equal((await request(`images/${ready.id}/content`, null, { headers: { 'X-WP-Nonce': 'invalid' } })).status, 403);
  assert.equal((await request(`images/${ready.id}/content?variant[]=original`)).status, 400);
  assert.equal((await fetch(base + '/private-images/' + fixture.workspace + '/original.png')).status, 404);
 });
 await test('Deletion denies byte access immediately and restore recovers the image', async () => {
  const deleted = await request(`images/${ready.id}/delete`, { expected_revision: Number(ready.revision) }); assert.equal(deleted.status, 200);
  assert.equal((await request(`images/${ready.id}/content`)).status, 404);
  const restored = await request(`images/${ready.id}/restore`, { expected_revision: Number(deleted.data.revision) }); assert.equal(restored.status, 200); ready = restored.data;
  assert.equal((await request(`images/${ready.id}/content`, null, { binary: true })).status, 200);
 });
 await test('Three independent images persist while a disguised image fails without undoing the trade', async () => {
  for (const name of ['entry.png', 'exit.png']) { const row = await reserve(name); assert.equal((await request(`images/${row.id}/upload`, uploadBody(name))).status, 200); }
  const bad = await reserve('disguised.jpg'); failedImage = bad; assert.equal((await request(`images/${bad.id}/upload`, uploadBody('disguised.jpg'))).status, 400);
  const gallery = (await request(`trades/${fixture.trade}/images`)).data.items; assert.equal(gallery.filter((item) => item.state === 'ready').length, 3); assert.equal(gallery.find((item) => item.id === bad.id).state, 'failed');
  assert.equal((await request(`trades/${fixture.trade}`)).status, 200);
 });
 await test('A failed upload retries alone after a decoding-limit issue is fixed', async () => {
  const row = await reserve('retry.png'); await settings({ max_pixels: 1 });
  assert.equal((await request(`images/${row.id}/upload`, uploadBody('retry.png'))).status, 400);
  await settings({ max_pixels: 40000000 }); const retried = await request(`images/${row.id}/upload`, uploadBody('retry.png')); assert.equal(retried.status, 200); assert.equal(retried.data.state, 'ready');
 });
 await test('Server image-count quota includes failed images and retained trash', async () => {
  const gallery = (await request(`trades/${fixture.trade}/images`)).data.items;
  const trash = await request(`images/${ready.id}/delete`, { expected_revision: Number(ready.revision) }); assert.equal(trash.status, 200); ready = trash.data;
  await settings({ max_images: gallery.length });
  const result = await request(`trades/${fixture.trade}/images`, { filename: 'over-quota.png', size: bytes.length, hash: crypto.createHash('sha256').update(bytes).digest('hex') }); assert.equal(result.status, 400);
  const restored = await request(`images/${ready.id}/restore`, { expected_revision: Number(ready.revision) }); assert.equal(restored.status, 200); ready = restored.data;
  await settings({ max_images: 20 });
 });


 await test('A corrected replacement retries the failed image without consuming a new slot', async () => {
  const before = (await request('trades/' + fixture.trade + '/images')).data.items; const current = before.find((item) => item.id === failedImage.id);
  const retry = await request('images/' + current.id + '/retry', { expected_revision: Number(current.revision), filename: 'corrected.png', size: bytes.length, hash: crypto.createHash('sha256').update(bytes).digest('hex') }); assert.equal(retry.status, 200); assert.equal(retry.data.id, current.id);
  assert.equal((await request('images/' + current.id + '/upload', uploadBody('corrected.png'))).status, 200);
  const after = (await request('trades/' + fixture.trade + '/images')).data.items; assert.equal(after.length, before.length); assert.equal(after.find((item) => item.id === current.id).state, 'ready');
 });
 await test('Database failure after encoding removes private files and retries safely with the same key', async () => {
  const row = await reserve('rollback.png'), key = crypto.randomUUID();
  const folder = path.join('tmp/private-images', String(fixture.workspace)); const before = fs.readdirSync(folder).length;
  const worker = (mode) => execFileSync('tmp/php81/php.exe', ['-d', 'extension_dir=tmp/php81/ext', '-d', 'extension=mysqli', 'tests/media-failure-worker.php', path.resolve('tmp/wordpress'), mode, String(fixture.workspace), String(row.id)]);
  worker('add');
  try { const failed = await request('images/' + row.id + '/upload', uploadBody('rollback.png'), { key }); assert.equal(failed.status, 500); assert.equal(fs.readdirSync(folder).length, before); }
  finally { worker('remove'); }
  const gallery = (await request('trades/' + fixture.trade + '/images')).data.items; assert.equal(gallery.find((item) => item.id === row.id).state, 'failed');
  const retry = await request('images/' + row.id + '/upload', uploadBody('rollback.png'), { key }); assert.equal(retry.status, 200); assert.equal(retry.data.state, 'ready'); assert.equal(fs.readdirSync(folder).length, before + 2);
 });
 await test('Gallery metadata and order are revision checked and private keys never appear in JSON', async () => {
  const result = await request(`images/${ready.id}/metadata`, { expected_revision: Number(ready.revision), caption: 'Entry chart', alt_text: 'Blue fixture chart', stage: 'entry', timeframe: '1h', sort_order: 99 }); assert.equal(result.status, 200); ready = result.data;
  const gallery = (await request(`trades/${fixture.trade}/images`)).data.items; assert.equal(gallery.at(-1).id, ready.id); assert.equal(gallery.some((item) => item.storage_key || item.thumb_key), false);
  assert.equal((await request(`images/${ready.id}/metadata`, { expected_revision: 1, caption: 'stale' })).status, 409);
 });
 console.log(`${passed} real HTTP media checks passed.`);
})().catch((error) => { console.error(error.message); process.exitCode = 1; });
