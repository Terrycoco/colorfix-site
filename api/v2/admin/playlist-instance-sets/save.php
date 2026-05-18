<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Entities\PlaylistInstanceSet;
use App\Repos\PdoPlaylistInstanceSetRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$id = isset($payload['id']) ? (int)$payload['id'] : null;
$handle = trim((string)($payload['handle'] ?? ''));
$title = trim((string)($payload['title'] ?? ''));
$subtitle = trim((string)($payload['subtitle'] ?? ''));
$context = trim((string)($payload['context'] ?? ''));
$endCtaLabel = trim((string)($payload['end_cta_label'] ?? 'Explore ColorFix'));
$endCtaUrl = trim((string)($payload['end_cta_url'] ?? '/'));
$endCtaEnabled = array_key_exists('end_cta_enabled', $payload) ? (bool)$payload['end_cta_enabled'] : true;

if ($handle === '') {
    respond(['ok' => false, 'error' => 'handle required'], 400);
}
if ($title === '') {
    respond(['ok' => false, 'error' => 'title required'], 400);
}

try {
    $set = new PlaylistInstanceSet(
        $id,
        $handle,
        $title,
        $subtitle !== '' ? $subtitle : null,
        $context !== '' ? $context : null,
        $endCtaLabel !== '' ? $endCtaLabel : 'Explore ColorFix',
        $endCtaUrl !== '' ? $endCtaUrl : '/',
        $endCtaEnabled
    );
    $repo = new PdoPlaylistInstanceSetRepository($pdo);
    $set = $repo->save($set);

    respond([
        'ok' => true,
        'id' => $set->id,
    ]);
} catch (\Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
