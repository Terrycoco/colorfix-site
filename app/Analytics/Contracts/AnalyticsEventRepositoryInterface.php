<?php
declare(strict_types=1);

namespace App\Analytics\Contracts;

use App\Analytics\DTO\AnalyticsEvent;

interface AnalyticsEventRepositoryInterface
{
    public function record(AnalyticsEvent $event): int;

    public function countEventsByResourceType(
        string $resourceType,
        string $eventKey
    ): array;

    public function listResourceTypes(): array;

    public function countPlaylistEngagement(): array;

}