<?php
declare(strict_types=1);

namespace App\ARTWORK\Services;

use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use App\ARTWORK\Repos\PdoArtworkRepository;
use App\PALETTES\Services\PaletteService;
use App\PLAYLISTS\Repos\PdoPlaylistRepository;
use App\PLAYLISTS\Repos\PdoPlayerExperienceRepository;
use App\REX\Repos\PdoRexReservationRepository;
use InvalidArgumentException;
use OutOfBoundsException;
use PDO;

/** Public, read-only composition of information owned by ARTWORK, PHOTOS and PALETTES. */
final class ArtworkPageService
{
    public function __construct(private PDO $pdo) {}

    /** Read-only artwork list for the existing public Gallery art-swatch renderer. */
    public function listGallery(): array
    {
        $rows = (new PdoArtworkRepository($this->pdo))->listPublicGallery();
        return array_map(static function (array $row): array {
            $path = trim((string)($row['photo_url'] ?? ''));
            $safePath = $path !== '' && !str_starts_with($path, '//')
                && !preg_match('/^[a-z][a-z0-9+.-]*:/i', $path)
                && !str_contains($path, '..')
                ? '/' . ltrim($path, '/') : '';
            return [
                'id' => 'art_' . $row['artwork_id'],
                'item_type' => 'art-swatch',
                'artwork_id' => (int)$row['artwork_id'],
                'photo_library_id' => (int)$row['photo_library_id'],
                'display_title' => $row['display_title'] ?? '',
                'official_title' => $row['official_title'] ?? '',
                'title' => $row['title'] ?? '',
                'artist' => $row['artist'] ?? '',
                'artist_last' => $row['artist_last'] ?? '',
                'photo_url' => $safePath,
                'crop_x' => $row['crop_x'] === null ? null : (float)$row['crop_x'],
                'crop_y' => $row['crop_y'] === null ? null : (float)$row['crop_y'],
                'crop_width' => $row['crop_width'] === null ? null : (float)$row['crop_width'],
                'crop_height' => $row['crop_height'] === null ? null : (float)$row['crop_height'],
            ];
        }, $rows);
    }

    /** The existing Gallery ArtSwatch links by artwork_id; photo_library_id is also supported. */
    public function get(?int $artworkId = null, ?int $photoLibraryId = null): array
    {
        if (($artworkId ?? 0) <= 0 && ($photoLibraryId ?? 0) <= 0) {
            throw new InvalidArgumentException('artwork_id or photo_library_id is required.');
        }
        $artworkService = new ArtworkService($this->pdo);
        $artwork = $artworkId !== null && $artworkId > 0
            ? $artworkService->findById($artworkId)
            : $artworkService->findByPhotoId((int)$photoLibraryId);
        if (!$artwork) { throw new OutOfBoundsException('Artwork not found.'); }

        $photos = new PdoPhotoLibraryRepository($this->pdo);
        $photo = $photos->findPublicArtworkPhoto((int)$artwork['photo_library_id']);
        if (!$photo) { throw new OutOfBoundsException('Artwork not publicly available.'); }

        $paletteId = (int)($photo['palette_id'] ?? 0);
        $swatches = $paletteId > 0 ? (new PaletteService($this->pdo))->getSwatches($paletteId) : [];
        $designs = $photos->listPublicInspiredDesigns((int)$photo['photo_library_id']);
        return [
            'artwork' => $artwork,
            'photo' => $this->photoPayload($photo),
            'paint_approximations' => $paletteId > 0 ? [
                'palette_id' => $paletteId,
                'members' => $swatches,
            ] : null,
            // Viewer selection/hero override is not yet an ARTWORK responsibility.
            // Return photo IDs so the eventual PV navigation can target each room.
            'inspired_designs' => array_map(fn(array $design): array => $this->photoPayload($design), $designs),
            'playlists' => $this->publicPlaylists($artworkService, (int)$artwork['artwork_id'], (int)$photo['photo_library_id']),
        ];
    }

    /** Read-only reverse of the existing contextual Art Study CTA; never reserve a token. */
    private function publicPlaylists(ArtworkService $artwork, int $artworkId, int $photoId): array
    {
        $experience = (new PdoPlayerExperienceRepository($this->pdo))->getByExperienceKey('public');
        if (!$experience || !$experience->isActive) { return []; }
        $candidates = (new PdoPlaylistRepository($this->pdo))->findPublicForArtworkPhoto($photoId, $experience->slideFlag);
        if (!$candidates) { return []; }
        // This existing lookup also normalizes legacy context.experience_key values.
        $reservations = (new PdoRexReservationRepository($this->pdo))->findActiveByResourceIds(
            'playlist_experience', 'playlist', array_keys($candidates)
        );
        $links = [];
        foreach ($candidates as $id => $playlist) {
            $public = null;
            foreach ($reservations[$id] ?? [] as $reservation) {
                if ($reservation->experienceKey === 'public' && $reservation->revokedAt === null && trim($reservation->token) !== '') {
                    $public = $reservation;
                    break; // Repository orders oldest stable reservation first.
                }
            }
            if (!$public || $artwork->publicStudyUrlForPhotos($playlist['photo_ids']) !== '/inspired-by/' . $artworkId) {
                continue;
            }
            $links[] = ['playlist_id' => $id, 'title' => $playlist['title'], 'rex_url' => '/t/' . rawurlencode($public->token)];
        }
        return $links;
    }

    private function photoPayload(array $photo): array
    {
        $path = trim((string)($photo['rel_path'] ?? ''));
        // Allow local image URLs only; prevent javascript:, external hosts, and scheme-relative URLs.
        $safePath = $path !== '' && !str_starts_with($path, '//')
            && !preg_match('/^[a-z][a-z0-9+.-]*:/i', $path)
            && !str_contains($path, '..')
            ? '/' . ltrim($path, '/') : '';
        return [
            'photo_library_id' => (int)$photo['photo_library_id'],
            'title' => $photo['title'] ?? '',
            'image_url' => $safePath,
            'alt_text' => $photo['alt_text'] ?? '',
            'palette_id' => isset($photo['palette_id']) ? (int)$photo['palette_id'] : null,
        ];
    }
}
