<?php
declare(strict_types=1);

namespace App\Services\Publishers;

interface Publisher
{
    public function platform(): string;

    public function buildPayload(array $asset, array $channel = []): array;
}
