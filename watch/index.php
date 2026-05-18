<?php
declare(strict_types=1);

use App\Repos\PdoPlaylistInstanceRepository;
use App\Repos\PdoPlaylistInstanceSetItemRepository;

require_once __DIR__ . '/../api/autoload.php';
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/functions/watch-config.php';

$config = loadWatchConfig($pdo ?? null);

$playlistInstanceId = (int)($config['playlist_instance_id'] ?? 0);
$repo = new PdoPlaylistInstanceRepository($pdo);
$instance = $playlistInstanceId > 0 ? $repo->getById($playlistInstanceId) : null;

if (!$instance || !$instance->isActive) {
    $setItemRepo = new PdoPlaylistInstanceSetItemRepository($pdo);
    $fallbackItems = $setItemRepo->listBySetId(3);
    foreach ($fallbackItems as $item) {
        $candidateId = (int)($item->playlistInstanceId ?? 0);
        if ($candidateId <= 0) {
            continue;
        }
        $candidate = $repo->getById($candidateId);
        if ($candidate && $candidate->isActive) {
            $playlistInstanceId = $candidateId;
            $instance = $candidate;
            break;
        }
    }
}

if (!$instance || !$instance->isActive) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Watch link not configured yet.";
    exit;
}

/**
 * Build query string with configured defaults first, then preserve inbound params.
 *
 * @param array<string, mixed> $source
 */
function appendQuery(array $source, array &$entries): void
{
    foreach ($source as $key => $value) {
        $name = trim((string)$key);
        if ($name === '') continue;
        if ($value === null || $value === '') continue;
        $entries[$name] = (string)$value;
    }
}

$queryEntries = [];
appendQuery((array)($config['query'] ?? []), $queryEntries);
appendQuery($_GET, $queryEntries);

unset($queryEntries['id']);

$query = http_build_query($queryEntries);
$baseUrl = 'https://colorfix.terrymarr.com';
$pathId = trim((string)($instance->slug ?? ''));
if ($pathId === '') {
    $pathId = (string)$playlistInstanceId;
}
$target = $baseUrl . '/playlist/' . rawurlencode($pathId) . ($query !== '' ? ('?' . $query) : '');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: ' . $target, true, 302);
exit;
