<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\Repos\PdoSavedPaletteRepository;
use App\Repos\PdoPaletteViewerPhotoRepository;
use App\Repos\PdoPaletteViewerRepository;
use App\Repos\PdoPhotoRepository;
use App\Repos\PdoPlaylistInstanceRepository;
use App\Repos\PdoProjectColorPlanRepository;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Resolvers\PlaylistExperienceResolver;
use App\REX\Resolvers\RexResolverRegistry;
use App\REX\Resolvers\ViewerResolver;
use App\REX\Services\RexResolver;
use App\Services\PhotoRenderingService;
use App\Services\PaletteViewerService;
use App\Services\PaletteViewerTokenService;

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        respond(['ok' => false, 'error' => 'GET only'], 405);
    }

    $savedRepo = new PdoSavedPaletteRepository($pdo);
    $photoRepo = new PdoPhotoRepository($pdo);
    $playlistInstanceRepo = new PdoPlaylistInstanceRepository($pdo);
    $projectColorPlanRepo = new PdoProjectColorPlanRepository($pdo);
    $renderSvc = new PhotoRenderingService($photoRepo, $pdo);
    $svc = new PaletteViewerService(
        $savedRepo,
        $renderSvc,
        $playlistInstanceRepo,
        $projectColorPlanRepo,
        new PdoPaletteViewerRepository($pdo),
        new PdoPaletteViewerPhotoRepository($pdo)
    );

    $token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
    if ($token !== '') {
        $tokenService = new PaletteViewerTokenService($pdo);
        try {
            $tokenPayload = $tokenService->decode($token);
        } catch (InvalidArgumentException) {
            $registry = new RexResolverRegistry();
            $registry->register('playlist_experience', new PlaylistExperienceResolver($pdo));
            $registry->register('viewer', new ViewerResolver($pdo));
            $result = (new RexResolver(
                new PdoRexReservationRepository($pdo),
                $registry
            ))->resolveToken($token, [
                'entry_point' => 'palette-viewer',
                'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
                'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ]);

            if ($result->resolverKey !== 'viewer' || !is_array($result->destination['viewer'] ?? null)) {
                throw new InvalidArgumentException('invalid palette viewer token');
            }

            respond(['ok' => true, 'data' => $result->destination['viewer']]);
        }

        $source = (string)($tokenPayload['source'] ?? 'saved');
        $paletteViewerKey = (string)($tokenPayload['palette_viewer_key'] ?? 'full_palette');

        if ($source === 'project_color_plan') {
            $colorPlanId = (int)($tokenPayload['color_plan_id'] ?? 0);
            $data = $svc->getProjectColorPlan($colorPlanId, $paletteViewerKey);

            $reservationToken = trim((string)($tokenPayload['reservation_token'] ?? ''));
            if ($reservationToken !== '') {
                $data['meta']['reservation_token'] = $reservationToken;
                $data['meta']['playlist_url'] = '/t/' . rawurlencode($reservationToken);
            }

            respond(['ok' => true, 'data' => $data]);
        }

        $hash = trim((string)($tokenPayload['hash'] ?? ''));
        $setId = isset($tokenPayload['set_id']) ? (int)$tokenPayload['set_id'] : null;
        $data = $svc->getSaved($hash, $setId && $setId > 0 ? $setId : null, $paletteViewerKey);
        $data['meta']['playlist_instance_id'] = $tokenPayload['playlist_instance_id'] ?? null;
        $playlistInstanceId = (int)($tokenPayload['playlist_instance_id'] ?? 0);
        if ($playlistInstanceId > 0) {
            $instance = $playlistInstanceRepo->getById($playlistInstanceId);
            if ($instance && $instance->isActive && $instance->shareEnabled) {
                $pathId = trim((string)($instance->slug ?? '')) !== ''
                    ? (string)$instance->slug
                    : (string)$playlistInstanceId;
                $data['meta']['playlist_url'] = '/p/' . rawurlencode($pathId);
                $data['meta']['playlist_title'] = $instance->displayTitle ?: $instance->instanceName;
            }
        }
        respond(['ok' => true, 'data' => $data]);
    }

    $paletteViewerId = isset($_GET['palette_viewer_id']) ? (int)$_GET['palette_viewer_id'] : 0;
    if ($paletteViewerId > 0) {
        $data = $svc->getCanonicalById($paletteViewerId);
        respond(['ok' => true, 'data' => $data]);
    }

    $source = isset($_GET['source']) ? strtolower(trim((string)$_GET['source'])) : '';
    if ($source !== 'saved') {
        respond(['ok' => false, 'error' => 'source must be saved'], 400);
    }

    $setId = isset($_GET['set_id']) ? (int)$_GET['set_id'] : null;
    $savedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($savedId > 0) {
        $data = $svc->getSavedById($savedId, $setId && $setId > 0 ? $setId : null);
        respond(['ok' => true, 'data' => $data]);
    }

    $hash = isset($_GET['hash']) ? trim((string)$_GET['hash']) : '';
    $data = $svc->getSaved($hash, $setId && $setId > 0 ? $setId : null);
    respond(['ok' => true, 'data' => $data]);
} catch (\InvalidArgumentException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (\RuntimeException $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 404);
} catch (\Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
