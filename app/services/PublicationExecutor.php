<?php
declare(strict_types=1);

namespace App\Services;

use App\Lib\SecretBox;
use App\Repos\PdoPublicationScheduleRepository;
use App\Repos\PdoPublisherRepository;
use RuntimeException;

final class PublicationExecutor
{
    public function __construct(
        private PdoPublicationScheduleRepository $scheduleRepo,
        private PdoPublisherRepository $publisherRepo,
        private ?SecretBox $secretBox = null
    ) {
        $this->secretBox = $secretBox ?? new SecretBox();
    }

    public function execute(int $publicationId): array
    {
        $publication = $this->scheduleRepo->findPublication($publicationId);
        if (!$publication) {
            return $this->failure('not_found', 'Publishing job not found.', false);
        }

        if (($publication['status'] ?? '') === 'published' && !empty($publication['external_id']) && !empty($publication['published_at'])) {
            return [
                'success' => true,
                'already_published' => true,
                'platform_post_id' => $publication['external_id'],
                'published_url' => $publication['external_url'] ?? null,
                'published_at' => $publication['published_at'],
                'retryable' => false,
                'response_summary' => ['already_published' => true],
            ];
        }

        $platform = strtolower(trim((string)($publication['platform'] ?? '')));
        return match ($platform) {
            'pinterest' => $this->executePinterest($publication),
            'youtube' => $this->failure('not_implemented', 'YouTube publisher execution is not implemented yet.', false),
            default => $this->failure('unsupported_platform', "Unsupported publisher platform: {$platform}", false),
        };
    }

    private function executePinterest(array $publication): array
    {
        $channel = $this->resolveChannel($publication);
        if (!$channel) {
            return $this->failure('missing_channel', 'Pinterest channel is missing.', false);
        }

        try {
            $service = new PinterestOAuthService($this->publisherRepo, null, $this->secretBox);
            $result = $service->publishPublishingJob($publication, $channel);
            $this->publisherRepo->createAttempt([
                'publishing_job_id' => (int)$publication['publishing_job_id'],
                'publishing_asset_id' => (int)$publication['publishing_asset_id'],
                'publishing_channel_id' => $publication['publishing_channel_id'] ?? null,
                'platform' => 'pinterest',
                'environment' => (string)($publication['environment'] ?? 'production'),
                'publisher_service' => 'PinterestPublisher',
                'status' => ((string)($publication['environment'] ?? 'production')) === 'production' ? 'published' : 'test_published',
                'request_payload_json' => $result['request_payload'] ?? null,
                'response_payload_json' => $result['response_summary'] ?? null,
                'external_id' => $result['platform_post_id'] ?? null,
                'external_url' => $result['published_url'] ?? null,
            ]);
            $this->scheduleRepo->updatePublicationPublished((int)$publication['publishing_asset_id'], [
                'status' => ((string)($publication['environment'] ?? 'production')) === 'production' ? 'published' : 'test_published',
                'external_id' => $result['platform_post_id'] ?? null,
                'external_url' => $result['published_url'] ?? null,
                'published_at' => $result['published_at'] ?? gmdate('Y-m-d H:i:s'),
                'record_publication_result' => ((string)($publication['environment'] ?? 'production')) === 'production',
                'lock_publication' => ((string)($publication['environment'] ?? 'production')) === 'production',
                'metadata_json' => $result['metadata_json'] ?? ($publication['metadata_json'] ?? []),
            ]);
            return $result;
        } catch (\Throwable $e) {
            $classified = $this->classifyException($e);
            $this->publisherRepo->createAttempt([
                'publishing_job_id' => (int)$publication['publishing_job_id'],
                'publishing_asset_id' => (int)$publication['publishing_asset_id'],
                'publishing_channel_id' => $publication['publishing_channel_id'] ?? null,
                'platform' => 'pinterest',
                'environment' => (string)($publication['environment'] ?? 'production'),
                'publisher_service' => 'PinterestPublisher',
                'status' => 'failed',
                'error_code' => $classified['error_code'],
                'error_message' => $classified['error_message'],
            ]);
            $this->scheduleRepo->updatePublicationError(
                (int)$publication['publishing_asset_id'],
                $classified['retryable'] ? 'scheduled_retry_pending' : 'failed',
                $classified['error_code'],
                $classified['error_message']
            );
            return $classified;
        }
    }

    private function resolveChannel(array $publication): ?array
    {
        $channelId = (int)($publication['publishing_channel_id'] ?? 0);
        if ($channelId > 0) {
            $channelKey = trim((string)($publication['channel_key'] ?? ''));
            if ($channelKey !== '') {
                return $this->publisherRepo->findChannelByKey($channelKey);
            }
        }

        $platform = trim((string)($publication['platform'] ?? ''));
        return $platform !== '' ? $this->publisherRepo->findDefaultChannelForPlatform($platform) : null;
    }

    private function classifyException(\Throwable $e): array
    {
        $message = $e->getMessage();
        $lower = strtolower($message);
        $retryable = str_contains($lower, 'timeout')
            || str_contains($lower, 'rate limit')
            || str_contains($lower, ' 429')
            || str_contains($lower, ' 500')
            || str_contains($lower, ' 502')
            || str_contains($lower, ' 503')
            || str_contains($lower, ' 504')
            || str_contains($lower, 'temporar')
            || str_contains($lower, 'connection');

        $code = 'publisher_error';
        if (str_contains($lower, 'expired') || str_contains($lower, 'not connected') || str_contains($lower, 'scope')) {
            $code = 'auth_required';
            $retryable = false;
        } elseif (str_contains($lower, 'not ready') || str_contains($lower, 'missing')) {
            $code = 'invalid_payload';
            $retryable = false;
        } elseif ($retryable) {
            $code = 'retryable_platform_error';
        }

        return $this->failure($code, $message, $retryable);
    }

    private function failure(string $code, string $message, bool $retryable): array
    {
        return [
            'success' => false,
            'retryable' => $retryable,
            'error_type' => $retryable ? 'retryable' : 'non_retryable',
            'error_code' => $code,
            'error_message' => $message,
            'response_summary' => ['error' => $message],
        ];
    }
}
