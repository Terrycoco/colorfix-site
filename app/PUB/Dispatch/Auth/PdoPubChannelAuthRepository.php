<?php
declare(strict_types=1);

namespace App\PUB\Dispatch\Auth;

use PDO;
use RuntimeException;

/**
 * PUB CHANNEL AUTH REPOSITORY
 *
 * Persistence used only by Dispatch/Auth.
 * Stores/retrieves the durable channel row and encrypted auth envelope.
 * It never decrypts credentials itself.
 */
final class PdoPubChannelAuthRepository
{
    public function __construct(private PDO $pdo) {}

    public function findChannelByKey(string $channelKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM publishing_channels WHERE channel_key = :channel_key LIMIT 1'
        );
        $stmt->execute([':channel_key' => trim($channelKey)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['publishing_channel_id'] = (int)$row['publishing_channel_id'];
        return $row;
    }

    public function upsertPinterestChannel(): array
    {
        $existing = $this->findChannelByKey(PinterestAuthConfig::CHANNEL_KEY);
        if ($existing) return $existing;

        $metadata = [
            'environment' => 'production',
            'board_id' => null,
            'board_name' => 'ColorFix by Terry',
            'board_url' => 'https://www.pinterest.com/terrymarr/colorfix-makeovers/',
            'board_slug' => 'terrymarr/colorfix-makeovers',
            'auth' => [
                'status' => 'not_connected',
                'granted_scopes' => [],
                'connected_at' => null,
                'last_auth_error' => null,
            ],
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
                    'board_name' => 'ColorFix by Terry',
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
            ':channel_key' => PinterestAuthConfig::CHANNEL_KEY,
            ':publisher_service' => 'PinterestImageShipper',
            ':label' => 'Pinterest - ColorFix by Terry',
            ':account_name' => 'terrymarr',
            ':status' => 'pending_auth',
            ':api_base_url' => PinterestAuthConfig::API_BASE,
            ':metadata_json' => json_encode(
                $metadata,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
        ]);

        $created = $this->findChannelByKey(PinterestAuthConfig::CHANNEL_KEY);
        if (!$created) {
            throw new RuntimeException('PUB could not create the Pinterest channel auth record.');
        }
        return $created;
    }

    public function upsertYouTubeChannel(): array
    {
        $existing = $this->findChannelByKey(YouTubeAuthConfig::CHANNEL_KEY);
        if ($existing) return $existing;

        $metadata = [
            'environment' => 'production',
            'auth' => [
                'status' => 'not_connected',
                'granted_scopes' => [],
                'connected_at' => null,
                'last_auth_error' => null,
                'last_auth_error_at' => null,
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
            ':channel_key' => YouTubeAuthConfig::CHANNEL_KEY,
            ':publisher_service' => 'YouTubeVideoShipper',
            ':label' => 'YouTube - ColorFix',
            ':account_name' => 'ColorFix',
            ':status' => 'pending_auth',
            ':api_base_url' => YouTubeAuthConfig::API_BASE,
            ':metadata_json' => json_encode(
                $metadata,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
        ]);

        $created = $this->findChannelByKey(YouTubeAuthConfig::CHANNEL_KEY);
        if (!$created) {
            throw new RuntimeException('PUB could not create the YouTube channel auth record.');
        }
        return $created;
    }

    public function updateChannelAuth(
        int $channelId,
        array $encrypted,
        ?string $expiresAt,
        array $metadata,
        string $status = 'connected'
    ): void {
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
        $stmt->bindValue(':metadata_json', json_encode(
            $metadata,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
        $stmt->bindValue(':publishing_channel_id', $channelId, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function clearChannelAuth(
        int $channelId,
        array $metadata,
        string $status = 'pending_auth'
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE publishing_channels
                SET status = :status,
                    encrypted_auth_payload = NULL,
                    auth_nonce = NULL,
                    auth_tag = NULL,
                    auth_key_ref = NULL,
                    auth_encryption_alg = NULL,
                    auth_expires_at = NULL,
                    auth_refreshed_at = NULL,
                    metadata_json = :metadata_json
              WHERE publishing_channel_id = :publishing_channel_id'
        );
        $stmt->execute([
            ':status' => $status,
            ':metadata_json' => json_encode(
                $metadata,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
            ':publishing_channel_id' => $channelId,
        ]);
    }

    public function updateAuthMetadata(
        int $channelId,
        array $metadata,
        ?string $status = null
    ): void {
        $sql = 'UPDATE publishing_channels SET metadata_json = :metadata_json';
        $params = [
            ':metadata_json' => json_encode(
                $metadata,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ),
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
}
