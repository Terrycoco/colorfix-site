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

function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = :table_name
            AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$playlistId = isset($_GET['playlist_id']) ? (int)$_GET['playlist_id'] : 0;
$tagsRaw = isset($_GET['tags']) ? trim((string)$_GET['tags']) : '';
$tags = [];
if ($tagsRaw !== '') {
    $tags = array_values(array_filter(array_map('trim', preg_split('/[|,]/', $tagsRaw))));
    $tags = array_map('strtolower', $tags);
}
$onlyActive = isset($_GET['active']) ? (int)$_GET['active'] === 1 : false;
$includeRetired = isset($_GET['include_retired']) ? (int)$_GET['include_retired'] === 1 : false;

if ($playlistId > 0) {
    $hasRetired = columnExists($pdo, 'playlist_instances', 'is_retired');
    $sql = <<<SQL
        SELECT
          playlist_instance_id,
          playlist_id,
          slug,
          instance_name,
          display_title,
          display_subtitle,
          instance_notes,
          cta_group_id,
          palette_viewer_cta_group_id,
          demo_enabled,
          cta_context_key,
          audience,
          cta_overrides,
          kicker_id,
          is_active
        FROM playlist_instances
        WHERE playlist_id = :playlist_id
        SQL;
    if ($onlyActive) {
        $sql .= "\n  AND is_active = 1";
    }
    if (!$includeRetired && $hasRetired) {
        $sql .= "\n  AND COALESCE(is_retired, 0) = 0";
    }
    $sql .= "\nORDER BY playlist_instance_id DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['playlist_id' => $playlistId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = array_map(static function (array $item): array {
        $slug = trim((string)($item['slug'] ?? ''));
        $item['playlist_instance_id'] = (int)($item['playlist_instance_id'] ?? 0);
        $item['playlist_id'] = (int)($item['playlist_id'] ?? 0);
        $item['demo_enabled'] = (int)($item['demo_enabled'] ?? 0);
        $item['kicker_id'] = $item['kicker_id'] !== null ? (int)$item['kicker_id'] : null;
        $item['is_active'] = (int)($item['is_active'] ?? 0);
        $item['playlist_slug'] = $slug !== '' ? $slug : null;
        $item['player_url'] = $slug ? "/playlist/{$slug}" : "/playlist/{$item['playlist_instance_id']}";
        return $item;
    }, $items);

    respond([
        'ok' => true,
        'items' => $items,
    ]);
}

$repo = new PdoPlaylistInstanceRepository($pdo);
$instances = $repo->listAll($onlyActive, $includeRetired);

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
        'slug' => $instance->slug,
        'instance_name' => $instance->instanceName,
        'display_title' => $instance->displayTitle,
        'display_subtitle' => $instance->displaySubtitle,
        'instance_notes' => $instance->instanceNotes,
        'cta_group_id' => $instance->ctaGroupId,
        'palette_viewer_cta_group_id' => $instance->paletteViewerCtaGroupId,
        'demo_enabled' => $instance->demoEnabled ? 1 : 0,
        'cta_context_key' => $instance->ctaContextKey,
        'audience' => $instance->audience,
        'cta_overrides' => $instance->ctaOverrides,
        'kicker_id' => $instance->kickerId,
        'is_active' => $instance->isActive ? 1 : 0,
    ];
}, $instances);

$items = array_map(static function (array $item): array {
    $slug = trim((string)($item['slug'] ?? ''));
    $item['playlist_slug'] = $slug !== '' ? $slug : null;
    $item['player_url'] = $slug ? "/playlist/{$slug}" : "/playlist/{$item['playlist_instance_id']}";
    return $item;
}, $items);

respond([
    'ok' => true,
    'items' => $items,
]);
