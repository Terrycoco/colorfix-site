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
    $clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
    $paletteId = isset($_GET['palette_id']) ? (int)$_GET['palette_id'] : 0;
    $includeInactive = !empty($_GET['include_inactive']) && $_GET['include_inactive'] !== '0';
    $inactiveOnly = !empty($_GET['inactive_only']) && $_GET['inactive_only'] !== '0';
    $missingTags = !empty($_GET['missing_tags']) && $_GET['missing_tags'] !== '0';
    $sort = trim((string)($_GET['sort'] ?? 'newest'));
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
    $exactPhotoIdSearch = preg_match('/^#?\d+$/', $q) === 1;

    $where = [];
    $params = [];
    $needsPaletteJoin = !$exactPhotoIdSearch && ($paletteId > 0 || $sourceType === 'saved_palette_photo');
    if (!$exactPhotoIdSearch && $sourceType !== '' && $sourceType !== 'saved_palette_photo') {
        $where[] = 'source_type = :source_type';
        $params[':source_type'] = $sourceType;
    }
    if ($exactPhotoIdSearch) {
        $where[] = 'photo_library.photo_library_id = :exact_photo_library_id';
        $params[':exact_photo_library_id'] = (int)ltrim($q, '#');
    } elseif ($q !== '') {
        $tokens = array_values(array_filter(array_map(
            static fn(string $part): string => trim($part),
            explode(',', $q)
        ), static fn(string $part): bool => $part !== ''));
        if (!$tokens) {
            $tokens = [$q];
        }

        $tokenClauses = [];
        foreach ($tokens as $idx => $token) {
            $suffix = '_' . $idx;
            $numericToken = preg_replace('/^#/', '', $token);
            $tokenClauses[] = '(photo_library.title LIKE :q_title' . $suffix
                . ' OR photo_library.tags LIKE :q_tags' . $suffix
                . ' OR photo_library.rel_path LIKE :q_path' . $suffix
                . ' OR clients.name LIKE :q_client_name' . $suffix
                . ' OR clients.email LIKE :q_client_email' . $suffix
                . (ctype_digit($numericToken) ? ' OR photo_library.photo_library_id = :q_photo_id_exact' . $suffix
                    . ' OR CAST(photo_library.photo_library_id AS CHAR) LIKE :q_photo_id' . $suffix : '')
                . ')';

            $params[':q_title' . $suffix] = '%' . $token . '%';
            $params[':q_tags' . $suffix] = '%' . $token . '%';
            $params[':q_path' . $suffix] = '%' . $token . '%';
            $params[':q_client_name' . $suffix] = '%' . $token . '%';
            $params[':q_client_email' . $suffix] = '%' . $token . '%';
            if (ctype_digit($numericToken)) {
                $params[':q_photo_id_exact' . $suffix] = (int)$numericToken;
                $params[':q_photo_id' . $suffix] = $numericToken . '%';
            }
        }

        $where[] = '(' . implode(' AND ', $tokenClauses) . ')';
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
    if (!$exactPhotoIdSearch && $clientId > 0) {
        $where[] = 'photo_library.client_id = :client_id';
        $params[':client_id'] = $clientId;
    }
    if (!$exactPhotoIdSearch && $missingTags) {
        $where[] = "(photo_library.tags IS NULL OR TRIM(photo_library.tags) = '')";
    }
    $explicitSearch = $q !== '' || !empty($photoLibraryIds);
    if ($explicitSearch) {
        // Direct searches should find matching rows even when inactive/retired;
        // otherwise replacement and cleanup workflows hide the exact photo needed.
    } elseif ($inactiveOnly) {
        $where[] = 'photo_library.is_inactive = 1';
    } elseif (!$includeInactive) {
        $where[] = 'photo_library.is_inactive = 0';
    }

    $hasNarrowingFilter = $q !== ''
        || $photoLibraryIds
        || $clientId > 0
        || $sourceType !== ''
        || $paletteId > 0
        || $inactiveOnly
        || $includeInactive
        || $missingTags;
    $limit = min($limit, $hasNarrowingFilter ? 50 : 5);

    $joins = "";
    if ($needsPaletteJoin) {
        $joins .= " JOIN saved_palette_set_photos spsp ON spsp.photo_library_id = photo_library.photo_library_id";
        $joins .= " JOIN saved_palette_sets sps ON sps.id = spsp.saved_palette_set_id";
    }
    if (!$exactPhotoIdSearch && $paletteId > 0) {
        $where[] = "sps.saved_palette_id = :palette_id";
        $params[':palette_id'] = $paletteId;
    }

    $sql = "SELECT photo_library.photo_library_id,
                   photo_library.source_type,
                   photo_library.source_id,
                   photo_library.client_id,
                   photo_library.photo_permission_status AS photo_permission_override_status,
                   photo_library.rel_path,
                   photo_library.title,
                   photo_library.tags,
                   photo_library.alt_text,
                   photo_library.ai_alt_text,
                   photo_library.ai_filename_slug,
                   photo_library.ai_alt_model,
                   photo_library.ai_alt_generated_at,
                   jobs.status AS ai_alt_status,
                   jobs.attempts AS ai_alt_attempts,
                   jobs.next_attempt_at AS ai_alt_next_attempt_at,
                   jobs.last_error AS ai_alt_error,
                   photo_library.note,
                   photo_library.show_in_gallery,
                   photo_library.has_palette,
                   photo_library.is_inactive,
                   photo_library.created_at,
                   photo_library.updated_at,
                   clients.name AS client_name,
                   clients.email AS client_email,
                   clients.photo_permission_status AS client_photo_permission_status,
                   COALESCE(NULLIF(photo_library.photo_permission_status, ''), clients.photo_permission_status, 'unknown') AS photo_permission_status,
                   (
                     SELECT s.saved_palette_id
                       FROM saved_palette_set_photos sp
                       JOIN saved_palette_sets s
                         ON s.id = sp.saved_palette_set_id
                      WHERE sp.photo_library_id = photo_library.photo_library_id
                   ORDER BY s.is_default DESC, sp.id ASC
                      LIMIT 1
                   ) AS attached_saved_palette_id,
                   (
                     SELECT COALESCE(NULLIF(p.nickname, ''), p.palette_hash, CONCAT('Saved #', p.id))
                       FROM saved_palette_set_photos sp
                       JOIN saved_palette_sets s
                         ON s.id = sp.saved_palette_set_id
                       JOIN saved_palettes p
                         ON p.id = s.saved_palette_id
                      WHERE sp.photo_library_id = photo_library.photo_library_id
                   ORDER BY s.is_default DESC, sp.id ASC
                      LIMIT 1
                   ) AS attached_saved_palette_label,
                   (
                     SELECT s.id
                       FROM saved_palette_set_photos sp
                       JOIN saved_palette_sets s
                         ON s.id = sp.saved_palette_set_id
                      WHERE sp.photo_library_id = photo_library.photo_library_id
                   ORDER BY s.is_default DESC, sp.id ASC
                      LIMIT 1
                   ) AS attached_saved_palette_set_id,
                   (
                     SELECT COALESCE(NULLIF(s.title, ''), s.slug, CONCAT('Set #', s.id))
                       FROM saved_palette_set_photos sp
                       JOIN saved_palette_sets s
                         ON s.id = sp.saved_palette_set_id
                      WHERE sp.photo_library_id = photo_library.photo_library_id
                   ORDER BY s.is_default DESC, sp.id ASC
                      LIMIT 1
                   ) AS attached_saved_palette_set_label,
                   (
                     SELECT sp.photo_type
                       FROM saved_palette_set_photos sp
                       JOIN saved_palette_sets s
                         ON s.id = sp.saved_palette_set_id
                      WHERE sp.photo_library_id = photo_library.photo_library_id
                   ORDER BY s.is_default DESC, sp.id ASC
                      LIMIT 1
                   ) AS attached_saved_palette_photo_type,
                   (
                     SELECT sp.trigger_mode
                       FROM saved_palette_set_photos sp
                       JOIN saved_palette_sets s
                         ON s.id = sp.saved_palette_set_id
                      WHERE sp.photo_library_id = photo_library.photo_library_id
                   ORDER BY s.is_default DESC, sp.id ASC
                      LIMIT 1
                   ) AS attached_saved_palette_trigger_mode,
                   (
                     SELECT sp.trigger_color_id
                       FROM saved_palette_set_photos sp
                       JOIN saved_palette_sets s
                         ON s.id = sp.saved_palette_set_id
                      WHERE sp.photo_library_id = photo_library.photo_library_id
                   ORDER BY s.is_default DESC, sp.id ASC
                      LIMIT 1
                   ) AS attached_saved_palette_trigger_color_id,
                   (
                     SELECT COUNT(*)
                       FROM saved_palette_set_photos sp
                      WHERE sp.photo_library_id = photo_library.photo_library_id
                   ) AS attached_saved_palette_link_count
            FROM photo_library
            LEFT JOIN clients ON clients.id = photo_library.client_id
            LEFT JOIN photo_alt_text_jobs jobs ON jobs.photo_library_id = photo_library.photo_library_id{$joins}";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $orderBy = match ($sort) {
        'oldest' => 'photo_library.created_at ASC, photo_library.photo_library_id ASC',
        'title' => 'photo_library.title ASC, photo_library.photo_library_id DESC',
        'id_asc' => 'photo_library.photo_library_id ASC',
        'id_desc' => 'photo_library.photo_library_id DESC',
        default => 'photo_library.created_at DESC, photo_library.photo_library_id DESC',
    };
    $sql .= " ORDER BY {$orderBy} LIMIT :limit OFFSET :offset";

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
        $filename = basename(parse_url($rawRelPath, PHP_URL_PATH) ?: $rawRelPath);
        return [
            'photo_library_id' => (int)$row['photo_library_id'],
            'source_type' => (string)$row['source_type'],
            'source_id' => $row['source_id'] !== null ? (int)$row['source_id'] : null,
            'client_id' => $row['client_id'] !== null ? (int)$row['client_id'] : null,
            'client_name' => $row['client_name'] ?? '',
            'client_email' => $row['client_email'] ?? '',
            'photo_permission_status' => $row['photo_permission_status'] ?? 'unknown',
            'photo_permission_override_status' => $row['photo_permission_override_status'] ?? '',
            'client_photo_permission_status' => $row['client_photo_permission_status'] ?? '',
            'raw_rel_path' => $rawRelPath,
            'rel_path' => $rawRelPath,
            'image_url' => $rawRelPath,
            'filename' => $filename,
            'title' => $row['title'] ?? '',
            'tags' => $row['tags'] ?? '',
            'alt_text' => $row['alt_text'] ?? '',
            'ai_alt_text' => $row['ai_alt_text'] ?? '',
            'ai_filename_slug' => $row['ai_filename_slug'] ?? '',
            'ai_alt_model' => $row['ai_alt_model'] ?? '',
            'ai_alt_generated_at' => $row['ai_alt_generated_at'] ?? null,
            'ai_alt_status' => $row['ai_alt_status'] ?? '',
            'ai_alt_attempts' => (int)($row['ai_alt_attempts'] ?? 0),
            'ai_alt_next_attempt_at' => $row['ai_alt_next_attempt_at'] ?? null,
            'ai_alt_error' => $row['ai_alt_error'] ?? '',
            'note' => $row['note'] ?? '',
            'show_in_gallery' => (int)$row['show_in_gallery'] === 1,
            'has_palette' => (int)$row['has_palette'] === 1,
            'is_inactive' => (int)($row['is_inactive'] ?? 0) === 1,
            'created_at' => $row['created_at'],
            'updated_at' => $updatedAt,
            'attached_saved_palette_id' => $row['attached_saved_palette_id'] !== null ? (int)$row['attached_saved_palette_id'] : null,
            'attached_saved_palette_label' => $row['attached_saved_palette_label'] ?? '',
            'attached_saved_palette_set_id' => $row['attached_saved_palette_set_id'] !== null ? (int)$row['attached_saved_palette_set_id'] : null,
            'attached_saved_palette_set_label' => $row['attached_saved_palette_set_label'] ?? '',
            'attached_saved_palette_photo_type' => $row['attached_saved_palette_photo_type'] ?? '',
            'attached_saved_palette_trigger_mode' => $row['attached_saved_palette_trigger_mode'] ?? '',
            'attached_saved_palette_trigger_color_id' => $row['attached_saved_palette_trigger_color_id'] !== null ? (int)$row['attached_saved_palette_trigger_color_id'] : null,
            'attached_saved_palette_link_count' => (int)($row['attached_saved_palette_link_count'] ?? 0),
        ];
    }, $rows);

    respond(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
