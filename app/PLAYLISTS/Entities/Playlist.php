<?php
declare(strict_types=1);

namespace App\PLAYLISTS\Entities;


class Playlist
{
    /** @var PlaylistStep[] */
    public array $steps;

    public array $meta;

    public function __construct(
        public string $playlist_id,
        public string $type,
        public string $title,
        array $steps = [],
        array $meta = []
    ) {
        $this->steps = $steps;
        $this->meta = $meta;
    }
}
