<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoAssetCreatorRepository;
use RuntimeException;

final class AssetCreatorService
{
    public function __construct(
        private PdoAssetCreatorRepository $repo,
        private ?AssetLibraryService $assetLibrary = null,
        private string $rootDir = ''
    ) {}

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

        $sourceType = trim((string)($payload['source_type'] ?? ''));
        $sourceId = (int)($payload['source_id'] ?? 0);
        if ($creatorKey === 'youtube.playlist_video' && $sourceType === 'playlist' && $sourceId > 0) {
            $existingJob = $this->repo->findReusableJobForSource($creatorKey, $sourceType, $sourceId);
            if ($existingJob) {
                return $this->updateJob((int)$existingJob['asset_creator_job_id'], $payload);
            }
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

    public function deleteJob(int $jobId): array
    {
        if ($jobId <= 0) {
            throw new RuntimeException('asset_creator_job_id required');
        }

        $job = $this->repo->findJob($jobId);
        if (!$job) {
            throw new RuntimeException('Asset creator job not found');
        }

        $deleted = $this->deleteOutputsForJob($jobId, false);

        $this->repo->deleteJob($jobId);

        return [
            'asset_creator_job_id' => $jobId,
            'deleted_asset_ids' => $deleted['deleted_asset_ids'],
            'deleted_count' => $deleted['deleted_count'],
        ];
    }

    public function deleteOutputsForJob(int $jobId, bool $refreshJob = true): array
    {
        if ($jobId <= 0) {
            throw new RuntimeException('asset_creator_job_id required');
        }
        if (!$this->assetLibrary) {
            throw new RuntimeException('Asset library service is required to delete generated outputs.');
        }

        $job = $this->repo->findJob($jobId);
        if (!$job) {
            throw new RuntimeException('Asset creator job not found');
        }

        $outputs = array_values(array_filter(
            $job['outputs'] ?? [],
            static fn(mixed $output): bool => is_array($output) && (int)($output['asset_library_id'] ?? 0) > 0
        ));
        $assetIds = array_map(static fn(array $output): int => (int)$output['asset_library_id'], $outputs);
        $this->assetLibrary->assertAssetsCanBeHardDeleted($assetIds);

        $deletedAssetIds = [];
        $counts = [];
        foreach ($outputs as $output) {
            $assetId = (int)($output['asset_library_id'] ?? 0);
            $result = $this->assetLibrary->hardDeleteUnpublishedAsset($assetId, $this->rootDir);
            if (!empty($result['deleted'])) {
                $deletedAssetIds[] = $assetId;
            }
            $counts[$assetId] = $result['counts'] ?? [];
        }

        if ($refreshJob) {
            $this->repo->updateJob($jobId, [
                'status' => 'draft',
                'last_run_at' => null,
            ]);
        }

        return [
            'asset_creator_job_id' => $jobId,
            'deleted_asset_ids' => $deletedAssetIds,
            'deleted_count' => count($deletedAssetIds),
            'counts' => $counts,
        ];
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

    private function deleteGeneratedOutputFile(int $jobId, string $relPath): void
    {
        $root = realpath($this->rootDir);
        if (!$root) {
            return;
        }

        $path = parse_url($relPath, PHP_URL_PATH);
        $path = $path !== false && $path !== null ? (string)$path : $relPath;
        $expectedPrefix = "/photos/pins/generated/job-{$jobId}/";
        if (!str_starts_with($path, $expectedPrefix)) {
            return;
        }

        $absPath = $root . DIRECTORY_SEPARATOR . ltrim($path, '/');
        $dir = realpath(dirname($absPath));
        if (!$dir || !str_starts_with($dir, $root . DIRECTORY_SEPARATOR)) {
            return;
        }
        if (is_file($absPath)) {
            @unlink($absPath);
        }
    }
}
