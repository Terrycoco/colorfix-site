<?php
declare(strict_types=1);

namespace App\Services;

use App\Lib\UrlNormalizer;
use App\Repos\PdoAssetCreatorRepository;
use App\Repos\PdoPublishingRepository;
use App\Repos\PdoPublisherRepository;
use RuntimeException;

final class PinterestPublishingService
{
    private const CHANNEL_KEY = 'pinterest_pin';
    private const OUTPUT_TYPE = 'before_after_pin';

    public function __construct(
        private PdoPublishingRepository $repo,
        private ?PdoPublisherRepository $publisherRepo = null,
        private ?PdoAssetCreatorRepository $assetCreatorRepo = null
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
        $board = $this->boardMetadata($payload);
        $channel = $this->publisherRepo?->findChannelByKey('pinterest_colorfix_makeovers')
            ?? $this->publisherRepo?->findDefaultChannelForPlatform('pinterest');
        $environment = $this->nullableString($payload['environment'] ?? null) ?? 'test';
        $channelId = isset($channel['publishing_channel_id']) ? (int)$channel['publishing_channel_id'] : null;
        $assetCreatorJobId = isset($payload['creator_job_id'])
            ? (int)$payload['creator_job_id']
            : (isset($payload['asset_creator_job_id']) ? (int)$payload['asset_creator_job_id'] : null);
        $ctaGroupId = isset($payload['cta_group_id']) ? (int)$payload['cta_group_id'] : null;
        $landingPageIdForJob = isset($landingPage['id']) ? (int)$landingPage['id'] : null;

        $jobId = $this->repo->createJob([
            'publishing_channel_id' => $channelId,
            'platform' => 'pinterest',
            'environment' => $environment,
            'source_type' => 'playlist',
            'source_id' => $playlistId,
            'creator_job_id' => $assetCreatorJobId,
            'playlist_instance_id' => isset($instance['playlist_instance_id']) ? (int)$instance['playlist_instance_id'] : null,
            'cta_group_id' => $ctaGroupId,
            'landing_page_id' => $landingPageIdForJob,
            'title' => $title,
            'description' => $description,
            'status' => $status === 'published' ? 'published' : 'draft',
            'notes' => $this->nullableString($payload['notes'] ?? null),
            'metadata_json' => [
                'destination_key' => $this->nullableString($payload['destination_key'] ?? null),
                'board' => $board,
            ],
        ]);

        $outputId = $this->repo->createOutput([
            'package_batch_id' => $jobId,
            'publishing_channel_id' => $channelId,
            'platform' => 'pinterest',
            'environment' => $environment,
            'source_type' => 'playlist',
            'source_id' => $playlistId,
            'source_asset_id' => isset($payload['source_asset_id'])
                ? (int)$payload['source_asset_id']
                : (isset($payload['asset_creator_output_id']) ? (int)$payload['asset_creator_output_id'] : null),
            'creator_job_id' => $assetCreatorJobId,
            'playlist_instance_id' => isset($instance['playlist_instance_id']) ? (int)$instance['playlist_instance_id'] : null,
            'cta_group_id' => $ctaGroupId,
            'landing_page_id' => $landingPageIdForJob,
            'asset_type' => self::OUTPUT_TYPE,
            'status' => $status,
            'title' => $title,
            'description' => $description,
            'image_url' => $this->nullableString($payload['image_url'] ?? $payload['asset_path'] ?? null),
            'tracking_code' => $trackingCode,
            'tracking_url' => $trackingUrl,
            'destination_url' => $destinationUrl,
            'asset_path' => $this->nullableString($payload['asset_path'] ?? null),
            'library_asset_id' => isset($payload['library_asset_id']) ? (int)$payload['library_asset_id'] : null,
            'metadata_json' => json_encode([
                'board' => $board['board_name'],
                'board_name' => $board['board_name'],
                'board_url' => $board['board_url'],
                'board_slug' => $board['board_slug'],
                'board_id' => $board['board_id'],
                'pinterest_api_publish_ready' => $board['board_id'] !== null,
                'pinterest_publish_blocked_reason' => $board['board_id'] === null ? 'pending_board_sync' : null,
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
            'package_batch_id' => $jobId,
            'package_id' => $outputId,
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

    public function prepareFromCreatorJob(array $payload): array
    {
        if (!$this->assetCreatorRepo) {
            throw new RuntimeException('Asset creator repository unavailable.');
        }

        $assetCreatorJobId = (int)($payload['creator_job_id'] ?? $payload['asset_creator_job_id'] ?? 0);
        if ($assetCreatorJobId <= 0) {
            throw new RuntimeException('creator_job_id required');
        }

        $creatorJob = $this->assetCreatorRepo->findJob($assetCreatorJobId);
        if (!$creatorJob) {
            throw new RuntimeException("Creator job not found: {$assetCreatorJobId}");
        }
        if (($creatorJob['source_type'] ?? '') !== 'playlist') {
            throw new RuntimeException('Only playlist creator jobs can be prepared for publishing right now.');
        }

        $playlistId = (int)($creatorJob['source_id'] ?? 0);
        if ($playlistId <= 0) {
            throw new RuntimeException('Creator job is missing its source playlist.');
        }
        $playlist = $this->repo->getPlaylistSummary($playlistId);
        if (!$playlist) {
            throw new RuntimeException("Playlist not found: {$playlistId}");
        }

        $playlistInstanceId = (int)($payload['playlist_instance_id'] ?? 0);
        if ($playlistInstanceId <= 0) {
            throw new RuntimeException('playlist_instance_id required. Use Instance Preview first.');
        }
        $instance = $this->repo->getDefaultPlaylistInstance($playlistId, $playlistInstanceId);
        if (!$instance || (int)($instance['playlist_instance_id'] ?? 0) !== $playlistInstanceId) {
            throw new RuntimeException("Playlist instance not found for playlist {$playlistId}: {$playlistInstanceId}");
        }

        $ctaGroupId = (int)($payload['cta_group_id'] ?? 0);
        if ($ctaGroupId <= 0) {
            throw new RuntimeException('cta_group_id required');
        }

        $environment = $this->nullableString($payload['environment'] ?? null) ?? 'test';
        $destinationKey = $this->nullableString($payload['destination_key'] ?? null)
            ?? ($environment === 'production' ? 'colorfix_makeovers' : 'colorfix_api_test');
        $job = $this->repo->findJobForCreatorDestination(
            $assetCreatorJobId,
            'pinterest',
            $environment,
            $ctaGroupId,
            $destinationKey
        );
        $createdJob = false;
        if ($job && (int)($job['playlist_instance_id'] ?? 0) > 0) {
            $playlistInstanceId = (int)$job['playlist_instance_id'];
            $instance = $this->repo->getDefaultPlaylistInstance($playlistId, $playlistInstanceId);
            if (!$instance || (int)($instance['playlist_instance_id'] ?? 0) !== $playlistInstanceId) {
                throw new RuntimeException("Playlist instance not found for existing package batch {$job['package_batch_id']}: {$playlistInstanceId}");
            }
        }
        $channel = $this->publisherRepo?->findChannelByKey('pinterest_colorfix_makeovers')
            ?? $this->publisherRepo?->findDefaultChannelForPlatform('pinterest');
        if (!$channel && $this->publisherRepo) {
            $channel = $this->publisherRepo->upsertPinterestChannel();
        }
        $channelId = isset($channel['publishing_channel_id']) ? (int)$channel['publishing_channel_id'] : null;
        $board = $this->destinationBoardMetadata($channel ?? [], $environment, $destinationKey);

        $title = $this->firstNonEmpty([
            $payload['title'] ?? null,
            $payload['instance_title'] ?? null,
            $instance['share_title'] ?? null,
            $instance['display_title'] ?? null,
            $playlist['headline'] ?? null,
            $playlist['title'] ?? null,
        ]);
        $description = $this->firstNonEmpty([
            $payload['description'] ?? null,
            $instance['share_description'] ?? null,
            $playlist['meta_description'] ?? null,
            $title,
        ]);

        if (!$job) {
            $jobId = $this->repo->createJob([
                'publishing_channel_id' => $channelId,
                'platform' => 'pinterest',
                'environment' => $environment,
                'source_type' => 'playlist',
                'source_id' => $playlistId,
                'creator_job_id' => $assetCreatorJobId,
                'playlist_instance_id' => $playlistInstanceId,
                'cta_group_id' => $ctaGroupId,
                'landing_page_id' => null,
                'title' => $title,
                'description' => $description,
                'status' => 'draft',
                'notes' => 'Prepared from creator job.',
                'metadata_json' => [
                    'destination_key' => $destinationKey,
                    'board' => $board,
                    'prepared_from_creator_job' => true,
                ],
            ]);
            $job = $this->repo->findJobById($jobId);
            $createdJob = true;
        }

        $jobId = (int)($job['package_batch_id'] ?? $job['publish_job_id'] ?? 0);
        if ($jobId <= 0) {
            throw new RuntimeException('Failed to create publishing job.');
        }

        $destinationUrl = $this->buildDestinationUrl($playlistId, $instance, null);
        $creatorOutputs = array_values($creatorJob['outputs'] ?? []);
        $createdOutputs = 0;
        $reusedOutputs = 0;
        $outputIds = [];
        foreach ($creatorOutputs as $output) {
            $creatorOutputId = (int)($output['source_asset_id'] ?? $output['asset_creator_output_id'] ?? 0);
            $assetLibraryId = (int)($output['asset_library_id'] ?? 0);
            if ($creatorOutputId <= 0 || $assetLibraryId <= 0) {
                continue;
            }
            $existing = $this->repo->findOutputByCreatorOutput($jobId, $creatorOutputId);
            if ($existing) {
                $reusedOutputs += 1;
                $outputIds[] = (int)$existing['package_id'];
                continue;
            }

            $outputMeta = $this->decodeJson($output['metadata_json'] ?? null);
            $assetTitle = $this->firstNonEmpty([
                $outputMeta['search_title'] ?? null,
                $outputMeta['pin_title'] ?? null,
                $output['title'] ?? null,
                $title,
            ]);
            $assetDescription = $this->firstNonEmpty([
                $outputMeta['description'] ?? null,
                $outputMeta['pin_description'] ?? null,
                $description,
            ]);
            $pinType = $this->nullableString($outputMeta['pin_type'] ?? $output['role'] ?? null) ?? 'pin';
            $trackingCode = $this->buildTrackingCode($playlistId);

            $outputId = $this->repo->createOutput([
                'package_batch_id' => $jobId,
                'publishing_channel_id' => $channelId,
                'platform' => 'pinterest',
                'environment' => $environment,
                'source_type' => 'playlist',
                'source_id' => $playlistId,
                'source_asset_id' => $creatorOutputId,
                'creator_job_id' => $assetCreatorJobId,
                'playlist_instance_id' => $playlistInstanceId,
                'cta_group_id' => $ctaGroupId,
                'landing_page_id' => null,
                'asset_type' => $pinType,
                'status' => 'packaged',
                'title' => $assetTitle,
                'description' => $assetDescription,
                'alt_text' => $assetDescription,
                'image_url' => UrlNormalizer::absoluteOrNull($output['public_url'] ?? $output['rel_path'] ?? ''),
                'media_url' => UrlNormalizer::absoluteOrNull($output['public_url'] ?? $output['rel_path'] ?? ''),
                'asset_path' => $this->nullableString($output['rel_path'] ?? null),
                'destination_url' => $destinationUrl,
                'canonical_destination_url' => $destinationUrl,
                'tracking_url' => $destinationUrl,
                'library_asset_id' => $assetLibraryId,
                'metadata_json' => [
                    'board' => $board['board_name'],
                    'board_name' => $board['board_name'],
                    'board_url' => $board['board_url'],
                    'board_slug' => $board['board_slug'],
                    'board_id' => $board['board_id'],
                    'destination_key' => $destinationKey,
                    'pin_type' => $pinType,
                    'tracking_code' => $trackingCode,
                    'creator_metadata' => $outputMeta,
                ],
                'published_at' => null,
            ]);
            $createdOutputs += 1;
            $outputIds[] = $outputId;
        }

        $this->repo->updateJobStatusFromOutputs($jobId);

        return [
            'package_batch_id' => $jobId,
            'publish_job_id' => $jobId,
            'created_job' => $createdJob,
            'created_outputs' => $createdOutputs,
            'reused_outputs' => $reusedOutputs,
            'output_ids' => $outputIds,
            'playlist_instance_id' => $playlistInstanceId,
            'destination_url' => $destinationUrl,
            'environment' => $environment,
        ];
    }

    private function boardMetadata(array $payload): array
    {
        $default = PinterestBoardConfig::defaultBoard();
        $boardName = $this->nullableString($payload['board_name'] ?? null)
            ?? $this->nullableString($payload['board'] ?? null)
            ?? $default['board_name'];
        $boardUrl = $this->nullableString($payload['board_url'] ?? null) ?? $default['board_url'];
        $boardSlug = $this->nullableString($payload['board_slug'] ?? null) ?? $default['board_slug'];
        $boardId = $this->nullableString($payload['board_id'] ?? null);

        return [
            'board_name' => $boardName,
            'board_url' => $boardUrl,
            'board_slug' => $boardSlug,
            'board_id' => $boardId,
        ];
    }

    private function destinationBoardMetadata(array $channel, string $environment, string $destinationKey): array
    {
        $metadata = $this->decodeJson($channel['metadata_json'] ?? null);
        $destinations = is_array($metadata['destinations'] ?? null) ? $metadata['destinations'] : [];
        foreach ($destinations as $destination) {
            if (!is_array($destination)) {
                continue;
            }
            if (
                (string)($destination['destination_key'] ?? '') === $destinationKey
                || (string)($destination['environment'] ?? '') === $environment
            ) {
                return [
                    'board_name' => $this->nullableString($destination['board_name'] ?? null) ?? PinterestBoardConfig::BOARD_NAME,
                    'board_url' => $this->nullableString($destination['board_url'] ?? null),
                    'board_slug' => $this->nullableString($destination['board_slug'] ?? null),
                    'board_id' => $this->nullableString($destination['board_id'] ?? null),
                ];
            }
        }
        return $this->boardMetadata([]);
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
            $instanceId = (int)($instance['playlist_instance_id'] ?? 0);
            $pathId = $instanceId > 0 ? (string)$instanceId : null;
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

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string)($value ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

}
