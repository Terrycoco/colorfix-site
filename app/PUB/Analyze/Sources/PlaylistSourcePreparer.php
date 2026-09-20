<?php
declare(strict_types=1);

namespace App\PUB\Analyze\Sources;

use App\PALETTES\PV\PVService;
use App\PLAYLISTS\Repos\PdoPlaylistRepository;
use App\PHOTOS\Entities\PhotoEntity;
use App\REX\DTO\RexReservationRelationship;
use App\REX\Services\RexReservationRelationships;
use RuntimeException;

/**
 * PLAYLIST SOURCE PREPARER
 *
 * Converts a ColorFix Playlist into the canonical
 * source shape consumed by PUB Analyze.
 *
 * PUB deliberately consumes the PUBLIC Playlist experience.
 */
final class PlaylistSourcePreparer
{
    public function __construct(
        private PdoPlaylistRepository $playlists,
        private PVService $pvService,
        private RexReservationRelationships $rexRelationships,
    ) {}

    /**
     * @return array{
     *   source_type: string,
     *   source_id: int,
     *   title: string,
     *   items: array<int, array<string, mixed>>,
     *   linked_pvs: array<int, array<string, mixed>>
     * }
     */
    public function prepare(int $playlistId): array
    {
        if ($playlistId <= 0) {
            throw new RuntimeException(
                'PlaylistSourcePreparer requires a valid playlist ID.'
            );
        }

        $playlist = $this->playlists->getAdminRowById($playlistId);

        if ($playlist === null) {
            throw new RuntimeException('Playlist not found.');
        }

        $items = $this->playlists->getActivePublishingSlides($playlistId);

        $projectRoot = dirname(__DIR__, 4);

        $items = array_map(
            static function (array $item) use ($projectRoot): array {
                $photoLibraryId = (int)($item['photo_library_id'] ?? 0);
                $imageUrl = trim((string)($item['image_url'] ?? ''));

                if ($photoLibraryId <= 0 || $imageUrl === '') {
                    return $item;
                }

                $filePath =
                    rtrim($projectRoot, DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR
                    . ltrim($imageUrl, '/');

                $photo = new PhotoEntity(
                    photoLibraryId: $photoLibraryId,
                    imageUrl: $imageUrl,
                    filePath: $filePath,
                    title: trim((string)($item['title'] ?? '')) ?: null,
                );

                $item['photo'] = $photo->toArray();

                return $item;
            },
            $items
        );

        $linkedPVs = $this->publicLinkedPVData($playlistId);

        return [
            'source_type' => 'playlist',
            'source_id' => $playlistId,
            'title' => (string)($playlist['title'] ?? ''),
            'items' => $items,
            'linked_pvs' => $linkedPVs,
        ];
    }

    /**
     * Build exactly the linked-PV projection declared by PUBContract.
     *
     * REX owns which PVs are related to the Playlist's public experience.
     * PALETTES owns the PV/photo/palette data itself.
     *
     * @return array<int, array{
     *   pv_id:int,
     *   kicker:string,
     *   intro:string,
     *   photo_palettes:array<int, array{
     *     photo_library_id:int,
     *     hex6s:array<int,string>
     *   }>
     * }>
     */
    private function publicLinkedPVData(int $playlistId): array
    {
        $relationships =
            $this->rexRelationships->childrenForResourceExperience(
                'playlist_experience',
                'playlist',
                $playlistId,
                'public',
                'viewer',
            );

        $result = [];
        $seenPvIds = [];

        foreach ($relationships as $relationship) {
            if (!$relationship instanceof RexReservationRelationship) {
                continue;
            }

            $reservation = $relationship->reservation;

            if (
                strtolower(trim($reservation->status)) !== 'active'
                || strtolower(trim($reservation->resolverKey)) !== 'viewer'
                || strtolower(trim($reservation->resourceType)) !== 'palette_viewer'
            ) {
                continue;
            }

            $pvId = (int)$reservation->resourceId;

            if ($pvId <= 0 || isset($seenPvIds[$pvId])) {
                continue;
            }

            $seenPvIds[$pvId] = true;

            $pv = $this->pvService->getPV($pvId);
            $meta = is_array($pv['meta'] ?? null)
                ? $pv['meta']
                : [];
            $swatches = is_array($pv['swatches'] ?? null)
                ? $pv['swatches']
                : [];

            $hex6s = [];

            foreach ($swatches as $swatch) {
                if (!is_array($swatch)) {
                    continue;
                }

                $hex6 = trim((string)($swatch['hex6'] ?? ''));

                if ($hex6 !== '' && !in_array($hex6, $hex6s, true)) {
                    $hex6s[] = $hex6;
                }
            }

            $photoLibraryIds = is_array($meta['photo_library_ids'] ?? null)
                ? $meta['photo_library_ids']
                : [];

            $photoPalettes = [];
            $seenPhotoIds = [];

            foreach ($photoLibraryIds as $photoLibraryId) {
                $photoLibraryId = (int)$photoLibraryId;

                if (
                    $photoLibraryId <= 0
                    || isset($seenPhotoIds[$photoLibraryId])
                ) {
                    continue;
                }

                $seenPhotoIds[$photoLibraryId] = true;

                $photoPalettes[] = [
                    'photo_library_id' => $photoLibraryId,
                    'hex6s' => $hex6s,
                ];
            }

            $result[] = [
                'pv_id' => $pvId,
                'kicker' => trim((string)($meta['kicker_text'] ?? '')),
                'intro' => trim((string)($meta['intro'] ?? '')),
                'photo_palettes' => $photoPalettes,
            ];
        }

        return $result;
    }
}
