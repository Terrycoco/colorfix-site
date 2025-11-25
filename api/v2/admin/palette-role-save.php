<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

const ROLE_SEQUENCE = ['body', 'trim', 'accent'];

function json_fail(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $raw = file_get_contents('php://input') ?: '';
    $payload = json_decode($raw, true);
    if (!is_array($payload)) json_fail('Invalid JSON');

    $assetId = trim((string)($payload['asset_id'] ?? ''));
    $rolesInput = $payload['roles'] ?? null;
    if (!is_array($rolesInput)) json_fail('roles object required');

    $normalized = [];
    foreach ($rolesInput as $slug => $colorValue) {
        $slugNorm = strtolower(trim((string)$slug));
        if ($slugNorm === '') continue;
        if (is_array($colorValue) && isset($colorValue['color_id'])) {
            $colorId = (int)$colorValue['color_id'];
        } else {
            $colorId = (int)$colorValue;
        }
        if ($colorId <= 0) continue;
        $normalized[$slugNorm] = $colorId;
    }
    if (empty($normalized)) json_fail('At least one role color is required');

    // Fetch color cluster_ids
    $colorIds = array_values(array_unique(array_filter($normalized, fn($v) => $v > 0)));
    $placeholders = implode(',', array_fill(0, count($colorIds), '?'));
    $stmt = $pdo->prepare("SELECT id, cluster_id FROM colors WHERE id IN ($placeholders)");
    $stmt->execute($colorIds);
    $colorRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $colorMap = [];
    foreach ($colorRows as $row) {
        $cid = (int)$row['cluster_id'];
        $id = (int)$row['id'];
        if ($cid <= 0) json_fail("Color #$id is missing a cluster");
        $colorMap[$id] = $cid;
    }
    foreach ($colorIds as $cid) {
        if (!isset($colorMap[$cid])) json_fail("Color #$cid not found");
    }

    // Build cluster lists (ordered + sorted for hash)
    $clusterSet = [];
    $orderedClusters = [];

    $orderedSlugs = array_values(array_unique(array_merge(ROLE_SEQUENCE, array_keys($normalized))));
    foreach ($orderedSlugs as $slug) {
        if (!isset($normalized[$slug])) continue;
        $colorId = $normalized[$slug];
        $clusterId = $colorMap[$colorId];
        if (!isset($clusterSet[$clusterId])) {
            $clusterSet[$clusterId] = true;
            $orderedClusters[] = $clusterId;
        }
    }

    if (empty($orderedClusters)) json_fail('Unable to determine palette members');
    $sortedClusters = array_keys($clusterSet);
    sort($sortedClusters, SORT_NUMERIC);
    $paletteHash = md5(implode(',', $sortedClusters));

    $pdo->beginTransaction();
    try {
        // Locate or create palette
        $stmt = $pdo->prepare("SELECT id, nickname, terry_says, terry_fav FROM palettes WHERE palette_hash = ? LIMIT 1");
        $stmt->execute([$paletteHash]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        $paletteId = $existing ? (int)$existing['id'] : 0;
        $paletteMeta = $existing ? [
            'nickname'   => $existing['nickname'] ?? null,
            'terry_says' => $existing['terry_says'] ?? null,
            'terry_fav'  => isset($existing['terry_fav']) ? (int)$existing['terry_fav'] : 0,
        ] : null;

        $created = false;
        if ($paletteId <= 0) {
            $ins = $pdo->prepare("
                INSERT INTO palettes (palette_hash, size, tier, status, source_note, created_at)
                VALUES (:hash, :size, 'S', 'active', 'admin-photo-preview', NOW())
            ");
            $ins->execute([
                ':hash' => $paletteHash,
                ':size' => count($sortedClusters),
            ]);
            $paletteId = (int)$pdo->lastInsertId();
            if ($paletteId <= 0) throw new RuntimeException('Failed to create palette');
            $created = true;
            $paletteMeta = ['nickname' => null, 'terry_says' => null, 'terry_fav' => 0];
        }

        // Ensure palette_members rows
        $insMember = $pdo->prepare("
            INSERT INTO palette_members (palette_id, member_cluster_id, order_hint)
            VALUES (:pid, :cid, :hint)
            ON DUPLICATE KEY UPDATE order_hint = VALUES(order_hint)
        ");
        $hint = 0;
        foreach ($orderedClusters as $clusterId) {
            $insMember->execute([
                ':pid'  => $paletteId,
                ':cid'  => $clusterId,
                ':hint' => $hint,
            ]);
            $hint += 10;
        }

        // Map role slugs to master_roles ids
        $roleSlugs = array_keys($normalized);
        $rolePlaceholders = implode(',', array_fill(0, count($roleSlugs), '?'));
        $roleStmt = $pdo->prepare("SELECT id, slug FROM master_roles WHERE slug IN ($rolePlaceholders)");
        $roleStmt->execute($roleSlugs);
        $roleRows = $roleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $roleMap = [];
        foreach ($roleRows as $row) {
            $roleMap[strtolower($row['slug'])] = (int)$row['id'];
        }
        foreach ($roleSlugs as $slug) {
            if (!isset($roleMap[$slug])) {
                throw new RuntimeException("Unknown role slug: $slug");
            }
        }

        $insRole = $pdo->prepare("
            INSERT INTO palette_role_members (palette_id, role_id, color_id, priority)
            VALUES (:pid, :rid, :cid, :prio)
            ON DUPLICATE KEY UPDATE color_id = VALUES(color_id), priority = VALUES(priority)
        ");
        $priority = 0;
        $rolesOut = [];
        foreach ($orderedSlugs as $slug) {
            if (!isset($normalized[$slug])) continue;
            $colorId = $normalized[$slug];
            $roleId = $roleMap[$slug] ?? null;
            if (!$roleId) continue;
            $insRole->execute([
                ':pid'  => $paletteId,
                ':rid'  => $roleId,
                ':cid'  => $colorId,
                ':prio' => $priority,
            ]);
            $rolesOut[] = [
                'slug'     => $slug,
                'role_id'  => $roleId,
                'color_id' => $colorId,
            ];
            $priority += 10;
        }

        $pdo->commit();

        echo json_encode([
            'ok'           => true,
            'palette_id'   => $paletteId,
            'palette_hash' => $paletteHash,
            'created'      => $created,
            'asset_id'     => $assetId ?: null,
            'roles'        => $rolesOut,
            'meta'         => $paletteMeta,
        ], JSON_UNESCAPED_SLASHES);
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

} catch (Throwable $e) {
    json_fail('Server error: ' . $e->getMessage(), 500);
}
