<?php
declare(strict_types=1);

namespace App\REX\DTO;

final readonly class RexReservationLink
{
    public function __construct(
        public int $id,
        public int $parentReservationId,
        public int $childReservationId,
        public string $relationshipKey,
        public int $sortOrder,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}
}
