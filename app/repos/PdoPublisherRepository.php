<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoPublisherRepository
{
    public function __construct(private PDO $pdo) {}

    public function findChannelByKey(string $channelKey): ?array
    {
        if (!$this->tableExists('publishing_channels')) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM publishing_channels
              WHERE channel_key = :channel_key
              LIMIT 1'
        );
        $stmt->execute([':channel_key' => $channelKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeChannel($row) : null;
    }

    public function findDefaultChannelForPlatform(string $platform): ?array
    {
        if (!$this->tableExists('publishing_channels')) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM publishing_channels
              WHERE platform = :platform
           ORDER BY status = "active" DESC, publishing_channel_id ASC
              LIMIT 1'
        );
        $stmt->execute([':platform' => $platform]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeChannel($row) : null;
    }

    public function createPublisherAsset(array $data): ?int
    {
        if (!$this->tableExists('publisher_assets')) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO publisher_assets (
                publishing_channel_id, publish_output_id, asset_library_id, platform, source_type,
                source_id, asset_type, title, description, image_url, destination_url, status,
                external_id, external_url, metadata_json, published_at
             ) VALUES (
                :publishing_channel_id, :publish_output_id, :asset_library_id, :platform, :source_type,
                :source_id, :asset_type, :title, :description, :image_url, :destination_url, :status,
                :external_id, :external_url, :metadata_json, :published_at
             )'
        );
        $stmt->execute([
            ':publishing_channel_id' => $this->nullableInt($data['publishing_channel_id'] ?? null),
            ':publish_output_id' => $this->nullableInt($data['publish_output_id'] ?? null),
            ':asset_library_id' => $this->nullableInt($data['asset_library_id'] ?? null),
            ':platform' => (string)$data['platform'],
            ':source_type' => (string)$data['source_type'],
            ':source_id' => (int)$data['source_id'],
            ':asset_type' => (string)$data['asset_type'],
            ':title' => $this->nullableString($data['title'] ?? null),
            ':description' => $this->nullableString($data['description'] ?? null),
            ':image_url' => $this->nullableString($data['image_url'] ?? null),
            ':destination_url' => $this->nullableString($data['destination_url'] ?? null),
            ':status' => $this->nullableString($data['status'] ?? null) ?: 'draft',
            ':external_id' => $this->nullableString($data['external_id'] ?? null),
            ':external_url' => $this->nullableString($data['external_url'] ?? null),
            ':metadata_json' => $this->jsonValue($data['metadata_json'] ?? null),
            ':published_at' => $this->nullableString($data['published_at'] ?? null),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    private function normalizeChannel(array $row): array
    {
        if (isset($row['publishing_channel_id'])) {
            $row['publishing_channel_id'] = (int)$row['publishing_channel_id'];
        }
        return $row;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = :table_name'
        );
        $stmt->execute([':table_name' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string)($value ?? ''));
        return $string !== '' ? $string : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value !== null && $value !== '' ? (int)$value : null;
    }

    private function jsonValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES);
        }
        return (string)$value;
    }
}
