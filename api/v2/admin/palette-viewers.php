<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../autoload.php';
require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/auth.php';

use App\Repos\PdoPaletteViewerPhotoRepository;
use App\Repos\PdoPaletteViewerRepository;
use App\Repos\PdoSavedPaletteRepository;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexReservationRelationships;
use App\REX\Services\RexReserver;
use App\REX\Services\RexTokenGenerator;
use App\Services\PaletteViewerAdminService;

function palette_viewers_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function palette_viewers_json_body(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('Invalid JSON payload.');
    }
    return $data;
}

try {
    $rexReservations = new PdoRexReservationRepository($pdo);
    $service = new PaletteViewerAdminService(
        new PdoPaletteViewerRepository($pdo),
        new PdoPaletteViewerPhotoRepository($pdo),
        new PdoSavedPaletteRepository($pdo),
        $rexReservations,
        new RexReservationRelationships($rexReservations),
        new RexReserver($rexReservations, new RexTokenGenerator())
    );

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET') {
        $mode = trim((string)($_GET['mode'] ?? ''));
        if ($mode === 'palettes') {
            palette_viewers_respond([
                'ok' => true,
                'items' => $service->paletteOptions(
                    trim((string)($_GET['q'] ?? '')),
                    isset($_GET['limit']) ? (int)$_GET['limit'] : 1000
                ),
            ]);
        }
        if ($mode === 'palette') {
            $savedPaletteId = isset($_GET['saved_palette_id']) ? (int)$_GET['saved_palette_id'] : 0;
            palette_viewers_respond([
                'ok' => true,
                'item' => $service->paletteDetail($savedPaletteId),
            ]);
        }

        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id > 0) {
            palette_viewers_respond([
                'ok' => true,
                'item' => $service->getViewer($id),
            ]);
        }

        palette_viewers_respond([
            'ok' => true,
            'items' => $service->listViewers(),
        ]);
    }

    if ($method === 'POST') {
        $saved = $service->saveViewer(palette_viewers_json_body());
        palette_viewers_respond([
            'ok' => true,
            'item' => $saved,
        ]);
    }

    palette_viewers_respond(['ok' => false, 'error' => 'GET or POST only'], 405);
} catch (InvalidArgumentException $e) {
    palette_viewers_respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    palette_viewers_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
