<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoAppliedPaletteRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'POST required']);
        exit;
    }
    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Invalid JSON');
    }
    $paletteId = isset($payload['palette_id']) ? (int)$payload['palette_id'] : 0;
    if ($paletteId <= 0) {
        throw new InvalidArgumentException('palette_id required');
    }

    $title = array_key_exists('title', $payload) ? trim((string)$payload['title']) : null;
    $notes = array_key_exists('notes', $payload) ? trim((string)$payload['notes']) : null;
    $tags = normalizeTags($payload['tags'] ?? null);

    $repo = new PdoAppliedPaletteRepository($pdo);
    if (!$repo->findById($paletteId)) {
        throw new RuntimeException('Palette not found');
    }

    $fields = [];
    if ($title !== null) $fields['title'] = $title === '' ? null : $title;
    if ($notes !== null) $fields['notes'] = $notes === '' ? null : $notes;
    if (array_key_exists('tags', $payload)) $fields['tags'] = $tags;

    if ($fields) {
        $repo->updatePalette($paletteId, $fields);
    }

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}

function normalizeTags($input): ?string {
    if ($input === null || $input === '') return null;
    if (is_array($input)) {
        $sanitized = array_filter(array_map(function ($tag) {
            return trim((string)$tag);
        }, $input), fn($val) => $val !== '');
        if (!$sanitized) return null;
        return implode(',', array_slice(array_unique($sanitized), 0, 40));
    }
    $parts = array_filter(array_map('trim', preg_split('/[,;]/', (string)$input)));
    if (!$parts) return null;
    $unique = array_slice(array_unique($parts), 0, 40);
    return implode(',', $unique);
}
