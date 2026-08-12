<?php
declare(strict_types=1);

namespace App\REX\DTO;

final readonly class RexAlias
{
    public function __construct(
        public int $id,
        public int $reservationId,
        public string $alias,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}
}
