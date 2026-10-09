<?php
declare(strict_types=1);

namespace App\ANA\Contracts;

interface ANASourceRepositoryInterface
{
    public function isValidSource(string $src): bool;
}
