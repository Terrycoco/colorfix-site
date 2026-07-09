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

    public function findChannelById(int $channelId): ?array
    {
        if (!$this->tableExists('publishing_channels')) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT *
               FROM publishing_channels
              WHERE publishing_channel_id = :publishing_channel_id
              LIMIT 1'
        );
        $stmt->execute([':publishing_channel_id' => $channelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeChannel($row) : null;
    }

    public function listPublishingChannels(): array
    {
        if (!$this->tableExists('publishing_channels')) {
            return [];
        }

        $stmt = $this->pdo->query(
            'SELECT *
               FROM publishing_channels
           ORDER BY platform ASC, label ASC, publishing_channel_id ASC'
        );
        return array_map([$this, 'normalizeChannel'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function upsertPinterestChannel(): array
    {
        $existing = $this->findChannelByKey('pinterest_colorfix_makeovers');
        if ($existing) {
            return $existing;
        }

        $metadata = [
            'environment' => 'production',
            'board_id' => null,
            'board_name' => 'ColorFix Makeovers',
            'board_url' => 'https://www.pinterest.com/terrymarr/colorfix-makeovers/',
            'board_slug' => 'terrymarr/colorfix-makeovers',
            'destinations' => [
                'test' => [
                    'destination_key' => 'colorfix_api_test',
                    'environment' => 'test',
                    'board_id' => null,
                    'board_name' => 'ColorFix API Test',
                    'board_url' => null,
                    'board_slug' => null,
                ],
                'production' => [
                    'destination_key' => 'colorfix_makeovers',
                    'environment' => 'production',
                    'board_id' => null,
                    'board_name' => 'ColorFix Makeovers',
                    'board_url' => 'https://www.pinterest.com/terrymarr/colorfix-makeovers/',
                    'board_slug' => 'terrymarr/colorfix-makeovers',
                ],
            ],
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO publishing_channels
                (platform, channel_key, publisher_service, label, account_name, status, api_base_url, metadata_json)
             VALUES
                (:platform, :channel_key, :publisher_service, :label, :account_name, :status, :api_base_url, :metadata_json)'
        );
        $stmt->execute([
            ':platform' => 'pinterest',
            ':channel_key' => 'pinterest_colorfix_makeovers',
            ':publisher_service' => 'PinterestPublisher',
            ':label' => 'Pinterest - ColorFix Makeovers',
            ':account_name' => 'terrymarr',
            ':status' => 'pending_auth',
            ':api_base_url' => 'https://api.pinterest.com/v5',
            ':metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
        ]);

        return $this->findChannelByKey('pinterest_colorfix_makeovers') ?? [];
    }

    public function upsertYouTubeChannel(): array
    {
        $existing = $this->findChannelByKey('youtube_colorfix');
        if ($existing) {
            return $existing;
        }

        $metadata = [
            'environment' => 'production',
            'auth' => [
                'status' => 'not_connected',
                'granted_scopes' => [],
                'connected_at' => null,
                'last_auth_error' => null,
            ],
        ];

        $stmt = $this->pdo->prepare(
            'INSERT INTO publishing_channels
                (platform, channel_key, publisher_service, label, account_name, status, api_base_url, metadata_json)
             VALUES
                (:platform, :channel_key, :publisher_service, :label, :account_name, :status, :api_base_url, :metadata_json)'
        );
        $stmt->execute([
            ':platform' => 'youtube',
            ':channel_key' => 'youtube_colorfix',
            ':publisher_service' => 'YouTubePublisher',
            ':label' => 'ColorFix YouTube',
            ':account_name' => 'ColorFix by Terry',
            ':status' => 'pending_auth',
            ':api_base_url' => 'https://www.googleapis.com/youtube/v3',
            ':metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
        ]);

        return $this->findChannelByKey('youtube_colorfix') ?? [];
    }

    public function updateChannelAuth(int $channelId, array $encrypted, ?string $expiresAt, array $metadata, string $status = 'connected'): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE publishing_channels
                SET status = :status,
                    encrypted_auth_payload = :encrypted_auth_payload,
                    auth_nonce = :auth_nonce,
                    auth_tag = :auth_tag,
                    auth_key_ref = :auth_key_ref,
                    auth_encryption_alg = :auth_encryption_alg,
                    auth_expires_at = :auth_expires_at,
                    auth_refreshed_at = UTC_TIMESTAMP(),
                    metadata_json = :metadata_json
              WHERE publishing_channel_id = :publishing_channel_id'
        );
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':encrypted_auth_payload', $encrypted['encrypted_payload'], PDO::PARAM_LOB);
        $stmt->bindValue(':auth_nonce', $encrypted['nonce'], PDO::PARAM_LOB);
        $stmt->bindValue(':auth_tag', $encrypted['tag'], PDO::PARAM_LOB);
        $stmt->bindValue(':auth_key_ref', $encrypted['key_ref']);
        $stmt->bindValue(':auth_encryption_alg', $encrypted['algorithm']);
        $stmt->bindValue(':auth_expires_at', $expiresAt);
        $stmt->bindValue(':metadata_json', json_encode($metadata, JSON_UNESCAPED_SLASHES));
        $stmt->bindValue(':publishing_channel_id', $channelId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function updateChannelMetadata(int $channelId, array $metadata, ?string $status = null): void
    {
        $sql = 'UPDATE publishing_channels SET metadata_json = :metadata_json';
        $params = [
            ':metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
            ':publishing_channel_id' => $channelId,
        ];
        if ($status !== null) {
            $sql .= ', status = :status';
            $params[':status'] = $status;
        }
        $sql .= ' WHERE publishing_channel_id = :publishing_channel_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function createSyncRun(int $channelId, string $kind, ?array $requestPayload = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO publisher_sync_runs
                (publishing_channel_id, platform, sync_kind, status, request_payload_json, started_at)
             VALUES
                (:publishing_channel_id, :platform, :sync_kind, :status, :request_payload_json, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            ':publishing_channel_id' => $channelId,
            ':platform' => 'pinterest',
            ':sync_kind' => $kind,
            ':status' => 'running',
            ':request_payload_json' => $requestPayload ? json_encode($requestPayload, JSON_UNESCAPED_SLASHES) : null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function finishSyncRun(int $runId, string $status, ?array $responsePayload = null, ?string $errorCode = null, ?string $errorMessage = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE publisher_sync_runs
                SET status = :status,
                    response_payload_json = :response_payload_json,
                    error_code = :error_code,
                    error_message = :error_message,
                    finished_at = UTC_TIMESTAMP()
              WHERE publisher_sync_run_id = :publisher_sync_run_id'
        );
        $stmt->execute([
            ':status' => $status,
            ':response_payload_json' => $responsePayload ? json_encode($responsePayload, JSON_UNESCAPED_SLASHES) : null,
            ':error_code' => $errorCode,
            ':error_message' => $errorMessage,
            ':publisher_sync_run_id' => $runId,
        ]);
    }

    public function createAttempt(array $data): int
    {
        $package = null;
        $packageId = $this->nullableInt($data['package_id'] ?? null);
        if ($packageId !== null) {
            $package = $this->findPublishingJobByPublishOutputId($packageId);
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO publisher_attempts
                (queue_item_id, package_batch_id, package_id, analyzer_job_id, creator_job_id, source_asset_id,
                 publishing_channel_id, platform, environment,
                 publisher_service, attempt_number, status,
                 request_payload_json, response_payload_json, external_post_id, external_post_url, error_code, error_message,
                 started_at, finished_at)
             VALUES
                (:queue_item_id, :package_batch_id, :package_id, :analyzer_job_id, :creator_job_id, :source_asset_id,
                 :publishing_channel_id, :platform, :environment,
                 :publisher_service, :attempt_number, :status,
                 :request_payload_json, :response_payload_json, :external_post_id, :external_post_url, :error_code, :error_message,
                 UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([
            ':queue_item_id' => $this->nullableInt($data['queue_item_id'] ?? null),
            ':package_batch_id' => (int)$data['package_batch_id'],
            ':package_id' => $packageId,
            ':analyzer_job_id' => $this->nullableInt($data['analyzer_job_id'] ?? ($package['analyzer_job_id'] ?? null)),
            ':creator_job_id' => $this->nullableInt($data['creator_job_id'] ?? ($package['creator_job_id'] ?? null)),
            ':source_asset_id' => $this->nullableInt($data['source_asset_id'] ?? ($package['source_asset_id'] ?? null)),
            ':publishing_channel_id' => $this->nullableInt($data['publishing_channel_id'] ?? null),
            ':platform' => (string)$data['platform'],
            ':environment' => (string)($data['environment'] ?? 'production'),
            ':publisher_service' => (string)$data['publisher_service'],
            ':attempt_number' => (int)($data['attempt_number'] ?? 1),
            ':status' => (string)$data['status'],
            ':request_payload_json' => $this->jsonValue($data['request_payload_json'] ?? null),
            ':response_payload_json' => $this->jsonValue($data['response_payload_json'] ?? null),
            ':external_post_id' => $this->nullableString($data['external_id'] ?? $data['external_post_id'] ?? null),
            ':external_post_url' => $this->nullableString($data['external_url'] ?? $data['external_post_url'] ?? null),
            ':error_code' => $this->nullableString($data['error_code'] ?? null),
            ':error_message' => $this->nullableString($data['error_message'] ?? null),
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function findPublishingJobByPublishOutputId(int $publishOutputId): ?array
    {
        if (!$this->tableExists('package_batches')) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT
                pa.*,
                pa.package_id AS publish_output_id,
                pa.package_id,
                pj.package_batch_id,
                pj.title AS job_title
               FROM packages pa
               JOIN package_batches pj
                 ON pj.package_batch_id = pa.package_batch_id
              WHERE pa.package_id = :publish_output_id
              LIMIT 1'
        );
        $stmt->execute([':publish_output_id' => $publishOutputId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['package_batch_id'] = (int)$row['package_batch_id'];
        $row['package_id'] = (int)$row['package_id'];
        $row['publish_job_id'] = $row['package_batch_id'];
        $row['publishing_job_id'] = $row['package_batch_id'];
        $row['publish_output_id'] = (int)$row['publish_output_id'];
        $row['publishing_asset_id'] = $row['package_id'];
        $row['publishing_channel_id'] = $row['publishing_channel_id'] !== null ? (int)$row['publishing_channel_id'] : null;
        return $row;
    }

    public function updatePublishingJobTestPublication(int $publishingJobId, array $metadata, ?string $externalId, ?string $externalUrl, ?string $lastErrorCode = null, ?string $lastErrorMessage = null): void
    {
        $assetId = (int)($metadata['package_id'] ?? $metadata['publish_output_id'] ?? 0);
        if ($assetId <= 0) {
            $stmt = $this->pdo->prepare(
                'SELECT package_id
                   FROM packages
                  WHERE package_batch_id = :package_batch_id
                  ORDER BY package_id DESC
                  LIMIT 1'
            );
            $stmt->execute([':package_batch_id' => $publishingJobId]);
            $assetId = (int)($stmt->fetchColumn() ?: 0);
        }
        if ($assetId <= 0) return;

        $stmt = $this->pdo->prepare(
            'UPDATE packages
                SET metadata_json = :metadata_json,
                    status = CASE WHEN :external_id IS NOT NULL THEN :published_status ELSE status END,
                    last_error_code = :last_error_code,
                    last_error_message = :last_error_message,
                    published_at = CASE WHEN :external_id IS NOT NULL THEN COALESCE(published_at, UTC_TIMESTAMP()) ELSE published_at END
              WHERE package_id = :package_id'
        );
        $publishedStatus = (($metadata['test_publication']['environment'] ?? $metadata['production_publication']['environment'] ?? 'test') === 'production')
            ? 'published'
            : 'test_published';
        $stmt->execute([
            ':metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
            ':published_status' => $publishedStatus,
            ':last_error_code' => $lastErrorCode,
            ':last_error_message' => $lastErrorMessage,
            ':external_id' => $externalId,
            ':package_id' => $assetId,
        ]);

        if ($externalId !== null || $externalUrl !== null) {
            $asset = $this->findPublishingJobByPublishOutputId($assetId);
            if ($asset) {
                $publication = $this->pdo->prepare(
                    'INSERT INTO published_assets
                        (package_batch_id, package_id, publishing_channel_id, platform, environment, status,
                         source_type, source_id, analyzer_job_id, creator_job_id, source_asset_id,
                         asset_library_id, playlist_instance_id, cta_group_id, landing_page_id, asset_type,
                         title, description, alt_text, media_url, destination_url, canonical_destination_url,
                         tracked_destination_url, board_id, board_name, board_url, board_slug, duplicate_fingerprint,
                         external_post_id, external_post_url, response_payload_json, metadata_json, published_at)
                     VALUES
                        (:package_batch_id, :package_id, :publishing_channel_id, :platform, :environment, :status,
                         :source_type, :source_id, :analyzer_job_id, :creator_job_id, :source_asset_id,
                         :asset_library_id, :playlist_instance_id, :cta_group_id, :landing_page_id, :asset_type,
                         :title, :description, :alt_text, :media_url, :destination_url, :canonical_destination_url,
                         :tracked_destination_url, :board_id, :board_name, :board_url, :board_slug, :duplicate_fingerprint,
                         :external_post_id, :external_post_url, :response_payload_json, :metadata_json, UTC_TIMESTAMP())'
                );
                $publication->execute([
                    ':package_batch_id' => (int)$asset['package_batch_id'],
                    ':package_id' => $assetId,
                    ':publishing_channel_id' => $asset['publishing_channel_id'] ?? null,
                    ':platform' => $asset['platform'] ?? 'pinterest',
                    ':environment' => $asset['environment'] ?? 'test',
                    ':status' => ($asset['environment'] ?? 'test') === 'production' ? 'published' : 'test_published',
                    ':source_type' => $asset['source_type'] ?? null,
                    ':source_id' => $asset['source_id'] ?? null,
                    ':analyzer_job_id' => $asset['analyzer_job_id'] ?? null,
                    ':creator_job_id' => $asset['creator_job_id'] ?? null,
                    ':source_asset_id' => $asset['source_asset_id'] ?? null,
                    ':asset_library_id' => $asset['asset_library_id'] ?? null,
                    ':playlist_instance_id' => $asset['playlist_instance_id'] ?? null,
                    ':cta_group_id' => $asset['cta_group_id'] ?? null,
                    ':landing_page_id' => $asset['landing_page_id'] ?? null,
                    ':asset_type' => $asset['asset_type'] ?? null,
                    ':title' => $asset['title'] ?? null,
                    ':description' => $asset['description'] ?? null,
                    ':alt_text' => $asset['alt_text'] ?? null,
                    ':media_url' => $asset['media_url'] ?? ($asset['image_url'] ?? null),
                    ':destination_url' => $asset['destination_url'] ?? null,
                    ':canonical_destination_url' => $asset['canonical_destination_url'] ?? null,
                    ':tracked_destination_url' => $asset['tracked_destination_url'] ?? null,
                    ':board_id' => $asset['board_id'] ?? null,
                    ':board_name' => $asset['board_name'] ?? null,
                    ':board_url' => $asset['board_url'] ?? null,
                    ':board_slug' => $asset['board_slug'] ?? null,
                    ':duplicate_fingerprint' => $asset['duplicate_fingerprint'] ?? null,
                    ':external_post_id' => $externalId,
                    ':external_post_url' => $externalUrl,
                    ':response_payload_json' => $this->jsonValue($metadata[$asset['environment'] . '_publication'] ?? null),
                    ':metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
                ]);
            }
        }
    }

    public function createPublishingJob(array $data): ?int
    {
        if (!$this->tableExists('package_batches')) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO package_batches (
                publishing_channel_id, platform, environment, source_type, source_id,
                title, description, status, metadata_json
             ) VALUES (
                :publishing_channel_id, :platform, :environment, :source_type, :source_id,
                :title, :description, :status, :metadata_json
             )'
        );
        $stmt->execute([
            ':publishing_channel_id' => $this->nullableInt($data['publishing_channel_id'] ?? null),
            ':platform' => (string)$data['platform'],
            ':environment' => (string)($data['environment'] ?? 'test'),
            ':source_type' => (string)$data['source_type'],
            ':source_id' => (int)$data['source_id'],
            ':title' => $this->nullableString($data['title'] ?? null),
            ':description' => $this->nullableString($data['description'] ?? null),
            ':status' => $this->nullableString($data['status'] ?? null) ?: 'draft',
            ':metadata_json' => $this->jsonValue($data['metadata_json'] ?? null),
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
