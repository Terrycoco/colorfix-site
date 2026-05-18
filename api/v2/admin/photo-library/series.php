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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

try {
    $sourceType = trim((string)($_GET['source_type'] ?? ''));
    $allowed = ['progression', 'article', 'pin', 'client'];
    if ($sourceType !== '' && !in_array($sourceType, $allowed, true)) {
        respond(['ok' => false, 'error' => 'Invalid source_type'], 400);
    }

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 200;
    $limit = max(1, min(500, $limit));

    $where = [
        "photo_library.rel_path LIKE '/photos/%/%/%'",
        "photo_library.rel_path NOT LIKE '/photos/uploads/saved-palettes/%'",
    ];
    $params = [];

    if ($sourceType !== '') {
        $where[] = 'photo_library.source_type = :source_type';
        $params[':source_type'] = $sourceType;
    }

    $sql = "
        SELECT DISTINCT
               SUBSTRING_INDEX(SUBSTRING_INDEX(photo_library.rel_path, '/', 4), '/', -1) AS series_label
          FROM photo_library
         WHERE " . implode(' AND ', $where) . "
      ORDER BY series_label ASC
         LIMIT {$limit}
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, \PDO::PARAM_STR);
    }
    $stmt->execute();

    $items = [];
    while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
        $label = trim((string)($row['series_label'] ?? ''));
        if ($label === '') {
            continue;
        }
        $items[] = $label;
    }

    respond(['ok' => true, 'items' => $items]);
} catch (\Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
