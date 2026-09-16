<?php
declare(strict_types=1);

namespace App\PALETTES\PV;

final class PV
{
    public function __construct(
        public readonly int $pvId,
        public readonly int $savedPaletteId,
        public readonly bool $isActive,
        public readonly array $meta,
        public readonly array $swatches
    ) {}

    public function toArray(): array
    {
        return [
            'meta' => $this->meta,
            'swatches' => $this->swatches,
        ];
    }
}