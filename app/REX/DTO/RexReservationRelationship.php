<?php
declare(strict_types=1);

namespace App\REX\DTO;

final readonly class RexReservationRelationship
{
    public function __construct(
        public int $linkId,
        public string $relationshipKey,
        public int $sortOrder,
        public RexReservation $reservation,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}
}
