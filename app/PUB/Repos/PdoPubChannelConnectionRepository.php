<?php
declare(strict_types=1);

namespace App\PUB\Repos;

use PDO;
use RuntimeException;

/**
 * PUB CHANNEL CONNECTION REPOSITORY
 *
 * PUB-owned persistence for external channel authorization/configuration.
 *
 * Reuses the existing durable tables:
 *
 *   publishing_channels
 *   publisher_sync_runs
 *
 * Those tables currently contain the proven Pinterest/YouTube connection
 * records and encrypted credentials. The old Publisher repository is no
 * longer required by active PUB code.
 *
 * This repository intentionally contains ONLY connection persistence.
 * Old publishing jobs, packages, attempts, schedules, and publication
 * history do not belong here.
 */
final class PdoPubChannelConnectionRepository
{
    public function __construct(
        private PDO $pdo
    ) {}


    public function findChannelByKey(
        string $channelKey
    ): ?array {
        $channelKey =
            trim(
                $channelKey
            );


        if ($channelKey === '') {
            return null;
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                SELECT *

                FROM publishing_channels

                WHERE channel_key =
                    :channel_key

                LIMIT 1
                SQL
            );


        $stmt->execute([
            'channel_key' =>
                $channelKey,
        ]);


        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        return $row
            ? $this->normalizeChannel(
                $row
            )
            : null;
    }


    public function findChannelById(
        int $channelId
    ): ?array {
        if ($channelId <= 0) {
            return null;
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                SELECT *

                FROM publishing_channels

                WHERE publishing_channel_id =
                    :publishing_channel_id

                LIMIT 1
                SQL
            );


        $stmt->execute([
            'publishing_channel_id' =>
                $channelId,
        ]);


        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        return $row
            ? $this->normalizeChannel(
                $row
            )
            : null;
    }


    /**
     * Ensure the durable ColorFix Pinterest connection record exists.
     */
    public function upsertPinterestChannel(): array
    {
        $existing =
            $this->findChannelByKey(
                'pinterest_colorfix_makeovers'
            );


        if ($existing) {
            return $existing;
        }


        $metadata = [
            'environment' =>
                'production',

            'board_id' =>
                null,

            'board_name' =>
                'ColorFix by Terry',

            'board_url' =>
                'https://www.pinterest.com/terrymarr/colorfix-makeovers/',

            'board_slug' =>
                'terrymarr/colorfix-makeovers',

            'auth' => [
                'status' =>
                    'not_connected',

                'granted_scopes' =>
                    [],

                'connected_at' =>
                    null,

                'last_auth_error' =>
                    null,
            ],

            'destinations' => [
                'test' => [
                    'destination_key' =>
                        'colorfix_api_test',

                    'environment' =>
                        'test',

                    'board_id' =>
                        null,

                    'board_name' =>
                        'ColorFix API Test',

                    'board_url' =>
                        null,

                    'board_slug' =>
                        null,
                ],

                'production' => [
                    'destination_key' =>
                        'colorfix_makeovers',

                    'environment' =>
                        'production',

                    'board_id' =>
                        null,

                    'board_name' =>
                        'ColorFix by Terry',

                    'board_url' =>
                        'https://www.pinterest.com/terrymarr/colorfix-makeovers/',

                    'board_slug' =>
                        'terrymarr/colorfix-makeovers',
                ],
            ],
        ];


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                INSERT INTO publishing_channels (
                    platform,
                    channel_key,
                    publisher_service,
                    label,
                    account_name,
                    status,
                    api_base_url,
                    metadata_json
                ) VALUES (
                    :platform,
                    :channel_key,
                    :publisher_service,
                    :label,
                    :account_name,
                    :status,
                    :api_base_url,
                    :metadata_json
                )
                SQL
            );


        $stmt->execute([
            'platform' =>
                'pinterest',

            'channel_key' =>
                'pinterest_colorfix_makeovers',

            /*
             * Kept only as existing table metadata.
             * Active PUB routing uses Shippers, not this value.
             */
            'publisher_service' =>
                'PinterestImageShipper',

            'label' =>
                'Pinterest - ColorFix by Terry',

            'account_name' =>
                'terrymarr',

            'status' =>
                'pending_auth',

            'api_base_url' =>
                'https://api.pinterest.com/v5',

            'metadata_json' =>
                json_encode(
                    $metadata,
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
                ),
        ]);


        $created =
            $this->findChannelByKey(
                'pinterest_colorfix_makeovers'
            );


        if (!$created) {
            throw new RuntimeException(
                'PUB could not create the Pinterest channel connection record.'
            );
        }


