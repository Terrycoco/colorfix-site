<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoPhotoLibraryRepository;

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

    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 4)), '/');
    $primary = $docRoot . $cleanPath;
    if (is_file($primary)) {
        return true;
    }

    $fallback = dirname(__DIR__, 4) . $cleanPath;
    return is_file($fallback);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        respond(['ok' => false, 'error' => 'GET only'], 405);
    }

    $issuesOnly = !isset($_GET['issues_only']) || $_GET['issues_only'] !== '0';
    $brokenUsedOnly = !empty($_GET['broken_used_only']) && $_GET['broken_used_only'] !== '0';
    $libraryFixList = !empty($_GET['library_fix_list']) && $_GET['library_fix_list'] !== '0';
    $includeUsages = $libraryFixList || (!empty($_GET['include_usages']) && $_GET['include_usages'] !== '0');
    $limit = isset($_GET['limit']) ? max(1, min(1000, (int)$_GET['limit'])) : 1000;
    $photoRepo = new PdoPhotoLibraryRepository($pdo);

    $sql = <<<SQL
        SELECT
            pl.photo_library_id,
            pl.source_type,
            pl.source_id,
            pl.client_id,
            pl.rel_path,
            pl.title,
            pl.tags,
            pl.alt_text,
            pl.note,
            pl.updated_at,
            (
              SELECT COUNT(*) FROM playlist_items pi
              WHERE pi.photo_library_id = pl.photo_library_id
            ) AS playlist_usage_count,
            (
              SELECT COUNT(*) FROM playlist_instance_set_items psi
              WHERE psi.photo_library_id = pl.photo_library_id
            ) AS playlist_set_usage_count,
            (
              SELECT COUNT(*) FROM playlist_instances pinst
              WHERE pinst.intro_image_url = CONCAT('photo:', pl.photo_library_id)
                 OR pinst.intro_image_url LIKE CONCAT('photo:', pl.photo_library_id, '|%')
            ) AS playlist_instance_intro_usage_count,
            (
              SELECT COUNT(*) FROM playlist_instances pinst
              WHERE pinst.share_image_url = CONCAT('photo:', pl.photo_library_id)
                 OR pinst.share_image_url LIKE CONCAT('photo:', pl.photo_library_id, '|%')
            ) AS playlist_instance_share_usage_count,
            (
              SELECT COUNT(*) FROM saved_palette_set_photos spsp
              WHERE spsp.photo_library_id = pl.photo_library_id
            ) AS viewer_usage_count,
            (
              SELECT COUNT(*) FROM article_sections ars
              WHERE ars.asset_id = pl.photo_library_id
            ) AS article_section_usage_count,
            (
              SELECT COUNT(*) FROM articles a
              WHERE a.hero_asset_id = pl.photo_library_id
                 OR a.hero_mobile_asset_id = pl.photo_library_id
            ) AS article_hero_usage_count,
            (
              SELECT COUNT(*) FROM photo_group_items pgi
              WHERE pgi.photo_library_id = pl.photo_library_id
            ) AS photo_group_usage_count,
            (
              SELECT COUNT(*) FROM photo_library dup
              WHERE dup.rel_path = pl.rel_path
            ) AS duplicate_rel_path_count
        FROM photo_library pl
        WHERE pl.rel_path IS NOT NULL
          AND pl.rel_path <> ''
        ORDER BY pl.updated_at DESC, pl.photo_library_id DESC
        LIMIT :limit
    SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    $summary = [
        'checked' => 0,
        'broken_file_count' => 0,
        'duplicate_rel_path_count' => 0,
        'orphan_count' => 0,
        'playlist_usage_rows' => 0,
        'viewer_usage_rows' => 0,
        'broken_used_count' => 0,
    ];

    foreach ($rows as $row) {
        $summary['checked']++;

        $playlistUsageCount = (int)($row['playlist_usage_count'] ?? 0);
        $playlistSetUsageCount = (int)($row['playlist_set_usage_count'] ?? 0);
        $playlistInstanceIntroUsageCount = (int)($row['playlist_instance_intro_usage_count'] ?? 0);
        $playlistInstanceShareUsageCount = (int)($row['playlist_instance_share_usage_count'] ?? 0);
        $viewerUsageCount = (int)($row['viewer_usage_count'] ?? 0);
        $articleSectionUsageCount = (int)($row['article_section_usage_count'] ?? 0);
        $articleHeroUsageCount = (int)($row['article_hero_usage_count'] ?? 0);
        $photoGroupUsageCount = (int)($row['photo_group_usage_count'] ?? 0);
        $duplicateRelPathCount = (int)($row['duplicate_rel_path_count'] ?? 0);
        $usageCount = $playlistUsageCount
            + $playlistSetUsageCount
            + $playlistInstanceIntroUsageCount
            + $playlistInstanceShareUsageCount
            + $viewerUsageCount
            + $articleSectionUsageCount
            + $articleHeroUsageCount
            + $photoGroupUsageCount;
        $rawRelPath = (string)($row['rel_path'] ?? '');
        $fileExists = library_path_exists($rawRelPath);
        $isOrphan = $usageCount === 0;
        $hasIssue = !$fileExists || $duplicateRelPathCount > 1 || $isOrphan;

        if (!$fileExists) {
            $summary['broken_file_count']++;
        }
        if ($duplicateRelPathCount > 1) {
            $summary['duplicate_rel_path_count']++;
        }
        if ($isOrphan) {
            $summary['orphan_count']++;
        }
        if ($playlistUsageCount > 0 || $playlistSetUsageCount > 0 || $playlistInstanceIntroUsageCount > 0 || $playlistInstanceShareUsageCount > 0) {
            $summary['playlist_usage_rows']++;
        }
        if ($viewerUsageCount > 0) {
            $summary['viewer_usage_rows']++;
        }
        if (!$fileExists && $usageCount > 0) {
            $summary['broken_used_count']++;
        }

        if (($brokenUsedOnly || $libraryFixList) && ($fileExists || $usageCount <= 0)) {
            continue;
        }

        if (!$brokenUsedOnly && !$libraryFixList && $issuesOnly && !$hasIssue) {
            continue;
        }

        $usages = $includeUsages ? $photoRepo->getUsageSummaryForPhoto((int)$row['photo_library_id']) : [];

        $items[] = [
            'photo_library_id' => (int)$row['photo_library_id'],
            'source_type' => (string)($row['source_type'] ?? ''),
            'source_id' => $row['source_id'] !== null ? (int)$row['source_id'] : null,
            'client_id' => $row['client_id'] !== null ? (int)$row['client_id'] : null,
            'title' => (string)($row['title'] ?? ''),
            'rel_path' => $rawRelPath,
            'updated_at' => $row['updated_at'] ?? null,
            'file_exists' => $fileExists,
            'duplicate_rel_path_count' => $duplicateRelPathCount,
            'playlist_usage_count' => $playlistUsageCount,
            'playlist_set_usage_count' => $playlistSetUsageCount,
            'playlist_instance_intro_usage_count' => $playlistInstanceIntroUsageCount,
            'playlist_instance_share_usage_count' => $playlistInstanceShareUsageCount,
            'viewer_usage_count' => $viewerUsageCount,
            'article_section_usage_count' => $articleSectionUsageCount,
            'article_hero_usage_count' => $articleHeroUsageCount,
            'photo_group_usage_count' => $photoGroupUsageCount,
            'total_usage_count' => $usageCount,
            'is_orphan' => $isOrphan,
            'fix_in_library' => !$fileExists && $usageCount > 0,
            'usages' => $usages,
        ];
    }

    if ($libraryFixList) {
        usort($items, static function (array $a, array $b): int {
            $usageCmp = ($b['total_usage_count'] ?? 0) <=> ($a['total_usage_count'] ?? 0);
            if ($usageCmp !== 0) {
                return $usageCmp;
            }
            return ($a['photo_library_id'] ?? 0) <=> ($b['photo_library_id'] ?? 0);
        });
    }

    respond([
        'ok' => true,
        'issues_only' => $issuesOnly,
        'broken_used_only' => $brokenUsedOnly,
        'library_fix_list' => $libraryFixList,
        'include_usages' => $includeUsages,
        'result' => [
            'summary' => $summary,
            'items' => $items,
        ],
    ]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
