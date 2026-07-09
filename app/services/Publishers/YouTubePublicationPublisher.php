<?php
declare(strict_types=1);

namespace App\Services\Publishers;

use RuntimeException;

final class YouTubePublicationPublisher implements PublicationPublisher
{
    public function platform(): string
    {
        return 'youtube';
    }

    public function serviceName(): string
    {
        return 'YouTubePublisher';
    }

    public function publish(array $package, array $channel): array
    {
        throw new RuntimeException('YouTube publisher execution is not implemented yet.');
    }
}
