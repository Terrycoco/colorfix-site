<?php
declare(strict_types=1);

namespace App\REX\Services;

use App\PV\Repos\PdoPVRepository;
use App\Repos\PdoPlaylistRepository;
use App\REX\Contracts\RexReservationRepositoryInterface;
use App\REX\DTO\RexReservation;
use PDO;
use Throwable;

final class RexPlaylistAudit
{
    public function __construct(
        private PDO $pdo,
        private PdoPlaylistRepository $playlists,
        private PdoPVRepository $pvs,
        private RexReservationRepositoryInterface $reservations,
    ) {}

    /**
     * Inspect a Playlist's palette → PV → Viewer REX chain plus its Thumbs child.
     *
     * Audit only. This service performs no writes.
     */
    public function audit(int $playlistId): array
    {
        if ($playlistId <= 0) {
            throw new \InvalidArgumentException('Valid playlist ID is required.');
        }

        $references = $this->playlists->listSavedPaletteReferencesByPlaylist($playlistId);
        $sourcePalettes = $this->groupReferencesBySavedPalette($references);

        $playlistRex = $this->canonicalPublicPlaylistRex($playlistId);

        $savedPaletteIds = array_keys($sourcePalettes);
        $publicPvIdsBySavedPalette = $this->findActivePublicPvIdsBySavedPaletteIds(
            $savedPaletteIds
        );

        $allPvIds = [];
        foreach ($publicPvIdsBySavedPalette as $pvIds) {
            foreach ($pvIds as $pvId) {
                $allPvIds[] = $pvId;
            }
        }

        $viewerRexByPv = $this->reservations->findActiveByResourceIds(
            'viewer',
            'palette_viewer',
            $allPvIds,
        );

        $linkedViewerRexIds = [];

        if ($playlistRex !== null) {
            foreach (
                $this->reservations->findChildRelationships(
                    $playlistRex->id,
                    'viewer'
                )
                as $relationship
            ) {
                $child = $relationship->reservation;

                if (
                    strtolower(trim($child->resolverKey)) !== 'viewer'
                    || strtolower(trim($child->resourceType)) !== 'palette_viewer'
                ) {
                    continue;
                }

                $linkedViewerRexIds[$child->id] = [
                    'link_id' => $relationship->linkId,
                    'sort_order' => $relationship->sortOrder,
                ];
            }
        }

        $items = [];
        $validPvCount = 0;

        foreach ($sourcePalettes as $savedPaletteId => $source) {
            $publicPvIds = $publicPvIdsBySavedPalette[$savedPaletteId] ?? [];

            $item = [
                'saved_palette_id' => $savedPaletteId,
                'playlist_item_ids' => $source['playlist_item_ids'],
                'reference_count' => $source['reference_count'],
                'sort_order' => $source['sort_order'],

                'pv_id' => null,
                'pv_title' => null,
                'public_pv_ids' => $publicPvIds,
                'color_count' => null,
                'has_photo' => null,

                'viewer_rex_id' => null,
                'viewer_rex_url' => null,
                'viewer_link_id' => null,

                'status' => 'ready',
                'message' => 'Ready',
            ];

            if ($publicPvIds === []) {
                $item['status'] = 'missing_pv';
                $item['message'] = 'No active Public Palette Viewer exists.';
                $items[] = $item;
                continue;
            }

            if (count($publicPvIds) > 1) {
                $item['status'] = 'multiple_public_pvs';
                $item['message'] = 'Multiple active Public Palette Viewers exist.';
                $items[] = $item;
                continue;
            }

            $pvId = $publicPvIds[0];
            $item['pv_id'] = $pvId;

            try {
                $pv = $this->pvs->findById($pvId);
            } catch (Throwable $e) {
                $item['status'] = 'invalid_pv';
                $item['message'] = $e->getMessage();
                $items[] = $item;
                continue;
            }

            if ($pv === null) {
                $item['status'] = 'missing_pv';
                $item['message'] = 'Public Palette Viewer could not be loaded.';
                $items[] = $item;
                continue;
            }

            $item['pv_title'] = trim((string)($pv->meta['title'] ?? ''));
            $item['color_count'] = count($pv->swatches);
            $item['has_photo'] = trim((string)($pv->meta['photo_url'] ?? '')) !== '';

            if ($item['color_count'] < 1) {
                $item['status'] = 'empty_pv';
                $item['message'] = 'Palette Viewer has no colors.';
                $items[] = $item;
                continue;
            }

            $validPvCount++;

            $viewerRexReservations = $viewerRexByPv[$pvId] ?? [];

            if ($viewerRexReservations === []) {
                $item['status'] = 'missing_viewer_rex';
                $item['message'] = 'Palette Viewer has no active Viewer REX.';
                $items[] = $item;
                continue;
            }

            if (count($viewerRexReservations) > 1) {
                $item['status'] = 'multiple_viewer_rex';
                $item['message'] = 'Palette Viewer has multiple active Viewer REX reservations.';
                $items[] = $item;
                continue;
            }

            $viewerRex = $viewerRexReservations[0];

            $item['viewer_rex_id'] = $viewerRex->id;
            $item['viewer_rex_url'] = '/t/' . $viewerRex->token;

            if ($playlistRex === null) {
                $item['status'] = 'missing_playlist_rex';
                $item['message'] = 'Playlist has no canonical active Public REX.';
                $items[] = $item;
                continue;
            }

            $link = $linkedViewerRexIds[$viewerRex->id] ?? null;

            if ($link === null) {
                $item['status'] = 'missing_viewer_link';
                $item['message'] = 'Viewer REX is not linked to the Playlist REX.';
                $items[] = $item;
                continue;
            }

            $item['viewer_link_id'] = $link['link_id'];
            $items[] = $item;
        }

        $readyCount = count(array_filter(
            $items,
            static fn(array $item): bool => $item['status'] === 'ready'
        ));

        $viewerIssueCount = count($items) - $readyCount;

        $thumbs = $this->auditThumbs(
            $playlistId,
            $playlistRex,
            $validPvCount,
        );

        $thumbsIssueCount = ($thumbs['required'] ?? false)
            && ($thumbs['status'] ?? '') !== 'ready'
                ? 1
                : 0;

        return [
            'playlist_id' => $playlistId,

            'playlist_rex' => $playlistRex === null
                ? null
                : [
                    'id' => $playlistRex->id,
                    'token' => $playlistRex->token,
                    'url' => '/t/' . $playlistRex->token,
                    'label' => $playlistRex->label,
                    'status' => $playlistRex->status,
                ],

            'summary' => [
                'reference_count' => count($references),
                'palette_count' => count($items),
                'valid_pv_count' => $validPvCount,
                'ready_count' => $readyCount,
                'issue_count' => $viewerIssueCount + $thumbsIssueCount,
                'viewer_issue_count' => $viewerIssueCount,
                'thumbs_issue_count' => $thumbsIssueCount,
                'thumbs_required' => (bool)($thumbs['required'] ?? false),
                'thumbs_status' => (string)($thumbs['status'] ?? ''),
                'is_clean' => $viewerIssueCount === 0 && $thumbsIssueCount === 0,
            ],

            'thumbs' => $thumbs,
            'items' => $items,
        ];
    }

