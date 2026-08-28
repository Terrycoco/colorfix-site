<?php
declare(strict_types=1);

namespace App\PUB\Dispatch\Pinterest;

use App\PUB\Dispatch\Auth\PinterestAuthService;
use App\PUB\Dispatch\DispatchShipmentResult;
use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;
use RuntimeException;

/**
 * PINTEREST IMAGE SHIPPER
 *
 * Shipping specialist for sealed Pinterest image packages.
 *
 * INPUT:
 *   package {
 *     title
 *     description
 *     link
 *     media_source {
 *       source_type = image_url
 *       url
 *     }
 *   }
 *
 * SHIPPER-OWNED:
 *   - production board ID
 *   - OAuth access token
 *   - Pinterest API URL
 *   - request protocol
 *
 * OUTPUT:
 *   receipt {
 *     external_id
 *     external_url
 *     response
 *   }
 *
 * DispatchManager persists the receipt and owns:
 *   shipping -> shipped
 *   dispatched_at
 */
final class PinterestImageShipper implements PubComWorkerContract
{
    private ?PubComChannel $pubComChannel = null;


    public function __construct(
        private PinterestShippingConfig $config,
        private PinterestAuthService $auth
    ) {}


    public function connectPubCom(
        PubComChannel $channel
    ): void {
        $this->pubComChannel =
            $channel;
    }


    /**
     * A Shipper is externally dependent.
     *
     * Missing/expired auth or a missing production board means
     * this shipping station is unavailable, not that the package
     * itself is malformed.
     */
    public function readiness(): PubComSignal
    {
        try {
            $this->auth
                ->validAccessToken();

            $this->config
                ->productionBoardId();

        } catch (\Throwable $e) {
            return PubComSignal::unavailable(
                'pinterest_image_shipping_unavailable',
                $e->getMessage(),
                [
                    'worker' =>
                        self::class,
                ]
            );
        }


        return PubComSignal::ready(
            'Pinterest Image Shipper is ready.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    /**
     * Inspect the sealed package only.
     *
     * The Shipper does not look back into pub_assets metadata
     * or Creator ingredients to repair a bad package.
     */
    public function preflight(
        array $package
    ): PubComSignal {
        try {
            $this->assertPackage(
                $package
            );

        } catch (RuntimeException $e) {
            return PubComSignal::ineligible(
                'pinterest_image_package_invalid',
                $e->getMessage(),
                [
                    'worker' =>
                        self::class,
                ]
            );
        }


        return PubComSignal::ready(
            'Pinterest image package is ready to ship.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    /**
     * Actually send one Pinterest image package.
     *
     * The saved package is not rewritten. board_id is fixed
     * station configuration supplied only to Pinterest's request.
     */
    public function ship(
        int $pubAssetId,
        array $package
    ): array {
        $this->assertPackage(
            $package
        );


        $payload = [
            'board_id' =>
                $this->config
                    ->productionBoardId(),

            'title' =>
                trim(
                    (string)$package[
                        'title'
                    ]
                ),

            'description' =>
                trim(
                    (string)$package[
                        'description'
                    ]
                ),

            'link' =>
                trim(
                    (string)$package[
                        'link'
                    ]
                ),

            'media_source' => [
                'source_type' =>
                    'image_url',

                'url' =>
                    trim(
                        (string)$package[
                            'media_source'
                        ][
                            'url'
                        ]
                    ),
            ],
        ];


        $response =
            $this->postJson(
                PinterestShippingConfig::PINS_PATH,
                $payload
            );


        $pinId =
            trim(
                (string)(
                    $response[
                        'id'
                    ]
                    ?? ''
                )
            );


        if ($pinId === '') {
            throw new RuntimeException(
                'Pinterest accepted the image request but returned no Pin ID.'
            );
        }


        return DispatchShipmentResult::completed([
            'external_id' =>
                $pinId,

            'external_url' =>
                'https://www.pinterest.com/pin/'
                . rawurlencode(
                    $pinId
                )
                . '/',

            'response' =>
                $response,
        ]);
    }


    private function assertPackage(
        array $package
    ): void {
        foreach (
            [
                'title',
                'description',
                'link',
            ]
            as $field
        ) {
            if (
                trim(
                    (string)(
                        $package[
                            $field
                        ]
                        ?? ''
                    )
                ) === ''
            ) {
                throw new RuntimeException(
                    "Pinterest image package is missing {$field}."
                );
            }
        }


        $link =
            trim(
                (string)$package[
                    'link'
                ]
            );


        if (!$this->isAbsoluteHttpUrl(
            $link
        )) {
            throw new RuntimeException(
                'Pinterest image package link must be an absolute HTTP(S) URL.'
            );
        }


        $mediaSource =
            is_array(
                $package[
                    'media_source'
                ]
                ?? null
            )
                ? $package[
                    'media_source'
                ]
                : [];


        $sourceType =
            strtolower(
                trim(
                    (string)(
                        $mediaSource[
                            'source_type'
                        ]
                        ?? ''
                    )
                )
            );


        if ($sourceType !== 'image_url') {
            throw new RuntimeException(
                'Pinterest image package media_source.source_type must be image_url.'
            );
        }


        $mediaUrl =
            trim(
                (string)(
                    $mediaSource[
                        'url'
                    ]
                    ?? ''
                )
            );


        if (!$this->isAbsoluteHttpUrl(
            $mediaUrl
        )) {
            throw new RuntimeException(
                'Pinterest image package media_source.url must be an absolute HTTP(S) URL.'
            );
        }
    }


    private function postJson(
        string $path,
        array $payload
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
                'Could not initialize Pinterest API request.'
            );
        }


        $json =
            json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
            );


        curl_setopt_array(
            $ch,
            [
                CURLOPT_RETURNTRANSFER =>
                    true,

                CURLOPT_POST =>
                    true,

                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '
                    . $this->auth
                        ->validAccessToken(),

                    'Content-Type: application/json',
                ],

                CURLOPT_POSTFIELDS =>
                    $json,

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


    private function isAbsoluteHttpUrl(
        string $value
    ): bool {
        return preg_match(
            '/^https?:\/\/[^\/\s]+(?:\/|$)/i',
            trim(
                $value
            )
        ) === 1;
    }
}
