<?php
declare(strict_types=1);

namespace App\REX\Services;

use App\REX\Contracts\RexReservationRepositoryInterface;
use App\REX\DTO\RexCreateReservationRequest;
use App\REX\DTO\RexReservation;
use App\Repos\PdoPaletteViewerRepository;
use App\Repos\PdoPlaylistRepository;
use InvalidArgumentException;

final class RexPlaylistViewerLinker
{
    public function __construct(
        private PdoPlaylistRepository $playlists,
        private PdoPaletteViewerRepository $paletteViewers,
        private RexReservationRepositoryInterface $reservations,
        private RexReservationRelationships $relationships,
        private RexReserver $reserver,
    ) {}

    public function linkAll(?int $playlistId = null): array
    {
        $references = $this->playlists->listSavedPaletteReferencesByPlaylist($playlistId);

        $playlistIds = [];
        $savedPaletteIds = [];
        foreach ($references as $reference) {
            $playlistIds[] = (int)($reference['playlist_id'] ?? 0);
            $savedPaletteIds[] = (int)($reference['saved_palette_id'] ?? 0);
        }

        $playlistReservations = $this->reservations->findActiveByResourceIds(
            'playlist_experience',
            'playlist',
            $playlistIds,
        );
        $viewersBySavedPalette = $this->paletteViewers->findActivePublicBySavedPaletteIds($savedPaletteIds);

        $viewerIds = [];
        foreach ($viewersBySavedPalette as $viewers) {
            foreach ($viewers as $viewer) {
                $viewerIds[] = $viewer->paletteViewerId;
            }
        }

        $viewerReservations = $this->reservations->findActiveByResourceIds(
            'viewer',
            'palette_viewer',
            $viewerIds,
        );

        $seenPairs = [];
        $created = [];
        $existing = [];
        $skipped = [];

        foreach ($references as $reference) {
            $playlistId = (int)($reference['playlist_id'] ?? 0);
            $savedPaletteId = (int)($reference['saved_palette_id'] ?? 0);
            $playlistItemId = (int)($reference['playlist_item_id'] ?? 0);
            $sortOrder = max(0, (int)($reference['order_index'] ?? 0));

            if ($playlistId <= 0 || $savedPaletteId <= 0) {
                $skipped[] = $this->skip($reference, 'missing playlist or saved palette id');
                continue;
            }

            $parents = $this->canonicalPublicPlaylistParents($playlistReservations[$playlistId] ?? []);
            if (count($parents) === 0) {
                $skipped[] = $this->skip($reference, 'missing playlist REX');
                continue;
            }

            $viewers = $viewersBySavedPalette[$savedPaletteId] ?? [];
            if (count($viewers) !== 1) {
                $skipped[] = $this->skip($reference, count($viewers) === 0 ? 'missing public palette viewer' : 'multiple public palette viewers');
                continue;
            }

            $viewer = $viewers[0];
            $children = $viewerReservations[$viewer->paletteViewerId] ?? [];
            if (count($children) > 1) {
                $skipped[] = $this->skip($reference, 'multiple viewer REX reservations');
                continue;
            }

            $child = $children[0] ?? $this->reserver->reserve(new RexCreateReservationRequest(
                label: $this->viewerLabel($viewer),
                resolverKey: 'viewer',
                resourceType: 'palette_viewer',
                resourceId: $viewer->paletteViewerId,
                adminNote: 'Auto-created by REX playlist/viewer linker.',
                context: ['format' => $viewer->format ?: 'public'],
            ));
            $viewerReservations[$viewer->paletteViewerId] = [$child];

            foreach ($parents as $parent) {
                $pairKey = $parent->id . ':' . $child->id;
                if (isset($seenPairs[$pairKey])) {
                    continue;
                }
                $seenPairs[$pairKey] = true;

                try {
                    $link = $this->relationships->create($parent->id, $child->id, 'viewer', $sortOrder);
                    $created[] = [
                        'link_id' => $link->id,
                        'parent_reservation_id' => $parent->id,
                        'child_reservation_id' => $child->id,
                        'playlist_id' => $playlistId,
                        'saved_palette_id' => $savedPaletteId,
                        'palette_viewer_id' => $viewer->paletteViewerId,
                        'playlist_item_id' => $playlistItemId,
                        'sort_order' => $sortOrder,
                    ];
                } catch (InvalidArgumentException $e) {
                    if (stripos($e->getMessage(), 'already exists') !== false) {
                        $existing[] = [
                            'parent_reservation_id' => $parent->id,
                            'child_reservation_id' => $child->id,
                            'playlist_id' => $playlistId,
                            'saved_palette_id' => $savedPaletteId,
                            'palette_viewer_id' => $viewer->paletteViewerId,
                            'playlist_item_id' => $playlistItemId,
                        ];
                        continue;
                    }
                    $skipped[] = $this->skip($reference, $e->getMessage());
                }
            }
        }

        return [
            'references' => count($references),
            'created_count' => count($created),
            'existing_count' => count($existing),
            'skipped_count' => count($skipped),
            'created' => $created,
            'existing' => $existing,
            'skipped' => $skipped,
        ];
    }

    private function skip(array $reference, string $reason): array
    {
        return [
            'reason' => $reason,
            'playlist_item_id' => (int)($reference['playlist_item_id'] ?? 0),
            'playlist_id' => (int)($reference['playlist_id'] ?? 0),
            'saved_palette_set_id' => (int)($reference['saved_palette_set_id'] ?? 0),
            'saved_palette_id' => (int)($reference['saved_palette_id'] ?? 0),
        ];
    }

    /**
     * @param RexReservation[] $reservations
     * @return RexReservation[]
     */
    private function canonicalPublicPlaylistParents(array $reservations): array
    {
        $eligible = array_values(array_filter(
            $reservations,
            static fn(RexReservation $reservation): bool =>
                strtolower(trim($reservation->resolverKey)) === 'playlist_experience'
                && strtolower(trim($reservation->resourceType)) === 'playlist'
                && strtolower(trim($reservation->status)) === 'active'
                && strtolower(trim((string)($reservation->context['experience_key'] ?? ''))) === 'public'
        ));

        usort(
            $eligible,
            static fn(RexReservation $a, RexReservation $b): int => $a->id <=> $b->id
        );

        return isset($eligible[0]) ? [$eligible[0]] : [];
    }

    private function viewerLabel(object $viewer): string
    {
        $label = trim((string)($viewer->title ?? ''));
        if ($label !== '') {
            return $label;
        }

        return 'Palette Viewer #' . (int)($viewer->paletteViewerId ?? 0);
    }
}
