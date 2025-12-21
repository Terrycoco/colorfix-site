<?php
declare(strict_types=1);

namespace App\Entities;

class Playlist
{
    /** @var PlaylistStep[] */
    public array $steps;

    public function __construct(
        public string $playlist_id,
        public string $type,
        public string $title,
        array $steps = []
    ) {
        $this->steps = $steps;
    }
}
