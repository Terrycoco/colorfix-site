<?php
declare(strict_types=1);

namespace App\Entities;

final class PlaylistInstanceSetItem
{
    public function __construct(
        public ?int $id,
        public int $setId,
        public ?int $playlistInstanceId,
        public string $itemType,
        public ?int $targetSetId,
        public string $title,
        public string $photoUrl,
        public int $sortOrder
    ) {}
}
