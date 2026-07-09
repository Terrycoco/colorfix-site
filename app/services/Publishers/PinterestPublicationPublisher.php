<?php
declare(strict_types=1);

namespace App\Services\Publishers;

use App\Lib\SecretBox;
use App\Repos\PdoPublisherRepository;
use App\Services\PinterestOAuthService;

final class PinterestPublicationPublisher implements PublicationPublisher
{
    public function __construct(
        private PdoPublisherRepository $publisherRepo,
        private SecretBox $secretBox
    ) {}

    public function platform(): string
    {
        return 'pinterest';
    }

    public function serviceName(): string
    {
        return 'PinterestPublisher';
    }

    public function publish(array $package, array $channel): array
    {
        $service = new PinterestOAuthService($this->publisherRepo, null, $this->secretBox);
        return $service->publishPublishingJob($package, $channel);
    }
}
