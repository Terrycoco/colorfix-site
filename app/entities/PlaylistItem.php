<?php
declare(strict_types=1);

namespace App\Entities;

class PlaylistItem
{
    public function __construct(
        public string $ap_id,
        public ?string $palette_hash,
        public ?string $image_url,
        public ?int $photo_library_id = null,
        public ?int $saved_palette_set_id = null,
        public ?string $saved_palette_photo_type = null,
        public ?string $title = null,
        public ?string $subtitle = null,
        public ?string $type = null,
        public ?bool $star = null,
        public ?string $layout = null,
        public ?string $transition = null,
        public ?int $duration_ms = null,
        public ?string $title_mode = null,
        public ?bool $exclude_from_thumbs = null,
        public ?bool $is_share_image = null,
        public ?string $alt_tag = null,
        public ?string $palette_title = null
    ) {
    }
}
