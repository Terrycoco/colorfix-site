<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../../../autoload.php';
require_once __DIR__ . '/../../../db.php';
require_once __DIR__ . '/../project-workflow/_helpers.php';
require_once __DIR__ . '/../auth.php';

use App\REX\DTO\RexReservationSearchCriteria;
use App\REX\Repos\PdoRexReservationRepository;
use App\PLAYLISTS\Repos\PdoPlaylistRepository;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        workflow_respond([
            'ok' => false,
            'error' => 'GET only',
        ], 405);
    }

    $rexRepo = new PdoRexReservationRepository($pdo);
    $playlistRepo = new PdoPlaylistRepository($pdo);

    $reservations = $rexRepo->search(
        new RexReservationSearchCriteria(
            resourceType: 'playlist',
            limit: 500,
        )
    );

    $grouped = [];

    foreach ($reservations as $reservation) {
        if ($reservation->status !== 'active') {
            continue;
        }

        if ($reservation->resolverKey !== 'playlist_experience') {
            continue;
        }

        $playlistId = (int)$reservation->resourceId;

        if ($playlistId <= 0) {
            continue;
        }

        if (!isset($grouped[$playlistId])) {
            $grouped[$playlistId] = [
                'rex' => [],
            ];
        }

        $grouped[$playlistId]['rex'][] = (int)$reservation->id;
    }

    $items = [];

    foreach ($grouped as $playlistId => $rexData) {
        $playlist = $playlistRepo->getAdminRowById((int)$playlistId);

        if (!$playlist) {
            continue;
        }

        $reservationIds = array_values(array_unique(array_map(
            'intval',
            $rexData['rex']
        )));
        sort($reservationIds, SORT_NUMERIC);

        $items[] = [
            'playlist_id' => (int)$playlist['playlist_id'],
            'title' => (string)($playlist['title'] ?? ''),
            'type' => (string)($playlist['type'] ?? ''),
            'rex' => $reservationIds,
        ];
    }

    usort(
        $items,
        static fn(array $a, array $b): int =>
            strcasecmp(
                (string)($a['title'] ?? ''),
                (string)($b['title'] ?? '')
            )
    );

    workflow_respond([
        'ok' => true,
        'items' => $items,
    ]);

} catch (Throwable $e) {
    workflow_respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
