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
$tagsRaw = isset($_GET['tags']) ? trim((string)$_GET['tags']) : '';
$tags = [];
if ($tagsRaw !== '') {
    $tags = array_values(array_filter(array_map('trim', preg_split('/[|,]/', $tagsRaw))));
    $tags = array_map('strtolower', $tags);
}
$onlyActive = isset($_GET['active']) ? (int)$_GET['active'] === 1 : false;

$repo = new PdoPlaylistInstanceRepository($pdo);
$instances = $repo->listAll($onlyActive);

if ($q !== '' || $tags) {
    $instances = array_values(array_filter($instances, static function ($instance) use ($q, $tags) {
        $needle = strtolower($q);
        $id = (string)($instance->id ?? '');
        $name = strtolower($instance->instanceName ?? '');
        $displayTitle = strtolower($instance->displayTitle ?? '');
        $notes = strtolower((string)($instance->instanceNotes ?? ''));
        $haystack = $name . ' ' . $displayTitle . ' ' . $notes;
        if ($needle !== '' && !(str_contains($id, $needle) || str_contains($haystack, $needle))) {
            return false;
        }
        if (!empty($tags)) {
            foreach ($tags as $tag) {
                if (!str_contains($haystack, $tag)) return false;
            }
        }
        return true;
    }));
}

$items = array_map(static function ($instance) {
    return [
        'playlist_instance_id' => $instance->id,
        'playlist_id' => $instance->playlistId,
        'instance_name' => $instance->instanceName,
        'display_title' => $instance->displayTitle,
        'instance_notes' => $instance->instanceNotes,
        'cta_group_id' => $instance->ctaGroupId,
        'cta_context_key' => $instance->ctaContextKey,
        'cta_overrides' => $instance->ctaOverrides,
        'is_active' => $instance->isActive ? 1 : 0,
    ];
}, $instances);

respond([
    'ok' => true,
    'items' => $items,
]);