    private function auditThumbs(
        int $playlistId,
        ?RexReservation $playlistRex,
        int $validPvCount,
    ): array {
        $required = $validPvCount > 1;

        $grouped = $this->reservations->findActiveByResourceIds(
            'playlist_thumbs',
            'playlist',
            [$playlistId],
        );

        $thumbsReservations = array_values(
            $grouped[$playlistId] ?? []
        );

        usort(
            $thumbsReservations,
            static fn(RexReservation $a, RexReservation $b): int =>
                $a->id <=> $b->id
        );

        $base = [
            'required' => $required,
            'valid_pv_count' => $validPvCount,
            'thumbs_rex_id' => null,
            'thumbs_rex_url' => null,
            'thumbs_link_id' => null,
            'fallback_rex_id' => null,
            'status' => $required ? 'missing_thumbs_rex' : 'not_required',
            'message' => $required
                ? 'Playlist has multiple valid PVs and needs a Thumbs REX.'
                : 'Thumbs REX is not required for fewer than 2 valid PVs.',
        ];

        if (!$required) {
            if (count($thumbsReservations) === 1) {
                $thumbsRex = $thumbsReservations[0];

                $base['thumbs_rex_id'] = $thumbsRex->id;
                $base['thumbs_rex_url'] = '/t/' . $thumbsRex->token;
                $base['fallback_rex_id'] = $thumbsRex->fallbackRexId;
                $base['status'] = 'retained';
                $base['message'] = 'Existing Thumbs REX is retained even though it is not currently required.';
            }

            return $base;
        }

        if ($playlistRex === null) {
            $base['status'] = 'missing_playlist_rex';
            $base['message'] = 'Playlist needs Thumbs but has no canonical active Public REX.';
            return $base;
        }

        if ($thumbsReservations === []) {
            return $base;
        }

        if (count($thumbsReservations) > 1) {
            $base['status'] = 'multiple_thumbs_rex';
            $base['message'] = 'Playlist has multiple active Thumbs REX reservations.';
            return $base;
        }

        $thumbsRex = $thumbsReservations[0];

        $base['thumbs_rex_id'] = $thumbsRex->id;
        $base['thumbs_rex_url'] = '/t/' . $thumbsRex->token;
        $base['fallback_rex_id'] = $thumbsRex->fallbackRexId;

        $matchingLink = null;

        foreach (
            $this->reservations->findChildRelationships(
                $playlistRex->id,
                'thumbs'
            )
            as $relationship
        ) {
            if ($relationship->reservation->id === $thumbsRex->id) {
                $matchingLink = $relationship;
                break;
            }
        }

        if ($matchingLink === null) {
            $base['status'] = 'missing_thumbs_link';
            $base['message'] = 'Thumbs REX exists but is not linked to the Playlist REX.';
            return $base;
        }

        $base['thumbs_link_id'] = $matchingLink->linkId;

        if ($thumbsRex->fallbackRexId !== $playlistRex->id) {
            $base['status'] = 'missing_thumbs_fallback';
            $base['message'] = 'Thumbs REX fallback does not point to the Playlist REX.';
            return $base;
        }

        $base['status'] = 'ready';
        $base['message'] = 'Thumbs REX is ready.';

        return $base;
    }

