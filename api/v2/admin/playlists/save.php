<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoPlaylistRepository;
use App\Services\PlaylistLandingService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'POST') !== 'POST') {
    respond(['ok' => false, 'error' => 'POST only'], 405);
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    respond(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$playlistId = isset($payload['playlist_id']) ? (int)$payload['playlist_id'] : 0;
$title = trim((string)($payload['title'] ?? ''));
$type = trim((string)($payload['type'] ?? ''));
$isActive = isset($payload['is_active']) ? (int)(bool)$payload['is_active'] : 1;
$headline = trim((string)($payload['headline'] ?? ''));
$pageTitle = trim((string)($payload['page_title'] ?? ''));
$metaDescription = trim((string)($payload['meta_description'] ?? ''));
$dek = trim((string)($payload['dek'] ?? ''));
$introHtml = trim((string)($payload['intro_html'] ?? ''));
$bodyHtml = trim((string)($payload['body_html'] ?? ''));
$heroImageIdRaw = $payload['hero_image_id'] ?? null;
$heroImageId = is_numeric($heroImageIdRaw) && (int)$heroImageIdRaw > 0 ? (int)$heroImageIdRaw : null;
$heroImageUrl = trim((string)($payload['hero_image_url'] ?? ''));
$heroAlt = trim((string)($payload['hero_alt'] ?? ''));
$indexable = isset($payload['indexable']) ? (int)(bool)$payload['indexable'] : 1;
$publishedAtRaw = trim((string)($payload['published_at'] ?? ''));
$requestedSlug = trim((string)($payload['slug'] ?? ''));

if ($title === '') {
    respond(['ok' => false, 'error' => 'title required'], 400);
}
if ($type === '') {
    respond(['ok' => false, 'error' => 'type required'], 400);
}

$repo = new PdoPlaylistRepository($pdo);
$seo = new PlaylistLandingService($repo);
$existing = $playlistId > 0 ? $repo->getAdminRowById($playlistId) : null;

$slugSource = $headline !== '' ? $headline : $title;
$slug = '';
if ($requestedSlug !== '') {
    $slug = $seo->generateSlug($requestedSlug, $playlistId > 0 ? $playlistId : null);
} elseif (!empty($existing['slug'])) {
    $slug = (string)$existing['slug'];
} else {
    $slug = $seo->generateSlug($slugSource, $playlistId > 0 ? $playlistId : null);
}

$publishedAt = null;
if ($publishedAtRaw !== '') {
    $publishedAt = str_replace('T', ' ', $publishedAtRaw);
    if (strlen($publishedAt) === 16) {
        $publishedAt .= ':00';
    }
}

if ($playlistId > 0) {
    $sql = <<<SQL
        UPDATE playlists
        SET title = :title,
            type = :type,
            is_active = :is_active,
            slug = :slug,
            headline = :headline,
            page_title = :page_title,
            meta_description = :meta_description,
            dek = :dek,
            intro_html = :intro_html,
            body_html = :body_html,
            hero_image_id = :hero_image_id,
            hero_image_url = :hero_image_url,
            hero_alt = :hero_alt,
            indexable = :indexable,
            published_at = :published_at,
            updated_at = NOW()
        WHERE playlist_id = :playlist_id
        SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'playlist_id' => $playlistId,
        'title' => $title,
        'type' => $type,
        'is_active' => $isActive,
        'slug' => $slug !== '' ? $slug : null,
        'headline' => $headline !== '' ? $headline : null,
        'page_title' => $pageTitle !== '' ? $pageTitle : null,
        'meta_description' => $metaDescription !== '' ? $metaDescription : null,
        'dek' => $dek !== '' ? $dek : null,
        'intro_html' => $introHtml !== '' ? $introHtml : null,
        'body_html' => $bodyHtml !== '' ? $bodyHtml : null,
        'hero_image_id' => $heroImageId,
        'hero_image_url' => $heroImageUrl !== '' ? $heroImageUrl : null,
        'hero_alt' => $heroAlt !== '' ? $heroAlt : null,
        'indexable' => $indexable,
        'published_at' => $publishedAt,
    ]);
} else {
    $sql = <<<SQL
        INSERT INTO playlists (
            title,
            type,
            is_active,
            slug,
            headline,
            page_title,
            meta_description,
            dek,
            intro_html,
            body_html,
            hero_image_id,
            hero_image_url,
            hero_alt,
            indexable,
            published_at,
            updated_at
        )
        VALUES (
            :title,
            :type,
            :is_active,
            :slug,
            :headline,
            :page_title,
            :meta_description,
            :dek,
            :intro_html,
            :body_html,
            :hero_image_id,
            :hero_image_url,
            :hero_alt,
            :indexable,
            :published_at,
            NOW()
        )
        SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'title' => $title,
        'type' => $type,
        'is_active' => $isActive,
        'slug' => $slug !== '' ? $slug : null,
        'headline' => $headline !== '' ? $headline : null,
        'page_title' => $pageTitle !== '' ? $pageTitle : null,
        'meta_description' => $metaDescription !== '' ? $metaDescription : null,
        'dek' => $dek !== '' ? $dek : null,
        'intro_html' => $introHtml !== '' ? $introHtml : null,
        'body_html' => $bodyHtml !== '' ? $bodyHtml : null,
        'hero_image_id' => $heroImageId,
        'hero_image_url' => $heroImageUrl !== '' ? $heroImageUrl : null,
        'hero_alt' => $heroAlt !== '' ? $heroAlt : null,
        'indexable' => $indexable,
        'published_at' => $publishedAt,
    ]);
    $playlistId = (int)$pdo->lastInsertId();
}

respond([
    'ok' => true,
    'playlist_id' => $playlistId,
    'slug' => $slug,
]);
