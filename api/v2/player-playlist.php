<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$freshRequest =
    isset($_GET['fresh'])
    && (string)$_GET['fresh'] !== '0';

if ($freshRequest) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
} else {
    header('Cache-Control: public, max-age=60, stale-while-revalidate=180');
}

require_once __DIR__ . '/../autoload.php';
require_once __DIR__ . '/../db.php';

use App\PLAYLISTS\Repos\PdoPlaylistRepository;
use App\PLAYLISTS\Services\PlaylistExperienceService;
use App\REX\DTO\RexResolutionBehavior;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Resolvers\PlaylistExperienceResolver;
use App\REX\Resolvers\RexResolverRegistry;
use App\REX\Resolvers\ViewerResolver;
use App\REX\Services\RexResolver;

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    respond([
        'ok' => false,
        'error' => 'GET only',
    ], 405);
}

$playlistId =
    isset($_GET['playlist_id'])
        ? (int)$_GET['playlist_id']
        : 0;

$playlistSlug =
    trim(
        (string)(
            $_GET['playlist_slug']
            ?? $_GET['slug']
            ?? ''
        )
    );

$reservationToken =
    trim(
        (string)(
            $_GET['reservation_token']
            ?? $_GET['token']
            ?? ''
        )
    );

$returnTo =
    trim(
        (string)($_GET['return_to'] ?? '')
    );

$start =
    isset($_GET['start'])
        ? (int)$_GET['start']
        : null;

$offset =
    isset($_GET['offset'])
        ? (int)$_GET['offset']
        : 0;

$position = null;

if (isset($_GET['position'])) {
    $position = (int)$_GET['position'];
} elseif (isset($_GET['pos'])) {
    $position = (int)$_GET['pos'];
}

$playlistItemId = 0;

foreach (
    [
        'playlist_item_id',
        'slide_id',
        'item_id',
    ] as $key
) {
    if (isset($_GET[$key])) {
        $playlistItemId =
            (int)$_GET[$key];

        break;
    }
}

$photoLibraryId = 0;

foreach (
    [
        'photo_library_id',
        'photo_id',
    ] as $key
) {
    if (isset($_GET[$key])) {
        $photoLibraryId =
            (int)$_GET[$key];

        break;
    }
}

$debugTiming =
    isset($_GET['debug_timing'])
    && (string)$_GET['debug_timing'] !== '0';

$startTarget = [
    'offset' =>
        $offset,

    'position' =>
        $position,

    'playlist_item_id' =>
        $playlistItemId,

    'photo_library_id' =>
        $photoLibraryId,
];

if (
    $playlistId <= 0
    && $playlistSlug === ''
    && $reservationToken === ''
) {
    respond([
        'ok' => false,
        'error' =>
            'playlist_id, playlist_slug, or reservation_token required',
        'code' =>
            'playlist_unavailable',
    ], 400);
}

