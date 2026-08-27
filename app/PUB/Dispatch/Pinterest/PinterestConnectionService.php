<?php
declare(strict_types=1);

namespace App\PUB\Dispatch\Pinterest;

use App\Lib\SecretBox;
use App\PUB\Dispatch\ChannelConnectionContract;
use App\PUB\Repos\PdoPubChannelConnectionRepository;
use PDO;
use RuntimeException;
use Throwable;

/**
 * PINTEREST CONNECTION SERVICE
 *
 * PUB-owned Pinterest authorization/connection machinery.
 *
 * This is the cleaned-up successor to the old PinterestOAuthService.
 *
 * It owns:
 *   - Pinterest OAuth authorization URL
 *   - OAuth code exchange
 *   - encrypted credential persistence
 *   - safe connection status
 *   - real authenticated connection test
 *   - board synchronization
 *   - disconnect
 *   - auth-error metadata
 *
 * It does NOT:
 *   - ship Pins
 *   - build Pinterest image/video payloads
 *   - read pub_assets
 *   - persist shipping receipts
 *
 * PinterestImageShipper / PinterestVideoShipper use the same
 * PinterestShippingConfig after this service establishes authorization.
 */
final class PinterestConnectionService implements ChannelConnectionContract
{
    private PdoPubChannelConnectionRepository $connectionRepo;
    private SecretBox $secretBox;
    private PinterestShippingConfig $config;


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

