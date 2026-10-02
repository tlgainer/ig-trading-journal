/* Preserve WordPress rest_route when plain permalinks are enabled. */
function tgitRestUrl(root, path) {
 'use strict';
 const url = new URL(root);
 const separator = path.indexOf('?');
 const route = separator < 0 ? path : path.slice(0, separator);
 const query = separator < 0 ? '' : path.slice(separator + 1);
 if (url.searchParams.has('rest_route')) {
  url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/$/, '') + '/' + route);
 } else {
  url.pathname = url.pathname.replace(/\/$/, '') + '/' + route;
 }
 for (const [key, value] of new URLSearchParams(query)) url.searchParams.set(key, value);
 return url.toString();
}
