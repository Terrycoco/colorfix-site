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

function with_cache_buster(string $relPath, ?string $updatedAt): string {
    $path = trim($relPath);
    if ($path === '') return '';
    $stamp = $updatedAt ? strtotime($updatedAt) : false;
    if ($stamp === false || $stamp <= 0) {
        return $path;
    }
    $sep = str_contains($path, '?') ? '&' : '?';
    return $path . $sep . 'v=' . $stamp;
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
        $where[] = '(photo_library.title LIKE :q_title OR photo_library.tags LIKE :q_tags OR photo_library.rel_path LIKE :q_path OR clients.name LIKE :q_client_name OR clients.email LIKE :q_client_email'
            . (ctype_digit($q) ? ' OR CAST(photo_library.photo_library_id AS CHAR) LIKE :q_photo_id' : '')
            . ')';
        $params[':q_title'] = '%' . $q . '%';
        $params[':q_tags'] = '%' . $q . '%';
        $params[':q_path'] = '%' . $q . '%';
        $params[':q_client_name'] = '%' . $q . '%';
        $params[':q_client_email'] = '%' . $q . '%';
        if (ctype_digit($q)) {
            $params[':q_photo_id'] = $q . '%';
        }
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

    $sql = "SELECT photo_library.photo_library_id,
                   photo_library.source_type,
                   photo_library.source_id,
                   photo_library.client_id,
                   photo_library.rel_path,
                   photo_library.title,
                   photo_library.tags,
                   photo_library.alt_text,
                   photo_library.note,
                   photo_library.show_in_gallery,
                   photo_library.has_palette,
                   photo_library.created_at,
                   photo_library.updated_at,
                   clients.name AS client_name,
                   clients.email AS client_email
            FROM photo_library
            LEFT JOIN clients ON clients.id = photo_library.client_id{$joins}";
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
        $rawRelPath = (string)$row['rel_path'];
        $updatedAt = $row['updated_at'] ?? null;
        $versionedPath = with_cache_buster($rawRelPath, $updatedAt);
        $filename = basename(parse_url($rawRelPath, PHP_URL_PATH) ?: $rawRelPath);
        return [
            'photo_library_id' => (int)$row['photo_library_id'],
            'source_type' => (string)$row['source_type'],
            'source_id' => $row['source_id'] !== null ? (int)$row['source_id'] : null,
            'client_id' => $row['client_id'] !== null ? (int)$row['client_id'] : null,
            'client_name' => $row['client_name'] ?? '',
            'client_email' => $row['client_email'] ?? '',
            'raw_rel_path' => $rawRelPath,
            'rel_path' => $versionedPath,
            'image_url' => $versionedPath,
            'filename' => $filename,
            'title' => $row['title'] ?? '',
            'tags' => $row['tags'] ?? '',
            'alt_text' => $row['alt_text'] ?? '',
            'note' => $row['note'] ?? '',
            'show_in_gallery' => (int)$row['show_in_gallery'] === 1,
            'has_palette' => (int)$row['has_palette'] === 1,
            'created_at' => $row['created_at'],
            'updated_at' => $updatedAt,
        ];
    }, $rows);

    respond(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
