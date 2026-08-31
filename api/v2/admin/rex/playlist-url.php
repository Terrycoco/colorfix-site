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
require_once __DIR__ . '/_helpers.php';

use App\REX\DTO\RexReservation;
use App\REX\DTO\RexReservationSearchCriteria;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexReservationRelationships;

function rex_playlist_url_is_public_playlist_rex(RexReservation $reservation): bool
{
    return strtolower(trim($reservation->resolverKey)) === 'playlist_experience'
        && strtolower(trim($reservation->resourceType)) === 'playlist'
        && strtolower(trim((string)($reservation->experienceKey ?? ''))) === 'public'
        && strtolower(trim($reservation->status)) === 'active';
}

/**
 * Pick the canonical public Playlist REX without using legacy Playlist Instance clues.
 *
 * Prefer a reservation that already has viewer children, then the oldest
 * reservation for stable URLs.
 *
 * @param RexReservation[] $matches
 */
function rex_playlist_url_select_canonical(
    array $matches,
    RexReservationRelationships $relationships
): ?RexReservation {
    $eligible = array_values(array_filter(
        $matches,
        'rex_playlist_url_is_public_playlist_rex'
    ));

    if (!$eligible) {
        return null;
    }

    usort(
        $eligible,
        static function (
            RexReservation $a,
            RexReservation $b
        ) use ($relationships): int {
            $aChildren = count(
                $relationships->children($a->id, 'viewer')
            );

            $bChildren = count(
                $relationships->children($b->id, 'viewer')
            );

            if ($aChildren !== $bChildren) {
                return $bChildren <=> $aChildren;
            }

            return $a->id <=> $b->id;
        }
    );

    return $eligible[0] ?? null;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        workflow_respond([
            'ok' => false,
            'error' => 'GET only',
        ], 405);
    }

    $playlistId = rex_admin_positive_int(
        $_GET['playlist_id'] ?? null,
        'Playlist ID'
    );

    $repo = new PdoRexReservationRepository($pdo);
    $relationships = new RexReservationRelationships($repo);

    $reservations = $repo->search(
        new RexReservationSearchCriteria(
            resolverKey: 'playlist_experience',
            resourceType: 'playlist',
            resourceId: $playlistId,
            status: 'active',
            limit: 500,
            experienceKey: 'public',
        )
    );

    $matches = array_values(array_filter(
        $reservations,
        'rex_playlist_url_is_public_playlist_rex'
    ));

    $selected = rex_playlist_url_select_canonical(
        $matches,
        $relationships
    );

    if (!$selected) {
        workflow_respond([
            'ok' => true,
            'item' => null,
            'reason' => 'No active Public Playlist REX reservation found.',
        ]);
    }

    workflow_respond([
        'ok' => true,
        'item' => rex_admin_reservation_payload($selected),
    ]);

} catch (InvalidArgumentException $e) {
    workflow_respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 400);

} catch (Throwable $e) {
    workflow_respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
