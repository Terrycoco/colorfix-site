<?php
declare(strict_types=1);

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/db.php';

use App\Repos\PdoPublisherRepository;
use App\Services\PinterestOAuthService;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$service = new PinterestOAuthService(new PdoPublisherRepository($pdo));

function pinterest_redirect(string $status, string $message = ''): void
{
    $params = ['pinterest_auth' => $status];
    if ($message !== '') {
        $params['message'] = $message;
    }
    header('Location: /admin/packager?' . http_build_query($params), true, 302);
    exit;
}

try {
    $oauthError = trim((string)($_GET['error'] ?? ''));
    if ($oauthError !== '') {
        $description = trim((string)($_GET['error_description'] ?? $oauthError));
        $service->markAuthError($description);
        pinterest_redirect('denied', $description);
    }

    $code = trim((string)($_GET['code'] ?? ''));
    $state = trim((string)($_GET['state'] ?? ''));
    $expectedState = trim((string)($_SESSION['pinterest_oauth_state'] ?? ''));
    $stateAge = time() - (int)($_SESSION['pinterest_oauth_state_created_at'] ?? 0);

    unset($_SESSION['pinterest_oauth_state'], $_SESSION['pinterest_oauth_state_created_at']);

    if ($code === '') {
        throw new RuntimeException('Pinterest OAuth callback is missing code.');
    }
    if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state) || $stateAge > 900) {
        throw new RuntimeException('Invalid Pinterest OAuth state.');
    }

    $service->handleCallback($code);
    pinterest_redirect('connected', 'Pinterest connected.');
} catch (Throwable $e) {
    try {
        $service->markAuthError($e->getMessage());
    } catch (Throwable) {
    }
    pinterest_redirect('error', $e->getMessage());
}
