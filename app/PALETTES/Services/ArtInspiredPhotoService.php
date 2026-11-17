<?php
declare(strict_types=1);
namespace App\PALETTES\Services;

use App\PHOTOS\Services\PhotoInspirationService;
use App\PHOTOS\Services\PhotoDeliveryService;
use App\ARTWORK\Services\ArtworkService;
use OutOfBoundsException;
use PDO;

/** Presentation context only: neither a new PV nor a copy of the artwork palette. */
final class ArtInspiredPhotoService
{
    public function __construct(private PDO $pdo) {}

    public function context(int $photoId): array
    {
        $pair = (new PhotoInspirationService($this->pdo))->getPublicInspiration($photoId);
        if (!$pair) throw new OutOfBoundsException('Art-inspired photograph is not publicly available.');
        $art = $pair['artwork_photo'];
        $metadata = (new ArtworkService($this->pdo))->findByPhotoId((int)$art['photo_library_id']);
        $paletteId = (int)($art['palette_id'] ?? 0);
        if (!$metadata || $paletteId <= 0) throw new OutOfBoundsException('Artwork metadata or palette is unavailable.');
        return [...$pair, 'artwork' => $metadata, 'palette_id' => $paletteId];
    }

    /** Preserve every swatch and interaction; replace only the room-specific photo presentation. */
    public function apply(array $viewer, int $photoId, int $paletteId): array
    {
        $context = $this->context($photoId);
        if ($context['palette_id'] !== $paletteId) throw new OutOfBoundsException('The artwork palette association has changed.');
        $room = $context['room']; $art = $context['artwork_photo']; $metadata = $context['artwork'];
        $viewer['meta'] = [...($viewer['meta'] ?? []),
            'photo_library_id' => $photoId,
            'photo_library_ids' => [$photoId, (int)$art['photo_library_id']],
            'photo_url' => $room['rel_path'],
            'photo_alt' => $room['alt_text'] ?: ($room['title'] ?? 'Art-inspired room'),
            'display_image_url' => PhotoDeliveryService::url($photoId, $room['rel_path']),
            'inset_photos' => [],
            'art_inspiration' => [
                'photo_library_id' => (int)$art['photo_library_id'],
                'url' => $art['rel_path'],
                'alt_text' => $metadata['display_title'] ?: ($metadata['official_title'] ?: 'Original artwork'),
                'artwork_id' => (int)$metadata['artwork_id'],
                'study_url' => ArtworkService::studyUrl((int)$metadata['artwork_id']),
            ],
        ];
        return $viewer;
    }
}
