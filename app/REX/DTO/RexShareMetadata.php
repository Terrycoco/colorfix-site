<?php
declare(strict_types=1);

namespace App\REX\DTO;

final readonly class RexShareMetadata
{
    public function __construct(
        public ?string $title = null,
        public ?string $description = null,
        public ?string $imageUrl = null,
    ) {}
}
