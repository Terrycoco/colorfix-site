<?php
declare(strict_types=1);

require __DIR__ . '/api/autoload.php';
require __DIR__ . '/api/db.php';

use App\Repos\PdoPhotoRepository;
use App\Repos\PdoPlaylistInstanceRepository;
use App\Repos\PdoSavedPaletteRepository;
use App\Services\PaletteViewerService;
use App\Services\PaletteViewerTokenService;
use App\Services\PhotoRenderingService;

function pv_origin(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'colorfix.terrymarr.com';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
    return $scheme . '://' . $host;
}

function pv_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function pv_absolute_url(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return pv_origin() . '/apple-touch-icon-teal-20260712.png';
    }
    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }
    return pv_origin() . '/' . ltrim($value, '/');
}

function pv_first_text(array $values, string $fallback): string
{
    foreach ($values as $value) {
        $text = trim(strip_tags((string)($value ?? '')));
        if ($text !== '') {
            return $text;
        }
    }
    return $fallback;
}

$code = trim((string)($_GET['code'] ?? ''));
$title = 'ColorFix by Terry';
$description = 'A ColorFix paint palette and design concept by Terry Marr.';
$image = pv_origin() . '/apple-touch-icon-teal-20260712.png';
$url = pv_origin() . ($_SERVER['REQUEST_URI'] ?? '/');

try {
    if ($code !== '') {
        $tokenService = new PaletteViewerTokenService($pdo);
        $payload = $tokenService->decode($code);
        $hash = trim((string)($payload['hash'] ?? ''));
        $setId = isset($payload['set_id']) ? (int)$payload['set_id'] : null;
        $viewerKey = (string)($payload['palette_viewer_key'] ?? 'full_palette');

        $savedRepo = new PdoSavedPaletteRepository($pdo);
        $photoRepo = new PdoPhotoRepository($pdo);
        $playlistInstanceRepo = new PdoPlaylistInstanceRepository($pdo);
        $renderSvc = new PhotoRenderingService($photoRepo, $pdo);
        $viewerSvc = new PaletteViewerService($savedRepo, $renderSvc, $playlistInstanceRepo);
        $data = $viewerSvc->getSaved($hash, $setId && $setId > 0 ? $setId : null, $viewerKey);
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        $displayTitle = pv_first_text([
            $meta['display_title'] ?? null,
            $meta['title'] ?? null,
            $meta['nickname'] ?? null,
        ], 'ColorFix Palette');
        $title = $displayTitle . ' | ColorFix by Terry';
        $description = pv_first_text([
            $meta['intro'] ?? null,
            $meta['notes'] ?? null,
            'A ColorFix paint palette and design concept by Terry Marr.',
        ], 'A ColorFix paint palette and design concept by Terry Marr.');
        $image = pv_absolute_url((string)($meta['photo_url'] ?? ''));
    }
} catch (Throwable) {
    // Keep the app shell usable even if metadata lookup fails.
}

$indexPath = __DIR__ . '/index.html';
$html = is_file($indexPath) ? (string)file_get_contents($indexPath) : '';
if ($html === '') {
    http_response_code(500);
    echo '<!doctype html><title>ColorFix</title><div id="root"></div>';
    exit;
}

$html = preg_replace('/\s*<title>.*?<\/title>\s*/is', "\n", $html, 1) ?? $html;
$html = preg_replace(
    '/\s*<meta\s+(?:property|name)=["\'](?:og:[^"\']+|twitter:[^"\']+)["\'][^>]*>\s*/i',
    "\n",
    $html
) ?? $html;
$html = preg_replace('/\s*<link\s+rel=["\']canonical["\'][^>]*>\s*/i', "\n", $html) ?? $html;

$metaTags = "\n" .
    '<title>' . pv_escape($title) . '</title>' . "\n" .
    '<meta name="description" content="' . pv_escape($description) . '">' . "\n" .
    '<meta property="og:type" content="website">' . "\n" .
    '<meta property="og:site_name" content="ColorFix by Terry">' . "\n" .
    '<meta property="og:title" content="' . pv_escape($title) . '">' . "\n" .
    '<meta property="og:description" content="' . pv_escape($description) . '">' . "\n" .
    '<meta property="og:image" content="' . pv_escape($image) . '">' . "\n" .
    '<meta property="og:url" content="' . pv_escape($url) . '">' . "\n" .
    '<meta name="twitter:card" content="summary_large_image">' . "\n" .
    '<meta name="twitter:title" content="' . pv_escape($title) . '">' . "\n" .
    '<meta name="twitter:description" content="' . pv_escape($description) . '">' . "\n" .
    '<meta name="twitter:image" content="' . pv_escape($image) . '">' . "\n" .
    '<link rel="canonical" href="' . pv_escape($url) . '">' . "\n";

if (str_contains($html, '</head>')) {
    $html = str_replace('</head>', $metaTags . '</head>', $html);
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo $html;
