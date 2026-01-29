<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoPlaylistInstanceSetRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';

$repo = new PdoPlaylistInstanceSetRepository($pdo);
$sets = $repo->listAll();

if ($q !== '') {
    $needle = strtolower($q);
    $sets = array_values(array_filter($sets, static function ($set) use ($needle) {
        $id = (string)($set->id ?? '');
        $handle = strtolower($set->handle ?? '');
        $title = strtolower($set->title ?? '');
        $subtitle = strtolower((string)($set->subtitle ?? ''));
        $context = strtolower((string)($set->context ?? ''));
        $haystack = $handle . ' ' . $title . ' ' . $subtitle . ' ' . $context;
        return str_contains($id, $needle) || str_contains($haystack, $needle);
    }));
}

$items = array_map(static function ($set) {
    return [
        'id' => $set->id,
        'handle' => $set->handle,
        'title' => $set->title,
        'subtitle' => $set->subtitle,
        'context' => $set->context,
    ];
}, $sets);

respond(['ok' => true, 'items' => $items]);
