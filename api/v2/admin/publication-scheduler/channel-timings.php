<?php
declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(200); exit; }
require_once __DIR__ . '/_bootstrap.php';

use App\Repos\PdoPublisherRepository;

function scheduler_channel_metadata(array $row): array {
    $value = $row['metadata_json'] ?? [];
    if (is_array($value)) return $value;
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}

function scheduler_channel_defaults(string $platform): array {
    $platform = strtolower(trim($platform));
    return [
        'timezone' => 'America/Los_Angeles',
        'max_posts_per_day' => $platform === 'youtube' ? 1 : 2,
        'minimum_spacing_minutes' => $platform === 'youtube' ? 1440 : 240,
        'publishing_window_start' => '08:00',
        'publishing_window_end' => '20:00',
        'lead_minutes' => 15,
        'slot_round_minutes' => 15,
    ];
}

function scheduler_clean_time(string $value, string $fallback): string {
    $value = trim($value);
    return preg_match('/^\d{2}:\d{2}$/', $value) ? $value : $fallback;
}

function scheduler_clean_settings(array $input, string $platform): array {
    $defaults = scheduler_channel_defaults($platform);
    return [
        'timezone' => trim((string)($input['timezone'] ?? $defaults['timezone'])) ?: $defaults['timezone'],
        'max_posts_per_day' => max(1, (int)($input['max_posts_per_day'] ?? $defaults['max_posts_per_day'])),
        'minimum_spacing_minutes' => max(0, (int)($input['minimum_spacing_minutes'] ?? $defaults['minimum_spacing_minutes'])),
        'publishing_window_start' => scheduler_clean_time((string)($input['publishing_window_start'] ?? ''), $defaults['publishing_window_start']),
        'publishing_window_end' => scheduler_clean_time((string)($input['publishing_window_end'] ?? ''), $defaults['publishing_window_end']),
        'lead_minutes' => max(0, (int)($input['lead_minutes'] ?? $defaults['lead_minutes'])),
        'slot_round_minutes' => max(1, (int)($input['slot_round_minutes'] ?? $defaults['slot_round_minutes'])),
    ];
}

try {
    $repo = new PdoPublisherRepository($pdo);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $payload = scheduler_payload();
        $channelId = (int)($payload['publishing_channel_id'] ?? 0);
        $environment = trim((string)($payload['environment'] ?? ''));
        if ($channelId <= 0) throw new RuntimeException('publishing_channel_id required.');
        if (!in_array($environment, ['test', 'production'], true)) {
            throw new RuntimeException('environment must be test or production.');
        }

        $channel = $repo->findChannelById($channelId);
        if (!$channel) throw new RuntimeException('Publishing channel not found.');

        $metadata = scheduler_channel_metadata($channel);
        $metadata['scheduler'] = is_array($metadata['scheduler'] ?? null) ? $metadata['scheduler'] : [];
        $metadata['scheduler']['environments'] = is_array($metadata['scheduler']['environments'] ?? null)
            ? $metadata['scheduler']['environments']
            : [];
        $metadata['scheduler']['environments'][$environment] = scheduler_clean_settings(
            (array)($payload['settings'] ?? []),
            (string)($channel['platform'] ?? '')
        );
        $metadata['scheduler']['updated_at'] = gmdate('c');

        $repo->updateChannelMetadata($channelId, $metadata);
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        scheduler_respond(['ok' => false, 'error' => 'GET or POST only'], 405);
    }

    $channels = [];
    foreach ($repo->listPublishingChannels() as $channel) {
        $metadata = scheduler_channel_metadata($channel);
        $platform = (string)($channel['platform'] ?? '');
        $defaults = scheduler_channel_defaults($platform);
        $environments = [];
        foreach (['test', 'production'] as $environment) {
            $stored = $metadata['scheduler']['environments'][$environment] ?? $metadata['scheduler'][$environment] ?? [];
            $environments[$environment] = array_merge($defaults, is_array($stored) ? $stored : []);
        }
        $channels[] = [
            'publishing_channel_id' => (int)$channel['publishing_channel_id'],
            'platform' => $platform,
            'channel_key' => $channel['channel_key'] ?? '',
            'label' => $channel['label'] ?? $channel['channel_key'] ?? $platform,
            'status' => $channel['status'] ?? '',
            'environments' => $environments,
        ];
    }

    scheduler_respond(['ok' => true, 'channels' => $channels]);
} catch (Throwable $e) {
    scheduler_respond(['ok' => false, 'error' => $e->getMessage()], 500);
}
