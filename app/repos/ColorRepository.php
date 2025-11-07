<?php
declare(strict_types=1);

namespace App\repos;

use App\entities\Color;

interface ColorRepository
{
    public function getById(int $id): ?Color;
    public function listByBrandExcept(string $brand, int $excludeId, int $max = 500): array; // returns Color[]

}