try {
    /*
     * Canonical REX path.
     */
    if ($reservationToken !== '') {
        $repo =
            new PdoRexReservationRepository(
                $pdo
            );

        $reservation =
            $repo->findByToken(
                $reservationToken
            );

        if (!$reservation) {
            throw new RuntimeException(
                'Playlist unavailable'
            );
        }

        $registry =
            new RexResolverRegistry();

        $registry->register(
            'playlist_experience',
            new PlaylistExperienceResolver(
                $pdo
            )
        );

        $registry->register(
            'viewer',
            new ViewerResolver(
                $pdo
            )
        );

        $resolver =
            new RexResolver(
                $repo,
                $registry
            );

        $result =
            $resolver->resolveToken(
                $reservationToken,
                [
                    'entry_point' =>
                        'player-playlist',

                    'src' =>
                        $_GET['src'] ?? null,

                    'start' =>
                        $start,

                    'start_target' =>
                        $startTarget,

                    'request_uri' =>
                        (string)(
                            $_SERVER['REQUEST_URI']
                            ?? ''
                        ),

                    'ip' =>
                        (string)(
                            $_SERVER['REMOTE_ADDR']
                            ?? ''
                        ),

                    'user_agent' =>
                        (string)(
                            $_SERVER['HTTP_USER_AGENT']
                            ?? ''
                        ),
                ]
            );

        if (
            $result->behavior
                !== RexResolutionBehavior::RENDER
            || $result->resolverKey
                !== 'playlist_experience'
        ) {
            throw new RuntimeException(
                'Playlist unavailable'
            );
        }

        $plan =
            $result->destination['playback_plan']
            ?? null;

        if (!is_array($plan)) {
            throw new RuntimeException(
                'Playlist unavailable'
            );
        }

        $plan['reservation_id'] =
            $reservation->id;

        $plan['reservation_token'] =
            $reservationToken;

        $plan['rex'] = [
            'reservation_id' =>
                $reservation->id,

            'token' =>
                $reservation->token,

            'resolver_key' =>
                $reservation->resolverKey,

            'resource_type' =>
                $reservation->resourceType,

            'resource_id' =>
                $reservation->resourceId,

            'context' =>
                $reservation->context,
        ];

        if (
            $returnTo !== ''
            && str_starts_with(
                $returnTo,
                '/'
            )
            && !str_starts_with(
                $returnTo,
                '//'
            )
        ) {
            $plan['reserved_viewer_url'] =
                $returnTo;

            $plan['originating_reserved_viewer_url'] =
                $returnTo;
        }

        $reservedUrl =
            '/t/'
            . rawurlencode(
                $reservationToken
            );

        $plan['originating_reserved_url'] =
            $reservedUrl;

        $plan['reserved_playlist_url'] =
            $reservedUrl;

        $payload = [
            'ok' =>
                true,

            'data' =>
                $plan,
        ];

        if ($debugTiming) {
            $timing =
                $result
                    ->analyticsMetadata[
                        'timing_ms'
                    ]
                ?? null;

            if (is_array($timing)) {
                $payload['timing_ms'] =
                    $timing;
            }
        }

        respond(
            $payload
        );
    }

    /*
     * Direct playlist path.
     *
     * No PlaylistInstance exists anymore.
     * Slug resolves directly to playlists.playlist_id.
     * Direct access is the public playlist experience.
     */
    if (
        $playlistId <= 0
        && $playlistSlug !== ''
    ) {
        $playlistRepo =
            new PdoPlaylistRepository(
                $pdo
            );

        $playlistId =
            $playlistRepo
                ->findIdBySlug(
                    $playlistSlug
                )
            ?? 0;
    }

    if ($playlistId <= 0) {
        throw new RuntimeException(
            'Playlist unavailable'
        );
    }

    $service =
        new PlaylistExperienceService(
            $pdo
        );

    $plan =
        $service
            ->buildPlaybackPlanFromPlaylistExperience(
                $playlistId,
                'public',
                isset($_GET['src'])
                    ? (string)$_GET['src']
                    : null,
                $start,
                $startTarget,
                null
            );

    $payload = [
        'ok' =>
            true,

        'data' =>
            $plan,
    ];

    if ($debugTiming) {
        $payload['timing_ms'] =
            $service->getLastTiming();
    }

    respond(
        $payload
    );

} catch (DomainException $e) {
    respond([
        'ok' =>
            false,

        'error' =>
            $e->getMessage(),

        'code' =>
            'player_experience_config_error',
    ], 500);

} catch (
    RuntimeException
    | InvalidArgumentException $e
) {
    respond([
        'ok' =>
            false,

        'error' =>
            'Playlist unavailable',

        'code' =>
            'playlist_unavailable',
    ], 404);

} catch (Throwable $e) {
    respond([
        'ok' =>
            false,

        'error' =>
            $e->getMessage(),
    ], 500);
}
