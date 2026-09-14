<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Entities;

final class PlaylistSet
{
    public function __construct(
        public ?int $id,
        public string $handle,
        public string $title,
        public ?string $subtitle = null,
        public ?string $context = null,
        public ?int $coverPhotoLibraryId = null,
        public ?string $endCtaLabel = null,
        public ?string $endCtaUrl = null,
        public bool $endCtaEnabled = true,
        public bool $isRetired = false,
        public ?string $retiredAt = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
}
