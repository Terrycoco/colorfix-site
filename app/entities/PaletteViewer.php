<?php
declare(strict_types=1);

namespace App\Entities;

final class PaletteViewer
{
    public function __construct(
        public int $paletteViewerId,
        public int $savedPaletteId,
        public string $format,
        public ?string $templateKey,
        public ?string $kickerText,
        public ?string $title,
        public ?string $intro,
        public ?string $notes,
        public ?string $ctaLabel,
        public bool $isActive,
        public string $createdAt,
        public ?string $updatedAt
    ) {}
}
