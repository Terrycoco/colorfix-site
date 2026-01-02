<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoPlaylistInstanceRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$onlyActive = isset($_GET['active']) ? (int)$_GET['active'] === 1 : false;

$repo = new PdoPlaylistInstanceRepository($pdo);
$instances = $repo->listAll($onlyActive);

if ($q !== '') {
    $instances = array_values(array_filter($instances, static function ($instance) use ($q) {
        $needle = strtolower($q);
        $id = (string)($instance->id ?? '');
        $name = strtolower($instance->instanceName ?? '');
        return str_contains($id, $needle) || str_contains($name, $needle);
    }));
}

$items = array_map(static function ($instance) {
    return [
        'playlist_instance_id' => $instance->id,
        'playlist_id' => $instance->playlistId,
        'instance_name' => $instance->instanceName,
        'instance_notes' => $instance->instanceNotes,
        'cta_group_id' => $instance->ctaGroupId,
        'cta_context_key' => $instance->ctaContextKey,
        'is_active' => $instance->isActive ? 1 : 0,
    ];
}, $instances);

respond([
    'ok' => true,
    'items' => $items,
]);