        return $created;
    }


    /**
     * Future YouTube connection service can use this same repository.
     */
    public function upsertYouTubeChannel(): array
    {
        $existing =
            $this->findChannelByKey(
                'youtube_colorfix'
            );


        if ($existing) {
            return $existing;
        }


        $metadata = [
            'environment' =>
                'production',

            'auth' => [
                'status' =>
                    'not_connected',

                'granted_scopes' =>
                    [],

                'connected_at' =>
                    null,

                'last_auth_error' =>
                    null,
            ],
        ];


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                INSERT INTO publishing_channels (
                    platform,
                    channel_key,
                    publisher_service,
                    label,
                    account_name,
                    status,
                    api_base_url,
                    metadata_json
                ) VALUES (
                    :platform,
                    :channel_key,
                    :publisher_service,
                    :label,
                    :account_name,
                    :status,
                    :api_base_url,
                    :metadata_json
                )
                SQL
            );


        $stmt->execute([
            'platform' =>
                'youtube',

            'channel_key' =>
                'youtube_colorfix',

            'publisher_service' =>
                'YouTubeVideoShipper',

            'label' =>
                'ColorFix YouTube',

            'account_name' =>
                'ColorFix by Terry',

            'status' =>
                'pending_auth',

            'api_base_url' =>
                'https://www.googleapis.com/youtube/v3',

            'metadata_json' =>
                json_encode(
                    $metadata,
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
                ),
        ]);


        $created =
            $this->findChannelByKey(
                'youtube_colorfix'
            );


        if (!$created) {
            throw new RuntimeException(
                'PUB could not create the YouTube channel connection record.'
            );
        }


        return $created;
    }


    /**
     * Replace encrypted OAuth credentials and safe connection metadata.
     *
     * The encrypted array shape comes directly from SecretBox::encryptJson().
     */
    public function updateChannelAuth(
        int $channelId,
        array $encrypted,
        ?string $expiresAt,
        array $metadata,
        string $status = 'connected'
    ): void {
        if ($channelId <= 0) {
            throw new RuntimeException(
                'Valid publishing_channel_id required.'
            );
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                UPDATE publishing_channels

                SET
                    status =
                        :status,

                    encrypted_auth_payload =
                        :encrypted_auth_payload,

                    auth_nonce =
                        :auth_nonce,

                    auth_tag =
                        :auth_tag,

                    auth_key_ref =
                        :auth_key_ref,

                    auth_encryption_alg =
                        :auth_encryption_alg,

                    auth_expires_at =
                        :auth_expires_at,

                    auth_refreshed_at =
                        UTC_TIMESTAMP(),

                    metadata_json =
                        :metadata_json

                WHERE publishing_channel_id =
                    :publishing_channel_id
                SQL
            );


        $stmt->bindValue(
            ':status',
            $status
        );

        $stmt->bindValue(
            ':encrypted_auth_payload',
            $encrypted[
                'encrypted_payload'
            ],
            PDO::PARAM_LOB
        );

        $stmt->bindValue(
            ':auth_nonce',
            $encrypted[
                'nonce'
            ],
            PDO::PARAM_LOB
        );

        $stmt->bindValue(
            ':auth_tag',
            $encrypted[
                'tag'
            ],
            PDO::PARAM_LOB
        );

        $stmt->bindValue(
            ':auth_key_ref',
            $encrypted[
                'key_ref'
            ]
        );

        $stmt->bindValue(
            ':auth_encryption_alg',
            $encrypted[
                'algorithm'
            ]
        );

        $stmt->bindValue(
            ':auth_expires_at',
            $expiresAt
        );

        $stmt->bindValue(
            ':metadata_json',
            json_encode(
                $metadata,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
            )
        );

        $stmt->bindValue(
            ':publishing_channel_id',
            $channelId,
            PDO::PARAM_INT
        );


        $stmt->execute();
    }


    public function clearChannelAuth(
        int $channelId,
        array $metadata,
        string $status = 'pending_auth'
    ): void {
        if ($channelId <= 0) {
            throw new RuntimeException(
                'Valid publishing_channel_id required.'
            );
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                UPDATE publishing_channels

                SET
                    status =
                        :status,

                    encrypted_auth_payload =
                        NULL,

                    auth_nonce =
                        NULL,

                    auth_tag =
                        NULL,

                    auth_key_ref =
                        NULL,

                    auth_encryption_alg =
                        NULL,

                    auth_expires_at =
                        NULL,

                    auth_refreshed_at =
                        NULL,

                    metadata_json =
                        :metadata_json

                WHERE publishing_channel_id =
                    :publishing_channel_id
                SQL
            );


        $stmt->execute([
            'status' =>
                $status,

            'metadata_json' =>
                json_encode(
                    $metadata,
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
                ),

            'publishing_channel_id' =>
                $channelId,
        ]);
    }


    public function updateChannelMetadata(
        int $channelId,
        array $metadata,
        ?string $status = null
    ): void {
        if ($channelId <= 0) {
            throw new RuntimeException(
                'Valid publishing_channel_id required.'
            );
        }


        $sql =
            'UPDATE publishing_channels'
            . ' SET metadata_json = :metadata_json';

        $params = [
            'metadata_json' =>
                json_encode(
                    $metadata,
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
                ),

            'publishing_channel_id' =>
                $channelId,
        ];


        if ($status !== null) {
            $sql .=
                ', status = :status';

            $params[
                'status'
            ] =
                $status;
        }


        $sql .=
            ' WHERE publishing_channel_id = :publishing_channel_id';


        $stmt =
            $this->pdo->prepare(
                $sql
            );


        $stmt->execute(
            $params
        );
    }


    /**
     * Record destination/config synchronization separately from shipment errors.
     */
    public function createSyncRun(
        int $channelId,
        string $kind,
        ?array $requestPayload = null,
        ?string $platform = null
    ): int {
        if ($channelId <= 0) {
            throw new RuntimeException(
                'Valid publishing_channel_id required.'
            );
        }


        $channel =
            $this->findChannelById(
                $channelId
            );


        if (!$channel) {
            throw new RuntimeException(
                "Channel connection #{$channelId} was not found."
            );
        }


        $resolvedPlatform =
            trim(
                (string)(
                    $platform
                    ?? $channel[
                        'platform'
                    ]
                    ?? ''
                )
            );


        if ($resolvedPlatform === '') {
            throw new RuntimeException(
                'Channel sync requires platform.'
            );
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                INSERT INTO publisher_sync_runs (
                    publishing_channel_id,
                    platform,
                    sync_kind,
                    status,
                    request_payload_json,
                    started_at
                ) VALUES (
                    :publishing_channel_id,
                    :platform,
                    :sync_kind,
                    :status,
                    :request_payload_json,
                    UTC_TIMESTAMP()
                )
                SQL
            );


        $stmt->execute([
            'publishing_channel_id' =>
                $channelId,

            'platform' =>
                $resolvedPlatform,

            'sync_kind' =>
                trim(
                    $kind
                ),

            'status' =>
                'running',

            'request_payload_json' =>
                $requestPayload !== null
                    ? json_encode(
                        $requestPayload,
                        JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                        | JSON_THROW_ON_ERROR
                    )
                    : null,
        ]);


        return (int)$this->pdo
            ->lastInsertId();
    }


    public function finishSyncRun(
        int $runId,
        string $status,
        ?array $responsePayload = null,
        ?string $errorCode = null,
        ?string $errorMessage = null
    ): void {
        if ($runId <= 0) {
            throw new RuntimeException(
                'Valid publisher_sync_run_id required.'
            );
        }


        $stmt =
            $this->pdo->prepare(
                <<<SQL
                UPDATE publisher_sync_runs

                SET
                    status =
                        :status,

                    response_payload_json =
                        :response_payload_json,

                    error_code =
                        :error_code,

                    error_message =
                        :error_message,

                    finished_at =
                        UTC_TIMESTAMP()

                WHERE publisher_sync_run_id =
                    :publisher_sync_run_id
                SQL
            );


        $stmt->execute([
            'status' =>
                trim(
                    $status
                ),

            'response_payload_json' =>
                $responsePayload !== null
                    ? json_encode(
                        $responsePayload,
                        JSON_UNESCAPED_SLASHES
                        | JSON_UNESCAPED_UNICODE
                        | JSON_THROW_ON_ERROR
                    )
                    : null,

            'error_code' =>
                $this->nullableString(
                    $errorCode
                ),

            'error_message' =>
                $this->nullableString(
                    $errorMessage
                ),

            'publisher_sync_run_id' =>
                $runId,
        ]);
    }


    private function normalizeChannel(
        array $row
    ): array {
        if (
            isset(
                $row[
                    'publishing_channel_id'
                ]
            )
        ) {
            $row[
                'publishing_channel_id'
            ] =
                (int)$row[
                    'publishing_channel_id'
                ];
        }


        return $row;
    }


    private function nullableString(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }


        $text =
            trim(
                (string)$value
            );


        return $text !== ''
            ? $text
            : null;
    }
}
