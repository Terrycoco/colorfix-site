<?php
declare(strict_types=1);

/**
 * Prebuild the ColorFix front-page payload.
 *
 * Usage:
 *   php api/tools/prebuild-front-page.php
 *   php api/tools/prebuild-front-page.php --base-url=https://colorfix.terrymarr.com
 */

$options = parseOptions($argv ?? []);
$baseUrl = rtrim((string)($options['base-url'] ?? getenv('COLORFIX_BASE_URL') ?: 'https://colorfix.terrymarr.com'), '/');
$outDir = (string)($options['out-dir'] ?? dirname(__DIR__) . '/cache');
$queryId = (int)($options['query-id'] ?? 4);
$setId = (int)($options['set-id'] ?? 9);
$variants = parseVariants((string)($options['variants'] ?? 'public,admin'));

if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Unable to create output directory: {$outDir}\n");
    exit(1);
}

$runQuery = httpJson(
    "{$baseUrl}/api/v2/run-query.php?t=" . time(),
    'POST',
    [
        'query_id' => $queryId,
        'params' => ['group_mode' => 'hue'],
        'searchFilters' => [],
    ]
);
if (empty($runQuery['success'])) {
    fwrite(STDERR, "Front-page query failed from {$baseUrl}\n");
    exit(1);
}

$playlistSet = httpJson("{$baseUrl}/api/v2/playlist-instance-sets/get.php?id={$setId}&_=" . time());
if (empty($playlistSet['ok']) || empty($playlistSet['set']['items']) || !is_array($playlistSet['set']['items'])) {
    fwrite(STDERR, "Front-page playlist set failed from {$baseUrl}\n");
    exit(1);
}

$frontPageRailItem = buildFrontPageRailItem($playlistSet['set'], $setId);
$inserts = attachFeaturedArticlePayloads($baseUrl, $runQuery['inserts'] ?? []);
$imagePreloads = collectImagePreloads($runQuery['results'] ?? [], $inserts, $frontPageRailItem);

foreach ($variants as $variant) {
    $payload = [
        'ok' => true,
        'success' => true,
        'variant' => $variant,
        'generated_at' => gmdate('c'),
        'source' => [
            'query_id' => $queryId,
            'playlist_set_id' => $setId,
            'base_url' => $baseUrl,
        ],
        'meta' => $runQuery['meta'] ?? null,
        'results' => $runQuery['results'] ?? [],
        'inserts' => $inserts,
        'frontPageRailItem' => $frontPageRailItem,
        'image_preloads' => $imagePreloads,
        'rowCount' => count($runQuery['results'] ?? []),
        'insertCount' => count($inserts),
    ];

    $path = "{$outDir}/front-page-{$variant}.json";
    $tmpPath = "{$path}.tmp";
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($tmpPath, $json) === false || !rename($tmpPath, $path)) {
        @unlink($tmpPath);
        fwrite(STDERR, "Unable to write {$path}\n");
        exit(1);
    }
    echo "Wrote {$path}\n";
}

function parseOptions(array $argv): array {
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) continue;
        $arg = substr($arg, 2);
        [$key, $value] = array_pad(explode('=', $arg, 2), 2, '1');
        $options[$key] = $value;
    }
    return $options;
}

function parseVariants(string $raw): array {
    $variants = array_values(array_unique(array_filter(array_map(static function ($value) {
        $value = strtolower(trim((string)$value));
        return in_array($value, ['public', 'admin'], true) ? $value : '';
    }, explode(',', $raw)))));
    return $variants ?: ['public', 'admin'];
}

function httpJson(string $url, string $method = 'GET', ?array $payload = null): array {
    $headers = [
        'Accept: application/json',
    ];
    $body = null;
    if ($payload !== null) {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $headers[] = 'Content-Type: application/json';
    }

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($curl);
        $err = curl_error($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($raw === false || $status >= 400) {
            throw new RuntimeException("Request failed ({$status}): {$url} {$err}");
        }
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            throw new RuntimeException("Invalid JSON from {$url}");
        }
        return $data;
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);
    $raw = file_get_contents($url, false, $context);
    if ($raw === false) {
        throw new RuntimeException("Request failed: {$url}");
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException("Invalid JSON from {$url}");
    }
    return $data;
}

function buildFrontPageRailItem(array $set, int $setId): array {
    $items = [];
    foreach (($set['items'] ?? []) as $index => $item) {
        if (strtolower((string)($item['item_type'] ?? 'instance')) === 'set') continue;
        $playerUrl = trim((string)($item['player_url'] ?? ''));
        if ($playerUrl === '' && !empty($item['playlist_instance_id'])) {
            $playerUrl = '/p/' . $item['playlist_instance_id'];
        } elseif (str_starts_with($playerUrl, '/playlist/')) {
            $playerUrl = '/p/' . substr($playerUrl, strlen('/playlist/'));
        }
        if ($playerUrl === '') continue;
        $items[] = [
            'id' => $item['id'] ?? "front-page-playlist-{$index}",
            'title' => formatFrontPageText((string)($item['title'] ?? '')),
            'subtitle' => formatFrontPageText((string)($item['subtitle'] ?? '')),
            'photo_url' => $item['photo_url'] ?? '',
            'photo_library_id' => $item['photo_library_id'] ?? null,
            'player_url' => $playerUrl,
        ];
    }

    return [
        'id' => "front-page-playlist-set-{$setId}",
        'item_type' => 'front-page-playlist-set',
        'insert_position' => 3,
        'title' => formatFrontPageText((string)($set['title'] ?? '')),
        'subtitle' => formatFrontPageText((string)($set['subtitle'] ?? '')),
        'items' => $items,
    ];
}

function attachFeaturedArticlePayloads(string $baseUrl, array $inserts): array {
    foreach ($inserts as &$item) {
        if (strtolower((string)($item['item_type'] ?? '')) !== 'featured-article') continue;
        $metadata = parseJsonObject((string)($item['body'] ?? '')) + parseJsonObject((string)($item['description'] ?? ''));
        $type = trim((string)($metadata['article_type'] ?? $metadata['type'] ?? ''));
        $url = "{$baseUrl}/api/v2/articles/featured.php";
        if ($type !== '') {
            $url .= '?type=' . rawurlencode($type);
        }
        try {
            $featured = httpJson($url);
            if (!empty($featured['ok'])) {
                $item['featured_payload'] = $featured['item'] ?? null;
            }
        } catch (Throwable $e) {
            $item['featured_payload_error'] = $e->getMessage();
        }
    }
    unset($item);
    return $inserts;
}

function parseJsonObject(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function collectImagePreloads(array $results, array $inserts, array $frontPageRailItem): array {
    $ids = [];
    $visit = static function ($value) use (&$visit, &$ids): void {
        if (!is_array($value)) return;
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $visit($child);
                continue;
            }
            $key = strtolower((string)$key);
            if (in_array($key, ['photo_library_id', 'photo_id', 'hero_asset_id', 'hero_mobile_asset_id'], true)) {
                $id = (int)$child;
                if ($id > 0) $ids[$id] = true;
            }
        }
    };
    $visit($inserts);
    $visit($frontPageRailItem);
    $visit(array_slice($results, 0, 8));

    $urls = [];
    foreach (array_keys($ids) as $id) {
        $urls[] = "/api/v2/image-thumb.php?id={$id}&w=520&q=72";
    }
    return array_slice($urls, 0, 12);
}

function formatFrontPageText(string $value): string {
    return trim((string)preg_replace('/\s*--\s*/', ' — ', $value));
}
