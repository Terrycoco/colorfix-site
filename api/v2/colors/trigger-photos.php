<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

require_once __DIR__ . '/../../../api/autoload.php';
require_once __DIR__ . '/../../../api/db.php';

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        respond(['ok' => false, 'error' => 'GET only'], 405);
    }

    $colorId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($colorId <= 0) {
        respond(['ok' => false, 'error' => 'Missing or invalid color ID'], 400);
    }

    $sql = "
        SELECT merged.*
          FROM (
            SELECT COALESCE(spsp.photo_library_id, 0) AS photo_library_id,
                   COALESCE(NULLIF(pl.rel_path, ''), NULLIF(spsp.rel_path, '')) AS photo_url,
                   spsp.photo_type,
                   spsp.trigger_color_id,
                   spsp.order_index,
                   sp.id AS palette_id,
                   sp.palette_hash,
                   sp.nickname AS palette_name,
                   sp.brand AS palette_brand,
                   sps.id AS saved_palette_set_id
              FROM saved_palette_members m
              JOIN saved_palette_sets sps
                ON sps.saved_palette_id = m.saved_palette_id
              JOIN saved_palette_set_photos spsp
                ON spsp.saved_palette_set_id = sps.id
             JOIN saved_palettes sp
                ON sp.id = m.saved_palette_id
         LEFT JOIN photo_library pl
                ON pl.photo_library_id = spsp.photo_library_id
             WHERE m.color_id = :color_id
               AND (
                 (spsp.photo_library_id IS NOT NULL
                  AND COALESCE(pl.show_in_gallery, 0) = 1
                  AND COALESCE(pl.has_palette, 0) = 1
                  AND COALESCE(pl.is_inactive, 0) = 0
                  AND COALESCE(pl.is_retired, 0) = 0)
                 OR (spsp.photo_library_id IS NULL AND spsp.show_in_gallery = 1)
               )
               AND spsp.trigger_mode <> 'none'

            UNION ALL

            SELECT COALESCE(pl.photo_library_id, 0) AS photo_library_id,
                   pl.rel_path AS photo_url,
                   spp.photo_type,
                   spp.trigger_color_id,
                   spp.order_index,
                   sp.id AS palette_id,
                   sp.palette_hash,
                   sp.nickname AS palette_name,
                   sp.brand AS palette_brand,
                   NULL AS saved_palette_set_id
              FROM saved_palette_members m
              JOIN saved_palette_photos spp
                ON spp.saved_palette_id = m.saved_palette_id
              JOIN saved_palettes sp
                ON sp.id = m.saved_palette_id
              JOIN photo_library pl
                ON pl.source_type = 'saved_palette_photo'
               AND pl.source_id = spp.id
             WHERE m.color_id = :legacy_color_id
               AND pl.show_in_gallery = 1
               AND pl.has_palette = 1
               AND COALESCE(pl.is_inactive, 0) = 0
               AND COALESCE(pl.is_retired, 0) = 0
               AND NOT EXISTS (
                 SELECT 1
                   FROM saved_palette_sets modern_set
                   JOIN saved_palette_set_photos modern
                     ON modern.saved_palette_set_id = modern_set.id
                  WHERE modern_set.saved_palette_id = spp.saved_palette_id
               )
          ) AS merged
         WHERE merged.photo_url IS NOT NULL
           AND merged.photo_url <> ''
         ORDER BY merged.photo_type = 'zoom' DESC, merged.order_index ASC, merged.palette_id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':color_id' => $colorId,
        ':legacy_color_id' => $colorId,
    ]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $items = [];
    $seen = [];

    foreach ($rows as $row) {
        $photoUrl = trim((string)($row['photo_url'] ?? ''));
        if ($photoUrl === '') {
            continue;
        }

        $key = implode(':', [
            (string)($row['palette_hash'] ?? ''),
            (string)($row['palette_id'] ?? ''),
            (string)($row['saved_palette_set_id'] ?? ''),
            (string)($row['photo_type'] ?? ''),
            $photoUrl,
        ]);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $items[] = [
            'photo_library_id' => (int)($row['photo_library_id'] ?? 0),
            'photo_url' => $photoUrl,
            'photo_type' => (string)($row['photo_type'] ?? 'full'),
            'trigger_color_id' => isset($row['trigger_color_id']) ? (int)$row['trigger_color_id'] : null,
            'palette_id' => (int)($row['palette_id'] ?? 0),
            'palette_hash' => $row['palette_hash'] ? (string)$row['palette_hash'] : null,
            'palette_name' => (string)($row['palette_name'] ?? ''),
            'palette_brand' => $row['palette_brand'] ? (string)$row['palette_brand'] : null,
            'saved_palette_set_id' => isset($row['saved_palette_set_id']) ? (int)$row['saved_palette_set_id'] : null,
        ];
    }

    respond(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
