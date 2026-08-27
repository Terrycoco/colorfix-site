<?php
declare(strict_types=1);

namespace App\PUB\Dispatch\Pinterest;

use App\Lib\EnvLoader;
use App\Lib\SecretBox;
use App\PUB\Repos\PdoPubChannelConnectionRepository;
use PDO;
use RuntimeException;

/**
 * PINTEREST SHIPPING CONFIG
 *
 * Shared equipment/configuration for every Pinterest Shipper.
 *
 * Owns:
 *   - Pinterest API/OAuth constants
 *   - the shared production destination key
 *   - access to the existing encrypted Pinterest OAuth credentials
 *   - access to the already-synced production board ID
 *
 * Does NOT:
 *   - build Pin payloads
 *   - call Pinterest publishing endpoints
 *   - interpret image/video package shapes
 *   - persist PUB shipment results
 *
 * Secrets remain in the existing environment/encrypted credential stores.
 * This class only retrieves them for authorized server-side callers.
 */
final class PinterestShippingConfig
{
    public const CHANNEL_KEY =
        'pinterest_colorfix_makeovers';

    public const AUTH_URL =
        'https://www.pinterest.com/oauth/';

    public const TOKEN_URL =
        'https://api.pinterest.com/v5/oauth/token';

    public const API_BASE =
        'https://api.pinterest.com/v5';

    public const PINS_PATH =
        '/pins';

    public const REQUEST_TIMEOUT_SECONDS =
        30;

    public const PRODUCTION_DESTINATION_KEY =
        'production';

    public const SCOPES = [
        'boards:read',
        'boards:write',
        'pins:read',
        'pins:write',
    ];


    private PdoPubChannelConnectionRepository $connectionRepo;
    private SecretBox $secretBox;


    public function __construct(
        PDO $pdo,
        ?SecretBox $secretBox = null
    ) {
        $this->connectionRepo =
            new PdoPubChannelConnectionRepository(
                $pdo
            );

        $this->secretBox =
            $secretBox
            ?? new SecretBox();
    }


    /**
     * Current production Pinterest access token.
     *
     * The token itself never belongs in pub_assets.package,
     * shipping_receipt, logs, or frontend responses.
     */
    public function accessToken(): string
    {
        $channel =
            $this->channelRow();


        $expires =
            trim(
                (string)(
                    $channel[
                        'auth_expires_at'
                    ]
                    ?? ''
                )
            );


        if (
            $expires !== ''
            && strtotime(
                $expires
            ) !== false
            && strtotime(
                $expires
            ) <= time()
        ) {
            throw new RuntimeException(
                'Pinterest access token is expired.'
            );
        }


        $tokens =
            $this->secretBox
                ->decryptJson(
                    $channel[
                        'encrypted_auth_payload'
                    ]
                    ?? null,

                    $channel[
                        'auth_nonce'
                    ]
                    ?? null,

                    $channel[
                        'auth_tag'
                    ]
                    ?? null
                );


        $accessToken =
            trim(
                (string)(
                    $tokens[
                        'access_token'
                    ]
                    ?? ''
                )
            );


        if ($accessToken === '') {
            throw new RuntimeException(
                'Pinterest is not connected.'
            );
        }


        return $accessToken;
    }


    /**
     * Fixed production board destination shared by Pinterest Shippers.
     *
     * The old OAuth/board-sync flow stores this under:
     *
     *   metadata_json.destinations.production.board_id
     */
    public function productionBoardId(): string
    {
        $metadata =
            $this->metadata(
                $this->channelRow()
            );


        $boardId =
            trim(
                (string)(
                    $metadata[
                        'destinations'
                    ][
                        self::PRODUCTION_DESTINATION_KEY
                    ][
                        'board_id'
                    ]
                    ?? ''
                )
            );


        if ($boardId === '') {
            throw new RuntimeException(
                'Pinterest production board is not configured.'
            );
        }


        return $boardId;
    }


    public function apiUrl(
        string $path
    ): string {
        $path =
            '/' .
            ltrim(
                trim(
                    $path
                ),
                '/'
            );


        return
            rtrim(
                self::API_BASE,
                '/'
            )
            . $path;
    }


    /**
     * Safe status object for the Dispatch UI.
     *
     * No secrets are returned.
     */
    public function status(): array
    {
        $channel =
            $this->channelRow();

        $metadata =
            $this->metadata(
                $channel
            );

        $board =
            $metadata[
                'destinations'
            ][
                self::PRODUCTION_DESTINATION_KEY
            ]
            ?? [];


        $tokenStatus =
            'connected';


        try {
            $this->accessToken();
        } catch (\Throwable $e) {
            $tokenStatus =
                $e->getMessage();
        }


        return [
            'channel_key' =>
                $channel[
                    'channel_key'
                ]
                ?? self::CHANNEL_KEY,

            'status' =>
                $channel[
                    'status'
                ]
                ?? 'pending_auth',

            'auth_expires_at' =>
                $channel[
                    'auth_expires_at'
                ]
                ?? null,

            'connection' =>
                $tokenStatus,

            'production_destination' => [
                'board_id' =>
                    $board[
                        'board_id'
                    ]
                    ?? null,

                'board_name' =>
                    $board[
                        'board_name'
                    ]
                    ?? null,

                'board_url' =>
                    $board[
                        'board_url'
                    ]
                    ?? null,
            ],
        ];
    }


    /*
     * OAuth environment values.
     *
     * These are here so the old OAuth service can be migrated to
     * this shared config instead of maintaining duplicate constants.
     */
    public function clientId(): string
    {
        return $this->requiredEnv(
            'PINTEREST_APP_ID'
        );
    }


    public function clientSecret(): string
    {
        return $this->requiredEnv(
            'PINTEREST_APP_SECRET'
        );
    }


    public function redirectUri(): string
    {
        return $this->requiredEnv(
            'PINTEREST_REDIRECT_URI'
        );
    }


    private function channelRow(): array
    {
        return $this->connectionRepo
            ->upsertPinterestChannel();
    }


    private function metadata(
        array $row
    ): array {
        $value =
            $row[
                'metadata_json'
            ]
            ?? [];


        if (is_array($value)) {
            return $value;
        }


        $decoded =
            json_decode(
                (string)$value,
                true
            );


        return is_array(
            $decoded
        )
            ? $decoded
            : [];
    }


    private function requiredEnv(
        string $key
    ): string {
        $value =
            trim(
                (string)(
                    EnvLoader::get(
                        $key
                    )
                    ?? ''
                )
            );


        if ($value === '') {
            throw new RuntimeException(
                "Missing required config: {$key}"
            );
        }


        return $value;
    }
}
