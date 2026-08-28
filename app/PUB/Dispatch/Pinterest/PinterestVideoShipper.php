<?php
declare(strict_types=1);

namespace App\PUB\Dispatch\Pinterest;

use App\PUB\Dispatch\Auth\PinterestAuthService;
use App\PUB\Dispatch\DispatchShipmentResult;
use App\PUB\Dispatch\Driver\DispatchDriverJob;
use App\PUB\Dispatch\Driver\DispatchDriverLauncher;
use App\PUB\Dispatch\Driver\DispatchDriverRouteContract;
use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;
use CURLFile;
use PDO;
use RuntimeException;

/**
 * PINTEREST VIDEO SHIPPER
 *
 * The specialist owns the complete Pinterest-video shipping strategy.
 *
 * ship()
 *   The normal DispatchManager entrypoint. This route is deliberately
 *   handed to a one-shot server driver because Pinterest video delivery
 *   requires multiple external trips and waiting/polling.
 *
 * driverRoute()
 *   The exact route the temporary driver executes:
 *
 *     1. get a valid Pinterest badge
 *     2. register video media
 *     3. upload the MP4 to Pinterest's returned upload station
 *     4. poll until Pinterest finishes processing the media
 *     5. create the final Pin using that media_id
 *     6. return the final Pinterest receipt
 *
 * The Shipper decides that it needs a driver. DispatchManager does not
 * know or care how this specialist executes its shipment.
 */
