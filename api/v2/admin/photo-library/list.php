<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoPhotoLibraryRepository;
use App\Services\PhotoLibraryService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

try {
    $q = trim((string)($_GET['q'] ?? ''));
    $sourceType = trim((string)($_GET['source_type'] ?? ''));
    $paletteId = isset($_GET['palette_id']) ? (int)$_GET['palette_id'] : 0;
    $photoLibraryIdsRaw = trim((string)($_GET['photo_library_ids'] ?? ''));
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $limit = max(1, min(300, $limit));
    $offset = max(0, $offset);
    $photoLibraryIds = [];
    if ($photoLibraryIdsRaw !== '') {
        foreach (explode(',', $photoLibraryIdsRaw) as $part) {
            $id = (int)trim($part);
            if ($id > 0) {
                $photoLibraryIds[$id] = $id;
            }
        }
        $photoLibraryIds = array_values($photoLibraryIds);
    }

    $where = [];
    $params = [];
    if ($sourceType !== '') {
        $where[] = 'source_type = :source_type';
        $params[':source_type'] = $sourceType;
    }
    if ($q !== '') {
        $where[] = '(photo_library.title LIKE :q_title OR photo_library.tags LIKE :q_tags OR photo_library.rel_path LIKE :q_path)';
        $params[':q_title'] = '%' . $q . '%';
        $params[':q_tags'] = '%' . $q . '%';
        $params[':q_path'] = '%' . $q . '%';
    }
    if ($photoLibraryIds) {
        $placeholders = [];
        foreach ($photoLibraryIds as $idx => $id) {
            $key = ':photo_library_id_' . $idx;
            $placeholders[] = $key;
            $params[$key] = $id;
        }
        $where[] = 'photo_library.photo_library_id IN (' . implode(', ', $placeholders) . ')';
    }

    $joins = "";
    if ($paletteId > 0) {
        $joins .= " JOIN saved_palette_photos spp ON spp.id = photo_library.source_id";
        $where[] = "photo_library.source_type = 'saved_palette_photo'";
        $where[] = "spp.saved_palette_id = :palette_id";
        $params[':palette_id'] = $paletteId;
    }

    if ($sourceType === '' || $sourceType === 'applied_palette') {
        $repo = new PdoPhotoLibraryRepository($pdo);
        $library = new PhotoLibraryService($repo);
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 4), '/');
        $publicRoot = rtrim(dirname(__DIR__, 4), '/');

        $paletteRows = $pdo->query("SELECT id, title, display_title, tags, alt_text FROM applied_palettes ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($paletteRows as $row) {
            $appliedPaletteId = (int)$row['id'];
            if ($appliedPaletteId <= 0) continue;
            $renderRel = "/photos/rendered/ap_{$appliedPaletteId}.jpg";
            $renderAbs = $docRoot . $renderRel;
            if (!is_file($renderAbs)) {
                $altAbs = $publicRoot . $renderRel;
                if (!is_file($altAbs)) {
                    continue;
                }
            }
            $library->syncAppliedPalettePhoto($row, $renderRel);
        }
    }

    $sql = "SELECT photo_library.photo_library_id,
                   photo_library.source_type,
                   photo_library.source_id,
                   photo_library.rel_path,
                   photo_library.title,
                   photo_library.tags,
                   photo_library.alt_text,
                   photo_library.note,
                   photo_library.show_in_gallery,
                   photo_library.has_palette,
                   photo_library.created_at,
                   photo_library.updated_at
            FROM photo_library{$joins}";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY updated_at DESC, created_at DESC LIMIT :limit OFFSET :offset';

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = array_map(static function(array $row): array {
        $relPath = (string)$row['rel_path'];
        $filename = basename(parse_url($relPath, PHP_URL_PATH) ?: $relPath);
        return [
            'photo_library_id' => (int)$row['photo_library_id'],
            'source_type' => (string)$row['source_type'],
            'source_id' => $row['source_id'] !== null ? (int)$row['source_id'] : null,
            'rel_path' => $relPath,
            'filename' => $filename,
            'title' => $row['title'] ?? '',
            'tags' => $row['tags'] ?? '',
            'alt_text' => $row['alt_text'] ?? '',
            'note' => $row['note'] ?? '',
            'show_in_gallery' => (int)$row['show_in_gallery'] === 1,
            'has_palette' => (int)$row['has_palette'] === 1,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }, $rows);

    respond(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
