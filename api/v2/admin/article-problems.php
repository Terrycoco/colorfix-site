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
            'hero' AS problem_type,
            a.id AS article_id,
            a.title AS article_title,
            a.hero_asset_id AS photo_library_id,
            pl.photo_library_id AS joined_photo_library_id,
            pl.rel_path AS library_rel_path
        FROM articles a
        LEFT JOIN photo_library pl
          ON pl.photo_library_id = a.hero_asset_id
        WHERE a.hero_asset_id IS NOT NULL

        UNION ALL

        SELECT
            'mobile_hero' AS problem_type,
            a.id AS article_id,
            a.title AS article_title,
            a.hero_mobile_asset_id AS photo_library_id,
            pl.photo_library_id AS joined_photo_library_id,
            pl.rel_path AS library_rel_path
        FROM articles a
        LEFT JOIN photo_library pl
          ON pl.photo_library_id = a.hero_mobile_asset_id
        WHERE a.hero_mobile_asset_id IS NOT NULL

        UNION ALL

        SELECT
            'section' AS problem_type,
            a.id AS article_id,
            a.title AS article_title,
            s.asset_id AS photo_library_id,
            pl.photo_library_id AS joined_photo_library_id,
            pl.rel_path AS library_rel_path
        FROM article_sections s
        JOIN articles a
          ON a.id = s.article_id
        LEFT JOIN photo_library pl
          ON pl.photo_library_id = s.asset_id
        WHERE s.asset_id IS NOT NULL
        ORDER BY article_title ASC, article_id ASC, problem_type ASC
    SQL;

    $stmt = $pdo->query($sql);
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $items = [];

    foreach ($rows as $row) {
        $linkedId = (int)($row['photo_library_id'] ?? 0);
        $joinedId = (int)($row['joined_photo_library_id'] ?? 0);
        $libraryRelPath = (string)($row['library_rel_path'] ?? '');
        $problem = '';

        if ($linkedId <= 0) {
            $problem = 'Article asset missing photo_library_id';
        } elseif ($joinedId <= 0) {
            $problem = 'Linked photo_library row does not exist';
        } elseif (trim($libraryRelPath) === '') {
            $problem = 'Linked photo_library row has no file path';
        } elseif (!library_path_exists($libraryRelPath)) {
            $problem = 'Linked photo_library file is missing';
        }

        if ($problem === '') {
            continue;
        }

        $problemType = (string)($row['problem_type'] ?? '');
        $detail = match ($problemType) {
            'hero' => 'Hero image',
            'mobile_hero' => 'Mobile hero image',
            default => 'Section image',
        };

        $items[] = [
            'key' => sprintf('%s:%d:%d', $problemType, (int)$row['article_id'], $linkedId),
            'thumb' => $libraryRelPath,
            'name' => trim((string)($row['article_title'] ?? '')) ?: sprintf('Article #%d', (int)$row['article_id']),
            'flagged' => sprintf('%s (%s)', $problem, $detail),
            'photo_library_id' => $linkedId > 0 ? $linkedId : null,
            'rel_path' => $libraryRelPath,
            'open_path' => '/admin/articles',
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
