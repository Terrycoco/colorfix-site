<?php
declare(strict_types=1);

namespace App\Services\Publishers;

final class PinterestPublisher implements Publisher
{
    public function platform(): string
    {
        return 'pinterest';
    }

    public function buildPayload(array $asset, array $channel = []): array
    {
        $metadata = $this->metadata($asset);
        $settings = $this->metadata($channel);
        $destinationUrl = $this->preferredDestinationUrl($asset);

        return [
            'board_id' => $metadata['board_id'] ?? $settings['board_id'] ?? null,
            'title' => $asset['title'] ?? '',
            'description' => $asset['description'] ?? '',
            'media_source' => [
                'source_type' => 'image_url',
                'url' => $asset['image_url'] ?? '',
            ],
            'link' => $destinationUrl,
        ];
    }

    private function preferredDestinationUrl(array $asset): string
    {
        foreach (['tracked_destination_url', 'tracking_url', 'destination_url', 'canonical_destination_url'] as $key) {
            $value = trim((string)($asset[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function metadata(array $row): array
    {
        $value = $row['metadata_json'] ?? [];
        if (is_array($value)) return $value;
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
