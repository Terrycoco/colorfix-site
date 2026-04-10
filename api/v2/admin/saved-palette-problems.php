<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function normalize_library_path(string $value): string
{
    $value = trim($value);
    if ($value === '' || str_starts_with($value, 'asset:') || str_starts_with($value, 'photo:')) {
        return '';
    }

    if (preg_match('~^https?://~i', $value)) {
        $parts = parse_url($value);
        $value = (string)($parts['path'] ?? '');
    }

    $value = preg_replace('/\?.*$/', '', $value) ?? '';
    if ($value === '') {
        return '';
    }
    return str_starts_with($value, '/') ? $value : '/' . ltrim($value, '/');
}

function library_path_exists(string $relPath): bool
{
    $cleanPath = normalize_library_path($relPath);
    if ($cleanPath === '' || !str_starts_with($cleanPath, '/')) {
        return false;
    }

    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 3)), '/');
    $primary = $docRoot . $cleanPath;
    if (is_file($primary)) {
        return true;
    }

    $fallback = dirname(__DIR__, 3) . $cleanPath;
    return is_file($fallback);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        respond(['ok' => false, 'error' => 'GET only'], 405);
    }

    $sql = <<<SQL
        SELECT
            sp.id AS photo_id,
            sp.photo_library_id,
            sp.photo_type,
            sp.rel_path AS saved_rel_path,
            s.id AS saved_palette_set_id,
            s.title AS set_title,
            s.slug AS set_slug,
            s.saved_palette_id,
            p.nickname AS palette_nickname,
            p.palette_hash,
            pl.rel_path AS library_rel_path,
            pl.title AS library_title,
            pl.photo_library_id AS joined_photo_library_id
        FROM saved_palette_set_photos sp
        JOIN saved_palette_sets s
          ON s.id = sp.saved_palette_set_id
        JOIN saved_palettes p
          ON p.id = s.saved_palette_id
        LEFT JOIN photo_library pl
          ON pl.photo_library_id = sp.photo_library_id
        ORDER BY p.nickname ASC, s.order_index ASC, sp.order_index ASC, sp.id ASC
    SQL;

    $stmt = $pdo->query($sql);
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $items = [];
    foreach ($rows as $row) {
        $photoLibraryId = (int)($row['photo_library_id'] ?? 0);
        $joinedPhotoLibraryId = (int)($row['joined_photo_library_id'] ?? 0);
        $libraryRelPath = (string)($row['library_rel_path'] ?? '');
        $savedRelPath = (string)($row['saved_rel_path'] ?? '');
        $usableRelPath = $libraryRelPath !== '' ? $libraryRelPath : $savedRelPath;

        $problem = '';
        if ($photoLibraryId <= 0) {
            $problem = 'Linked photo row missing photo_library_id';
        } elseif ($joinedPhotoLibraryId <= 0) {
            $problem = 'Linked photo_library row does not exist';
        } elseif ($libraryRelPath === '') {
            $problem = 'Linked photo_library row has no file path';
        } elseif (!library_path_exists($libraryRelPath)) {
            $problem = 'Linked photo_library file is missing';
        }

        if ($problem === '') {
            continue;
        }

        $paletteId = (int)($row['saved_palette_id'] ?? 0);
        $setId = (int)($row['saved_palette_set_id'] ?? 0);
        $params = http_build_query([
            'type' => 'saved',
            'id' => $paletteId,
            'set_id' => $setId,
        ]);

        $paletteLabel = trim((string)($row['palette_nickname'] ?? '')) ?: sprintf('Saved Palette #%d', $paletteId);
        $setLabel = trim((string)($row['set_title'] ?? '')) ?: trim((string)($row['set_slug'] ?? '')) ?: sprintf('Set #%d', $setId);

        $items[] = [
            'key' => sprintf('saved-problem:%d', (int)$row['photo_id']),
            'photo_id' => (int)$row['photo_id'],
            'photo_library_id' => $photoLibraryId > 0 ? $photoLibraryId : null,
            'saved_palette_id' => $paletteId,
            'saved_palette_set_id' => $setId,
            'photo_type' => (string)($row['photo_type'] ?? ''),
            'thumb' => $usableRelPath,
            'name' => sprintf('%s — %s', $paletteLabel, $setLabel),
            'flagged' => sprintf('%s (%s)', $problem, (string)($row['photo_type'] ?? 'photo')),
            'rel_path' => $libraryRelPath !== '' ? $libraryRelPath : $savedRelPath,
            'open_path' => '/admin/palette-photos?' . $params,
        ];
    }

    respond([
        'ok' => true,
        'result' => [
            'count' => count($items),
            'items' => $items,
        ],
    ]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
