<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';

use App\Repos\PdoPlaylistInstanceRepository;

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
    respond(['ok' => false, 'error' => 'Missing id'], 400);
}

$repo = new PdoPlaylistInstanceRepository($pdo);
$instance = $repo->getById($id);
if (!$instance) {
    respond(['ok' => false, 'error' => 'Not found'], 404);
}

respond([
    'ok' => true,
    'item' => [
        'playlist_instance_id' => $instance->id,
        'playlist_id' => $instance->playlistId,
        'instance_name' => $instance->instanceName,
        'instance_notes' => $instance->instanceNotes,
        'intro_layout' => $instance->introLayout,
        'intro_title' => $instance->introTitle,
        'intro_subtitle' => $instance->introSubtitle,
        'intro_body' => $instance->introBody,
        'intro_image_url' => $instance->introImageUrl,
        'cta_group_id' => $instance->ctaGroupId,
        'cta_context_key' => $instance->ctaContextKey,
        'share_enabled' => $instance->shareEnabled ? 1 : 0,
        'share_title' => $instance->shareTitle,
        'share_description' => $instance->shareDescription,
        'share_image_url' => $instance->shareImageUrl,
        'skip_intro_on_replay' => $instance->skipIntroOnReplay ? 1 : 0,
        'hide_stars' => $instance->hideStars ? 1 : 0,
        'is_active' => $instance->isActive ? 1 : 0,
        'created_from_instance' => $instance->createdFromInstance,
    ],
]);
