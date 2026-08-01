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

function cf_truthy(mixed $value): bool
{
    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function cf_known_admin_device_token(): bool
{
    $token = trim((string)($_COOKIE['cf_device_token'] ?? ''));
    if ($token === '') {
        return false;
    }

    $tokenFile = dirname(__DIR__, 2) . '/data/device_tokens.json';
    if (!is_file($tokenFile)) {
        return false;
    }

    $raw = @file_get_contents($tokenFile);
    $decoded = $raw ? json_decode($raw, true) : null;
    return is_array($decoded) && isset($decoded[$token]);
}

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
    cf_truthy($payload['is_internal'] ?? false)
    || cf_truthy($_COOKIE['cf_internal_viewer'] ?? false)
    || (isset($_COOKIE['cf_admin']) && $_COOKIE['cf_admin'] === '1')
    || (isset($_COOKIE['cf_admin_global']) && $_COOKIE['cf_admin_global'] === '1')
    || cf_known_admin_device_token()
);

try {
    $service = new UserEventService(new PdoUserEventRepository($pdo));
    $id = $service->recordEvent($payload);
    respond([
        'ok' => true,
        'id' => $id,
        'is_internal' => !empty($payload['is_internal']),
    ]);
} catch (InvalidArgumentException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
