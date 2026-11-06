<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server' || !getenv('ANA_RECORDING_TEST_DB')) { http_response_code(404); exit; }
require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/fixtures/ANAFixtureDatabase.php';
$pdo = anaFixtureDatabase(getenv('ANA_RECORDING_TEST_DB'));
$_SERVER['REMOTE_ADDR'] = 'ana-fixture-' . hash('sha256', getenv('ANA_RECORDING_TEST_DB'));
$route = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($route === '/__rows') {
    header('Content-Type: application/json');
    echo json_encode($pdo->query('SELECT * FROM analytics_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)); exit;
}
if ($route === '/__login') {
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_users (id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, is_active INTEGER, session_version INTEGER)');
    if (!$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn()) {
        $stmt=$pdo->prepare('INSERT INTO admin_users VALUES (1,?,?,1,1)');
        $stmt->execute(['admin@example.test',password_hash('fixture-admin-password', PASSWORD_DEFAULT)]);
    }
    \App\SYSTEM\Endpoints\AdminAuthEndpoint::handle($pdo, 'login');
}
if ($route === '/api/v2/admin/ana/ignore-config.php') {
    \App\SYSTEM\Http\AdminGuard::requireAdmin($pdo);
    \App\ANA\Endpoints\IgnoreConfigEndpoint::handle($pdo);
}
if (in_array($route, ['/api/v2/admin/ana/record-event.php','/api/v2/admin/analytics/record-event.php'],true)) {
    \App\ANA\Endpoints\RecordEventEndpoint::handle($pdo);
}
http_response_code(404);
