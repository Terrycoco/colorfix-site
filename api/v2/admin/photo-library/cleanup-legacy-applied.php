<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoPhotoLibraryRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function columnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = :table
            AND COLUMN_NAME = :column"
    );
    $stmt->execute([
        ':table' => $table,
        ':column' => $column,
    ]);
    return (int)$stmt->fetchColumn() > 0;
}

function isExactBasePath(string $relPath): bool {
    return preg_match('~/base\.jpg$~i', $relPath) === 1;
}

function appendTag(?string $tags, string $tag): string {
    $tag = trim($tag);
    $parts = array_values(array_filter(array_map(
        static fn(string $part): string => trim($part),
        explode(',', (string)$tags)
    )));
    $lower = array_map('strtolower', $parts);
    if (!in_array(strtolower($tag), $lower, true)) {
        $parts[] = $tag;
    }
    return implode(',', $parts);
}

function tagPhotoLibraryRows(PDO $pdo, array $ids, string $tag): int {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    if (!$ids) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT photo_library_id, tags FROM photo_library WHERE photo_library_id IN ({$placeholders})");
    $stmt->execute($ids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
        return 0;
    }

    $updated = 0;
    $updateStmt = $pdo->prepare("UPDATE photo_library SET tags = :tags, updated_at = NOW() WHERE photo_library_id = :id");
    foreach ($rows as $row) {
        $before = (string)($row['tags'] ?? '');
        $after = appendTag($before, $tag);
        if ($after === $before) {
            continue;
        }
        $updateStmt->execute([
            ':id' => (int)$row['photo_library_id'],
            ':tags' => $after,
        ]);
        $updated += $updateStmt->rowCount();
    }
    return $updated;
}