final class PinterestVideoShipper implements
    PubComWorkerContract,
    DispatchDriverRouteContract
{
    private const MEDIA_PATH = '/media';

    /*
     * Hard outer leash for the entire one-shot driver process.
     *
     * The OS-level timeout is enforced by DispatchDriverLauncher.
     */
    private const DRIVER_TIMEOUT_SECONDS = 600;

    /*
     * Inner limits keep individual network operations bounded too.
     */
    private const UPLOAD_TIMEOUT_SECONDS = 120;
    private const MEDIA_READY_TIMEOUT_SECONDS = 480;
    private const MEDIA_POLL_INTERVAL_SECONDS = 5;

    private ?PubComChannel $pubComChannel = null;
    private ?DispatchDriverLauncher $driverLauncher = null;


    public function __construct(
        private PinterestShippingConfig $config,
        private PinterestAuthService $auth
    ) {}


    public static function forDriver(
        PDO $pdo,
        string $projectRoot
    ): self {
        return new self(
            new PinterestShippingConfig(
                $pdo
            ),
            new PinterestAuthService(
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
            /*
             * Prove the badge office, destination, and driving company
             * are all available before Dispatch accepts this route.
             */
            $this->auth
                ->validAccessToken();

            $this->config
                ->productionBoardId();

            $this->driverLauncher()
                ->assertAvailable();

        } catch (\Throwable $e) {
            return PubComSignal::unavailable(
                'pinterest_video_shipping_unavailable',
                $e->getMessage(),
                [
                    'worker' =>
                        self::class,
                ]
            );
        }


        return PubComSignal::ready(
            'Pinterest Video Shipper is ready.',
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
                'pinterest_video_package_invalid',
                $e->getMessage(),
                [
                    'worker' =>
                        self::class,
                ]
            );
        }


        return PubComSignal::ready(
            'Pinterest video package is ready to ship.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    /**
     * DispatchManager hands this specialist one sealed package.
     *
     * This specialist chooses an asynchronous driver because its route
     * can include a large upload plus repeated processing-status checks.
     *
     * The Manager receives only IN_PROGRESS. It does not know a driver
     * was involved.
     */
    public function ship(
        int $pubAssetId,
        array $package
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Pinterest Video Shipper requires a valid pub_asset_id.'
            );
        }


        $this->assertPackage(
            $package
        );


        $job =
            new DispatchDriverJob(
                $pubAssetId,
                self::class,
                self::DRIVER_TIMEOUT_SECONDS
            );


        $handle =
            $this->driverLauncher()
                ->requestDriver(
                    $job
                );


        error_log(
            'Pinterest video shipment #'
            . $pubAssetId
            . ' handed to Dispatch driver PID '
            . $handle->pid()
            . '.'
        );


        return DispatchShipmentResult::inProgress();
    }


    /**
     * Complete route executed inside the temporary server driver process.
     *
     * The driving company does not interpret any of these Pinterest steps.
     * It simply runs this specialist-authored route and returns its receipt
     * to DispatchDesk.
     */
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


        /*
         * TRIP 1 — register the video shipment.
         */
        $registration =
            $this->postJson(
                self::MEDIA_PATH,
                [
                    'media_type' =>
                        'video',
                ],
                $accessToken
            );


        $mediaId =
            trim(
                (string)(
                    $registration[
                        'media_id'
                    ]
                    ?? ''
                )
            );

        $uploadUrl =
            trim(
                (string)(
                    $registration[
                        'upload_url'
                    ]
                    ?? ''
                )
            );

        $uploadParameters =
            is_array(
                $registration[
                    'upload_parameters'
                ]
                ?? null
            )
                ? $registration[
                    'upload_parameters'
                ]
                : [];


        if ($mediaId === '') {
            throw new RuntimeException(
                'Pinterest video registration returned no media_id.'
            );
        }


        if ($uploadUrl === '') {
            throw new RuntimeException(
                'Pinterest video registration returned no upload_url.'
            );
        }


        if ($uploadParameters === []) {
            throw new RuntimeException(
                'Pinterest video registration returned no upload parameters.'
            );
        }


        /*
         * TRIP 2 — take the actual MP4 to Pinterest's returned upload dock.
         *
         * This upload URL is not the Pinterest API endpoint and does not
         * receive the Pinterest bearer token. It receives only Pinterest's
         * transient upload parameters plus the file.
         */
        $this->uploadVideo(
            $uploadUrl,
            $uploadParameters,
            (string)$package[
                'video_file_path'
            ]
        );


        /*
         * TRIP 3+ — keep checking until Pinterest has processed the media.
         */
        $mediaStatus =
            $this->waitForMedia(
                $mediaId,
                $accessToken
            );


        /*
         * FINAL TRIP — create the actual Pin using the processed media.
         */
        $response =
            $this->postJson(
                PinterestShippingConfig::PINS_PATH,
                [
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
                            'video_id',

                        'cover_image_url' =>
                            trim(
                                (string)$package[
                                    'media_source'
                                ][
                                    'cover_image_url'
                                ]
                            ),

                        'media_id' =>
                            $mediaId,
                    ],
                ],
                $accessToken
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
                'Pinterest accepted the video Pin request but returned no Pin ID.'
            );
        }


        return [
            'external_id' =>
                $pinId,

            'external_url' =>
                'https://www.pinterest.com/pin/'
                . rawurlencode(
                    $pinId
                )
                . '/',

            'media_id' =>
                $mediaId,

            'media_status' =>
                $mediaStatus,

            'response' =>
                $response,
        ];
    }


    private function driverLauncher(): DispatchDriverLauncher
    {
        if ($this->driverLauncher === null) {
            /*
             * __DIR__:
             *   <root>/app/PUB/Dispatch/Pinterest
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
                'description',
                'link',
                'video_file_path',
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
                    "Pinterest video package requires {$field}."
                );
            }
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
                'Pinterest video package MP4 is missing or unreadable.'
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


        if (
            strtolower(
                trim(
                    (string)(
                        $mediaSource[
                            'source_type'
                        ]
                        ?? ''
                    )
                )
            ) !== 'video_id'
        ) {
            throw new RuntimeException(
                'Pinterest video package requires media_source.source_type = video_id.'
            );
        }


        $coverImageUrl =
            trim(
                (string)(
                    $mediaSource[
                        'cover_image_url'
                    ]
                    ?? ''
                )
            );


        if (
            !preg_match(
                '#^https?://#i',
                $coverImageUrl
            )
        ) {
            throw new RuntimeException(
                'Pinterest video package requires an absolute cover_image_url.'
            );
        }
    }


    private function uploadVideo(
        string $uploadUrl,
        array $uploadParameters,
        string $videoFile
    ): void {
        $fields = [];


        foreach (
            $uploadParameters
            as $name => $value
        ) {
            if (
                is_scalar(
                    $value
                )
                || $value === null
            ) {
                $fields[
                    (string)$name
                ] =
                    (string)$value;
            }
        }


        $fields[
            'file'
        ] =
            new CURLFile(
                $videoFile,
                'video/mp4',
                basename(
                    $videoFile
                )
            );


        $ch =
            curl_init(
                $uploadUrl
            );


        if ($ch === false) {
            throw new RuntimeException(
                'Could not initialize Pinterest video upload.'
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
                    $fields,

                CURLOPT_CONNECTTIMEOUT =>
                    PinterestShippingConfig::REQUEST_TIMEOUT_SECONDS,

                CURLOPT_TIMEOUT =>
                    self::UPLOAD_TIMEOUT_SECONDS,
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
                'Pinterest video upload failed: '
                . $curlError
            );
        }


        if (
            $status < 200
            || $status >= 300
        ) {
            throw new RuntimeException(
                'Pinterest video upload failed (HTTP '
                . $status
                . '): '
                . trim(
                    (string)$raw
                )
            );
        }
    }


    private function waitForMedia(
        string $mediaId,
        string $accessToken
    ): array {
        $deadline =
            time()
            + self::MEDIA_READY_TIMEOUT_SECONDS;


        $lastResponse = [];


        while (
            time() <=
            $deadline
        ) {
            $lastResponse =
                $this->getJson(
                    self::MEDIA_PATH
                    . '/'
                    . rawurlencode(
                        $mediaId
                    ),
                    $accessToken
                );


            $status =
                strtolower(
                    trim(
                        (string)(
                            $lastResponse[
                                'status'
                            ]
                            ?? ''
                        )
                    )
                );


            if (
                in_array(
                    $status,
                    [
                        'succeeded',
                        'ready',
                    ],
                    true
                )
            ) {
                return $lastResponse;
            }


            if (
                in_array(
                    $status,
                    [
                        'failed',
                        'failure',
                        'error',
                    ],
                    true
                )
            ) {
                throw new RuntimeException(
                    'Pinterest failed while processing video media'
                    . (
                        $status !== ''
                            ? " ({$status})"
                            : ''
                    )
                    . '.'
                );
            }


            sleep(
                self::MEDIA_POLL_INTERVAL_SECONDS
            );
        }


        throw new RuntimeException(
            'Pinterest video media did not become ready within '
            . self::MEDIA_READY_TIMEOUT_SECONDS
            . ' seconds.'
        );
    }


    private function postJson(
        string $path,
        array $payload,
        string $accessToken
    ): array {
        return $this->requestJson(
            'POST',
            $path,
            $accessToken,
            $payload
        );
    }


    private function getJson(
        string $path,
        string $accessToken
    ): array {
        return $this->requestJson(
            'GET',
            $path,
            $accessToken,
            null
        );
    }


    private function requestJson(
        string $method,
        string $path,
        string $accessToken,
        ?array $payload
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
                'Could not initialize Pinterest shipping request.'
            );
        }


        $headers = [
            'Authorization: Bearer '
            . $accessToken,

            'Accept: application/json',
        ];


        $options = [
            CURLOPT_RETURNTRANSFER =>
                true,

            CURLOPT_CUSTOMREQUEST =>
                strtoupper(
                    $method
                ),

            CURLOPT_HTTPHEADER =>
                $headers,

            CURLOPT_CONNECTTIMEOUT =>
                PinterestShippingConfig::REQUEST_TIMEOUT_SECONDS,

            CURLOPT_TIMEOUT =>
                PinterestShippingConfig::REQUEST_TIMEOUT_SECONDS,
        ];


        if ($payload !== null) {
            $headers[] =
                'Content-Type: application/json';

            $options[
                CURLOPT_HTTPHEADER
            ] =
                $headers;

            $options[
                CURLOPT_POSTFIELDS
            ] =
                json_encode(
                    $payload,
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
                );
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


        if ($raw === false) {
            throw new RuntimeException(
                'Pinterest shipping request failed: '
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
                    ? (
                        trim(
                            (string)(
                                $decoded[
                                    'message'
                                ]
                                ?? ''
                            )
                        )
                        ?: json_encode(
                            $decoded,
                            JSON_UNESCAPED_SLASHES
                        )
                    )
                    : trim(
                        (string)$raw
                    );


            throw new RuntimeException(
                'Pinterest shipping request failed (HTTP '
                . $status
                . '): '
                . $message
            );
        }


        if (!is_array(
            $decoded
        )) {
            throw new RuntimeException(
                'Pinterest shipping request returned invalid JSON.'
            );
        }


        return $decoded;
    }
}
