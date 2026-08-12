<?php
declare(strict_types=1);

namespace App\REX\DTO;

final readonly class RexUpdateMetadataRequest
{
    public function __construct(
        public int $reservationId,
        public string $label,
        public ?string $sourceKey = null,
    ) {}
}
