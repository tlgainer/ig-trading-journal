const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const context = vm.createContext({ URL, URLSearchParams });
vm.runInContext(fs.readFileSync('assets/rest-url.js', 'utf8'), context);
for (const root of ['https://example.test/wp-json/tgit/v1/', 'https://example.test/?rest_route=/tgit/v1/', 'https://example.test/subdirectory/index.php?rest_route=%2Ftgit%2Fv1%2F']) {
 for (const path of ['workspaces', 'workspaces/1/transactions?after=42&limit=100']) {
  const actual = new URL(context.tgitRestUrl(root, path));
  const route = path.split('?')[0];
  const original = new URL(root);
  if (original.searchParams.has('rest_route')) {
   assert.equal(actual.searchParams.get('rest_route'), '/tgit/v1/' + route);
   assert.equal(actual.pathname, original.pathname);
  } else assert.equal(actual.pathname, '/wp-json/tgit/v1/' + route);
  assert.equal(actual.origin, original.origin);
  assert.equal(actual.searchParams.get('after'), path.includes('?') ? '42' : null);
  assert.equal(actual.searchParams.get('limit'), path.includes('?') ? '100' : null);
 }
}
console.log('6 REST URL tests passed (pretty/plain/subdirectory routes, pagination).');
