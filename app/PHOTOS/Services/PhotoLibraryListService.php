<?php
declare(strict_types=1);

namespace App\PHOTOS\Services;

use PDO;

/** Canonical Photo Library list/search query and response projection.
 * The Artwork picker reuses this search instead of maintaining another SQL search.
 */
final class PhotoLibraryListService
{
    public function __construct(private PDO $pdo) {}

    /** @return array<int,array<string,mixed>> */
    public function list(array $queryParams, array $options = []): array
    {
    $q = trim((string)($queryParams['q'] ?? ''));
    $sourceType = trim((string)($queryParams['source_type'] ?? ''));
    $clientId = isset($queryParams['client_id']) ? (int)$queryParams['client_id'] : 0;
    $paletteId = isset($queryParams['palette_id']) ? (int)$queryParams['palette_id'] : 0;
    $includeInactive = !empty($queryParams['include_inactive']) && $queryParams['include_inactive'] !== '0';
    $inactiveOnly = !empty($queryParams['inactive_only']) && $queryParams['inactive_only'] !== '0';
    $missingTags = !empty($queryParams['missing_tags']) && $queryParams['missing_tags'] !== '0';
    $sort = trim((string)($queryParams['sort'] ?? 'newest'));
    $photoLibraryIdsRaw = trim((string)($queryParams['photo_library_ids'] ?? ''));
    $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 100;
    $offset = isset($queryParams['offset']) ? (int)$queryParams['offset'] : 0;
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
    if (!empty($options['exclude_art'])) {
        $where[] = "photo_library.source_type <> 'art'";
    }
    if (!empty($options['active_only'])) {
        $where[] = 'photo_library.is_inactive = 0';
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

    $joins = ' LEFT JOIN saved_palettes attached_palette ON attached_palette.id = photo_library.palette_id';
    if (!$exactPhotoIdSearch && $sourceType === 'saved_palette_photo') { $where[] = 'photo_library.palette_id IS NOT NULL'; }
    if (!$exactPhotoIdSearch && $paletteId > 0) {
        $where[] = 'photo_library.palette_id = :palette_id'; $params[':palette_id'] = $paletteId;
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
                   photo_library.palette_id,
                   photo_library.is_inactive,
                   photo_library.is_retired,
                   photo_library.created_at,
                   photo_library.updated_at,
                   clients.name AS client_name,
                   clients.email AS client_email,
                   clients.photo_permission_status AS client_photo_permission_status,
                   COALESCE(NULLIF(photo_library.photo_permission_status, ''), clients.photo_permission_status, 'unknown') AS photo_permission_status,
                   photo_library.palette_id AS attached_saved_palette_id,
                   COALESCE(NULLIF(attached_palette.nickname,''), attached_palette.palette_hash) AS attached_saved_palette_label,
                   NULL AS attached_saved_palette_set_id, NULL AS attached_saved_palette_set_label,
                   CASE WHEN photo_library.palette_id IS NOT NULL THEN 'full' ELSE NULL END AS attached_saved_palette_photo_type,
                   'any' AS attached_saved_palette_trigger_mode, NULL AS attached_saved_palette_trigger_color_id,
                   CASE WHEN photo_library.palette_id IS NOT NULL THEN 1 ELSE 0 END AS attached_saved_palette_link_count,
                   (SELECT pv.palette_viewer_id FROM palette_viewers pv WHERE pv.saved_palette_id=photo_library.palette_id
                     ORDER BY pv.is_active DESC, (pv.format='public') DESC, pv.palette_viewer_id LIMIT 1) AS attached_palette_viewer_id
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

    $stmt = $this->pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $libraryOwnsPalette = (new \App\PHOTOS\Repos\PdoPhotoLibraryRepository($this->pdo))->paletteColumnAvailable();
    if ($libraryOwnsPalette) {
        $direct = $this->pdo->prepare("SELECT pl.palette_id, COALESCE(NULLIF(p.display_title, ''), NULLIF(p.nickname, ''), p.palette_hash) AS label
            FROM photo_library pl LEFT JOIN saved_palettes p ON p.id = pl.palette_id WHERE pl.photo_library_id = ?");
        foreach ($rows as &$row) {
            $direct->execute([$row['photo_library_id']]);
            $link = $direct->fetch(PDO::FETCH_ASSOC);
            $row['attached_saved_palette_id'] = $link['palette_id'];
            $row['attached_saved_palette_label'] = $link['label'];
            $row['attached_saved_palette_link_count'] = $link['palette_id'] ? 1 : 0;
            foreach (['set_id', 'set_label', 'photo_type', 'trigger_mode', 'trigger_color_id'] as $field) {
                $row['attached_saved_palette_' . $field] = null;
            }
        }
        unset($row);
    }

    $items = array_map(static function(array $row) use ($libraryOwnsPalette): array {
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
            'palette_owned_by_library' => $libraryOwnsPalette,
            'palette_id' => $row['attached_saved_palette_id'] !== null ? (int)$row['attached_saved_palette_id'] : null,
            'is_inactive' => (int)($row['is_inactive'] ?? 0) === 1,
            'is_retired' => (int)($row['is_retired'] ?? 0) === 1,
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

        return $items;
    }
}
