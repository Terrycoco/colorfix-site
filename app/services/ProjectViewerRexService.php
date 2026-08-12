<?php
declare(strict_types=1);

namespace App\Services;

use App\REX\DTO\RexCreateReservationRequest;
use App\REX\DTO\RexReservation;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Services\RexReservationRelationships;
use App\REX\Services\RexReserver;
use App\REX\Services\RexTokenGenerator;
use App\Repos\PdoProjectColorPlanRepository;
use App\Repos\PdoProjectRepository;
use App\Repos\PdoPlayerExperienceRepository;
use PDO;
use RuntimeException;

final class ProjectViewerRexService
{
    private const VIEWER_RELATIONSHIP_KEY = 'viewer';

    private PdoProjectColorPlanRepository $colorPlans;
    private PdoProjectRepository $projects;
    private PdoPlayerExperienceRepository $playerExperiences;
    private PdoRexReservationRepository $reservations;
    private RexReservationRelationships $relationships;
    private RexReserver $reserver;

    public function __construct(private PDO $pdo)
    {
        $this->colorPlans = new PdoProjectColorPlanRepository($pdo);
        $this->projects = new PdoProjectRepository($pdo);
        $this->playerExperiences = new PdoPlayerExperienceRepository($pdo);
        $this->reservations = new PdoRexReservationRepository($pdo);
        $this->relationships = new RexReservationRelationships($this->reservations);
        $this->reserver = new RexReserver(
            $this->reservations,
            new RexTokenGenerator()
        );
    }

    public function ensureColorPlanViewer(int $colorPlanId, string $viewerKey): array
    {
        $viewerKey = $this->normalizeViewerKey($viewerKey);
        $plan = $this->colorPlans->findPlan($colorPlanId);
        if (!$plan) {
            throw new RuntimeException("Color Plan {$colorPlanId} was not found.");
        }

        $projectId = (int)($plan['project_id'] ?? 0);
        $project = $projectId > 0 ? $this->projects->findById($projectId) : null;
        if (!$project) {
            throw new RuntimeException("Project for Color Plan {$colorPlanId} was not found.");
        }

        $existingViewerReservation = $this->findViewerReservation($plan, $viewerKey);
        $playlistStatus = $this->resolvePlaylistParent($project, $viewerKey);

        if (($playlistStatus['status'] ?? '') !== 'ok') {
            unset($playlistStatus['parent']);

            return [
                'viewer' => $existingViewerReservation
                    ? $this->reservationPayload($existingViewerReservation, 'reused')
                    : null,
                'relationship' => $playlistStatus,
            ];
        }

        $viewerCreated = false;
        $viewerReservation = $existingViewerReservation;
        if (!$viewerReservation) {
            $viewerReservation = $this->createViewerReservation($plan, $project, $viewerKey);
            $viewerCreated = true;
        }

        $playlistStatus = $this->ensurePlaylistRelationship(
            $playlistStatus['parent'],
            $viewerReservation
        );

        return [
            'viewer' => $this->reservationPayload(
                $viewerReservation,
                $viewerCreated ? 'created' : 'reused'
            ),
            'relationship' => $playlistStatus,
        ];
    }

    private function findViewerReservation(array $plan, string $viewerKey): ?RexReservation
    {
        $planId = (int)$plan['id'];
        $matches = array_values(array_filter(
            $this->reservations->findByResource('color_plan', $planId, 500),
            static fn(RexReservation $reservation): bool =>
                $reservation->resolverKey === 'viewer'
                && $reservation->resourceType === 'color_plan'
                && $reservation->resourceId === $planId
                && $reservation->status === RexReserver::STATUS_ACTIVE
                && $reservation->revokedAt === null
                && strtolower(trim((string)($reservation->context['format'] ?? ''))) === $viewerKey
        ));

        if ($matches) {
            usort(
                $matches,
                static fn(RexReservation $a, RexReservation $b): int => $a->id <=> $b->id
            );

            return $matches[0];
        }

        return null;
    }

    private function createViewerReservation(array $plan, array $project, string $viewerKey): RexReservation
    {
        $planId = (int)$plan['id'];

        return $this->reserver->reserve(new RexCreateReservationRequest(
            label: $this->viewerLabel($project, $plan, $viewerKey),
            resolverKey: 'viewer',
            resourceType: 'color_plan',
            resourceId: $planId,
            adminNote: 'Auto-created from Project Viewer Save workflow.',
            context: ['format' => $viewerKey],
        ));
    }

