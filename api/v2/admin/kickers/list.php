<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

try {
    $sql = <<<SQL
        SELECT
            k.kicker_id,
            k.slug,
            k.display_text,
            k.is_active,
            k.sort_order,
            k.created_at,
            k.updated_at,
            (SELECT COUNT(*) FROM saved_palettes sp WHERE sp.kicker_id = k.kicker_id) AS saved_count,
            (SELECT COUNT(*) FROM applied_palettes ap WHERE ap.kicker_id = k.kicker_id) AS applied_count,
            (SELECT COUNT(*) FROM playlist_instances pi WHERE pi.kicker_id = k.kicker_id) AS playlist_instance_count
        FROM kickers k
        ORDER BY k.sort_order ASC, k.display_text ASC
    SQL;
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = array_map(static function (array $row): array {
        return [
            'kicker_id' => (int)$row['kicker_id'],
            'slug' => (string)$row['slug'],
            'display_text' => (string)$row['display_text'],
            'is_active' => (int)$row['is_active'] === 1,
            'sort_order' => (int)$row['sort_order'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'saved_count' => (int)$row['saved_count'],
            'applied_count' => (int)$row['applied_count'],
            'playlist_instance_count' => (int)$row['playlist_instance_count'],
        ];
    }, $rows);

    respond(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
