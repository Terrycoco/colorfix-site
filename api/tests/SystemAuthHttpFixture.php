<?php
declare(strict_types=1);

// Local HTTP integration fixture. Never enabled by a production PHP request.
if (PHP_SAPI !== 'cli-server' || !getenv('SYSTEM_AUTH_TEST_DB')) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../autoload.php';

use App\SYSTEM\Endpoints\AdminAuthEndpoint;
use App\SYSTEM\Http\AdminGuard;
use App\SYSTEM\Http\AdminHttp;

$path = getenv('SYSTEM_AUTH_TEST_DB');
$pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE IF NOT EXISTS admin_users (
    id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, password_hash TEXT,
    is_active INTEGER NOT NULL DEFAULT 1, session_version INTEGER NOT NULL DEFAULT 1
)');
if (!$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn()) {
    $stmt = $pdo->prepare('INSERT INTO admin_users (email,password_hash) VALUES (?,?)');
    $stmt->execute(['admin@example.test', password_hash('initial-test-password', PASSWORD_DEFAULT)]);
}
// Isolate rate limiting between independent test runs.
$_SERVER['REMOTE_ADDR'] = 'fixture-' . hash('sha256', $path);
$route = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($route === '/public-catalog') {
    $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__, 2) . '/api/v2/admin/supercats.php';
    AdminGuard::requireForEndpoint($pdo);
    AdminHttp::respond(['ok' => true]);
}
if ($route === '/protected') {
    AdminHttp::respond(['ok' => true, 'user' => AdminGuard::requireAdmin($pdo)]);
}
if ($route === '/slow-protected') {
    AdminGuard::requireAdmin($pdo);
    header('Content-Type: text/plain');
    echo "ready\n";
    flush();
    usleep(500000);
    echo "done\n";
    exit;
}
$actions = [
    '/login' => 'login', '/session' => 'session', '/device-auth' => 'device-auth',
    '/device-register' => 'device-register', '/logout' => 'logout',
    '/logout-all' => 'logout-all', '/change-password' => 'change-password',
];
AdminAuthEndpoint::handle($pdo, $actions[$route] ?? 'unknown');
