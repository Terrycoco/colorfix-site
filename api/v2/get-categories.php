<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\Repos\PdoCategoryRepository;

try {
    // Optional: ?types=hue,neutral (default = all four, same as v1’s “all”)
    $typesParam = isset($_GET['types']) ? trim((string)$_GET['types']) : '';
    $types = array_values(array_filter(array_map('trim', explode(',', $typesParam))));
    $types = array_map('strtolower', $types);

    if (empty($types)) {
        $types = ['hue','neutral','lightness','chroma'];
    }

    $repo = new PdoCategoryRepository($pdo);
    $rows = $repo->fetchByTypes($types);

    // v1-compatible response (your AppState helper already handles both raw and wrapped)
    echo json_encode([
        'status' => 'success',
        'data'   => $rows,
    ], JSON_UNESCAPED_SLASHES);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
