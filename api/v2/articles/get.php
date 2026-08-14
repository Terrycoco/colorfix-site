<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';

use App\Repos\PdoArticleRepository;
use App\Repos\PdoCtaRepository;
use App\REX\Repos\PdoRexReservationRepository;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    respond(['ok' => false, 'error' => 'GET only'], 405);
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    respond(['ok' => false, 'error' => 'id required'], 400);
}
 
$adminOverride = isset($_GET['admin']) && (string)$_GET['admin'] === '1';
$isAdmin = $adminOverride
    || (isset($_COOKIE['cf_admin']) && $_COOKIE['cf_admin'] === '1')
    || (isset($_COOKIE['cf_admin_global']) && $_COOKIE['cf_admin_global'] === '1');

try {
    $repo = new PdoArticleRepository($pdo);
    $rexRepo = new PdoRexReservationRepository($pdo);
    $article = $repo->getArticleById($id);
    if (!$article) {
        respond(['ok' => false, 'error' => 'Not found'], 404);
    }
    if (($article['status'] ?? '') !== 'published' && !$isAdmin) {
        respond(['ok' => false, 'error' => 'Not published'], 403);
    }

    $sections = $repo->listSections($id);
    $tags = $repo->getArticleTags($id);

    $photoIds = [];
    if (!empty($article['hero_asset_id'])) {
        $photoIds[] = (int)$article['hero_asset_id'];
    }
    foreach ($sections as $section) {
        if (!empty($section['asset_id'])) {
            $photoIds[] = (int)$section['asset_id'];
        }
    }
    $photoIds = array_values(array_unique(array_filter($photoIds)));
    $photoMap = [];
    if ($photoIds) {
        $placeholders = implode(',', array_fill(0, count($photoIds), '?'));
        $stmt = $pdo->prepare("SELECT photo_library_id, rel_path, title, alt_text, updated_at FROM photo_library WHERE photo_library_id IN ($placeholders)");
        $stmt->execute($photoIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $photoMap[(int)$row['photo_library_id']] = [
                'photo_library_id' => (int)$row['photo_library_id'],
                'rel_path' => $row['rel_path'],
                'title' => $row['title'],
                'alt_text' => $row['alt_text'],
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }
    }

    $heroPhoto = null;
    if (!empty($article['hero_asset_id'])) {
        $heroPhoto = $photoMap[(int)$article['hero_asset_id']] ?? null;
    }

    $sections = array_map(static function (array $section) use ($photoMap): array {
        $asset = null;
        if (!empty($section['asset_id'])) {
            $asset = $photoMap[(int)$section['asset_id']] ?? null;
        }
        $section['asset'] = $asset;
        return $section;
    }, $sections);

    $ctas = [];
    $overrides = [];
    if (!empty($article['cta_overrides'])) {
        $decoded = json_decode((string)$article['cta_overrides'], true);
        if (is_array($decoded)) {
            $overrides = $decoded;
        }
    }
    $ctaIds = $overrides['_cta_ids'] ?? [];
    if (is_array($ctaIds)) {
        $ctaIds = array_values(array_filter(array_map('intval', $ctaIds)));
    } else {
        $ctaIds = [];
    }
    if ($ctaIds) {
        $ctaRepo = new PdoCtaRepository($pdo);
        $ctas = $ctaRepo->getByIds($ctaIds);
        if ($ctas) {
            foreach ($ctas as $idx => $cta) {
                $ctaId = $cta['cta_id'] ?? null;
                if ($ctaId === null) continue;
                $key = (string)$ctaId;
                if (!isset($overrides[$key]) || !is_array($overrides[$key])) continue;
                $base = [];
                if (!empty($cta['params'])) {
                    $parsed = json_decode((string)$cta['params'], true);
                    if (is_array($parsed)) $base = $parsed;
                }
                $merged = array_merge($base, $overrides[$key]);
                $playlistId = isset($merged['playlist_id'])
                    ? (int)$merged['playlist_id']
                    : 0;

                if ($playlistId > 0) {
                    $reservations = $rexRepo->findActiveByResourceIdsAndExperience(
                        'playlist_experience',
                        'playlist',
                        [$playlistId],
                        'public'
                    );

                    $reservation = $reservations[$playlistId] ?? null;

                    if ($reservation !== null) {
                        $merged['url'] = '/t/' . $reservation->token;
                    }
                }
                $cta['params'] = json_encode($merged, JSON_UNESCAPED_SLASHES);
                $ctas[$idx] = $cta;
            }
        }
    }

    respond([
        'ok' => true,
        'item' => [
            'article' => $article,
            'tags' => $tags,
            'hero' => $heroPhoto,
            'sections' => $sections,
            'ctas' => $ctas,
        ],
    ]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
