<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\PublishingPackagerService;

final class PublishingPackagerController
{
    public function __construct(private PublishingPackagerService $service) {}

    public function list(array $query): array
    {
        return [
            'ok' => true,
            'object_type' => 'package_batch_list',
            'items' => $this->service->listPackageBatches([
                'q' => trim((string)($query['q'] ?? '')),
            ]),
        ];
    }

    public function packagePinterestCreatorJob(array $payload): array
    {
        return [
            'ok' => true,
            'object_type' => 'package_batch',
            'item' => $this->service->packagePinterestCreatorJob($payload),
        ];
    }
}
