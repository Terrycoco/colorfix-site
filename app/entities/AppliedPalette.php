<?php
declare(strict_types=1);

namespace App\Entities;

class AppliedPalette
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $title,
        public readonly ?string $displayTitle,
        public readonly ?string $notes,
        public readonly ?string $tags,
        public readonly ?int $kickerId,
        public readonly ?string $altText,
        public readonly int $photoId,
        public readonly string $assetId,
        public readonly array $entries
    ) {}
}