        $this->config =
            new PinterestShippingConfig(
                $pdo,
                $this->secretBox
            );
    }


    public function channelKey(): string
    {
        return 'pinterest';
    }


    /**
     * Safe local status.
     *
     * No access token, refresh token, client secret, nonce,
     * auth tag, or encrypted credential payload is returned.
     */
    public function status(): array
    {
        $channel =
            $this->connectionRepo
                ->upsertPinterestChannel();

        $metadata =
            $this->metadata(
                $channel
            );


        return [
            'channel' => [
                'publishing_channel_id' =>
                    isset(
                        $channel[
                            'publishing_channel_id'
                        ]
                    )
                        ? (int)$channel[
                            'publishing_channel_id'
                        ]
                        : null,

                'channel_key' =>
                    $channel[
                        'channel_key'
                    ]
                    ?? PinterestShippingConfig::CHANNEL_KEY,

                'label' =>
                    $channel[
                        'label'
                    ]
                    ?? 'Pinterest',

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

                'auth_refreshed_at' =>
                    $channel[
                        'auth_refreshed_at'
                    ]
                    ?? null,
            ],

            'auth' =>
                $metadata[
                    'auth'
                ]
                ?? [
                    'status' =>
                        $channel[
                            'status'
                        ]
                        ?? 'pending_auth',
                ],

            'destinations' =>
                $metadata[
                    'destinations'
                ]
                ?? [],

            'scopes_requested' =>
                PinterestShippingConfig::SCOPES,
        ];
    }


    public function authorizationUrl(
        string $state
    ): string {
        $state =
            trim(
                $state
            );


        if ($state === '') {
            throw new RuntimeException(
                'Pinterest authorization requires OAuth state.'
            );
        }


        return
            PinterestShippingConfig::AUTH_URL
            . '?'
            . http_build_query(
                [
                    'client_id' =>
                        $this->config
                            ->clientId(),

                    'redirect_uri' =>
                        $this->config
                            ->redirectUri(),

                    'response_type' =>
                        'code',

                    'scope' =>
                        implode(
                            ' ',
                            PinterestShippingConfig::SCOPES
                        ),

                    'state' =>
                        $state,
                ],
                '',
                '&',
                PHP_QUERY_RFC3986
            );
    }


    /**
     * Exchange one successful Pinterest OAuth code and replace the
     * current encrypted credentials atomically through the existing
     * publisher credential repository.
     *
     * OAuth state validation must happen before this method is called.
     */
    public function handleCallback(
        string $code
    ): array {
        $code =
            trim(
                $code
            );


        if ($code === '') {
            throw new RuntimeException(
                'Pinterest OAuth callback did not include a code.'
            );
        }


        $channel =
            $this->connectionRepo
                ->upsertPinterestChannel();

        $token =
            $this->exchangeCode(
                $code
            );

        $expiresAt =
            $this->expiresAt(
                $token
            );

        $grantedScopes =
            $this->scopesFromToken(
                $token
            );

        $metadata =
            $this->metadata(
                $channel
            );

        $metadata[
            'auth'
        ] = [
            'status' =>
                'connected',

            'connected_at' =>
                gmdate(
                    'c'
                ),

            'granted_scopes' =>
                $grantedScopes,

            'last_auth_error' =>
                null,
        ];


        /*
         * Only the encrypted credential store receives OAuth secrets.
         * They are never returned by status() and never belong in PUB
         * package/receipt/error-log data.
         */
        $encrypted =
            $this->secretBox
                ->encryptJson(
                    [
                        'access_token' =>
                            $token[
                                'access_token'
                            ]
                            ?? '',

                        'refresh_token' =>
                            $token[
                                'refresh_token'
                            ]
                            ?? null,

                        'token_type' =>
                            $token[
                                'token_type'
                            ]
                            ?? 'bearer',

                        'scope' =>
                            $token[
                                'scope'
                            ]
                            ?? implode(
                                ',',
                                $grantedScopes
                            ),

                        'expires_in' =>
                            $token[
                                'expires_in'
                            ]
                            ?? null,

                        'received_at' =>
                            gmdate(
                                'c'
                            ),
                    ]
                );


        $this->connectionRepo
            ->updateChannelAuth(
                (int)$channel[
                    'publishing_channel_id'
                ],
                $encrypted,
                $expiresAt,
                $metadata,
                'connected'
            );


        return [
            'channel' =>
                $this->connectionRepo
                    ->findChannelByKey(
                        PinterestShippingConfig::CHANNEL_KEY
                    ),

            'granted_scopes' =>
                $grantedScopes,

            'expires_at' =>
                $expiresAt,
        ];
    }


    /**
     * Make a real authenticated Pinterest API request.
     *
     * This proves more than status(): the stored production token
     * must actually be accepted by Pinterest.
     */
    public function testConnection(): array
    {
        $testedAt =
            gmdate(
                'c'
            );


        try {
            $response =
                $this->request(
                    'GET',
                    '/boards?page_size=1&privacy=public_and_secret'
                );


            return [
                'ok' =>
                    true,

                'channel' =>
                    'pinterest',

                'tested_at' =>
                    $testedAt,

                'message' =>
                    'Pinterest connection is working.',

                'provider_response' => [
                    'items_returned' =>
                        count(
                            $this->extractBoards(
                                $response
                            )
                        ),
                ],
            ];

        } catch (Throwable $e) {
            $this->markAuthError(
                $e->getMessage()
            );

            throw $e;
        }
    }


    public function disconnect(): void
    {
        $channel =
            $this->connectionRepo
                ->upsertPinterestChannel();

        $metadata =
            $this->metadata(
                $channel
            );


        $metadata[
            'auth'
        ] = [
            'status' =>
                'not_connected',

            'connected_at' =>
                null,

            'granted_scopes' =>
                [],

            'last_auth_error' =>
                null,

            'disconnected_at' =>
                gmdate(
                    'c'
                ),
        ];


        $this->connectionRepo
            ->clearChannelAuth(
                (int)$channel[
                    'publishing_channel_id'
                ],
                $metadata,
                'pending_auth'
            );
    }


    /**
     * Save a safe auth failure description in channel metadata.
     *
     * Callers must never pass credential values into this message.
     */
    public function markAuthError(
        string $message
    ): void {
        $message =
            trim(
                $message
            );


        if ($message === '') {
            $message =
                'Pinterest authorization failed.';
        }


        $channel =
            $this->connectionRepo
                ->upsertPinterestChannel();

        $metadata =
            $this->metadata(
                $channel
            );


        $metadata[
            'auth'
        ] =
            array_merge(
                is_array(
                    $metadata[
                        'auth'
                    ]
                    ?? null
                )
                    ? $metadata[
                        'auth'
                    ]
                    : [],

                [
                    'status' =>
                        'error',

                    'last_auth_error' =>
                        $message,

                    'last_auth_error_at' =>
                        gmdate(
                            'c'
                        ),
                ]
            );


        $this->connectionRepo
            ->updateChannelMetadata(
                (int)$channel[
                    'publishing_channel_id'
                ],
                $metadata,
                'auth_error'
            );
    }


    /**
     * Refresh the two ColorFix Pinterest board destinations.
     *
     * This remains Pinterest-specific and therefore is intentionally
     * not part of ChannelConnectionContract.
     */
    public function syncBoards(): array
    {
        $channel =
            $this->connectionRepo
                ->upsertPinterestChannel();

        $runId =
            $this->connectionRepo
                ->createSyncRun(
                    (int)$channel[
                        'publishing_channel_id'
                    ],
                    'pinterest_boards',
                    [
                        'endpoints' => [
                            '/boards?page_size=100&privacy=public_and_secret',
                            '/boards?page_size=100&privacy=secret',
                            '/boards?page_size=100&privacy=protected',
                        ],

                        'match_names' => [
                            'ColorFix API Test',
                            'ColorFix by Terry',
                        ],
                    ]
                );


        try {
            $responses = [
                'public_and_secret' =>
                    $this->request(
                        'GET',
                        '/boards?page_size=100&privacy=public_and_secret'
                    ),

                'secret' =>
                    $this->request(
                        'GET',
                        '/boards?page_size=100&privacy=secret'
                    ),

                'protected' =>
                    $this->request(
                        'GET',
                        '/boards?page_size=100&privacy=protected'
                    ),
            ];


            $boards =
                $this->mergeBoards(
                    [
                        ...$this->extractBoards(
                            $responses[
                                'public_and_secret'
                            ]
                        ),

                        ...$this->extractBoards(
                            $responses[
                                'secret'
                            ]
                        ),

                        ...$this->extractBoards(
                            $responses[
                                'protected'
                            ]
                        ),
                    ]
                );


            $metadata =
                $this->metadata(
                    $channel
                );

            $metadata[
                'destinations'
            ] =
                is_array(
                    $metadata[
                        'destinations'
                    ]
                    ?? null
                )
                    ? $metadata[
                        'destinations'
                    ]
                    : [];


            $matches =
                [];


            foreach (
                [
                    'test' => [
                        'board_name' =>
                            'ColorFix API Test',

                        'destination_key' =>
                            'colorfix_api_test',
                    ],

                    'production' => [
                        'board_name' =>
                            'ColorFix by Terry',

                        'destination_key' =>
                            'colorfix_makeovers',
                    ],
                ]
                as $environment => $expected
            ) {
                $board =
                    $this->findBoardByName(
                        $boards,
                        $expected[
                            'board_name'
                        ]
                    );


                $destination =
                    array_merge(
                        is_array(
                            $metadata[
                                'destinations'
                            ][
                                $environment
                            ]
                            ?? null
                        )
                            ? $metadata[
                                'destinations'
                            ][
                                $environment
                            ]
                            : [],

                        $expected,

                        [
                            'environment' =>
                                $environment,
                        ]
                    );


                if ($board) {
                    $destination[
                        'board_id'
                    ] =
                        (string)(
                            $board[
                                'id'
                            ]
                            ?? ''
                        );

                    $destination[
                        'board_url'
                    ] =
                        $this->boardUrl(
                            $board
                        );

                    $destination[
                        'board_slug'
                    ] =
                        $this->boardSlug(
                            $board
                        );

                    $destination[
                        'last_synced_at'
                    ] =
                        gmdate(
                            'c'
                        );
                }


                $metadata[
                    'destinations'
                ][
                    $environment
                ] =
                    $destination;


                $matches[
                    $environment
                ] = [
                    'board_name' =>
                        $expected[
                            'board_name'
                        ],

                    'matched' =>
                        (bool)$board,

                    'board_id' =>
                        $destination[
                            'board_id'
                        ]
                        ?? null,

                    'board_url' =>
                        $destination[
                            'board_url'
                        ]
                        ?? null,
                ];
            }


            $sourceCounts = [
                'public_and_secret' =>
                    count(
                        $this->extractBoards(
                            $responses[
                                'public_and_secret'
                            ]
                        )
                    ),

                'secret' =>
                    count(
                        $this->extractBoards(
                            $responses[
                                'secret'
                            ]
                        )
                    ),

                'protected' =>
                    count(
                        $this->extractBoards(
                            $responses[
                                'protected'
                            ]
                        )
                    ),
            ];


            $metadata[
                'last_board_sync'
            ] = [
                'synced_at' =>
                    gmdate(
                        'c'
                    ),

                'matched' =>
                    $matches,
            ];


            $this->connectionRepo
                ->updateChannelMetadata(
                    (int)$channel[
                        'publishing_channel_id'
                    ],
                    $metadata,
                    'connected'
                );


            $this->connectionRepo
                ->finishSyncRun(
                    $runId,
                    'success',
                    [
                        'matched' =>
                            $matches,

                        'board_count' =>
                            count(
                                $boards
                            ),

                        'source_counts' =>
                            $sourceCounts,
                    ]
                );


            return [
                'ok' =>
                    true,

                'matches' =>
                    $matches,

                'board_count' =>
                    count(
                        $boards
                    ),

                'source_counts' =>
                    $sourceCounts,
            ];

        } catch (Throwable $e) {
            $this->connectionRepo
                ->finishSyncRun(
                    $runId,
                    'failed',
                    null,
                    'pinterest_api_error',
                    $e->getMessage()
                );

            throw $e;
        }
    }


    private function exchangeCode(
        string $code
    ): array {
        $ch =
            curl_init(
                PinterestShippingConfig::TOKEN_URL
            );


        if ($ch === false) {
            throw new RuntimeException(
                'Could not initialize Pinterest token exchange.'
            );
        }


        curl_setopt_array(
            $ch,
            [
                CURLOPT_RETURNTRANSFER =>
                    true,

                CURLOPT_POST =>
                    true,

                CURLOPT_HTTPHEADER => [
                    'Authorization: Basic '
                    . base64_encode(
                        $this->config
                            ->clientId()
                        . ':'
                        . $this->config
                            ->clientSecret()
                    ),

                    'Content-Type: application/x-www-form-urlencoded',
                ],

                CURLOPT_POSTFIELDS =>
                    http_build_query(
                        [
                            'grant_type' =>
                                'authorization_code',

                            'code' =>
                                $code,

                            'redirect_uri' =>
                                $this->config
                                    ->redirectUri(),
                        ]
                    ),

                CURLOPT_TIMEOUT =>
                    PinterestShippingConfig::REQUEST_TIMEOUT_SECONDS,
            ]
        );


        $raw =
            curl_exec(
                $ch
            );

        $status =
            (int)curl_getinfo(
                $ch,
                CURLINFO_RESPONSE_CODE
            );

        $curlError =
            curl_error(
                $ch
            );


        curl_close(
            $ch
        );


        if ($raw === false) {
            throw new RuntimeException(
                'Pinterest token exchange failed: '
                . $curlError
            );
        }


        $decoded =
            json_decode(
                (string)$raw,
                true
            );


        if (
            $status < 200
            || $status >= 300
            || !is_array(
                $decoded
            )
            || empty(
                $decoded[
                    'access_token'
                ]
            )
        ) {
            $message =
                is_array(
                    $decoded
                )
                    ? json_encode(
                        $decoded,
                        JSON_UNESCAPED_SLASHES
                    )
                    : (string)$raw;


            throw new RuntimeException(
                "Pinterest token exchange failed ({$status}): {$message}"
            );
        }


        return $decoded;
    }


    /**
     * Authenticated Pinterest API request used only by connection
     * management (test/sync), not by Shippers.
     */
    private function request(
        string $method,
        string $path
    ): array {
        $ch =
            curl_init(
                $this->config
                    ->apiUrl(
                        $path
                    )
            );


        if ($ch === false) {
            throw new RuntimeException(
                'Could not initialize Pinterest connection request.'
            );
        }


        curl_setopt_array(
            $ch,
            [
                CURLOPT_RETURNTRANSFER =>
                    true,

                CURLOPT_CUSTOMREQUEST =>
                    strtoupper(
                        $method
                    ),

                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '
                    . $this->config
                        ->accessToken(),

                    'Content-Type: application/json',
                ],

                CURLOPT_TIMEOUT =>
                    PinterestShippingConfig::REQUEST_TIMEOUT_SECONDS,
            ]
        );


        $raw =
            curl_exec(
                $ch
            );

        $status =
            (int)curl_getinfo(
                $ch,
                CURLINFO_RESPONSE_CODE
            );

        $curlError =
            curl_error(
                $ch
            );


        curl_close(
            $ch
        );


        if ($raw === false) {
            throw new RuntimeException(
                'Pinterest API request failed: '
                . $curlError
            );
        }


        $decoded =
            json_decode(
                (string)$raw,
                true
            );


        if (
            $status < 200
            || $status >= 300
        ) {
            $message =
                is_array(
                    $decoded
                )
                    ? json_encode(
                        $decoded,
                        JSON_UNESCAPED_SLASHES
                    )
                    : (string)$raw;


            throw new RuntimeException(
                "Pinterest API error {$status}: {$message}"
            );
        }


        return is_array(
            $decoded
        )
            ? $decoded
            : [];
    }


    private function expiresAt(
        array $token
    ): ?string {
        $expiresIn =
            isset(
                $token[
                    'expires_in'
                ]
            )
                ? (int)$token[
                    'expires_in'
                ]
                : 0;


        return $expiresIn > 0
            ? gmdate(
                'Y-m-d H:i:s',
                time()
                + $expiresIn
            )
            : null;
    }


    private function scopesFromToken(
        array $token
    ): array {
        $scope =
            trim(
                (string)(
                    $token[
                        'scope'
                    ]
                    ?? ''
                )
            );


        if ($scope === '') {
            return PinterestShippingConfig::SCOPES;
        }


        return array_values(
            array_filter(
                preg_split(
                    '/[\s,]+/',
                    $scope
                )
                ?: []
            )
        );
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


    private function extractBoards(
        array $response
    ): array {
        if (
            isset(
                $response[
                    'items'
                ]
            )
            && is_array(
                $response[
                    'items'
                ]
            )
        ) {
            return $response[
                'items'
            ];
        }


        if (
            isset(
                $response[
                    'data'
                ]
            )
            && is_array(
                $response[
                    'data'
                ]
            )
        ) {
            return $response[
                'data'
            ];
        }


        return [];
    }


    private function mergeBoards(
        array $boards
    ): array {
        $merged =
            [];


        foreach (
            $boards
            as $board
        ) {
            $id =
                trim(
                    (string)(
                        $board[
                            'id'
                        ]
                        ?? ''
                    )
                );

            $key =
                $id !== ''
                    ? $id
                    : strtolower(
                        trim(
                            (string)(
                                $board[
                                    'name'
                                ]
                                ?? ''
                            )
                        )
                    );


            if ($key === '') {
                continue;
            }


            $merged[
                $key
            ] =
                $board;
        }


        return array_values(
            $merged
        );
    }


    private function findBoardByName(
        array $boards,
        string $name
    ): ?array {
        foreach (
            $boards
            as $board
        ) {
            if (
                (string)(
                    $board[
                        'name'
                    ]
                    ?? ''
                ) === $name
            ) {
                return $board;
            }
        }


        return null;
    }


    private function boardUrl(
        array $board
    ): ?string {
        foreach (
            [
                'url',
                'link',
            ]
            as $key
        ) {
            $value =
                trim(
                    (string)(
                        $board[
                            $key
                        ]
                        ?? ''
                    )
                );


            if ($value !== '') {
                return $value;
            }
        }


        return null;
    }


    private function boardSlug(
        array $board
    ): ?string {
        $owner =
            trim(
                (string)(
                    $board[
                        'owner'
                    ][
                        'username'
                    ]
                    ?? ''
                )
            );

        $name =
            trim(
                (string)(
                    $board[
                        'name'
                    ]
                    ?? ''
                )
            );


        if (
            $owner === ''
            || $name === ''
        ) {
            return null;
        }


        return
            $owner
            . '/'
            . strtolower(
                trim(
                    (string)preg_replace(
                        '/[^a-z0-9]+/i',
                        '-',
                        $name
                    ),
                    '-'
                )
            );
    }
}
