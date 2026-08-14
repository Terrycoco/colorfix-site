<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

@ini_set('display_errors', '0');
@ini_set('log_errors', '1');

require_once __DIR__ . '/../../../api/autoload.php';
require_once __DIR__ . '/../../../api/db.php';

use App\Repos\PdoColorTriggerPhotoRepository;
use App\Repos\PdoPaletteViewerRepository;
use App\REX\Repos\PdoRexReservationRepository;

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        respond(['ok' => false, 'error' => 'GET only'], 405);
    }

    $colorId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($colorId <= 0) {
        respond(['ok' => false, 'error' => 'Missing or invalid color ID'], 400);
    }

    $triggerPhotoRepo = new PdoColorTriggerPhotoRepository($pdo);
    $paletteViewerRepo = new PdoPaletteViewerRepository($pdo);
    $rexRepo = new PdoRexReservationRepository($pdo);

    $rows = $triggerPhotoRepo->findGalleryTriggerPhotosForColor($colorId);

    $savedPaletteIds = array_values(array_unique(array_filter(
        array_map(
            static fn(array $row): int => (int)($row['palette_id'] ?? 0),
            $rows
        ),
        static fn(int $id): bool => $id > 0
    )));

    $rexUrlBySavedPaletteId = [];

    if ($savedPaletteIds) {
        $publicViewersBySavedPaletteId =
            $paletteViewerRepo->findActivePublicBySavedPaletteIds($savedPaletteIds);

        $paletteViewerIdBySavedPaletteId = [];
        $paletteViewerIds = [];

        foreach ($publicViewersBySavedPaletteId as $savedPaletteId => $viewers) {
            $viewer = $viewers[0] ?? null;
            if ($viewer === null) {
                continue;
            }

            $paletteViewerId = (int)$viewer->paletteViewerId;
            if ($paletteViewerId <= 0) {
                continue;
            }

            $paletteViewerIdBySavedPaletteId[(int)$savedPaletteId] = $paletteViewerId;
            $paletteViewerIds[$paletteViewerId] = true;
        }

        if ($paletteViewerIds) {
            $rexByPaletteViewerId = $rexRepo->findActiveByResourceIds(
                'viewer',
                'palette_viewer',
                array_keys($paletteViewerIds)
            );

            foreach ($paletteViewerIdBySavedPaletteId as $savedPaletteId => $paletteViewerId) {
                $reservation = ($rexByPaletteViewerId[$paletteViewerId] ?? [])[0] ?? null;
                if ($reservation !== null) {
                    $rexUrlBySavedPaletteId[$savedPaletteId] = '/t/' . $reservation->token;
                }
            }
        }
    }

    $items = [];
    $seen = [];

    foreach ($rows as $row) {
        $photoUrl = trim((string)($row['photo_url'] ?? ''));
        if ($photoUrl === '') {
            continue;
        }

        $key = implode(':', [
            (string)($row['palette_hash'] ?? ''),
            (string)($row['palette_id'] ?? ''),
            (string)($row['saved_palette_set_id'] ?? ''),
            (string)($row['photo_type'] ?? ''),
            $photoUrl,
        ]);

        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $paletteId = (int)($row['palette_id'] ?? 0);

        $items[] = [
            'photo_library_id' => (int)($row['photo_library_id'] ?? 0),
            'photo_url' => $photoUrl,
            'photo_type' => (string)($row['photo_type'] ?? 'full'),
            'trigger_color_id' => isset($row['trigger_color_id'])
                ? (int)$row['trigger_color_id']
                : null,
            'palette_id' => $paletteId,
            'palette_hash' => !empty($row['palette_hash'])
                ? (string)$row['palette_hash']
                : null,
            'palette_name' => (string)($row['palette_name'] ?? ''),
            'palette_brand' => !empty($row['palette_brand'])
                ? (string)$row['palette_brand']
                : null,
            'saved_palette_set_id' => isset($row['saved_palette_set_id'])
                ? (int)$row['saved_palette_set_id']
                : null,
            'rex_url' => $rexUrlBySavedPaletteId[$paletteId] ?? null,
        ];
    }

    respond(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    respond(['ok' => false, 'error' => $e->getMessage()], 500);
}