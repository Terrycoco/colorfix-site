<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/auth.php';

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

    $sql = "SELECT pl.photo_library_id AS photo_id, pl.photo_library_id, 'full' AS photo_type,
        pl.rel_path AS saved_rel_path, NULL AS saved_palette_set_id, NULL AS set_title, NULL AS set_slug,
        pl.palette_id AS saved_palette_id, p.nickname AS palette_nickname, p.palette_hash,
        pl.rel_path AS library_rel_path, pl.title AS library_title, pl.photo_library_id AS joined_photo_library_id
        FROM photo_library pl JOIN saved_palettes p ON p.id=pl.palette_id ORDER BY p.nickname,pl.photo_library_id";

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
        $setLabel = trim((string)($row['set_title'] ?? '')) ?: trim((string)($row['set_slug'] ?? '')) ?: 'Main photo';

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
            'open_path' => '/admin/palette-viewers?palette_id=' . $paletteId,
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
