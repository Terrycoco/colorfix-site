<?php
declare(strict_types=1);

namespace App\Services\Publishers;

interface PublicationPublisher
{
    public function platform(): string;

    public function serviceName(): string;

    public function publish(array $package, array $channel): array;
}
