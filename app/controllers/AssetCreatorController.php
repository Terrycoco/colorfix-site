<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AssetCreatorService;
use RuntimeException;

final class AssetCreatorController
{
    public function __construct(private AssetCreatorService $service) {}

    public function list(array $query): array
    {
        return [
            'ok' => true,
            'items' => $this->service->listJobs([
                'q' => trim((string)($query['q'] ?? '')),
                'creator_key' => trim((string)($query['creator_key'] ?? '')),
                'status' => trim((string)($query['status'] ?? '')),
            ]),
        ];
    }

    public function detail(array $query): array
    {
        $jobId = (int)($query['asset_creator_job_id'] ?? $query['id'] ?? 0);
        if ($jobId <= 0) {
            throw new RuntimeException('asset_creator_job_id required');
        }
        $job = $this->service->getJob($jobId);
        if (!$job) {
            throw new RuntimeException('Asset creator job not found');
        }
        return ['ok' => true, 'item' => $job];
    }

    public function save(array $payload): array
    {
        $jobId = (int)($payload['asset_creator_job_id'] ?? $payload['id'] ?? 0);
        $item = $jobId > 0
            ? $this->service->updateJob($jobId, $payload)
            : $this->service->createJob($payload);
        return ['ok' => true, 'item' => $item];
    }

    public function addOutput(array $payload): array
    {
        $jobId = (int)($payload['asset_creator_job_id'] ?? $payload['id'] ?? 0);
        if ($jobId <= 0) {
            throw new RuntimeException('asset_creator_job_id required');
        }
        return ['ok' => true, 'item' => $this->service->addOutput($jobId, $payload)];
    }

    public function delete(array $payload): array
    {
        $jobId = (int)($payload['asset_creator_job_id'] ?? $payload['id'] ?? 0);
        if ($jobId <= 0) {
            throw new RuntimeException('asset_creator_job_id required');
        }
        $action = trim((string)($payload['action'] ?? 'job'));
        if ($action === 'outputs') {
            return ['ok' => true, 'item' => $this->service->deleteOutputsForJob($jobId)];
        }
        return ['ok' => true, 'item' => $this->service->deleteJob($jobId)];
    }
}
