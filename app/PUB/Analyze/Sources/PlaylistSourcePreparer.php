<?php
declare(strict_types=1);

namespace App\PUB\Analyze\Sources;

use App\PALETTES\PV\PVService;
use App\PLAYLISTS\Repos\PdoPlaylistRepository;
use App\PHOTOS\Entities\PhotoEntity;
use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use App\PHOTOS\Services\PhotoEditorialService;
use App\REX\DTO\RexReservationRelationship;
use App\REX\Services\RexReservationRelationships;
use RuntimeException;

/**
 * PLAYLIST SOURCE PREPARER
 *
 * Converts a ColorFix Playlist into the canonical
 * source shape consumed by PUB Analyze.
 *
 * All active slides are collected neutrally; linked PVs use the public experience.
 */
final class PlaylistSourcePreparer
{
    public function __construct(
        private PdoPlaylistRepository $playlists,
        private PVService $pvService,
        private RexReservationRelationships $rexRelationships,
        private PhotoEditorialService $photoEditorial,
        private PdoPhotoLibraryRepository $photos,
    ) {}

    /**
     * @return array{
     *   source_type: string,
     *   source_id: int,
     *   title: string,
     *   editorial_type: ?string,
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
        $photoIds = array_values(array_unique(array_filter(array_map(
            static fn (array $item): int => (int)($item['photo_library_id'] ?? 0),
            $items
        ))));
        $photos = $this->photos->findManyByIds($photoIds);
        $editorials = $this->photoEditorial->getMany($photoIds);

        $items = array_map(
            static function (array $item) use ($projectRoot, $photos, $editorials): array {
                $photoLibraryId = (int)($item['photo_library_id'] ?? 0);
                if ($photoLibraryId <= 0) return $item;
                $record = $photos[$photoLibraryId] ?? [];
                $imageUrl = trim((string)($item['image_url'] ?? ''));
                if ($imageUrl === '') $imageUrl = trim((string)($record['rel_path'] ?? ''));

                // Keep the full owner record even when no usable image URL exists.
                $photo = $record;
                if ($imageUrl !== '') {
                    $entity = new PhotoEntity(
                        photoLibraryId: $photoLibraryId,
                        imageUrl: $imageUrl,
                        filePath: rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($imageUrl, '/'),
                        title: array_key_exists('title', $record)
                            ? $record['title']
                            : (trim((string)($item['title'] ?? '')) ?: null),
                    );
                    $photo = array_replace($record, $entity->toArray());
                }
                if ($photo === []) return $item;
                $editorial = $editorials[$photoLibraryId] ?? [];
                $photo['editorial'] = [
                    'photo_description' => $editorial['photo_description'] ?? null,
                    'design_notes' => $editorial['design_notes'] ?? null,
                    'refined_commentary' => $editorial['refined_commentary'] ?? null,
                ];
                $item['photo'] = $photo;
                return $item;
            },
            $items
        );

        $linkedPVs = $this->publicLinkedPVData($playlistId);

        return [
            'source_type' => 'playlist',
            'source_id' => $playlistId,
            'title' => (string)($playlist['title'] ?? ''),
            'editorial_type' => $playlist['editorial_type'] ?? null,
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
