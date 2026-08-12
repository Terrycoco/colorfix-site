<?php
declare(strict_types=1);

namespace App\REX\Resolvers;

use App\REX\Contracts\RexResolverInterface;
use App\REX\DTO\RexReservation;
use App\REX\DTO\RexReservationDescriptor;
use App\REX\DTO\RexResolutionBehavior;
use App\REX\DTO\RexResolutionRequest;
use App\REX\DTO\RexResolutionResult;
use App\REX\DTO\RexShareMetadata;
use App\Repos\PdoPlaylistRepository;
use App\Services\PlayerExperienceService;
use DomainException;
use PDO;
use RuntimeException;

final class PlaylistExperienceResolver implements RexResolverInterface
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function resolve(
        RexResolutionRequest $request
    ): RexResolutionResult {
        $playlistId = $request->resourceId;

        if ($playlistId <= 0) {
            throw new RuntimeException(
                'Playlist Experience reservation requires a valid playlist ID.'
            );
        }

        $experienceKey = strtolower(trim(
            (string)($request->context['experience_key'] ?? '')
        ));

        if ($experienceKey === '') {
            throw new DomainException(
                'Playlist Experience reservation requires context.experience_key.'
            );
        }

        $start = null;
        if (
            array_key_exists('start', $request->requestMetadata)
            && $request->requestMetadata['start'] !== null
        ) {
            $start = (int)$request->requestMetadata['start'];
        }

        $startTarget = is_array(
            $request->requestMetadata['start_target'] ?? null
        )
            ? $request->requestMetadata['start_target']
            : null;

        $service = new PlayerExperienceService($this->pdo);

        $plan = $service->buildPlaybackPlanFromPlaylistExperience(
            $playlistId,
            $experienceKey,
            $request->sourceKey,
            $start,
            $startTarget,
            $request->reservation->token
        );

        return new RexResolutionResult(
            resolverKey: 'playlist_experience',
            resourceType: $request->resourceType,
            resourceId: $playlistId,
            behavior: RexResolutionBehavior::RENDER,
            shareMetadata: new RexShareMetadata(
                title: $plan['share_title']
                    ?? $plan['display_title']
                    ?? null,
                description: $plan['share_description']
                    ?? $plan['project_summary']
                    ?? null,
                imageUrl: $plan['share_image_url'] ?? null,
            ),
            destination: [
                'playback_plan' => $plan,
            ],
            analyticsMetadata: [
                'reservation_id' => $request->reservation->id,
                'playlist_id' => $playlistId,
                'experience_key' => $experienceKey,
                'timing_ms' => $service->getLastTiming(),
            ],
        );
    }

    public function describe(
        RexReservation $reservation
    ): RexReservationDescriptor {
        $playlistId = $reservation->resourceId;

        $repo = new PdoPlaylistRepository($this->pdo);
        $playlist = $repo->getAdminRowById($playlistId);

        if (!$playlist) {
            throw new RuntimeException(
                "Playlist {$playlistId} was not found."
            );
        }

        $experienceKey = strtolower(trim(
            (string)($reservation->context['experience_key'] ?? '')
        ));

        $experienceLabel = $experienceKey !== ''
            ? ucfirst($experienceKey)
            : 'Unknown';

        $playlistTitle = trim((string)($playlist['title'] ?? ''));
        $playlistSlug = trim((string)($playlist['slug'] ?? ''));

        return new RexReservationDescriptor(
            title: $playlistTitle !== ''
                ? "{$playlistTitle} — {$experienceLabel}"
                : "Playlist #{$playlistId} — {$experienceLabel}",
            fields: [
                [
                    'label' => 'Playlist',
                    'value' => $playlistTitle !== ''
                        ? $playlistTitle
                        : "Playlist #{$playlistId}",
                ],
                [
                    'label' => 'Playlist ID',
                    'value' => (string)$playlistId,
                ],
                [
                    'label' => 'Experience',
                    'value' => $experienceLabel,
                ],
                [
                    'label' => 'Slug',
                    'value' => $playlistSlug !== ''
                        ? $playlistSlug
                        : '—',
                ],
            ],
        );
    }

    public function previewDescribe(
        string $resourceType,
        int $resourceId,
        array $context
    ): RexReservationDescriptor {
        if ($resourceType !== 'playlist') {
            throw new RuntimeException(
                "Playlist Experience resolver requires resource_type 'playlist'."
            );
        }

        if ($resourceId <= 0) {
            throw new RuntimeException(
                'Playlist Experience preview requires a valid playlist ID.'
            );
        }

        $repo = new PdoPlaylistRepository($this->pdo);
        $playlist = $repo->getAdminRowById($resourceId);

        if (!$playlist) {
            throw new RuntimeException(
                "Playlist {$resourceId} was not found."
            );
        }

        $experienceKey = strtolower(trim(
            (string)($context['experience_key'] ?? '')
        ));

        if ($experienceKey === '') {
            throw new DomainException(
                'Playlist Experience preview requires context.experience_key.'
            );
        }

        if (!in_array(
            $experienceKey,
            ['public', 'concept', 'client', 'painter'],
            true
        )) {
            throw new DomainException(
                "Unsupported Playlist Experience '{$experienceKey}'."
            );
        }

        $experienceLabel = ucfirst($experienceKey);

        $playlistTitle = trim((string)($playlist['title'] ?? ''));
        $playlistSlug = trim((string)($playlist['slug'] ?? ''));

        return new RexReservationDescriptor(
            title: $playlistTitle !== ''
                ? "{$playlistTitle} — {$experienceLabel}"
                : "Playlist #{$resourceId} — {$experienceLabel}",
            fields: [
                [
                    'label' => 'Playlist',
                    'value' => $playlistTitle !== ''
                        ? $playlistTitle
                        : "Playlist #{$resourceId}",
                ],
                [
                    'label' => 'Playlist ID',
                    'value' => (string)$resourceId,
                ],
                [
                    'label' => 'Experience',
                    'value' => $experienceLabel,
                ],
                [
                    'label' => 'Slug',
                    'value' => $playlistSlug !== ''
                        ? $playlistSlug
                        : '—',
                ],
            ],
        );
    }

}