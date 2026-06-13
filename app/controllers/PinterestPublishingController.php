<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\PinterestPublishingService;

final class PinterestPublishingController
{
    public function __construct(
        private PinterestPublishingService $service
    ) {}

    public function list(array $query): array
    {
        return [
            'ok' => true,
            'items' => $this->service->listJobs([
                'q' => trim((string)($query['q'] ?? '')),
            ]),
        ];
    }

    public function save(array $payload): array
    {
        return [
            'ok' => true,
            'item' => $this->service->createDraft($payload),
        ];
    }

    public function markPublished(array $payload): array
    {
        return [
            'ok' => true,
            'item' => $this->service->markPublished($payload),
        ];
    }
}
