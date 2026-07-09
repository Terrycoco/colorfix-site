<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Entities\PlaylistInstance;
use App\Repos\PdoAudienceTypeRepository;
use App\Repos\PdoPlaylistInstanceRepository;
use App\Repos\PdoPlaylistInstanceUrlReservationRepository;

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

$repo = new PdoPlaylistInstanceRepository($pdo);
$id = isset($payload['playlist_instance_id']) ? (int)$payload['playlist_instance_id'] : null;
$playlistId = isset($payload['playlist_id']) ? (int)$payload['playlist_id'] : 0;
$instanceName = trim((string)($payload['instance_name'] ?? ''));
$slug = trim((string)($payload['slug'] ?? $payload['playlist_slug'] ?? ''));
$urlReservationKey = trim((string)($payload['url_reservation_key'] ?? $payload['playlist_instance_url_reservation_key'] ?? ''));
$allowSlugEdit = !empty($payload['allow_slug_edit']);
$displayTitle = trim((string)($payload['display_title'] ?? ''));
$displaySubtitle = trim((string)($payload['display_subtitle'] ?? ''));
$introLayout = trim((string)($payload['intro_layout'] ?? 'default'));
$ctaContextKey = trim((string)($payload['cta_context_key'] ?? ''));
$audience = trim((string)($payload['audience'] ?? ''));
$ctaOverrides = $payload['cta_overrides'] ?? null;
if ($ctaContextKey === '') {
    $ctaContextKey = 'default';
}
if ($audience === '') {
    $audience = null;
}

$audienceRepo = new PdoAudienceTypeRepository($pdo);
if ($audience !== null) {
    $audience = strtolower($audience);
    if (!$audienceRepo->isAllowedKey($audience)) {
        respond(['ok' => false, 'error' => 'Invalid audience'], 400);
    }
}

if ($playlistId <= 0) {
    respond(['ok' => false, 'error' => 'playlist_id required'], 400);
}
if ($instanceName === '') {
    respond(['ok' => false, 'error' => 'instance_name required'], 400);
}
if ($introLayout === '') {
    $introLayout = 'default';
}

try {
    $reservationRepo = new PdoPlaylistInstanceUrlReservationRepository($pdo);
    $urlReservation = $urlReservationKey !== '' ? $reservationRepo->findByKey($urlReservationKey) : null;
    if ($urlReservationKey !== '' && !$urlReservation) {
        respond(['ok' => false, 'error' => 'URL reservation not found'], 400);
    }
    if ($urlReservation) {
        if ((int)($urlReservation['playlist_id'] ?? 0) !== $playlistId) {
            respond(['ok' => false, 'error' => 'URL reservation does not belong to this playlist'], 400);
        }
        $slug = trim((string)($urlReservation['slug'] ?? ''));
    } elseif ($slug !== '') {
        $reservedSlug = $reservationRepo->findBySlug($slug);
        if ($reservedSlug && empty($reservedSlug['playlist_instance_id'])) {
            respond(['ok' => false, 'error' => 'Slug is reserved for a future playlist instance'], 409);
        }
    }

    if ($id !== null && $id > 0 && !$allowSlugEdit) {
        $existing = $repo->getById($id);
        if ($existing) {
            $slug = trim((string)($existing->slug ?? ''));
        }
    }

    $instance = new PlaylistInstance(
        $id,
        $playlistId,
        $instanceName,
        $displayTitle !== '' ? $displayTitle : null,
        $displaySubtitle !== '' ? $displaySubtitle : null,
        $payload['instance_notes'] ?? null,
        $introLayout,
        $payload['intro_title'] ?? null,
        $payload['intro_subtitle'] ?? null,
        $payload['intro_body'] ?? null,
        $payload['intro_image_url'] ?? null,
        isset($payload['cta_group_id']) && $payload['cta_group_id'] !== '' ? (int)$payload['cta_group_id'] : null,
        isset($payload['palette_viewer_cta_group_id']) && $payload['palette_viewer_cta_group_id'] !== '' ? (int)$payload['palette_viewer_cta_group_id'] : null,
        !empty($payload['demo_enabled']),
        $ctaContextKey,
        $audience,
        is_array($ctaOverrides) ? json_encode($ctaOverrides, JSON_UNESCAPED_SLASHES) : (is_string($ctaOverrides) ? $ctaOverrides : null),
        !empty($payload['share_enabled']),
        $payload['share_title'] ?? null,
        $payload['share_description'] ?? null,
        $payload['share_image_url'] ?? null,
        !empty($payload['skip_intro_on_replay']),
        !empty($payload['hide_stars']),
        isset($payload['is_active']) ? (bool)$payload['is_active'] : true,
        isset($payload['created_from_instance']) && $payload['created_from_instance'] !== '' ? (int)$payload['created_from_instance'] : null,
        isset($payload['kicker_id']) && $payload['kicker_id'] !== '' ? (int)$payload['kicker_id'] : null,
        $slug !== '' ? $slug : null
    );

    $instance = $repo->save($instance);
    $claimedReservation = null;
    if ($urlReservation) {
        $claimedReservation = $reservationRepo->claim(
            $urlReservationKey,
            (int)$instance->id,
            (string)($instance->slug ?? $slug)
        );
    }

    respond([
        'ok' => true,
        'playlist_instance_id' => $instance->id,
        'slug' => $instance->slug,
        'player_url' => $instance->slug ? '/playlist/' . $instance->slug : '/playlist/' . $instance->id,
        'url_reservation' => $claimedReservation,
    ]);
} catch (\Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
