<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use App\Repos\PdoPublisherRepository;
use App\Repos\PdoPublicationScheduleRepository;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    scheduler_respond(['ok' => false, 'error' => 'POST only'], 405);
}

try {
    $payload = scheduler_payload();
    $packageId = (int)($payload['package_id'] ?? $payload['publish_output_id'] ?? 0);
    $queueItemId = (int)($payload['queue_item_id'] ?? 0);
    $externalUrl = trim((string)($payload['external_url'] ?? $payload['published_url'] ?? ''));
    $externalId = trim((string)($payload['external_id'] ?? $payload['platform_post_id'] ?? ''));
    $notes = trim((string)($payload['notes'] ?? ''));

    if ($packageId <= 0) {
        throw new RuntimeException('package_id required');
    }
    if ($externalUrl === '' && $externalId === '') {
        throw new RuntimeException('Enter the published URL or external ID returned by the channel.');
    }

    $scheduleRepo = new PdoPublicationScheduleRepository($pdo);
    $publisherRepo = new PdoPublisherRepository($pdo);
    $publication = $scheduleRepo->findPublication($packageId);
    if (!$publication) {
        throw new RuntimeException("Package not found: {$packageId}");
    }
    if ($queueItemId > 0) {
        $schedule = $scheduleRepo->findScheduleById($queueItemId);
        if (!$schedule || (int)($schedule['package_id'] ?? 0) !== $packageId) {
            throw new RuntimeException('Queue item does not match this package.');
        }
    }

    $platform = (string)($publication['platform'] ?? 'unknown');
    if ($externalId === '' && $platform === 'youtube') {
        $externalId = scheduler_youtube_id_from_url($externalUrl);
    }
    $environment = (string)($publication['environment'] ?? 'production');
    $publishedStatus = $environment === 'production' ? 'published' : 'test_published';
    $publishedAt = gmdate('Y-m-d H:i:s');
    $metadata = is_array($publication['metadata_json'] ?? null) ? $publication['metadata_json'] : [];
    $manualRecord = [
        'mode' => 'manual_channel_upload',
        'platform' => $platform,
        'environment' => $environment,
        'external_id' => $externalId !== '' ? $externalId : null,
        'external_url' => $externalUrl !== '' ? $externalUrl : null,
        'notes' => $notes !== '' ? $notes : null,
        'recorded_at' => gmdate('c'),
    ];
    $metadata['manual_publication'] = $manualRecord;
    $metadata[$environment . '_publication'] = array_merge($metadata[$environment . '_publication'] ?? [], $manualRecord);

    $publisherRepo->createAttempt([
        'queue_item_id' => $queueItemId > 0 ? $queueItemId : null,
        'package_batch_id' => (int)($publication['package_batch_id'] ?? 0),
        'package_id' => $packageId,
        'publishing_channel_id' => $publication['publishing_channel_id'] ?? null,
        'platform' => $platform,
        'environment' => $environment,
        'publisher_service' => 'ManualChannelUpload',
        'status' => $publishedStatus,
        'request_payload_json' => [
            'manual' => true,
            'source' => 'scheduler_manual_details',
            'notes' => $notes !== '' ? $notes : null,
        ],
        'response_payload_json' => $manualRecord,
        'external_id' => $externalId !== '' ? $externalId : null,
        'external_url' => $externalUrl !== '' ? $externalUrl : null,
    ]);

    $scheduleRepo->updatePublicationPublished($packageId, [
        'status' => $publishedStatus,
        'external_id' => $externalId !== '' ? $externalId : null,
        'external_url' => $externalUrl !== '' ? $externalUrl : null,
        'published_at' => $publishedAt,
        'record_publication_result' => true,
        'lock_publication' => $environment === 'production',
        'response_payload_json' => $manualRecord,
        'metadata_json' => $metadata,
    ]);

    if ($queueItemId > 0) {
        $scheduleRepo->completeSchedule($queueItemId, [
            'platform_post_id' => $externalId !== '' ? $externalId : null,
            'published_url' => $externalUrl !== '' ? $externalUrl : null,
            'response_summary' => $manualRecord,
        ]);
    }

    scheduler_respond([
        'ok' => true,
        'item' => [
            'package_id' => $packageId,
            'queue_item_id' => $queueItemId ?: null,
            'status' => $publishedStatus,
            'external_id' => $externalId !== '' ? $externalId : null,
            'external_url' => $externalUrl !== '' ? $externalUrl : null,
            'published_at' => $publishedAt,
        ],
    ]);
} catch (RuntimeException $e) {
    scheduler_respond(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    scheduler_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}

function scheduler_youtube_id_from_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    $parts = parse_url($url);
    if (!is_array($parts)) return '';
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
        if (!empty($query['v'])) return trim((string)$query['v']);
    }
    $path = trim((string)($parts['path'] ?? ''), '/');
    if ($path === '') return '';
    $segments = explode('/', $path);
    return trim((string)end($segments));
}
