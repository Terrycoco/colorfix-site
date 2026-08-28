<?php
declare(strict_types=1);

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/db.php';

use App\PUB\Dispatch\Auth\YouTubeAuthService;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$service = new YouTubeAuthService($pdo);

function youtube_redirect(string $status, string $message = ''): void
{
    $params = ['youtube_auth' => $status];
    if ($message !== '') {
        $params['message'] = $message;
    }
    $returnTo = (string)($_SESSION['youtube_oauth_return_to'] ?? '/admin/packager');
    unset($_SESSION['youtube_oauth_return_to']);
    $allowed = ['/admin/packager', '/admin/publisher', '/admin/scheduler'];
    if (!in_array($returnTo, $allowed, true)) {
        $returnTo = '/admin/packager';
    }
    header('Location: ' . $returnTo . '?' . http_build_query($params), true, 302);
    exit;
}

try {
    $oauthError = trim((string)($_GET['error'] ?? ''));
    if ($oauthError !== '') {
        $description = trim((string)($_GET['error_description'] ?? $oauthError));
        $message = $description !== '' ? $description : $oauthError;
        $service->markAuthError($message);
        youtube_redirect('denied', $message);
    }

    $code = trim((string)($_GET['code'] ?? ''));
    $state = trim((string)($_GET['state'] ?? ''));
    $expectedState = trim((string)($_SESSION['youtube_oauth_state'] ?? ''));
    $stateAge = time() - (int)($_SESSION['youtube_oauth_state_created_at'] ?? 0);

    unset($_SESSION['youtube_oauth_state'], $_SESSION['youtube_oauth_state_created_at']);

    if ($code === '') {
        throw new RuntimeException('YouTube OAuth callback is missing code.');
    }
    if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state) || $stateAge > 900) {
        throw new RuntimeException('Invalid YouTube OAuth state.');
    }

    $service->handleCallback($code);
    youtube_redirect('connected', 'YouTube connected.');
} catch (Throwable $e) {
    try {
        $service->markAuthError($e->getMessage());
    } catch (Throwable) {
    }
    youtube_redirect('error', $e->getMessage());
}
