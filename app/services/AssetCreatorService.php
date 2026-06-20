<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoAssetCreatorRepository;
use RuntimeException;

final class AssetCreatorService
{
    public function __construct(private PdoAssetCreatorRepository $repo) {}

    public function listJobs(array $filters = []): array
    {
        return $this->repo->listJobs($filters);
    }

    public function getJob(int $jobId): ?array
    {
        return $this->repo->findJob($jobId);
    }

    public function getJobsForAsset(int $assetLibraryId): array
    {
        return $this->repo->findJobsForAsset($assetLibraryId);
    }

    public function createJob(array $payload): array
    {
        $creatorKey = trim((string)($payload['creator_key'] ?? ''));
        if ($creatorKey === '') {
            throw new RuntimeException('creator_key required');
        }

        $jobId = $this->repo->createJob([
            'creator_key' => $creatorKey,
            'source_type' => $payload['source_type'] ?? null,
            'source_id' => $payload['source_id'] ?? null,
            'playlist_instance_id' => $payload['playlist_instance_id'] ?? null,
            'title' => $payload['title'] ?? null,
            'status' => $payload['status'] ?? 'draft',
            'instructions_json' => $payload['instructions_json'] ?? ($payload['instructions'] ?? null),
            'notes' => $payload['notes'] ?? null,
        ]);

        if (isset($payload['inputs']) && is_array($payload['inputs'])) {
            $this->repo->replaceInputs($jobId, $payload['inputs']);
        }
        $this->syncOutputMetadata($jobId, $payload);

        $job = $this->repo->findJob($jobId);
        if (!$job) {
            throw new RuntimeException('Failed to create asset creator job');
        }
        return $job;
    }

    public function updateJob(int $jobId, array $payload): array
    {
        $update = [];
        foreach (['creator_key', 'source_type', 'source_id', 'playlist_instance_id', 'title', 'status', 'notes'] as $key) {
            if (array_key_exists($key, $payload)) {
                $update[$key] = $payload[$key];
            }
        }
        if (array_key_exists('instructions_json', $payload)) {
            $update['instructions_json'] = $payload['instructions_json'];
        } elseif (array_key_exists('instructions', $payload)) {
            $update['instructions_json'] = $payload['instructions'];
        }
        $this->repo->updateJob($jobId, $update);

        if (isset($payload['inputs']) && is_array($payload['inputs'])) {
            $this->repo->replaceInputs($jobId, $payload['inputs']);
        }
        $this->syncOutputMetadata($jobId, $payload);

        $job = $this->repo->findJob($jobId);
        if (!$job) {
            throw new RuntimeException('Asset creator job not found');
        }
        return $job;
    }

    public function addOutput(int $jobId, array $payload): array
    {
        $assetLibraryId = (int)($payload['asset_library_id'] ?? 0);
        if ($assetLibraryId <= 0) {
            throw new RuntimeException('asset_library_id required');
        }

        $this->repo->addOutput($jobId, [
            'asset_library_id' => $assetLibraryId,
            'role' => $payload['role'] ?? 'output',
            'status' => $payload['status'] ?? 'created',
            'generated_at' => $payload['generated_at'] ?? date('Y-m-d H:i:s'),
            'metadata_json' => $payload['metadata_json'] ?? ($payload['metadata'] ?? null),
        ]);

        $this->repo->updateJob($jobId, [
            'status' => $payload['job_status'] ?? 'generated',
            'last_run_at' => date('Y-m-d H:i:s'),
        ]);

        $job = $this->repo->findJob($jobId);
        if (!$job) {
            throw new RuntimeException('Asset creator job not found');
        }
        return $job;
    }

    private function syncOutputMetadata(int $jobId, array $payload): void
    {
        $instructions = $payload['instructions'] ?? $payload['instructions_json'] ?? null;
        if (!is_array($instructions)) {
            $decoded = json_decode((string)($instructions ?? ''), true);
            $instructions = is_array($decoded) ? $decoded : null;
        }
        if (is_array($instructions)) {
            $this->repo->syncOutputAssetMetadataFromInstructions($jobId, $instructions);
        }
    }
}
