<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Entities;

final class PlaylistSetItem
{
    public function __construct(
        public ?int $id,
        public int $playlistSetId,
        public ?int $playlistId,
        public ?int $targetSetId,
        public string $itemType = 'playlist',
        public int $sortOrder = 0,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
}
