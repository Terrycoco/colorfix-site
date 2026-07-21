<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=60, stale-while-revalidate=300');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$family = trim((string)($_GET['family'] ?? $_GET['q'] ?? ''));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 80;
$limit = max(1, min(200, $limit));

if ($family === '') {
    respond([
        'ok' => true,
        'family' => '',
        'items' => [],
        'meta' => ['limit' => $limit, 'count' => 0],
    ]);
}

$needle = strtolower(preg_replace('/\s+/', ' ', $family));
$needleSingular = preg_replace('/s$/', '', $needle);
$familyLike = '%' . $needleSingular . '%';
$neutralFamilies = ['white', 'black', 'gray', 'grey', 'greige', 'beige', 'brown'];
$isNeutralFamily = in_array($needleSingular, $neutralFamilies, true);

try {
    $sql = <<<SQL
        SELECT
            sp.id AS saved_palette_id,
            sp.palette_hash,
            COALESCE(NULLIF(TRIM(sp.display_title), ''), NULLIF(TRIM(sp.nickname), ''), NULLIF(TRIM(pi.title), ''), CONCAT('Palette #', sp.id)) AS palette_title,
            pi.playlist_item_id,
            pi.playlist_id,
            pi.order_index,
            pi.title AS slide_title,
            pi.finder_start,
            pi.image_url,
            pi.photo_library_id,
            pl.rel_path AS photo_rel_path,
            pl.updated_at AS photo_updated_at,
            inst.playlist_instance_id,
            inst.slug AS playlist_slug,
            COALESCE(NULLIF(TRIM(inst.display_title), ''), NULLIF(TRIM(inst.instance_name), ''), NULLIF(TRIM(p.title), ''), CONCAT('Playlist #', inst.playlist_instance_id)) AS playlist_title,
            GROUP_CONCAT(DISTINCT NULLIF(TRIM(c.hue_cats), '') ORDER BY c.hue_cats SEPARATOR ', ') AS hue_families,
            GROUP_CONCAT(DISTINCT NULLIF(TRIM(c.neutral_cats), '') ORDER BY c.neutral_cats SEPARATOR ', ') AS neutral_families
          FROM saved_palettes sp
          JOIN saved_palette_members spm
            ON spm.saved_palette_id = sp.id
          JOIN colors c
            ON c.id = spm.color_id
          LEFT JOIN saved_palette_sets sps
            ON sps.saved_palette_id = sp.id
          LEFT JOIN saved_palette_set_photos spsp
            ON spsp.saved_palette_set_id = sps.id
          JOIN playlist_items pi
            ON (
                (sp.palette_hash IS NOT NULL AND sp.palette_hash <> '' AND pi.palette_hash = sp.palette_hash)
                OR (sps.id IS NOT NULL AND pi.saved_palette_set_id = sps.id)
                OR (spsp.photo_library_id IS NOT NULL AND pi.photo_library_id = spsp.photo_library_id)
                OR (spsp.rel_path IS NOT NULL AND spsp.rel_path <> '' AND pi.image_url = spsp.rel_path)
            )
           AND pi.is_active = 1
          JOIN playlist_instances inst
            ON inst.playlist_id = pi.playlist_id
           AND inst.is_active = 1
           AND inst.share_enabled = 1
          JOIN playlists p
            ON p.playlist_id = pi.playlist_id
           AND p.is_active = 1
           AND p.is_public = 1
          LEFT JOIN photo_library pl
            ON pl.photo_library_id = pi.photo_library_id
         WHERE __FAMILY_CLAUSE__
           AND (
                LOWER(COALESCE(pi.analyzer_role, '')) = 'after'
                OR LOWER(COALESCE(pi.item_type, '')) = 'after'
                OR (
                    spsp.photo_library_id IS NOT NULL
                    AND pi.photo_library_id = spsp.photo_library_id
                    AND LOWER(COALESCE(spsp.photo_type, '')) = 'after'
                )
                OR (
                    spsp.rel_path IS NOT NULL
                    AND spsp.rel_path <> ''
                    AND pi.image_url = spsp.rel_path
                    AND LOWER(COALESCE(spsp.photo_type, '')) = 'after'
                )
            )
         GROUP BY
            sp.id,
            sp.palette_hash,
            sp.display_title,
            sp.nickname,
            pi.title,
            pi.finder_start,
            pi.playlist_item_id,
            pi.playlist_id,
            pi.order_index,
            pi.image_url,
            pi.photo_library_id,
            pl.rel_path,
            pl.updated_at,
            inst.playlist_instance_id,
            inst.slug,
            inst.display_title,
            inst.instance_name,
            p.title
         ORDER BY pi.playlist_id DESC, pi.order_index ASC, pi.playlist_item_id ASC
         LIMIT {$limit}
        SQL;

    $familyClause = $isNeutralFamily
        ? "LOWER(COALESCE(c.neutral_cats, '')) LIKE :family_neutral"
        : "LOWER(COALESCE(c.hue_cats, '')) LIKE :family_hue AND NULLIF(TRIM(COALESCE(c.neutral_cats, '')), '') IS NULL";
    $sql = str_replace('__FAMILY_CLAUSE__', $familyClause, $sql);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($isNeutralFamily
        ? [':family_neutral' => $familyLike]
        : [':family_hue' => $familyLike]
    );
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $seen = [];
    $items = [];
    foreach ($rows as $row) {
        $itemId = (int)($row['playlist_item_id'] ?? 0);
        $instanceId = (int)($row['playlist_instance_id'] ?? 0);
        if ($itemId <= 0 || $instanceId <= 0) {
            continue;
        }
        $savedPaletteId = (int)($row['saved_palette_id'] ?? 0);
        $paletteHash = (string)($row['palette_hash'] ?? '');
        $key = $savedPaletteId > 0 ? ('saved-palette:' . $savedPaletteId) : ('palette-hash:' . $paletteHash);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $photoUrl = trim((string)($row['photo_rel_path'] ?? ''));
        if ($photoUrl !== '') {
            $updatedAt = trim((string)($row['photo_updated_at'] ?? ''));
            $stamp = $updatedAt !== '' ? strtotime($updatedAt) : false;
            if ($stamp && $stamp > 0) {
                $photoUrl .= (str_contains($photoUrl, '?') ? '&' : '?') . 'v=' . $stamp;
            }
        } else {
            $photoUrl = trim((string)($row['image_url'] ?? ''));
        }

        $playlistHandle = trim((string)($row['playlist_slug'] ?? ''));
        if ($playlistHandle === '') {
            $playlistHandle = (string)$instanceId;
        }
        $finderStart = strtolower(trim((string)($row['finder_start'] ?? 'auto')));
        if (!in_array($finderStart, ['auto', 'this', 'previous'], true)) {
            $finderStart = 'auto';
        }
        $offset = match ($finderStart) {
            'this' => 0,
            'previous' => -1,
            default => -1,
        };

        $families = array_values(array_unique(array_filter(array_map('trim', explode(',', implode(',', [
            (string)($row['hue_families'] ?? ''),
            (string)($row['neutral_families'] ?? ''),
        ]))))));

        $items[] = [
            'saved_palette_id' => $savedPaletteId,
            'palette_hash' => $paletteHash,
            'palette_title' => (string)($row['palette_title'] ?? ''),
            'playlist_instance_id' => $instanceId,
            'playlist_title' => (string)($row['playlist_title'] ?? ''),
            'playlist_item_id' => $itemId,
            'slide_title' => (string)($row['slide_title'] ?? ''),
            'photo_library_id' => isset($row['photo_library_id']) ? (int)$row['photo_library_id'] : null,
            'photo_url' => $photoUrl,
            'families' => $families,
            'finder_start' => $finderStart,
            'offset' => $offset,
            'player_url' => '/playlist/' . rawurlencode($playlistHandle) . '?slide_id=' . rawurlencode((string)$itemId) . '&offset=' . rawurlencode((string)$offset),
        ];
    }

    respond([
        'ok' => true,
        'family' => $family,
        'items' => $items,
        'meta' => [
            'limit' => $limit,
            'count' => count($items),
        ],
    ]);
} catch (\Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
