<?php
declare(strict_types=1);

namespace App\ARTWORK\Services;

use App\PHOTOS\Repos\PdoPhotoLibraryRepository;
use App\ARTWORK\Repos\PdoArtworkRepository;
use App\PALETTES\Managers\SavedPaletteManager;
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
        $palette = $paletteId > 0 ? (new SavedPaletteManager($this->pdo))->get($paletteId) : null;
        $designs = $photos->listPublicInspiredDesigns((int)$photo['photo_library_id']);
        return [
            'artwork' => $artwork,
            'photo' => $this->photoPayload($photo),
            'paint_approximations' => $palette ? [
                'palette_id' => $paletteId,
                'palette' => $palette['palette'],
                'members' => array_map(static fn(array $color): array => [
                    'color_id' => isset($color['color_id']) ? (int)$color['color_id'] : null,
                    'name' => $color['color_name'] ?? '',
                    'brand' => $color['color_brand_name'] ?: ($color['color_brand'] ?? ''),
                    'code' => $color['color_code'] ?? '',
                    'hex' => $color['color_hex6'] ?? null,
                    'role' => $color['role'] ?? null,
                    'note' => $color['note'] ?? null,
                ], $palette['members']),
            ] : null,
            // Viewer selection/hero override is not yet an ARTWORK responsibility.
            // Return photo IDs so the eventual PV navigation can target each room.
            'inspired_designs' => array_map(fn(array $design): array => $this->photoPayload($design), $designs),
        ];
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
