<?php
declare(strict_types=1);

namespace App\repos;

use App\entities\Swatch;

interface SwatchRepository
{
    /**
     * Fetch many swatches by ID.
     * @return array<int,Swatch> keyed by swatch id
     */
    public function getByIds(array $ids): array;
}
