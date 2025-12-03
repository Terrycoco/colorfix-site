<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function normalize_tags($value): array {
    $tags = [];
    if (is_string($value)) {
        $parts = preg_split('/[,\|]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
        $tags = $parts ?: [];
    } elseif (is_array($value)) {
        $tags = $value;
    } else {
        return [];
    }
    $out = [];
    foreach ($tags as $tag) {
        $t = strtolower(trim((string)$tag));
        if ($t !== '') $out[$t] = true;
    }
    return array_keys($out);
}

try {
    $input = [];
    if (($_SERVER['CONTENT_TYPE'] ?? '') !== '' && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $input = $decoded;
        }
    }

    $tags = normalize_tags($input['tags'] ?? $_POST['tags'] ?? $_GET['tags'] ?? []);
    $favOnly = (int)($input['favorites_only'] ?? $_POST['favorites_only'] ?? $_GET['favorites_only'] ?? 0) ? 1 : 0;
    $limit = (int)($input['limit'] ?? $_POST['limit'] ?? $_GET['limit'] ?? 30);
    $limit = max(1, min(60, $limit));
    $q = trim((string)($input['q'] ?? $_POST['q'] ?? $_GET['q'] ?? ''));
    $bodyFamilyFilter = trim((string)($input['body_family'] ?? $_POST['body_family'] ?? $_GET['body_family'] ?? ''));

    $roleStmt = $pdo->prepare("SELECT slug, id FROM master_roles WHERE slug IN ('body','trim','accent')");
    $roleStmt->execute();
    $roleRows = $roleStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    $bodyRoleId = (int)($roleRows['body'] ?? 0);
    $trimRoleId = (int)($roleRows['trim'] ?? 0);
    $accentRoleId = (int)($roleRows['accent'] ?? 0);

    if (!$bodyRoleId) respond(['ok' => false, 'error' => 'Body role not configured'], 500);

    $params = [
        ':limit' => $limit,
        ':favOnly' => $favOnly,
        ':q' => $q,
        ':like1' => '%' . $q . '%',
        ':like2' => '%' . $q . '%',
        ':body_role_id' => $bodyRoleId,
        ':body_family_filter_any' => $bodyFamilyFilter,
        ':body_family_filter_match' => $bodyFamilyFilter,
    ];

    $tagJoin = '';
    if ($tags) {
        $placeholders = [];
        foreach ($tags as $idx => $tag) {
            $ph = ':tag' . $idx;
            $placeholders[] = $ph;
            $params[$ph] = $tag;
        }
        $tagList = implode(',', $placeholders);
        $tagJoin = "
            LEFT JOIN (
                SELECT pt.palette_id, COUNT(*) AS tag_hits
                FROM palette_tags pt
                WHERE pt.tag IN ($tagList)
                GROUP BY pt.palette_id
            ) tag_match ON tag_match.palette_id = p.id
        ";
    } else {
        $tagJoin = "LEFT JOIN (SELECT 0 AS tag_hits, 0 AS palette_id) tag_match ON tag_match.palette_id = p.id";
    }

    $bodyFamilyExpr = "TRIM(SUBSTRING_INDEX(
        CASE
          WHEN c_body.neutral_cats IS NOT NULL AND c_body.neutral_cats <> '' THEN c_body.neutral_cats
          WHEN c_body.hue_cats IS NOT NULL AND c_body.hue_cats <> '' THEN c_body.hue_cats
          ELSE 'unclassified'
        END,
        ',', 1
    ))";

    $sql = "
        SELECT
            p.id,
            p.nickname,
            p.terry_fav,
            p.size,
            COALESCE(tag_match.tag_hits, 0) AS tag_hits,
            $bodyFamilyExpr AS body_family
        FROM palettes p
        $tagJoin
        LEFT JOIN palette_role_members pr_body ON pr_body.palette_id = p.id AND pr_body.role_id = :body_role_id
        LEFT JOIN colors c_body ON c_body.id = pr_body.color_id
        WHERE p.status <> 'hidden'
          AND (:favOnly = 0 OR p.terry_fav = 1)
          AND (
            :q = '' OR
            (p.nickname IS NOT NULL AND p.nickname LIKE :like1) OR
            EXISTS (SELECT 1 FROM palette_tags pt2 WHERE pt2.palette_id = p.id AND pt2.tag LIKE :like2)
          )
        GROUP BY p.id, p.nickname, p.terry_fav, p.size, body_family, tag_match.tag_hits
        HAVING (:body_family_filter_any = '' OR body_family = :body_family_filter_match)
        ORDER BY tag_hits DESC, p.terry_fav DESC, body_family ASC, p.id DESC
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($key, $value, $type);
    }
    $stmt->execute();
    $palettes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$palettes) {
        respond(['ok' => true, 'items' => [], 'body_families' => []]);
    }

    $paletteIds = array_map(fn($row) => (int)$row['id'], $palettes);
    $idPlaceholders = implode(',', array_fill(0, count($paletteIds), '?'));

    // Fetch tags
    $tagsStmt = $pdo->prepare("SELECT palette_id, tag FROM palette_tags WHERE palette_id IN ($idPlaceholders)");
    $tagsStmt->execute($paletteIds);
    $tagRows = $tagsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $tagsByPalette = [];
    foreach ($tagRows as $row) {
        $pid = (int)$row['palette_id'];
        $tagsByPalette[$pid][] = $row['tag'];
    }

    // Fetch role assignments
    $roleStmt2 = $pdo->prepare("
        SELECT
            prm.palette_id,
            mr.slug,
            c.id AS color_id,
            c.name,
            c.brand,
            c.code,
            c.hex6,
            c.neutral_cats,
            c.hue_cats,
            c.chip_num,
            c.brand,
            c.hcl_l,
            c.lab_l
        FROM palette_role_members prm
        JOIN master_roles mr ON mr.id = prm.role_id
        JOIN colors c ON c.id = prm.color_id
        WHERE prm.palette_id IN ($idPlaceholders)
    ");
    $roleStmt2->execute($paletteIds);
    $roleRows = $roleStmt2->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rolesByPalette = [];
    foreach ($roleRows as $row) {
        $pid = (int)$row['palette_id'];
        $slug = strtolower((string)$row['slug']);
        $rolesByPalette[$pid][$slug] = [
            'color_id' => (int)$row['color_id'],
            'name' => $row['name'],
            'brand' => $row['brand'],
            'code' => $row['code'],
            'chip_num' => $row['chip_num'],
            'hex6' => strtoupper((string)$row['hex6']),
            'neutral_cats' => $row['neutral_cats'],
            'hue_cats' => $row['hue_cats'],
            'hcl_l' => isset($row['hcl_l']) ? (float)$row['hcl_l'] : null,
            'lab_l' => isset($row['lab_l']) ? (float)$row['lab_l'] : null,
        ];
    }

    $bodyFamiliesSet = [];
    $items = [];
    foreach ($palettes as $row) {
        $pid = (int)$row['id'];
        $bodyFamily = $row['body_family'] ?: 'unclassified';
        $bodyFamiliesSet[$bodyFamily] = true;
        $items[] = [
            'palette_id' => $pid,
            'nickname' => $row['nickname'],
            'terry_fav' => (int)$row['terry_fav'],
            'tag_hits' => (int)$row['tag_hits'],
            'body_family' => $bodyFamily,
            'tags' => $tagsByPalette[$pid] ?? [],
            'roles' => $rolesByPalette[$pid] ?? [],
        ];
    }

    ksort($bodyFamiliesSet, SORT_NATURAL | SORT_FLAG_CASE);
    respond([
        'ok' => true,
        'items' => $items,
        'body_families' => array_keys($bodyFamiliesSet),
    ]);

} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
