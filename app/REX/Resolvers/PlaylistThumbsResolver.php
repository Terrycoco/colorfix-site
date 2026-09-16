<?php
declare(strict_types=1);

namespace App\REX\Resolvers;

use App\PALETTES\Repos\PdoPVRepository;
use App\REX\Contracts\RexResolverInterface;
use App\REX\DTO\RexReservation;
use App\REX\DTO\RexReservationDescriptor;
use App\REX\DTO\RexResolutionBehavior;
use App\REX\DTO\RexResolutionRequest;
use App\REX\DTO\RexResolutionResult;
use App\REX\DTO\RexShareMetadata;
use App\REX\Repos\PdoRexReservationRepository;
use App\PLAYLISTS\Repos\PdoPlaylistRepository;
use PDO;
use RuntimeException;

final class PlaylistThumbsResolver implements RexResolverInterface
{
    private PdoPVRepository $pvs;
    private PdoPlaylistRepository $playlists;
    private PdoRexReservationRepository $reservations;

    public function __construct(
        private PDO $pdo
    ) {
        $this->pvs = new PdoPVRepository($pdo);
        $this->playlists = new PdoPlaylistRepository($pdo);
        $this->reservations = new PdoRexReservationRepository($pdo);
    }

    public function resolve(
        RexResolutionRequest $request
    ): RexResolutionResult {
        if ($request->resourceType !== 'playlist') {
            throw new RuntimeException(
                "Playlist Thumbs resolver requires resource_type 'playlist'."
            );
        }

        $playlistId = $request->resourceId;

        if ($playlistId <= 0) {
            throw new RuntimeException(
                'Playlist Thumbs reservation requires a valid playlist ID.'
            );
        }

        /*
         * The Thumbs reservation does not store collection membership.
         * Its parent Playlist REX owns that relationship graph.
         */
        $parentRelationships = $this->reservations->findParentRelationships(
            $request->reservation->id,
            'thumbs'
        );

        if (count($parentRelationships) !== 1) {
            throw new RuntimeException(
                count($parentRelationships) === 0
                    ? 'Playlist Thumbs REX has no parent Playlist REX.'
                    : 'Playlist Thumbs REX has multiple parent Playlist REX relationships.'
            );
        }

        $parentRex = $parentRelationships[0]->reservation;

        if (
            strtolower(trim($parentRex->resolverKey)) !== 'playlist_experience'
            || strtolower(trim($parentRex->resourceType)) !== 'playlist'
            || $parentRex->resourceId !== $playlistId
            || strtolower(trim($parentRex->status)) !== 'active'
        ) {
            throw new RuntimeException(
                'Playlist Thumbs parent is not the active Playlist REX for this playlist.'
            );
        }

        $playlist = $this->playlists->getAdminRowById($playlistId);

        if (!$playlist) {
            throw new RuntimeException(
                "Playlist {$playlistId} was not found."
            );
        }

        $playlistTitle = trim((string)($playlist['title'] ?? ''));

        if ($playlistTitle === '') {
            $playlistTitle = "Playlist #{$playlistId}";
        }

        $viewerRelationships = $this->reservations->findChildRelationships(
            $parentRex->id,
            'viewer'
        );

        usort(
            $viewerRelationships,
            static function ($a, $b): int {
                $sort = $a->sortOrder <=> $b->sortOrder;

                if ($sort !== 0) {
                    return $sort;
                }

                return $a->linkId <=> $b->linkId;
            }
        );

        $items = [];
        $seenPvIds = [];

        foreach ($viewerRelationships as $relationship) {
            $viewerRex = $relationship->reservation;

            if (
                strtolower(trim($viewerRex->resolverKey)) !== 'viewer'
                || strtolower(trim($viewerRex->resourceType)) !== 'palette_viewer'
                || strtolower(trim($viewerRex->status)) !== 'active'
            ) {
                continue;
            }

            $pvId = $viewerRex->resourceId;

            if ($pvId <= 0 || isset($seenPvIds[$pvId])) {
                continue;
            }

            $pv = $this->pvs->findById($pvId);

            if ($pv === null || !$pv->isActive || count($pv->swatches) < 1) {
                continue;
            }

            $format = strtolower(trim(
                (string)($pv->meta['format'] ?? '')
            ));

            if ($format !== 'public') {
                continue;
            }

            $seenPvIds[$pvId] = true;

            $title = trim((string)($pv->meta['title'] ?? ''));

            if ($title === '') {
                $title = "Palette Viewer #{$pvId}";
            }

            $swatches = [];

            foreach ($pv->swatches as $swatch) {
                $hex6 = trim((string)($swatch['hex6'] ?? ''));

                if ($hex6 === '') {
                    continue;
                }

                $swatches[] = [
                    'hex6' => $hex6,
                    'name' => $this->nullableText(
                        $swatch['name'] ?? null
                    ),
                    'code' => $this->nullableText(
                        $swatch['code'] ?? null
                    ),
                ];
            }

            if ($swatches === []) {
                continue;
            }

            $items[] = [
                'pv_id' => $pvId,
                'title' => $title,
                'photo_url' => trim(
                    (string)($pv->meta['photo_url'] ?? '')
                ),
                'photo_alt' => $this->nullableText(
                    $pv->meta['photo_alt'] ?? null
                ),
                'swatches' => $swatches,
                'viewer_rex_id' => $viewerRex->id,
                'viewer_url' => '/t/' . $viewerRex->token,
                'sort_order' => $relationship->sortOrder,
            ];
        }

        /*
         * A published Thumbs REX is allowed to outlive the multi-PV state.
         * One current Viewer still renders meaningfully. Zero current Viewers
         * is not meaningful, so throw and let the REX fallback take over.
         */
        if ($items === []) {
            throw new RuntimeException(
                'Playlist Thumbs REX has no valid Viewer children.'
            );
        }

        $firstPhotoUrl = null;

        foreach ($items as $item) {
            $candidate = trim((string)($item['photo_url'] ?? ''));

            if ($candidate !== '') {
                $firstPhotoUrl = $candidate;
                break;
            }
        }

        return new RexResolutionResult(
            resolverKey: 'playlist_thumbs',
            resourceType: 'playlist',
            resourceId: $playlistId,
            behavior: RexResolutionBehavior::RENDER,
            shareMetadata: new RexShareMetadata(
                title: $playlistTitle . ' — Colors Used',
                description: "Explore the colors used in {$playlistTitle}.",
                imageUrl: $firstPhotoUrl,
            ),
            destination: [
                'collection' => [
                    'playlist_id' => $playlistId,
                    'playlist_title' => $playlistTitle,
                    'title' => 'Colors Used',
                    'parent_rex_id' => $parentRex->id,
                    'parent_url' => '/t/' . $parentRex->token,
                    'items' => $items,
                ],
            ],
            analyticsMetadata: [
                'reservation_id' => $request->reservation->id,
                'playlist_id' => $playlistId,
                'parent_rex_id' => $parentRex->id,
                'viewer_count' => count($items),
            ],
        );
    }