    private function resolvePlaylistParent(
        array $project,
        string $viewerKey
    ): array {
        $parentExperienceKey = $this->rexParentExperienceKey($viewerKey);
        if ($parentExperienceKey === null) {
            return [
                'status' => 'warning',
                'warning' => "Player Experience {$this->formatLabel($viewerKey)} has no REX parent experience configured.",
                'created' => false,
                'existing' => false,
                'parent_reservation_id' => null,
                'parent_experience_key' => null,
            ];
        }

        $playlistId = (int)($project['current_playlist_id'] ?? 0);
        if ($playlistId <= 0) {
            return [
                'status' => 'warning',
                'warning' => 'No current project playlist is attached, so no Playlist REX relationship was created.',
                'created' => false,
                'existing' => false,
                'parent_reservation_id' => null,
                'parent_experience_key' => $parentExperienceKey,
            ];
        }

        $matches = $this->matchingPlaylistReservations($playlistId, $parentExperienceKey);
        if (count($matches) === 0) {
            return [
                'status' => 'warning',
                'warning' => "No matching {$this->formatLabel($parentExperienceKey)} Playlist REX exists for playlist #{$playlistId}.",
                'created' => false,
                'existing' => false,
                'parent_reservation_id' => null,
                'parent_experience_key' => $parentExperienceKey,
            ];
        }

        if (count($matches) > 1) {
            return [
                'status' => 'ambiguous',
                'warning' => 'Multiple matching Playlist REX reservations exist; relationship was not created.',
                'created' => false,
                'existing' => false,
                'parent_reservation_id' => null,
                'parent_experience_key' => $parentExperienceKey,
                'matches' => array_map(
                    fn(RexReservation $reservation): array => [
                        'id' => $reservation->id,
                        'source_key' => $reservation->sourceKey,
                    ],
                    $matches
                ),
            ];
        }

        return [
            'status' => 'ok',
            'created' => false,
            'existing' => false,
            'parent_reservation_id' => $matches[0]->id,
            'parent_experience_key' => $parentExperienceKey,
            'parent' => $matches[0],
        ];
    }

    private function rexParentExperienceKey(string $viewerKey): ?string
    {
        $experience = $this->playerExperiences->getByExperienceKey($viewerKey);
        if (!$experience) {
            return null;
        }

        $parentKey = strtolower(trim($experience->rexParentExperienceKey));
        return $parentKey !== '' ? $parentKey : null;
    }

    private function ensurePlaylistRelationship(
        RexReservation $parent,
        RexReservation $viewerReservation
    ): array {
        foreach ($this->relationships->parents($viewerReservation->id, self::VIEWER_RELATIONSHIP_KEY) as $existingParent) {
            if ($existingParent->id === $parent->id) {
                return [
                    'status' => 'ok',
                    'created' => false,
                    'existing' => true,
                    'parent_reservation_id' => $parent->id,
                ];
            }
        }

        $this->relationships->create(
            $parent->id,
            $viewerReservation->id,
            self::VIEWER_RELATIONSHIP_KEY,
            0
        );

        return [
            'status' => 'ok',
            'created' => true,
            'existing' => false,
            'parent_reservation_id' => $parent->id,
        ];
    }

    /**
     * @return RexReservation[]
     */
    private function matchingPlaylistReservations(int $playlistId, string $viewerKey): array
    {
        $matches = array_values(array_filter(
            $this->reservations->findByResource('playlist', $playlistId, 500),
            static fn(RexReservation $reservation): bool =>
                $reservation->resolverKey === 'playlist_experience'
                && $reservation->resourceType === 'playlist'
                && $reservation->resourceId === $playlistId
                && $reservation->status === RexReserver::STATUS_ACTIVE
                && $reservation->revokedAt === null
                && strtolower(trim((string)($reservation->context['experience_key'] ?? ''))) === $viewerKey
        ));

        usort(
            $matches,
            static fn(RexReservation $a, RexReservation $b): int => $a->id <=> $b->id
        );

        return $matches;
    }

    private function reservationPayload(RexReservation $reservation, string $mode): array
    {
        return [
            'id' => $reservation->id,
            'token' => $reservation->token,
            'public_url' => $this->relationships->publicUrl($reservation),
            'mode' => $mode,
        ];
    }

    private function viewerLabel(array $project, array $plan, string $viewerKey): string
    {
        $projectTitle = $this->firstNonEmpty([
            $project['name'] ?? null,
            'Project #' . (int)($project['id'] ?? 0),
        ]);
        $planTitle = $this->firstNonEmpty([
            $plan['area_name'] ?? null,
            $plan['nickname'] ?? null,
            $plan['scheme_title'] ?? null,
            'Color Plan #' . (int)($plan['id'] ?? 0),
        ]);

        return "{$projectTitle} — {$planTitle} — {$this->formatLabel($viewerKey)} Viewer";
    }

    private function normalizeViewerKey(string $viewerKey): string
    {
        $viewerKey = strtolower(trim($viewerKey));
        if (!in_array($viewerKey, ['concept', 'client', 'painter'], true)) {
            throw new RuntimeException('Invalid Project Viewer format.');
        }

        return $viewerKey;
    }

    private function formatLabel(string $viewerKey): string
    {
        return ucfirst($viewerKey);
    }

    /**
     * @param array<int, mixed> $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $text = trim((string)($value ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return 'ColorFix Viewer';
    }
}
