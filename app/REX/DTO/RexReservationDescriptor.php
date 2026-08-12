<?php
declare(strict_types=1);

namespace App\REX\DTO;

final class RexReservationDescriptor
{
    public function __construct(
        public readonly string $title,
        public readonly array $fields = [],
    ) {}
}