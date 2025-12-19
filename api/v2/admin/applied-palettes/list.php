<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoAppliedPaletteRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'GET only']);
        exit;
    }

    $repo = new PdoAppliedPaletteRepository($pdo);
    $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 40;
    $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    $rows = $repo->listPalettes(['q' => $q], $limit, $offset);

    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 4), '/');
    $publicRoot = rtrim(dirname(__DIR__, 4) . '/public', '/');
    foreach ($rows as &$row) {
        $id = (int)$row['id'];
        $renderRel = "/photos/rendered/ap_{$id}.jpg";
        $thumbRel = "/photos/rendered/ap_{$id}-thumb.jpg";
        $renderAbs = is_file($docRoot . $renderRel) ? $docRoot . $renderRel : $publicRoot . $renderRel;
        $thumbAbs = is_file($docRoot . $thumbRel) ? $docRoot . $thumbRel : $publicRoot . $thumbRel;
        $row['view_url'] = '/view/' . $id;
        $row['render_rel_path'] = is_file($renderAbs) ? $renderRel : null;
        $row['render_thumb_rel_path'] = is_file($thumbAbs) ? $thumbRel : null;
    }
    unset($row);

    echo json_encode([
        'ok' => true,
        'items' => $rows,
        'meta' => [
            'limit' => $limit,
            'offset' => $offset,
            'count' => count($rows),
        ],
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
