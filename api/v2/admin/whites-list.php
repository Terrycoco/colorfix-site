<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

// we're in /api/v2/admin/
require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use PDO;

/**
 * GET /api/v2/admin/whites-list.php?brand=de
 * Source: colors table (not swatch_view)
 * Filter: neutral_cats LIKE '%Whites%' (exact casing per your data)
 * Returns: { brand, count, items: [{id,name,code,hex6,lrv}] } in alpha order by name
 */
try {
    /** @var PDO $pdo */
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) throw new RuntimeException('PDO missing');

    $brand = isset($_GET['brand']) ? trim((string)$_GET['brand']) : '';
    if ($brand === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Provide ?brand=<brand_code>'], JSON_PRETTY_PRINT);
        exit;
    }

    // If you ever need case-insensitive matching regardless of collation, swap the WHERE with:
    // WHERE brand = ? AND LOWER(COALESCE(neutral_cats,'')) LIKE '%whites%'
    $sql = "
      SELECT id, name, code, hex6, lrv
      FROM colors
      WHERE brand = ?
        AND COALESCE(neutral_cats,'') LIKE '%Whites%'
      ORDER BY name ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$brand]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'brand' => $brand,
        'count' => count($rows),
        'items' => $rows
    ], JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