    public function describe(
        RexReservation $reservation
    ): RexReservationDescriptor {
        return $this->describePlaylist(
            $reservation->resourceType,
            $reservation->resourceId,
        );
    }

    public function previewDescribe(
        string $resourceType,
        int $resourceId,
        array $context
    ): RexReservationDescriptor {
        return $this->describePlaylist(
            $resourceType,
            $resourceId,
        );
    }

    private function describePlaylist(
        string $resourceType,
        int $playlistId,
    ): RexReservationDescriptor {
        if ($resourceType !== 'playlist') {
            throw new RuntimeException(
                "Playlist Thumbs resolver requires resource_type 'playlist'."
            );
        }

        if ($playlistId <= 0) {
            throw new RuntimeException(
                'Playlist Thumbs requires a valid playlist ID.'
            );
        }

        $playlist = $this->playlists->getAdminRowById($playlistId);

        if (!$playlist) {
            throw new RuntimeException(
                "Playlist {$playlistId} was not found."
            );
        }

        $playlistTitle = trim((string)($playlist['title'] ?? ''));
        $playlistSlug = trim((string)($playlist['slug'] ?? ''));

        return new RexReservationDescriptor(
            title: $playlistTitle !== ''
                ? "{$playlistTitle} — Thumbs"
                : "Playlist #{$playlistId} — Thumbs",
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
                    'label' => 'View',
                    'value' => 'Thumbs',
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

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));

        return $text === '' ? null : $text;
    }
}
