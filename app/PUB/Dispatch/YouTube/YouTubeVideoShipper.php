<?php
declare(strict_types=1);

namespace App\PUB\Dispatch\YouTube;

use App\PUB\Dispatch\Auth\YouTubeAuthService;
use App\PUB\Dispatch\DispatchShipmentResult;
use App\PUB\Dispatch\Driver\DispatchDriverJob;
use App\PUB\Dispatch\Driver\DispatchDriverLauncher;
use App\PUB\Dispatch\Driver\DispatchDriverRouteContract;
use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;
use PDO;
use RuntimeException;

/**
 * YOUTUBE VIDEO SHIPPER
 *
 * The specialist owns the complete YouTube-video shipping strategy.
 *
 * ship()
 *   Hands the long-running route to Dispatch's generic one-shot driver.
 *
 * driverRoute()
 *   1. get a valid YouTube badge
 *   2. start a resumable videos.insert session
 *   3. stream the MP4 to Google's returned session URI
 *   4. resume after an interrupted/5xx upload when necessary
 *   5. capture the new YouTube video ID
 *   6. upload the authored JPEG with thumbnails.set
 *   7. return the final YouTube receipt
 */
final class YouTubeVideoShipper implements
    PubComWorkerContract,
    DispatchDriverRouteContract
{
    /*
     * Outer leash enforced by DispatchDriverLauncher.
     *
     * YouTube uploads can be larger than Pinterest uploads, so give the
     * driver enough room for one upload plus a bounded resume attempt.
     */
    private const DRIVER_TIMEOUT_SECONDS = 900;

    private ?PubComChannel $pubComChannel = null;
    private ?DispatchDriverLauncher $driverLauncher = null;

    public function __construct(
        private YouTubeShippingConfig $config,
        private YouTubeAuthService $auth
    ) {}

    public static function forDriver(
        PDO $pdo,
        string $projectRoot
    ): self {
        return new self(
            new YouTubeShippingConfig(),
            new YouTubeAuthService(
                $pdo
            )
        );
    }

    public function connectPubCom(
        PubComChannel $channel
    ): void {
        $this->pubComChannel =
            $channel;
    }

    public function readiness(): PubComSignal
    {
        try {
            $this->auth
                ->validAccessToken();

            $this->driverLauncher()
                ->assertAvailable();

        } catch (\Throwable $e) {
            return PubComSignal::unavailable(
                'youtube_video_shipping_unavailable',
                $e->getMessage(),
                [
                    'worker' =>
                        self::class,
                ]
            );
        }

        return PubComSignal::ready(
            'YouTube Video Shipper is ready.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }

    public function preflight(
        array $package
    ): PubComSignal {
        try {
            $this->assertPackage(
                $package
            );

        } catch (RuntimeException $e) {
            return PubComSignal::ineligible(
                'youtube_video_package_invalid',
                $e->getMessage(),
                [
                    'worker' =>
                        self::class,
                ]
            );
        }

        return PubComSignal::ready(
            'YouTube video package is ready to ship.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }

    public function ship(
        int $pubAssetId,
        array $package,
        bool $notifyOnPublish = false
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'YouTube Video Shipper requires a valid pub_asset_id.'
            );
        }

        $this->assertPackage(
            $package
        );

        $job =
            new DispatchDriverJob(
                $pubAssetId,
                self::class,
                self::DRIVER_TIMEOUT_SECONDS,
                $notifyOnPublish
            );

        $handle =
            $this->driverLauncher()
                ->requestDriver(
                    $job
                );

        error_log(
            'YouTube video shipment #'
            . $pubAssetId
            . ' handed to Dispatch driver PID '
            . $handle->pid()
            . '.'
        );

        return DispatchShipmentResult::inProgress();
    }

    public function driverRoute(
        DispatchDriverJob $job,
        array $package
    ): array {
        $this->assertPackage(
            $package
        );

        $accessToken =
            $this->auth
                ->validAccessToken();

        $videoFile =
            trim(
                (string)$package[
                    'video_file_path'
                ]
            );

        $thumbnailFile =
            trim(
                (string)$package[
                    'thumbnail_file_path'
                ]
            );

        $title =
            trim(
                (string)$package[
                    'title'
                ]
            );

        $description =
            (string)$package[
                'description'
            ];

        $privacyStatus =
            $this->config
                ->videoPrivacyStatus();

        $uploadUrl =
            $this->startResumableSession(
                $videoFile,
                $title,
                $description,
                $privacyStatus,
                $accessToken
            );

        $videoResponse =
            $this->uploadVideoResumable(
                $uploadUrl,
                $videoFile,
                $accessToken
            );

        $videoId =
            trim(
                (string)(
                    $videoResponse[
                        'id'
                    ]
                    ?? ''
                )
            );

        if ($videoId === '') {
            throw new RuntimeException(
                'YouTube accepted the video upload but returned no video ID.'
            );
        }

        $this->uploadThumbnail(
            $videoId,
            $thumbnailFile,
            $accessToken
        );

        $actualPrivacy =
            strtolower(
                trim(
                    (string)(
                        $videoResponse[
                            'status'
                        ][
                            'privacyStatus'
                        ]
                        ?? ''
                    )
                )
            );

        return [
            'external_id' =>
                $videoId,

            'external_url' =>
                'https://www.youtube.com/watch?v='
                . rawurlencode(
                    $videoId
                ),

            'response' => [
                'requested_privacy_status' =>
                    $privacyStatus,

                'privacy_status' =>
                    $actualPrivacy !== ''
                        ? $actualPrivacy
                        : $privacyStatus,

                'thumbnail_set' =>
                    true,
            ],
        ];
    }

    private function driverLauncher(): DispatchDriverLauncher
    {
        if ($this->driverLauncher === null) {
            /*
             * __DIR__:
             *   <root>/app/PUB/Dispatch/YouTube
             *
             * Four levels up is the ColorFix project root.
             */
            $this->driverLauncher =
                new DispatchDriverLauncher(
                    dirname(
                        __DIR__,
                        4
                    )
                );
        }

        return $this->driverLauncher;
    }

    private function assertPackage(
        array $package
    ): void {
        foreach (
            [
                'title',
                'video_file_path',
                'thumbnail_file_path',
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
                    "YouTube video package requires {$field}."
                );
            }
        }

        if (!array_key_exists(
            'description',
            $package
        )) {
            throw new RuntimeException(
                'YouTube video package requires description.'
            );
        }

        $videoFile =
            trim(
                (string)$package[
                    'video_file_path'
                ]
            );

        if (
            !is_file(
                $videoFile
            )
            || !is_readable(
                $videoFile
            )
        ) {
            throw new RuntimeException(
                'YouTube video package MP4 is missing or unreadable.'
            );
        }

        $videoBytes =
            filesize(
                $videoFile
            );

        if (
            $videoBytes === false
            || $videoBytes <= 0
        ) {
            throw new RuntimeException(
                'YouTube video package MP4 is empty or its size could not be read.'
            );
        }

        if (
            $videoBytes >
            YouTubeShippingConfig::MAX_VIDEO_BYTES
        ) {
            throw new RuntimeException(
                'YouTube video package MP4 exceeds YouTube\'s 256 GB upload limit.'
            );
        }

        $thumbnailFile =
            trim(
                (string)$package[
                    'thumbnail_file_path'
                ]
            );

        if (
            !is_file(
                $thumbnailFile
            )
            || !is_readable(
                $thumbnailFile
            )
        ) {
            throw new RuntimeException(
                'YouTube video package thumbnail is missing or unreadable.'
            );
        }

        $thumbnailBytes =
            filesize(
                $thumbnailFile
            );

        if (
            $thumbnailBytes === false
            || $thumbnailBytes <= 0
        ) {
            throw new RuntimeException(
                'YouTube thumbnail is empty or its size could not be read.'
            );
        }

        if (
            $thumbnailBytes >
            YouTubeShippingConfig::MAX_THUMBNAIL_BYTES
        ) {
            throw new RuntimeException(
                'YouTube thumbnail exceeds the 2 MB upload limit.'
            );
        }

        $imageInfo =
            @getimagesize(
                $thumbnailFile
            );

        if (
            !is_array(
                $imageInfo
            )
            || (
                $imageInfo[
                    2
                ]
                ?? null
            ) !== IMAGETYPE_JPEG
        ) {
            throw new RuntimeException(
                'YouTube authored thumbnail must be a JPEG.'
            );
        }
    }

    private function startResumableSession(
        string $videoFile,
        string $title,
        string $description,
        string $privacyStatus,
        string $accessToken
    ): string {
        $videoBytes =
            $this->requiredFileSize(
                $videoFile,
                'YouTube MP4'
            );

        $payload =
            json_encode(
                [
                    'snippet' => [
                        'title' =>
                            $title,

                        'description' =>
                            $description,
                    ],

                    'status' => [
                        'privacyStatus' =>
                            $privacyStatus,
                    ],
                ],
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
            );

        $responseHeaders = [];

        $ch =
            curl_init(
                $this->config
                    ->resumableVideoUrl()
            );

        if ($ch === false) {
            throw new RuntimeException(
                'Could not initialize YouTube resumable upload session.'
            );
        }

        curl_setopt_array(
            $ch,
            [
                CURLOPT_RETURNTRANSFER =>
                    true,

                CURLOPT_POST =>
                    true,

                CURLOPT_POSTFIELDS =>
                    $payload,

                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '
                    . $accessToken,

                    'Accept: application/json',

                    'Content-Type: application/json; charset=UTF-8',

                    'Content-Length: '
                    . strlen(
                        $payload
                    ),

                    'X-Upload-Content-Length: '
                    . $videoBytes,

                    'X-Upload-Content-Type: '
                    . YouTubeShippingConfig::VIDEO_MIME,
                ],

                CURLOPT_HEADERFUNCTION =>
                    $this->headerCollector(
                        $responseHeaders
                    ),

                CURLOPT_CONNECTTIMEOUT =>
                    YouTubeShippingConfig::REQUEST_TIMEOUT_SECONDS,

                CURLOPT_TIMEOUT =>
                    YouTubeShippingConfig::REQUEST_TIMEOUT_SECONDS,
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
                'YouTube resumable-session request failed: '
                . $curlError
            );
        }

        if (
            $status < 200
            || $status >= 300
        ) {
            throw new RuntimeException(
                'YouTube resumable-session request failed (HTTP '
                . $status
                . '): '
                . $this->providerMessage(
                    (string)$raw
                )
            );
        }

        $location =
            trim(
                (string)(
                    $responseHeaders[
                        'location'
                    ]
                    ?? ''
                )
            );

        if ($location === '') {
            throw new RuntimeException(
                'YouTube resumable-session response did not include a Location header.'
            );
        }

        return $location;
    }

    private function uploadVideoResumable(
        string $uploadUrl,
        string $videoFile,
        string $accessToken
    ): array {
        $totalBytes =
            $this->requiredFileSize(
                $videoFile,
                'YouTube MP4'
            );

        $offset = 0;
        $recoveries = 0;

        while (true) {
            $result =
                $this->putVideoBytes(
                    $uploadUrl,
                    $videoFile,
                    $accessToken,
                    $offset,
                    $totalBytes
                );

            $status =
                (int)$result[
                    'status'
                ];

            if (
                $result[
                    'raw'
                ] !== false
                && $status >= 200
                && $status < 300
            ) {
                return $this->decodeVideoResource(
                    (string)$result[
                        'raw'
                    ]
                );
            }

            if ($status === 308) {
                ++$recoveries;

                if (
                    $recoveries >
                    YouTubeShippingConfig::RESUME_MAX_ATTEMPTS
                ) {
                    throw new RuntimeException(
                        'YouTube video upload remained incomplete after repeated resume attempts.'
                    );
                }

                $offset =
                    $this->nextOffsetFromHeaders(
                        $result[
                            'headers'
                        ]
                    );

                if ($offset >= $totalBytes) {
                    $state =
                        $this->recoverUploadState(
                            $uploadUrl,
                            $accessToken,
                            $totalBytes,
                            $recoveries
                        );

                    if (
                        isset(
                            $state[
                                'video'
                            ]
                        )
                    ) {
                        return $state[
                            'video'
                        ];
                    }

                    $offset =
                        (int)$state[
                            'offset'
                        ];
                } else {
                    $this->sleepBeforeRetry(
                        $recoveries,
                        $result[
                            'headers'
                        ][
                            'retry-after'
                        ]
                        ?? null
                    );
                }

                continue;
            }

            if (
                $result[
                    'raw'
                ] === false
                || in_array(
                    $status,
                    [
                        500,
                        502,
                        503,
                        504,
                    ],
                    true
                )
            ) {
                $state =
                    $this->recoverUploadState(
                        $uploadUrl,
                        $accessToken,
                        $totalBytes,
                        $recoveries
                    );

                if (
                    isset(
                        $state[
                            'video'
                        ]
                    )
                ) {
                    return $state[
                        'video'
                    ];
                }

                $offset =
                    (int)$state[
                        'offset'
                    ];

                continue;
            }

            $message =
                $result[
                    'raw'
                ] === false
                    ? (string)$result[
                        'curl_error'
                    ]
                    : $this->providerMessage(
                        (string)$result[
                            'raw'
                        ]
                    );

            throw new RuntimeException(
                'YouTube video upload failed (HTTP '
                . $status
                . '): '
                . $message
            );
        }
    }

    private function putVideoBytes(
        string $uploadUrl,
        string $videoFile,
        string $accessToken,
        int $offset,
        int $totalBytes
    ): array {
        $remaining =
            $totalBytes
            - $offset;

        if ($remaining <= 0) {
            throw new RuntimeException(
                'YouTube upload has no remaining video bytes to send.'
            );
        }

        $handle =
            fopen(
                $videoFile,
                'rb'
            );

        if ($handle === false) {
            throw new RuntimeException(
                'Could not open YouTube MP4 for upload.'
            );
        }

        if (
            $offset > 0
            && fseek(
                $handle,
                $offset
            ) !== 0
        ) {
            fclose(
                $handle
            );

            throw new RuntimeException(
                'Could not seek to the YouTube upload resume point.'
            );
        }

        $headers = [
            'Authorization: Bearer '
            . $accessToken,

            'Accept: application/json',

            'Content-Type: '
            . YouTubeShippingConfig::VIDEO_MIME,

            'Content-Length: '
            . $remaining,
        ];

        if ($offset > 0) {
            $headers[] =
                'Content-Range: bytes '
                . $offset
                . '-'
                . ($totalBytes - 1)
                . '/'
                . $totalBytes;
        }

        $responseHeaders = [];

        $ch =
            curl_init(
                $uploadUrl
            );

        if ($ch === false) {
            fclose(
                $handle
            );

            throw new RuntimeException(
                'Could not initialize YouTube video upload.'
            );
        }

        $options = [
            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_UPLOAD =>
                true,

            CURLOPT_CUSTOMREQUEST =>
                'PUT',

            CURLOPT_INFILE =>
                $handle,

            CURLOPT_HTTPHEADER =>
                $headers,

            CURLOPT_HEADERFUNCTION =>
                $this->headerCollector(
                    $responseHeaders
                ),

            CURLOPT_CONNECTTIMEOUT =>
                YouTubeShippingConfig::REQUEST_TIMEOUT_SECONDS,

            CURLOPT_TIMEOUT =>
                YouTubeShippingConfig::VIDEO_UPLOAD_TIMEOUT_SECONDS,
        ];

        if (defined(
            'CURLOPT_INFILESIZE_LARGE'
        )) {
            $options[
                CURLOPT_INFILESIZE_LARGE
            ] =
                $remaining;
        } else {
            $options[
                CURLOPT_INFILESIZE
            ] =
                $remaining;
        }

        curl_setopt_array(
            $ch,
            $options
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

        fclose(
            $handle
        );

        return [
            'raw' =>
                $raw,

            'status' =>
                $status,

            'headers' =>
                $responseHeaders,

            'curl_error' =>
                $curlError,
        ];
    }

    private function recoverUploadState(
        string $uploadUrl,
        string $accessToken,
        int $totalBytes,
        int &$recoveries
    ): array {
        while (
            $recoveries <
            YouTubeShippingConfig::RESUME_MAX_ATTEMPTS
        ) {
            ++$recoveries;

            $probe =
                $this->probeUpload(
                    $uploadUrl,
                    $accessToken,
                    $totalBytes
                );

            $status =
                (int)$probe[
                    'status'
                ];

            if (
                $probe[
                    'raw'
                ] !== false
                && $status >= 200
                && $status < 300
            ) {
                return [
                    'video' =>
                        $this->decodeVideoResource(
                            (string)$probe[
                                'raw'
                            ]
                        ),
                ];
            }

            if ($status === 308) {
                $this->sleepBeforeRetry(
                    $recoveries,
                    $probe[
                        'headers'
                    ][
                        'retry-after'
                    ]
                    ?? null
                );

                return [
                    'offset' =>
                        $this->nextOffsetFromHeaders(
                            $probe[
                                'headers'
                            ]
                        ),
                ];
            }

            if ($status === 404) {
                throw new RuntimeException(
                    'YouTube resumable upload session expired.'
                );
            }

            if (
                $probe[
                    'raw'
                ] === false
                || in_array(
                    $status,
                    [
                        500,
                        502,
                        503,
                        504,
                    ],
                    true
                )
            ) {
                $this->sleepBeforeRetry(
                    $recoveries,
                    $probe[
                        'headers'
                    ][
                        'retry-after'
                    ]
                    ?? null
                );

                continue;
            }

            throw new RuntimeException(
                'YouTube upload-status check failed (HTTP '
                . $status
                . '): '
                . (
                    $probe[
                        'raw'
                    ] === false
                        ? (string)$probe[
                            'curl_error'
                        ]
                        : $this->providerMessage(
                            (string)$probe[
                                'raw'
                            ]
                        )
                )
            );
        }

        throw new RuntimeException(
            'YouTube video upload could not be resumed after repeated interruptions.'
        );
    }

    private function probeUpload(
        string $uploadUrl,
        string $accessToken,
        int $totalBytes
    ): array {
        $responseHeaders = [];

        $ch =
            curl_init(
                $uploadUrl
            );

        if ($ch === false) {
            throw new RuntimeException(
                'Could not initialize YouTube upload-status check.'
            );
        }

        curl_setopt_array(
            $ch,
            [
                CURLOPT_RETURNTRANSFER =>
                    true,

                CURLOPT_CUSTOMREQUEST =>
                    'PUT',

                CURLOPT_POSTFIELDS =>
                    '',

                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '
                    . $accessToken,

                    'Accept: application/json',

                    'Content-Length: 0',

                    'Content-Range: bytes */'
                    . $totalBytes,
                ],

                CURLOPT_HEADERFUNCTION =>
                    $this->headerCollector(
                        $responseHeaders
                    ),

                CURLOPT_CONNECTTIMEOUT =>
                    YouTubeShippingConfig::REQUEST_TIMEOUT_SECONDS,

                CURLOPT_TIMEOUT =>
                    YouTubeShippingConfig::REQUEST_TIMEOUT_SECONDS,
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

        return [
            'raw' =>
                $raw,

            'status' =>
                $status,

            'headers' =>
                $responseHeaders,

            'curl_error' =>
                $curlError,
        ];
    }

    private function nextOffsetFromHeaders(
        array $headers
    ): int {
        $range =
            trim(
                (string)(
                    $headers[
                        'range'
                    ]
                    ?? ''
                )
            );

        if ($range === '') {
            return 0;
        }

        if (!preg_match(
            '/bytes\s*=\s*0-(\d+)/i',
            $range,
            $matches
        )) {
            throw new RuntimeException(
                'YouTube returned an invalid resumable-upload Range header.'
            );
        }

        return ((int)$matches[1]) + 1;
    }

    private function decodeVideoResource(
        string $raw
    ): array {
        $decoded =
            json_decode(
                $raw,
                true
            );

        if (!is_array(
            $decoded
        )) {
            throw new RuntimeException(
                'YouTube video upload returned invalid JSON.'
            );
        }

        $videoId =
            trim(
                (string)(
                    $decoded[
                        'id'
                    ]
                    ?? ''
                )
            );

        if ($videoId === '') {
            throw new RuntimeException(
                'YouTube video upload response did not include a video ID.'
            );
        }

        return $decoded;
    }

    private function uploadThumbnail(
        string $videoId,
        string $thumbnailFile,
        string $accessToken
    ): array {
        $body =
            file_get_contents(
                $thumbnailFile
            );

        if ($body === false) {
            throw new RuntimeException(
                'Could not read authored YouTube thumbnail.'
            );
        }

        $lastMessage =
            'Unknown YouTube thumbnail upload failure.';

        for (
            $attempt = 1;
            $attempt <=
            YouTubeShippingConfig::THUMBNAIL_MAX_ATTEMPTS;
            ++$attempt
        ) {
            $responseHeaders = [];

            $ch =
                curl_init(
                    $this->config
                        ->thumbnailUrl(
                            $videoId
                        )
                );

            if ($ch === false) {
                throw new RuntimeException(
                    'Could not initialize YouTube thumbnail upload.'
                );
            }

            curl_setopt_array(
                $ch,
                [
                    CURLOPT_RETURNTRANSFER =>
                        true,

                    CURLOPT_POST =>
                        true,

                    CURLOPT_POSTFIELDS =>
                        $body,

                    CURLOPT_HTTPHEADER => [
                        'Authorization: Bearer '
                        . $accessToken,

                        'Accept: application/json',

                        'Content-Type: '
                        . YouTubeShippingConfig::THUMBNAIL_MIME,

                        'Content-Length: '
                        . strlen(
                            $body
                        ),
                    ],

                    CURLOPT_HEADERFUNCTION =>
                        $this->headerCollector(
                            $responseHeaders
                        ),

                    CURLOPT_CONNECTTIMEOUT =>
                        YouTubeShippingConfig::REQUEST_TIMEOUT_SECONDS,

                    CURLOPT_TIMEOUT =>
                        YouTubeShippingConfig::REQUEST_TIMEOUT_SECONDS,
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

            if (
                $raw !== false
                && $status >= 200
                && $status < 300
            ) {
                $decoded =
                    json_decode(
                        (string)$raw,
                        true
                    );

                if (!is_array(
                    $decoded
                )) {
                    throw new RuntimeException(
                        'YouTube thumbnail upload returned invalid JSON.'
                    );
                }

                return $decoded;
            }

            $lastMessage =
                $raw === false
                    ? $curlError
                    : $this->providerMessage(
                        (string)$raw
                    );

            if (
                !in_array(
                    $status,
                    [
                        429,
                        500,
                        502,
                        503,
                        504,
                    ],
                    true
                )
                && $raw !== false
            ) {
                break;
            }

            if (
                $attempt <
                YouTubeShippingConfig::THUMBNAIL_MAX_ATTEMPTS
            ) {
                $this->sleepBeforeRetry(
                    $attempt,
                    $responseHeaders[
                        'retry-after'
                    ]
                    ?? null
                );
            }
        }

        throw new RuntimeException(
            'YouTube thumbnail upload failed: '
            . $lastMessage
        );
    }

    private function headerCollector(
        array &$headers
    ): callable {
        return static function (
            $curl,
            string $line
        ) use (&$headers): int {
            $length =
                strlen(
                    $line
                );

            $parts =
                explode(
                    ':',
                    $line,
                    2
                );

            if (
                count(
                    $parts
                ) === 2
            ) {
                $name =
                    strtolower(
                        trim(
                            $parts[
                                0
                            ]
                        )
                    );

                if ($name !== '') {
                    $headers[
                        $name
                    ] =
                        trim(
                            $parts[
                                1
                            ]
                        );
                }
            }

            return $length;
        };
    }

    private function requiredFileSize(
        string $path,
        string $label
    ): int {
        $bytes =
            filesize(
                $path
            );

        if (
            $bytes === false
            || $bytes <= 0
        ) {
            throw new RuntimeException(
                "{$label} size could not be read."
            );
        }

        return (int)$bytes;
    }

    private function providerMessage(
        string $raw
    ): string {
        $decoded =
            json_decode(
                $raw,
                true
            );

        if (is_array(
            $decoded
        )) {
            $message =
                trim(
                    (string)(
                        $decoded[
                            'error'
                        ][
                            'message'
                        ]
                        ?? ''
                    )
                );

            if ($message !== '') {
                return $message;
            }

            $encoded =
                json_encode(
                    $decoded,
                    JSON_UNESCAPED_SLASHES
                );

            if (
                is_string(
                    $encoded
                )
                && $encoded !== ''
            ) {
                return $encoded;
            }
        }

        $raw =
            trim(
                $raw
            );

        return $raw !== ''
            ? $raw
            : 'Unknown provider error.';
    }

    private function sleepBeforeRetry(
        int $attempt,
        mixed $retryAfter
    ): void {
        $retrySeconds =
            is_scalar(
                $retryAfter
            )
                ? (int)$retryAfter
                : 0;

        if ($retrySeconds <= 0) {
            $retrySeconds =
                min(
                    16,
                    2 ** max(
                        0,
                        $attempt - 1
                    )
                );
        }

        sleep(
            $retrySeconds
        );
    }
}
