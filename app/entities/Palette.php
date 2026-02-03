<?php
declare(strict_types=1);

namespace App\Entities;

class Palette
{
    public readonly array $meta;
    public readonly array $swatches;

    public function __construct(array $meta, array $swatches)
    {
        $this->meta = $meta;
        $this->swatches = $swatches;
    }

    public function toArray(): array
    {
        return [
            'meta' => $this->meta,
            'swatches' => $this->swatches,
        ];
    }
}
