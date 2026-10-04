<?php
/** Local HTTP test adapter. Never a production entry point or authentication bypass. */
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(403); exit; }
$site = getenv('TGIT_TEST_SITE_PATH');
if (!$site || !is_file($site . '/wp-config.php')) { http_response_code(503); exit; }
define('REST_REQUEST', true);
require $site . '/wp-load.php';
if (!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE !== true || wp_get_environment_type() !== 'development') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/ig-trading-journal.php';
$route = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/assets/(admin\.css|admin\.js|collection\.js|journal\.js|opening\.js|calculators\.js|research\.js|reports\.js|tabs\.js|rest-url\.js)$#D', $route)) {
 header('Content-Type: ' . (str_ends_with($route, '.css') ? 'text/css' : 'application/javascript'));
 readfile(dirname(__DIR__) . $route); exit;
}
if ($route === '/preview') {
 if (!is_user_logged_in()) { http_response_code(401); exit; }
 header('Content-Type: text/html; charset=utf-8');
 echo '<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Disposable journal preview</title><link rel="stylesheet" href="/assets/admin.css"></head><body>';
 \GainerInteractive\IGTradingJournal\Admin\Screen::render();
 echo '<script>window.tgitConfig=' . wp_json_encode(['root' => 'http://127.0.0.1:19308/?rest_route=/tgit/v1/', 'nonce' => wp_create_nonce('wp_rest'), 'actorId' => get_current_user_id(), 'canCreate' => true, 'i18n' => ['loading' => 'Loading', 'empty' => 'No records yet', 'saved' => 'Saved', 'unknown' => 'Missing price', 'edit' => 'Edit draft', 'editResearch' => 'Edit', 'post' => 'Post draft', 'editing' => 'Editing draft', 'network' => 'Request failed']]) . ';</script>';
 echo '<script src="/assets/rest-url.js"></script><script src="/assets/tabs.js"></script><script src="/assets/collection.js"></script><script src="/assets/admin.js"></script><script src="/assets/opening.js"></script><script src="/assets/journal.js"></script><script src="/assets/calculators.js"></script><script src="/assets/research.js"></script><script src="/assets/reports.js"></script></body></html>'; exit;
}
if (isset($_GET['rest_route'])) { rest_get_server()->serve_request(wp_unslash($_GET['rest_route'])); exit; }
http_response_code(404);