    /**
     * One audit row per unique saved palette.
     * Repeated references remain visible through reference_count/item IDs.
     */
    private function groupReferencesBySavedPalette(array $references): array
    {
        $grouped = [];

        foreach ($references as $reference) {
            $savedPaletteId = (int)($reference['saved_palette_id'] ?? 0);
            $playlistItemId = (int)($reference['playlist_item_id'] ?? 0);
            $sortOrder = max(0, (int)($reference['order_index'] ?? 0));

            if ($savedPaletteId <= 0) {
                continue;
            }

            if (!isset($grouped[$savedPaletteId])) {
                $grouped[$savedPaletteId] = [
                    'playlist_item_ids' => [],
                    'reference_count' => 0,
                    'sort_order' => $sortOrder,
                ];
            }

            $grouped[$savedPaletteId]['reference_count']++;

            if (
                $playlistItemId > 0
                && !in_array(
                    $playlistItemId,
                    $grouped[$savedPaletteId]['playlist_item_ids'],
                    true
                )
            ) {
                $grouped[$savedPaletteId]['playlist_item_ids'][] = $playlistItemId;
            }

            $grouped[$savedPaletteId]['sort_order'] = min(
                $grouped[$savedPaletteId]['sort_order'],
                $sortOrder
            );
        }

        uasort(
            $grouped,
            static fn(array $a, array $b): int =>
                $a['sort_order'] <=> $b['sort_order']
        );

        return $grouped;
    }

private function canonicalPublicPlaylistRex(int $playlistId): ?RexReservation
{
    $grouped = $this->reservations->findActiveByResourceIds(
        'playlist_experience',
        'playlist',
        [$playlistId],
    );

    $eligible = array_values(array_filter(
        $grouped[$playlistId] ?? [],
        static fn(RexReservation $reservation): bool =>
            strtolower(trim(
                (string)($reservation->experienceKey ?? '')
            )) === 'public'
    ));

    usort(
        $eligible,
        static fn(RexReservation $a, RexReservation $b): int =>
            $a->id <=> $b->id
    );

    return $eligible[0] ?? null;
}
    /**
     * @param int[] $savedPaletteIds
     * @return array<int, int[]>
     */
    private function findActivePublicPvIdsBySavedPaletteIds(
        array $savedPaletteIds
    ): array {
        $savedPaletteIds = array_values(array_unique(array_filter(
            array_map('intval', $savedPaletteIds),
            static fn(int $id): bool => $id > 0
        )));

        if ($savedPaletteIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [];

        foreach ($savedPaletteIds as $index => $savedPaletteId) {
            $placeholder = ':saved_palette_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $savedPaletteId;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                palette_viewer_id,
                saved_palette_id

             FROM palette_viewers

             WHERE saved_palette_id IN (" . implode(', ', $placeholders) . ")
               AND is_active = 1
               AND LOWER(TRIM(format)) = 'public'

             ORDER BY
                saved_palette_id ASC,
                palette_viewer_id ASC"
        );

        $stmt->execute($params);

        $grouped = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $savedPaletteId = (int)($row['saved_palette_id'] ?? 0);
            $pvId = (int)($row['palette_viewer_id'] ?? 0);

            if ($savedPaletteId <= 0 || $pvId <= 0) {
                continue;
            }

            $grouped[$savedPaletteId] ??= [];
            $grouped[$savedPaletteId][] = $pvId;
        }

        return $grouped;
    }
}
