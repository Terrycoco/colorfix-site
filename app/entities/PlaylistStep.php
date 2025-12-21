<?php
declare(strict_types=1);

namespace App\Entities;

class PlaylistStep
{
    /** @var PlaylistItem[] */
    public array $items;

    public function __construct(
        public string $step_id,
        public bool $is_group,
        array $items = []
    ) {
        $this->items = $items;
    }
}
