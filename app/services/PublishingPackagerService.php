<?php
declare(strict_types=1);

namespace App\Services;

/**
 * The Packager turns finished creator outputs into publish-ready packages.
 *
 * A package batch groups the packages made from one creator job for a selected
 * channel/environment. Each package stores the route, destination, CTA,
 * title, description, media, tracking, and platform metadata the Scheduler and
 * Publisher need later. Scheduler should only decide when and which package
 * goes next; it should not rebuild publishing labels.
 */
final class PublishingPackagerService
{
    public function __construct(private PinterestPublishingService $pinterest) {}

    public function listPackageBatches(array $filters = []): array
    {
        return array_map([$this, 'normalizeBatch'], $this->pinterest->listJobs($filters));
    }

    public function packagePinterestCreatorJob(array $payload): array
    {
        return $this->normalizePackageResult($this->pinterest->prepareFromCreatorJob($payload));
    }

    private function normalizeBatch(array $batch): array
    {
        $batch['object_type'] = 'package_batch';
        $batch['package_batch_id'] = (int)($batch['publishing_job_id'] ?? $batch['publish_job_id'] ?? 0);
        $batch['publishing_job_id'] = $batch['package_batch_id'];
        $batch['publish_job_id'] = $batch['package_batch_id'];
        $batch['status_label'] = $this->batchStatusLabel((string)($batch['status'] ?? 'draft'));

        $packages = [];
        foreach (($batch['outputs'] ?? []) as $package) {
            $packages[] = $this->normalizePackage($package, $batch['package_batch_id']);
        }
        $batch['packages'] = $packages;
        $batch['outputs'] = $packages;
        return $batch;
    }

    private function normalizePackage(array $package, int $batchId): array
    {
        $packageId = (int)($package['publishing_asset_id'] ?? $package['publish_output_id'] ?? $package['package_id'] ?? 0);
        $package['object_type'] = 'package';
        $package['package_id'] = $packageId;
        $package['publishing_asset_id'] = $packageId;
        $package['publish_output_id'] = $packageId;
        $package['package_batch_id'] = $batchId;
        $package['publishing_job_id'] = $batchId;
        $package['publish_job_id'] = $batchId;
        $package['package_status'] = $package['status'] ?? 'packaged';
        return $package;
    }

    private function normalizePackageResult(array $result): array
    {
        $batchId = (int)($result['publishing_job_id'] ?? $result['publish_job_id'] ?? $result['package_batch_id'] ?? 0);
        $packageIds = array_values(array_map('intval', $result['output_ids'] ?? $result['package_ids'] ?? []));

        return $result + [
            'object_type' => 'package_batch',
            'package_batch_id' => $batchId,
            'package_ids' => $packageIds,
            'created_packages' => (int)($result['created_outputs'] ?? 0),
            'reused_packages' => (int)($result['reused_outputs'] ?? 0),
            'status' => 'packaged',
            'next_stage' => 'scheduler',
        ];
    }

    private function batchStatusLabel(string $status): string
    {
        return match ($status) {
            'draft', 'in_progress' => 'packaged',
            'published' => 'complete',
            'needs_attention', 'failed' => 'complete_with_errors',
            default => $status,
        };
    }
}
