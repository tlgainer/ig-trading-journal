<?php
/** Local HTTP test adapter. Never a production entry point or authentication bypass. */
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) { http_response_code(403); exit; }
$site = getenv('TGIT_TEST_SITE_PATH');
if (!$site || !is_file($site . '/wp-config.php')) { http_response_code(503); exit; }
define('REST_REQUEST', true);
require $site . '/wp-load.php';
if (!defined('TGIT_DISPOSABLE_TEST_SITE') || TGIT_DISPOSABLE_TEST_SITE !== true || wp_get_environment_type() !== 'development') { http_response_code(403); exit; }
require_once dirname(__DIR__) . '/ig-trading-journal.php';
if (isset($_GET['tgit_ai_fixture']) && $_GET['tgit_ai_fixture'] === '1') {
 $ai_fixture = json_decode(file_get_contents(dirname(__DIR__) . '/tmp/ai-settings-browser-fixtures.json'), true);
 if (!is_array($ai_fixture) || !preg_match('/^fixture_ais_[a-f0-9]{10}_$/D', $ai_fixture['prefix'] ?? '')) { http_response_code(503); exit; }
 $ai_connection = clone $wpdb; $ai_connection->result = null; $ai_connection->prefix = $ai_fixture['prefix']; $wpdb = $ai_connection;
}
$route = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (isset($_GET['tgit_publication_fixture']) && $_GET['tgit_publication_fixture'] === '1') {
 $publication_fixture = json_decode(file_get_contents(dirname(__DIR__) . '/tmp/ai-publication-browser-fixtures.json'), true);
 if (!is_array($publication_fixture) || !preg_match('/^fixture_rev_[a-f0-9]{10}_$/D', $publication_fixture['prefix'] ?? '')) { http_response_code(503); exit; }
 $publication_connection = clone $wpdb; $publication_connection->result = null; $publication_connection->prefix = $publication_fixture['prefix']; $wpdb = $publication_connection;
}
if (isset($_GET['tgit_review_fixture']) && $_GET['tgit_review_fixture'] === '1') {
 $review_fixture = json_decode(file_get_contents(dirname(__DIR__) . '/tmp/ai-review-browser-fixtures.json'), true);
 if (!is_array($review_fixture) || !preg_match('/^fixture_rev_[a-f0-9]{10}_$/D', $review_fixture['prefix'] ?? '')) { http_response_code(503); exit; }
 $review_connection = clone $wpdb; $review_connection->result = null; $review_connection->prefix = $review_fixture['prefix']; $wpdb = $review_connection;
}
if (preg_match('#^/assets/(admin\.css|admin\.js|collection\.js|journal\.js|opening\.js|calculators\.js|research\.js|reports\.js|market-data\.js|fundamentals\.js|ai-settings\.js|ai-evidence\.js|ai-reviews\.js|tabs\.js|rest-url\.js)$#D', $route)) {
 header('Content-Type: ' . (str_ends_with($route, '.css') ? 'text/css' : 'application/javascript'));
 readfile(dirname(__DIR__) . $route); exit;
}
if ($route === '/preview') {
 if (!is_user_logged_in()) { http_response_code(401); exit; }
 header('Content-Type: text/html; charset=utf-8');
 echo '<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Disposable journal preview</title><link rel="stylesheet" href="/assets/admin.css"></head><body>';
 \GainerInteractive\IGTradingJournal\Admin\Screen::render();
 echo '<script>window.tgitConfig=' . wp_json_encode(['root' => 'http://127.0.0.1:19308/?' . (isset($publication_fixture) ? 'tgit_publication_fixture=1&' : (isset($review_fixture) ? 'tgit_review_fixture=1&' : (isset($ai_fixture) ? 'tgit_ai_fixture=1&' : ''))) . 'rest_route=/tgit/v1/', 'nonce' => wp_create_nonce('wp_rest'), 'actorId' => get_current_user_id(), 'canCreate' => true, 'i18n' => ['loading' => 'Loading', 'empty' => 'No records yet', 'saved' => 'Saved', 'unknown' => 'Missing price', 'edit' => 'Edit draft', 'editResearch' => 'Edit', 'post' => 'Post draft', 'editing' => 'Editing draft', 'network' => 'Request failed']]) . ';</script>';
 echo '<script src="/assets/rest-url.js"></script><script src="/assets/tabs.js"></script><script src="/assets/collection.js"></script><script src="/assets/admin.js"></script><script src="/assets/opening.js"></script><script src="/assets/journal.js"></script><script src="/assets/calculators.js"></script><script src="/assets/research.js"></script><script src="/assets/reports.js"></script><script src="/assets/market-data.js"></script><script src="/assets/fundamentals.js"></script><script src="/assets/ai-settings.js"></script><script src="/assets/ai-evidence.js"></script><script src="/assets/ai-reviews.js"></script></body></html>'; exit;
}
if (isset($_GET['rest_route'])) { rest_get_server()->serve_request(wp_unslash($_GET['rest_route'])); exit; }
http_response_code(404);

