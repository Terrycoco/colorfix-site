<?php
declare(strict_types=1);

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/v2/admin/auth.php';

use App\PUB\Dispatch\Auth\YouTubeAuthService;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo 'GET only';
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function youtube_safe_return_to(string $value): string
{
    $path = trim($value);
    if ($path === '') {
        $path = parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH) ?: '';
    }
    $allowed = ['/admin/packager', '/admin/publisher', '/admin/scheduler'];
    return in_array($path, $allowed, true) ? $path : '/admin/packager';
}

try {
    $state = bin2hex(random_bytes(32));
    $_SESSION['youtube_oauth_state'] = $state;
    $_SESSION['youtube_oauth_state_created_at'] = time();
    $_SESSION['youtube_oauth_return_to'] = youtube_safe_return_to((string)($_GET['return'] ?? ''));

    $service = new YouTubeAuthService($pdo);
    header('Location: ' . $service->authorizationUrl($state), true, 302);
    exit;
} catch (Throwable $e) {
    error_log('YouTube OAuth connect error: ' . $e->getMessage());
    $returnTo = youtube_safe_return_to((string)($_GET['return'] ?? ''));
    header('Location: ' . $returnTo . '?youtube_auth=error&message=' . rawurlencode($e->getMessage()), true, 302);
    exit;
}
