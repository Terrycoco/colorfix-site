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

function extract_photo_ref_id(string $value): int
{
    $value = trim($value);
    if (!str_starts_with($value, 'photo:')) {
        return 0;
    }
    $rest = substr($value, 6);
    $pipeAt = strpos($rest, '|');
    $rawId = $pipeAt === false ? $rest : substr($rest, 0, $pipeAt);
    return ctype_digit(trim($rawId)) ? (int)trim($rawId) : 0;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        respond(['ok' => false, 'error' => 'GET only'], 405);
    }

    $sql = <<<SQL
        SELECT
            'playlist_item' AS problem_type,
            pi.playlist_item_id AS row_id,
            p.playlist_id AS parent_id,
            p.title AS parent_title,
            pi.photo_library_id,
            pi.image_url AS raw_ref,
            pl.photo_library_id AS joined_photo_library_id,
            pl.rel_path AS library_rel_path,
            pl.title AS library_title,
            pi.item_type AS detail_type
        FROM playlist_items pi
        JOIN playlists p
          ON p.playlist_id = pi.playlist_id
        LEFT JOIN photo_library pl
          ON pl.photo_library_id = pi.photo_library_id
        WHERE COALESCE(pi.is_active, 1) = 1

        UNION ALL

        SELECT
            'playlist_set_item' AS problem_type,
            psi.id AS row_id,
            ps.id AS parent_id,
            ps.title AS parent_title,
            psi.photo_library_id,
            '' AS raw_ref,
            pl.photo_library_id AS joined_photo_library_id,
            pl.rel_path AS library_rel_path,
            pl.title AS library_title,
            '' AS detail_type
        FROM playlist_instance_set_items psi
        JOIN playlist_instance_sets ps
          ON ps.id = psi.playlist_instance_set_id
        LEFT JOIN photo_library pl
          ON pl.photo_library_id = psi.photo_library_id

        UNION ALL

        SELECT
            'playlist_instance_intro' AS problem_type,
            pi.playlist_instance_id AS row_id,
            pi.playlist_instance_id AS parent_id,
            pi.instance_name AS parent_title,
            NULL AS photo_library_id,
            pi.intro_image_url AS raw_ref,
            NULL AS joined_photo_library_id,
            NULL AS library_rel_path,
            NULL AS library_title,
            '' AS detail_type
        FROM playlist_instances pi
        WHERE pi.intro_image_url IS NOT NULL
          AND pi.intro_image_url <> ''

        UNION ALL

        SELECT
            'playlist_instance_share' AS problem_type,
            pi.playlist_instance_id AS row_id,
            pi.playlist_instance_id AS parent_id,
            pi.instance_name AS parent_title,
            NULL AS photo_library_id,
            pi.share_image_url AS raw_ref,
            NULL AS joined_photo_library_id,
            NULL AS library_rel_path,
            NULL AS library_title,
            '' AS detail_type
        FROM playlist_instances pi
        WHERE pi.share_image_url IS NOT NULL
          AND pi.share_image_url <> ''
        ORDER BY problem_type ASC, parent_title ASC, row_id ASC
    SQL;

    $stmt = $pdo->query($sql);
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $items = [];

    foreach ($rows as $row) {
        $problemType = (string)($row['problem_type'] ?? '');
        $rawRef = (string)($row['raw_ref'] ?? '');
        $linkedId = (int)($row['photo_library_id'] ?? 0);
        $refId = extract_photo_ref_id($rawRef);
        if ($linkedId <= 0 && $refId > 0) {
            $linkedId = $refId;
        }
        $joinedId = (int)($row['joined_photo_library_id'] ?? 0);
        $libraryRelPath = (string)($row['library_rel_path'] ?? '');

        $problem = '';
        if ($problemType === 'playlist_item') {
            if ($linkedId <= 0 && $rawRef !== '') {
                $problem = 'Playlist item missing photo_library_id';
            }
        } elseif (in_array($problemType, ['playlist_instance_intro', 'playlist_instance_share'], true)) {
            if ($refId <= 0) {
                continue;
            }
            $lookup = $pdo->prepare("SELECT photo_library_id, rel_path, title FROM photo_library WHERE photo_library_id = :id LIMIT 1");
            $lookup->execute([':id' => $refId]);
            $lib = $lookup->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$lib) {
                $problem = 'Playlist instance photo ref points to missing photo_library row';
            } elseif (trim((string)($lib['rel_path'] ?? '')) === '') {
                $problem = 'Playlist instance linked photo_library row has no file path';
            } elseif (!library_path_exists((string)$lib['rel_path'])) {
                $problem = 'Playlist instance linked photo_library file is missing';
            }
            $joinedId = (int)($lib['photo_library_id'] ?? 0);
            $libraryRelPath = (string)($lib['rel_path'] ?? '');
        } else {
            if ($linkedId <= 0) {
                $problem = 'Linked row missing photo_library_id';
            }
        }

        if ($problem === '') {
            if ($linkedId > 0 && $joinedId <= 0) {
                $problem = 'Linked photo_library row does not exist';
            } elseif ($joinedId > 0 && trim($libraryRelPath) === '') {
                $problem = 'Linked photo_library row has no file path';
            } elseif ($joinedId > 0 && !library_path_exists($libraryRelPath)) {
                $problem = 'Linked photo_library file is missing';
            }
        }

        if ($problem === '') {
            continue;
        }

        $parentId = (int)($row['parent_id'] ?? 0);
        $parentTitle = trim((string)($row['parent_title'] ?? ''));
        $openPath = match ($problemType) {
            'playlist_item' => '/admin/playlists',
            'playlist_set_item' => '/admin/playlist-instance-sets',
            default => '/admin/playlist-instances',
        };
        $detail = match ($problemType) {
            'playlist_item' => sprintf('Playlist item #%d', (int)$row['row_id']),
            'playlist_set_item' => sprintf('Playlist set item #%d', (int)$row['row_id']),
            'playlist_instance_intro' => 'Intro image',
            'playlist_instance_share' => 'Share image',
            default => '',
        };

        $items[] = [
            'key' => sprintf('%s:%d', $problemType, (int)$row['row_id']),
            'thumb' => $libraryRelPath,
            'name' => $parentTitle !== '' ? $parentTitle : sprintf('Playlist Ref #%d', $parentId),
            'flagged' => $detail !== '' ? sprintf('%s (%s)', $problem, $detail) : $problem,
            'photo_library_id' => $linkedId > 0 ? $linkedId : null,
            'rel_path' => $libraryRelPath,
            'open_path' => $openPath,
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
