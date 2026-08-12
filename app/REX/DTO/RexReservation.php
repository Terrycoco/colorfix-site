<?php
declare(strict_types=1);

namespace App\REX\DTO;

final readonly class RexReservation
{
    public function __construct(
        public int $id,
        public string $token,
        public string $label,
        public ?string $adminNote,
        public ?string $sourceKey,
        public string $resolverKey,
        public string $resourceType,
        public int $resourceId,
        public array $context,
        public string $status,
        public ?string $revokedAt,
        public ?string $createdAt,
        public ?string $updatedAt,
    ) {}
}