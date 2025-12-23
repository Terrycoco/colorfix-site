<?php
declare(strict_types=1);

namespace App\Entities;

class PlaylistItem
{
    public function __construct(
        public string $ap_id,
        public string $image_url,
        public ?string $title = null,
        public ?string $transition = null,
        public ?int $duration_ms = null,
        public ?string $title_mode = null
    ) {
    }
}
