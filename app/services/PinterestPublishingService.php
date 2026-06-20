<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoPublishingRepository;
use RuntimeException;

final class PinterestPublishingService
{
    private const CHANNEL_KEY = 'pinterest_pin';
    private const OUTPUT_TYPE = 'before_after_pin';

    public function __construct(
        private PdoPublishingRepository $repo
    ) {}

    public function listJobs(array $filters = []): array
    {
        $filters['channel_key'] = self::CHANNEL_KEY;
        return $this->repo->listJobs($filters);
    }

    public function createDraft(array $payload): array
    {
        $playlistId = (int)($payload['playlist_id'] ?? 0);
        if ($playlistId <= 0) {
            throw new RuntimeException('playlist_id required');
        }

        $playlist = $this->repo->getPlaylistSummary($playlistId);
        if (!$playlist) {
            throw new RuntimeException("Playlist not found: {$playlistId}");
        }

        $preferredInstanceId = isset($payload['playlist_instance_id']) ? (int)$payload['playlist_instance_id'] : null;
        $instance = $this->repo->getDefaultPlaylistInstance($playlistId, $preferredInstanceId);

        $title = $this->firstNonEmpty([
            $payload['title'] ?? null,
            $instance['share_title'] ?? null,
            $instance['display_title'] ?? null,
            $playlist['headline'] ?? null,
            $playlist['title'] ?? null,
        ]);
        $description = $this->firstNonEmpty([
            $payload['description'] ?? null,
            $instance['share_description'] ?? null,
            $playlist['meta_description'] ?? null,
        ]);

        $trackingCode = $this->buildTrackingCode($playlistId);
        $landingPage = null;
        $landingPageId = isset($payload['landing_page_id']) ? (int)$payload['landing_page_id'] : 0;
        if ($landingPageId > 0) {
            $landingPage = $this->repo->getLandingPageForPublishing($landingPageId);
            if (!$landingPage) {
                throw new RuntimeException("Landing page not found: {$landingPageId}");
            }
        }
        $destinationUrl = $this->buildDestinationUrl($playlistId, $instance, $landingPage);
        $trackingUrl = $destinationUrl;
        $externalUrl = $this->nullableString($payload['external_url'] ?? null);
        $publishedAt = $this->normalizeDateTime($payload['published_at'] ?? null);
        $status = $externalUrl || $publishedAt ? 'published' : 'draft';

        $jobId = $this->repo->createJob([
            'source_type' => 'playlist',
            'source_id' => $playlistId,
            'playlist_instance_id' => isset($instance['playlist_instance_id']) ? (int)$instance['playlist_instance_id'] : null,
            'title' => $title,
            'status' => $status === 'published' ? 'published' : 'draft',
            'notes' => $this->nullableString($payload['notes'] ?? null),
        ]);

        $outputId = $this->repo->createOutput([
            'publish_job_id' => $jobId,
            'channel_key' => self::CHANNEL_KEY,
            'output_type' => self::OUTPUT_TYPE,
            'status' => $status,
            'title' => $title,
            'description' => $description,
            'tracking_code' => $trackingCode,
            'tracking_url' => $trackingUrl,
            'destination_url' => $destinationUrl,
            'external_url' => $externalUrl,
            'asset_path' => $this->nullableString($payload['asset_path'] ?? null),
            'library_asset_id' => isset($payload['library_asset_id']) ? (int)$payload['library_asset_id'] : null,
            'metadata_json' => json_encode([
                'board' => $this->nullableString($payload['board'] ?? null),
                'pin_type' => self::OUTPUT_TYPE,
                'manual_entry' => true,
                'landing_page_id' => $landingPage['id'] ?? null,
                'landing_page_slug' => $landingPage['slug'] ?? null,
                'destination_kind' => $landingPage ? 'landing_page' : 'player_fallback',
            ], JSON_UNESCAPED_SLASHES),
            'generated_at' => null,
            'staged_at' => null,
            'published_at' => $publishedAt,
        ]);

        return [
            'publish_job_id' => $jobId,
            'publish_output_id' => $outputId,
            'tracking_code' => $trackingCode,
            'tracking_url' => $trackingUrl,
            'destination_url' => $destinationUrl,
            'status' => $status,
        ];
    }

    public function markPublished(array $payload): array
    {
        $outputId = (int)($payload['publish_output_id'] ?? 0);
        if ($outputId <= 0) {
            throw new RuntimeException('publish_output_id required');
        }
        $jobId = (int)($payload['publish_job_id'] ?? 0);
        $externalUrl = $this->nullableString($payload['external_url'] ?? null);
        $publishedAt = $this->normalizeDateTime($payload['published_at'] ?? null) ?? date('Y-m-d H:i:s');
        $this->repo->updateOutputStatus($outputId, 'published', $externalUrl, $publishedAt);
        if ($jobId > 0) {
            $this->repo->updateJobStatusFromOutputs($jobId);
        }
        return [
            'publish_output_id' => $outputId,
            'status' => 'published',
            'published_at' => $publishedAt,
        ];
    }

    private function buildTrackingCode(int $playlistId): string
    {
        return 'pin-' . $playlistId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(2));
    }

    private function buildDestinationUrl(int $playlistId, ?array $instance, ?array $landingPage): string
    {
        if ($landingPage) {
            $slug = trim((string)($landingPage['slug'] ?? ''));
            if ($slug !== '') {
                return '/s/' . rawurlencode($slug) . '?src=pinterest';
            }
        }

        $pathId = null;
        if ($instance) {
            $slug = trim((string)($instance['slug'] ?? ''));
            $pathId = $slug !== '' ? $slug : (string)($instance['playlist_instance_id'] ?? '');
        }
        if (!$pathId) {
            $pathId = (string)$playlistId;
        }
        return '/p/' . rawurlencode($pathId) . '?src=pinterest';
    }

    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            $string = trim((string)($value ?? ''));
            if ($string !== '') return $string;
        }
        return 'Pinterest Pin';
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string)($value ?? ''));
        return $string !== '' ? $string : null;
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        $string = trim((string)($value ?? ''));
        if ($string === '') return null;
        $stamp = strtotime($string);
        if ($stamp === false) return null;
        return date('Y-m-d H:i:s', $stamp);
    }
}
