<?php
declare(strict_types=1);

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/v2/admin/auth.php';

use App\Repos\PdoPublisherRepository;
use App\Services\PinterestOAuthService;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo 'GET only';
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

try {
    $state = bin2hex(random_bytes(32));
    $_SESSION['pinterest_oauth_state'] = $state;
    $_SESSION['pinterest_oauth_state_created_at'] = time();

    $service = new PinterestOAuthService(new PdoPublisherRepository($pdo));
    header('Location: ' . $service->authorizationUrl($state), true, 302);
    exit;
} catch (Throwable $e) {
    header('Location: /admin/publisher?pinterest_auth=error&message=' . rawurlencode($e->getMessage()), true, 302);
    exit;
}
