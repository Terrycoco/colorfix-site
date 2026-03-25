<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Repos\PdoUserEventRepository;
use App\Services\UserEventService;

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

$raw = file_get_contents('php://input') ?: '';
$payload = [];

if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

if (!$payload) {
    $payload = $_POST;
}

if (!is_array($payload)) {
    respond(['ok' => false, 'error' => 'Invalid payload'], 400);
}

$payload['referrer'] = $payload['referrer'] ?? ($_SERVER['HTTP_REFERER'] ?? null);
$payload['user_agent'] = $payload['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);
$payload['is_internal'] = (
    !empty($payload['is_internal'])
    || (isset($_COOKIE['cf_admin']) && $_COOKIE['cf_admin'] === '1')
    || (isset($_COOKIE['cf_admin_global']) && $_COOKIE['cf_admin_global'] === '1')
);

try {
    $service = new UserEventService(new PdoUserEventRepository($pdo));
    $id = $service->recordEvent($payload);
    respond(['ok' => true, 'id' => $id]);
} catch (InvalidArgumentException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
