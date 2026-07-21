<?php
declare(strict_types=1);

namespace App\Entities;

final class PlaylistInstanceSet
{
    public function __construct(
        public ?int $id,
        public string $handle,
        public string $title,
        public ?string $subtitle,
        public ?string $context,
        public ?string $endCtaLabel = null,
        public ?string $endCtaUrl = null,
        public bool $endCtaEnabled = true,
        public ?string $updatedAt = null
    ) {}
}
