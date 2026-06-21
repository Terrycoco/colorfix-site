<?php
declare(strict_types=1);

namespace App\Services\Publishers;

final class YouTubePublisher implements Publisher
{
    public function platform(): string
    {
        return 'youtube';
    }

    public function buildPayload(array $asset, array $channel = []): array
    {
        $metadata = $this->metadata($asset);
        $settings = $this->metadata($channel);

        return [
            'video_path' => $metadata['video_path'] ?? null,
            'thumbnail_path' => $metadata['thumbnail_path'] ?? null,
            'youtube_channel_id' => $metadata['youtube_channel_id'] ?? $settings['youtube_channel_id'] ?? null,
            'youtube_playlist_id' => $metadata['youtube_playlist_id'] ?? $settings['youtube_playlist_id'] ?? null,
            'snippet' => [
                'title' => $asset['title'] ?? '',
                'description' => $asset['description'] ?? '',
                'categoryId' => $metadata['category_id'] ?? null,
                'tags' => $metadata['tags'] ?? [],
            ],
            'status' => [
                'privacyStatus' => $metadata['privacy_status'] ?? 'private',
                'selfDeclaredMadeForKids' => (bool)($metadata['made_for_kids'] ?? false),
            ],
        ];
    }

    private function metadata(array $row): array
    {
        $value = $row['metadata_json'] ?? [];
        if (is_array($value)) return $value;
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
