<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server' || !getenv('SAS_AUTH_TEST_DB')) { http_response_code(404); exit; }
require_once __DIR__ . '/../autoload.php';
use App\SYSTEM\Endpoints\AdminAuthEndpoint;
use App\SYSTEM\Http\AdminGuard;
use App\SYSTEM\Http\AdminHttp;

$path = getenv('SAS_AUTH_TEST_DB');
$pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE IF NOT EXISTS admin_users (id INTEGER PRIMARY KEY, email TEXT UNIQUE, password_hash TEXT, is_active INTEGER, session_version INTEGER)');
if (!$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn()) {
    $stmt = $pdo->prepare('INSERT INTO admin_users VALUES (1,?,?,1,1)');
    $stmt->execute(['admin@example.test', password_hash('fixture-admin-password', PASSWORD_DEFAULT)]);
}
$_SERVER['REMOTE_ADDR'] = 'sas-fixture-' . hash('sha256', $path);
$route = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($route === '/api/v2/admin/sas/search-photos.php') {
    AdminGuard::requireAdmin($pdo);
    \App\SYSTEM\Services\AdminAuthService::fromPdo($pdo)->rememberDevice();
    $items = trim((string)($_GET['q'] ?? '')) === 'Mardo' ? [
        ['photo_id' => 701, 'rel_path' => '/photos/fixture-701.jpg'],
        ['photo_id' => 702, 'rel_path' => '/photos/fixture-702.jpg'],
    ] : [];
    AdminHttp::respond(['ok' => true, 'items' => $items, 'total' => count($items), 'page' => 1, 'per_page' => 30]);
}
if ($route === '/api/login.php') AdminAuthEndpoint::handle($pdo, 'login');
if ($route === '/api/session.php') AdminAuthEndpoint::handle($pdo, 'session');
if ($route === '/api/admin-change-password.php') AdminAuthEndpoint::handle($pdo, 'change-password');
if ($route === '/api/logout.php') AdminAuthEndpoint::handle($pdo, 'logout');
http_response_code(404);
