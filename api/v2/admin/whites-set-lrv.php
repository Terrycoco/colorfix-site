<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

// we're in /api/v2/admin/, go up TWO levels
require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use PDO;

/**
 * POST /api/v2/admin/whites-set-lrv.php
 * Body: JSON
 * {
 *   "brand": "de",
 *   "updates": [
 *     {"name": "Trite White",   "lrv": 80.0},
 *     {"name": "Swiss Coffee",  "lrv": 84.1}
 *   ]
 * }
 *
 * Behavior:
 * - Updates ONLY colors.lrv for matching rows (brand + name).
 * - NO recalculation, NO cluster changes, NO side effects.
 */
try {
    /** @var PDO $pdo */
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>'PDO missing'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $raw  = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Expected JSON body'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $brand   = trim((string)($data['brand'] ?? ''));
    $updates = $data['updates'] ?? null;

    if ($brand === '' || !is_array($updates) || !$updates) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'Provide "brand" and non-empty "updates" array'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $sel = $pdo->prepare("SELECT id FROM colors WHERE brand = ? AND name = ? LIMIT 1");
    $upd = $pdo->prepare("UPDATE colors SET lrv = :lrv WHERE brand = :brand AND name = :name LIMIT 1");

    $results = [];
    $updated = 0;

    foreach ($updates as $u) {
        $name = isset($u['name']) ? trim((string)$u['name']) : '';
        $lrv  = isset($u['lrv'])  ? (float)$u['lrv']       : null;

        // Basic validation: require name + finite LRV (optionally clamp 0..100)
        if ($name === '' || $lrv === null || !is_finite($lrv)) {
            $results[] = ['name'=>$name, 'ok'=>false, 'reason'=>'bad name or lrv'];
            continue;
        }
        // Optional clamp (uncomment if you want strict bounds)
        // $lrv = max(0.0, min(100.0, $lrv));

        $sel->execute([$brand, $name]);
        $id = (int)$sel->fetchColumn();

        if ($id <= 0) {
            $results[] = ['name'=>$name, 'ok'=>false, 'reason'=>'not found for brand'];
            continue;
        }

        $upd->execute([':lrv'=>$lrv, ':brand'=>$brand, ':name'=>$name]);
        $ok = ($upd->rowCount() > 0);

        if ($ok) $updated++;
        $results[] = ['id'=>$id, 'name'=>$name, 'ok'=>$ok, 'lrv'=>$lrv];
    }

    echo json_encode([
        'ok'      => true,
        'brand'   => $brand,
        'updated' => $updated,
        'results' => $results,
        'note'    => 'LRVs saved. No recalculation performed.',
    ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES);
}
