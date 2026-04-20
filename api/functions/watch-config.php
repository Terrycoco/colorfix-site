<?php
declare(strict_types=1);

use App\Repos\PdoAppConfigRepository;

function watchConfigPhpPath(): string
{
    return dirname(__DIR__, 2) . '/config/watch.php';
}

function watchConfigJsonPath(): string
{
    return dirname(__DIR__) . '/data/watch-config.json';
}

/**
 * @return array{playlist_instance_id:int,query:array<string,string>}
 */
function loadWatchConfig(?PDO $pdo = null): array
{
    if ($pdo instanceof PDO) {
        try {
            $repo = new PdoAppConfigRepository($pdo);
            $data = $repo->getJson('watch');
            if (is_array($data)) {
                return [
                    'playlist_instance_id' => (int)($data['playlist_instance_id'] ?? 0),
                    'query' => is_array($data['query'] ?? null) ? $data['query'] : [],
                ];
            }
        } catch (\Throwable) {
            // Fall through to file-backed config until the migration exists everywhere.
        }
    }

    $jsonPath = watchConfigJsonPath();
    if (is_file($jsonPath)) {
        $raw = @file_get_contents($jsonPath);
        $data = json_decode((string)$raw, true);
        if (is_array($data)) {
            return [
                'playlist_instance_id' => (int)($data['playlist_instance_id'] ?? 0),
                'query' => is_array($data['query'] ?? null) ? $data['query'] : [],
            ];
        }
    }

    $phpPath = watchConfigPhpPath();
    $data = is_file($phpPath) ? require $phpPath : [];

    return [
        'playlist_instance_id' => (int)($data['playlist_instance_id'] ?? 0),
        'query' => is_array($data['query'] ?? null) ? $data['query'] : [],
    ];
}

/**
 * @param array<string,string> $query
 */
function saveWatchConfig(int $playlistInstanceId, array $query = [], ?PDO $pdo = null): bool
{
    if ($pdo instanceof PDO) {
        try {
            $repo = new PdoAppConfigRepository($pdo);
            $repo->setJson('watch', [
                'playlist_instance_id' => $playlistInstanceId,
                'query' => $query,
            ]);
            return true;
        } catch (\Throwable) {
            // Fall through to file-backed config until the migration exists everywhere.
        }
    }

    $path = watchConfigJsonPath();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }

    $payload = json_encode([
        'playlist_instance_id' => $playlistInstanceId,
        'query' => $query,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if (!is_string($payload)) {
        return false;
    }

    return @file_put_contents($path, $payload . PHP_EOL, LOCK_EX) !== false;
}
