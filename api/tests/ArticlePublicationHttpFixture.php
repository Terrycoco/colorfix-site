<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server' || !getenv('ARTICLE_TEST_DIR')) { http_response_code(404); exit; }
require_once __DIR__ . '/../autoload.php';
$dir = getenv('ARTICLE_TEST_DIR');
$pdo = new PDO('sqlite:' . $dir . '/test.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE IF NOT EXISTS articles (id INTEGER PRIMARY KEY, status TEXT, title TEXT, slug TEXT, dek TEXT, meta_description TEXT, published_at TEXT, updated_at TEXT, cta_overrides TEXT, created_at TEXT, type TEXT, kicker TEXT, hero_asset_id INTEGER);
CREATE TABLE IF NOT EXISTS article_sections (id INTEGER, article_id INTEGER, sort_order INTEGER);
CREATE TABLE IF NOT EXISTS article_tags (id INTEGER, slug TEXT, name TEXT);
CREATE TABLE IF NOT EXISTS article_tag_map (article_id INTEGER, tag_id INTEGER);
CREATE TABLE IF NOT EXISTS playlists (playlist_id INTEGER, article_id INTEGER, editorial_type TEXT);
CREATE TABLE IF NOT EXISTS admin_users (id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, is_active INTEGER, session_version INTEGER);
INSERT OR IGNORE INTO articles VALUES (1,'draft','Private fixture','fixture','Fixture description','Fixture SEO',NULL,NULL,'{\"_cta_ids\":[]}',NULL,'guide',NULL,NULL);");
if (!$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn()) {
    $pdo->prepare('INSERT INTO admin_users VALUES (1,?,?,1,1)')->execute(['fixture@example.test', password_hash('fixture-password', PASSWORD_DEFAULT)]);
}
$route = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($route === '/__login') { \App\SYSTEM\Endpoints\AdminAuthEndpoint::handle($pdo, 'login'); exit; }
if ($route === '/api/v2/articles/list.php') { \App\ARTICLES\Endpoints\ArticleListEndpoint::handle($pdo); exit; }
if ($route === '/api/v2/admin/articles/list.php') {
    \App\SYSTEM\Http\AdminGuard::requireAdmin($pdo);
    \App\ARTICLES\Endpoints\AdminArticleListEndpoint::handle($pdo); exit;
}
if ($route === '/publication') {
    \App\SYSTEM\Http\AdminGuard::requireAdmin($pdo);
    \App\ARTICLES\Endpoints\ArticlePublicationEndpoint::handle($pdo); exit;
}
if (preg_match('~^/articles/([0-9]+)$~', $route, $match)) {
    $_GET['id'] = $match[1];
    \App\ARTICLES\Endpoints\ArticlePageEndpoint::handle($pdo, $dir); exit;
}
http_response_code(404);
