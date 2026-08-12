<?php
declare(strict_types=1);

namespace App\Entities;

final class PaletteViewerPhoto
{
    public function __construct(
        public int $paletteViewerPhotoId,
        public int $paletteViewerId,
        public ?int $photoLibraryId,
        public ?string $relPath,
        public string $photoType,
        public string $triggerMode,
        public ?int $triggerColorId,
        public ?string $caption,
        public ?string $altText,
        public int $orderIndex,
        public string $createdAt,
        public ?string $updatedAt
    ) {}
}
