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
        public ?string $context
    ) {}
}