try {
    if (!in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'POST'], true)) {
        respond(['ok' => false, 'error' => 'GET or POST only'], 405);
    }

    $commit = (string)($_GET['commit'] ?? $_POST['commit'] ?? '0') === '1';
    $tagOriginals = (string)($_GET['tag_originals'] ?? $_POST['tag_originals'] ?? '0') === '1';
    $tagCandidates = (string)($_GET['tag_candidates'] ?? $_POST['tag_candidates'] ?? '0') === '1';
    $tagOnly = (string)($_GET['tag_only'] ?? $_POST['tag_only'] ?? '0') === '1';
    $defaultTag = $tagCandidates ? 'ap-cleanup' : 'ap';
    $cleanupTag = trim((string)($_GET['tag'] ?? $_POST['tag'] ?? $defaultTag));
    if ($cleanupTag === '') {
        $cleanupTag = $defaultTag;
    }
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : (isset($_POST['limit']) ? (int)$_POST['limit'] : 5000);
    $limit = max(1, min(20000, $limit));

    $repo = new PdoPhotoLibraryRepository($pdo);
    $hasRetired = columnExists($pdo, 'photo_library', 'is_retired');
    $hasInactive = columnExists($pdo, 'photo_library', 'is_inactive');

    $candidateStmt = $pdo->prepare(
        "SELECT photo_library_id, source_type, source_id, rel_path, title, show_in_gallery, has_palette"
        . ($hasInactive ? ", is_inactive" : ", 0 AS is_inactive")
        . ($hasRetired ? ", is_retired" : ", 0 AS is_retired") . "
           FROM photo_library
          WHERE (
                source_type IN ('applied_palette', 'applied_palette_photo', 'applied_before')
                OR rel_path LIKE '/photos/rendered/ap_%'
          )
            AND rel_path NOT REGEXP '/base\\.jpg$'
          ORDER BY photo_library_id ASC
          LIMIT {$limit}"
    );
    $candidateStmt->execute();
    $legacyAppliedRows = $candidateStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $dupeStmt = $pdo->query(
        "SELECT rel_path, GROUP_CONCAT(photo_library_id ORDER BY photo_library_id ASC) AS ids, COUNT(*) AS count
           FROM photo_library
          WHERE rel_path REGEXP '/base\\.jpg$'
          GROUP BY rel_path
         HAVING COUNT(*) > 1
          ORDER BY count DESC, rel_path ASC"
    );
    $baseDuplicateGroups = [];
    foreach (($dupeStmt ? $dupeStmt->fetchAll(PDO::FETCH_ASSOC) : []) ?: [] as $group) {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)$group['ids']))));
        if (count($ids) < 2) {
            continue;
        }
        $keeperId = $ids[0];
        $duplicateIds = array_slice($ids, 1);
        $baseDuplicateGroups[] = [
            'rel_path' => (string)$group['rel_path'],
            'keeper_photo_library_id' => $keeperId,
            'duplicate_photo_library_ids' => $duplicateIds,
            'count' => (int)$group['count'],
        ];
    }

    $variantStmt = $pdo->query(
        "SELECT photo_library_id, source_type, source_id, rel_path, title, show_in_gallery, has_palette"
        . ($hasInactive ? ", is_inactive" : ", 0 AS is_inactive")
        . ($hasRetired ? ", is_retired" : ", 0 AS is_retired") . "
           FROM photo_library
          WHERE rel_path REGEXP '/prepared/base_[A-Za-z0-9]+\\.jpg$'
          ORDER BY rel_path ASC, photo_library_id ASC
          LIMIT 500"
    );
    $hashedBaseVariants = $variantStmt ? ($variantStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $baseOriginalStmt = $pdo->prepare(
        "SELECT photo_library_id, source_type, source_id, rel_path, title, tags, show_in_gallery, has_palette"
        . ($hasInactive ? ", is_inactive" : ", 0 AS is_inactive")
        . ($hasRetired ? ", is_retired" : ", 0 AS is_retired") . "
           FROM photo_library
          WHERE rel_path REGEXP '/base\\.jpg$'
          ORDER BY rel_path ASC, photo_library_id ASC
          LIMIT {$limit}"
    );
    $baseOriginalStmt->execute();
    $baseOriginalRows = $baseOriginalStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $baseOriginalRowsToTag = array_values(array_filter(
        $baseOriginalRows,
        static fn(array $row): bool => !in_array(strtolower($cleanupTag), array_map(
            'trim',
            explode(',', strtolower((string)($row['tags'] ?? '')))
        ), true)
    ));

    $playlistItemStmt = $pdo->query(
        "SELECT playlist_item_id, playlist_id, order_index, ap_id, palette_hash, image_url, photo_library_id, saved_palette_set_id, title, item_type
           FROM playlist_items
          WHERE ap_id IS NOT NULL
            AND ap_id <> 0
          ORDER BY playlist_id ASC, order_index ASC, playlist_item_id ASC
          LIMIT {$limit}"
    );
    $legacyPlaylistItems = $playlistItemStmt ? ($playlistItemStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    $legacyPlaylistItemsToRetire = [];
    $legacyPlaylistItemsToClearAp = [];
    foreach ($legacyPlaylistItems as $row) {
        $hasModernReference = trim((string)($row['palette_hash'] ?? '')) !== ''
            || (int)($row['photo_library_id'] ?? 0) > 0
            || (int)($row['saved_palette_set_id'] ?? 0) > 0
            || trim((string)($row['image_url'] ?? '')) !== '';
        if ($hasModernReference) {
            $legacyPlaylistItemsToClearAp[] = $row;
        } else {
            $legacyPlaylistItemsToRetire[] = $row;
        }
    }

    $baseDuplicatePhotoIdsToRetire = [];
    foreach ($baseDuplicateGroups as $group) {
        foreach ($group['duplicate_photo_library_ids'] as $duplicateId) {
            $baseDuplicatePhotoIdsToRetire[] = (int)$duplicateId;
        }
    }
    $cleanupCandidatePhotoIds = array_values(array_unique(array_merge(
        array_map(static fn(array $row): int => (int)$row['photo_library_id'], $legacyAppliedRows),
        $baseDuplicatePhotoIdsToRetire
    )));

    $cleanupCandidateRowsToTag = [];
    if ($cleanupCandidatePhotoIds) {
        $placeholders = implode(',', array_fill(0, count($cleanupCandidatePhotoIds), '?'));
        $candidateRowsStmt = $pdo->prepare(
            "SELECT photo_library_id, source_type, source_id, rel_path, title, tags, show_in_gallery, has_palette"
            . ($hasInactive ? ", is_inactive" : ", 0 AS is_inactive")
            . ($hasRetired ? ", is_retired" : ", 0 AS is_retired") . "
               FROM photo_library
              WHERE photo_library_id IN ({$placeholders})
              ORDER BY photo_library_id ASC"
        );
        $candidateRowsStmt->execute($cleanupCandidatePhotoIds);
        $cleanupCandidateRowsToTag = array_values(array_filter(
            $candidateRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            static fn(array $row): bool => !in_array(strtolower($cleanupTag), array_map(
                'trim',
                explode(',', strtolower((string)($row['tags'] ?? '')))
            ), true)
        ));
    }

    $applied = [
        'retired_legacy_applied_rows' => 0,
        'merged_base_duplicate_rows' => 0,
        'tagged_base_original_rows' => 0,
        'tagged_cleanup_candidate_rows' => 0,
        'retired_legacy_playlist_items' => 0,
        'cleared_legacy_playlist_ap_ids' => 0,
    ];

    if ($commit) {
        $pdo->beginTransaction();
        try {
            if (!$tagOnly && $legacyAppliedRows) {
                $ids = array_map(static fn(array $row): int => (int)$row['photo_library_id'], $legacyAppliedRows);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $sets = ['show_in_gallery = 0', 'has_palette = 0', 'updated_at = NOW()'];
                if ($hasInactive) {
                    $sets[] = 'is_inactive = 1';
                }
                if ($hasRetired) {
                    $sets[] = 'is_retired = 1';
                }
                $stmt = $pdo->prepare("UPDATE photo_library SET " . implode(', ', $sets) . " WHERE photo_library_id IN ({$placeholders})");
                $stmt->execute($ids);
                $applied['retired_legacy_applied_rows'] = $stmt->rowCount();
            }

            if (!$tagOnly) {
                foreach ($baseDuplicateGroups as $group) {
                    $keeperId = (int)$group['keeper_photo_library_id'];
                    foreach ($group['duplicate_photo_library_ids'] as $duplicateId) {
                        $duplicateId = (int)$duplicateId;
                        if ($duplicateId <= 0 || $duplicateId === $keeperId) {
                            continue;
                        }
                        $repo->reassignAllUsages($duplicateId, $keeperId);
                        $sets = ['show_in_gallery = 0', 'has_palette = 0', 'updated_at = NOW()'];
                        if ($hasInactive) {
                            $sets[] = 'is_inactive = 1';
                        }
                        if ($hasRetired) {
                            $sets[] = 'is_retired = 1';
                        }
                        $stmt = $pdo->prepare("UPDATE photo_library SET " . implode(', ', $sets) . " WHERE photo_library_id = ?");
                        $stmt->execute([$duplicateId]);
                        $applied['merged_base_duplicate_rows'] += $stmt->rowCount();
                    }
                }
            }

            if ($tagOriginals && $baseOriginalRowsToTag) {
                $tagStmt = $pdo->prepare("UPDATE photo_library SET tags = :tags, updated_at = NOW() WHERE photo_library_id = :id");
                foreach ($baseOriginalRowsToTag as $row) {
                    $tagStmt->execute([
                        ':id' => (int)$row['photo_library_id'],
                        ':tags' => appendTag($row['tags'] ?? null, $cleanupTag),
                    ]);
                    $applied['tagged_base_original_rows'] += $tagStmt->rowCount();
                }
            }

            if ($tagCandidates && $cleanupCandidatePhotoIds) {
                $applied['tagged_cleanup_candidate_rows'] = tagPhotoLibraryRows($pdo, $cleanupCandidatePhotoIds, $cleanupTag);
            }

            if (!$tagOnly && $legacyPlaylistItemsToClearAp) {
                $ids = array_map(static fn(array $row): int => (int)$row['playlist_item_id'], $legacyPlaylistItemsToClearAp);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("UPDATE playlist_items SET ap_id = NULL WHERE playlist_item_id IN ({$placeholders})");
                $stmt->execute($ids);
                $applied['cleared_legacy_playlist_ap_ids'] = $stmt->rowCount();
            }

            if (!$tagOnly && $legacyPlaylistItemsToRetire) {
                $ids = array_map(static fn(array $row): int => (int)$row['playlist_item_id'], $legacyPlaylistItemsToRetire);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $pdo->prepare("UPDATE playlist_items SET ap_id = NULL, is_active = 0 WHERE playlist_item_id IN ({$placeholders})");
                $stmt->execute($ids);
                $applied['retired_legacy_playlist_items'] = $stmt->rowCount();
            }

            $pdo->commit();
        } catch (Throwable $inner) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $inner;
        }
    }

    respond([
        'ok' => true,
        'commit' => $commit,
        'summary' => [
            'legacy_applied_rows_to_retire' => count($legacyAppliedRows),
            'base_duplicate_groups_to_merge' => count($baseDuplicateGroups),
            'base_duplicate_rows_to_retire' => array_sum(array_map(static fn(array $group): int => count($group['duplicate_photo_library_ids']), $baseDuplicateGroups)),
            'base_original_rows' => count($baseOriginalRows),
            'base_original_rows_to_tag' => count($baseOriginalRowsToTag),
            'cleanup_candidate_photo_rows_to_tag' => count($cleanupCandidateRowsToTag),
            'hashed_base_variants_reported_only' => count($hashedBaseVariants),
            'legacy_playlist_items_with_ap_id' => count($legacyPlaylistItems),
            'legacy_playlist_items_to_clear_ap_id' => count($legacyPlaylistItemsToClearAp),
            'legacy_playlist_items_to_retire' => count($legacyPlaylistItemsToRetire),
        ],
        'applied' => $applied,
        'tag_originals' => $tagOriginals,
        'tag_candidates' => $tagCandidates,
        'tag_only' => $tagOnly,
        'tag' => $cleanupTag,
        'legacy_applied_rows' => array_slice($legacyAppliedRows, 0, 100),
        'base_original_rows_to_tag' => array_slice($baseOriginalRowsToTag, 0, 100),
        'cleanup_candidate_rows_to_tag' => array_slice($cleanupCandidateRowsToTag, 0, 100),
        'base_duplicate_groups' => array_slice($baseDuplicateGroups, 0, 100),
        'hashed_base_variants_reported_only' => array_slice($hashedBaseVariants, 0, 100),
        'legacy_playlist_items_to_clear_ap_id' => array_slice($legacyPlaylistItemsToClearAp, 0, 100),
        'legacy_playlist_items_to_retire' => array_slice($legacyPlaylistItemsToRetire, 0, 100),
        'note' => 'Dry-run by default. commit=1 retires DB rows, merges exact base.jpg duplicate rows, clears legacy ap_id from modernized playlist items, and retires playlist items that only point to legacy AP. Add tag_candidates=1&tag_only=1 to tag cleanup candidate photo_library rows without retiring/merging. Add tag_originals=1 to append the tag to exact base.jpg originals. It does not delete physical files or retire hashed base variants.',
    ]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
