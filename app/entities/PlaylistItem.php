<?php
declare(strict_types=1);

namespace App\Entities;

class PlaylistItem
{
    public function __construct(
        public string $ap_id,
        public ?string $palette_hash,
        public ?string $image_url,
        public ?string $title = null,
        public ?string $subtitle = null,
        public ?string $type = null,
        public ?bool $star = null,
        public ?string $layout = null,
        public ?string $transition = null,
        public ?int $duration_ms = null,
        public ?string $title_mode = null,
        public ?bool $exclude_from_thumbs = null
    ) {
    }
}
