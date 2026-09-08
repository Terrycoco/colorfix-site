<?php
declare(strict_types=1);

namespace App\PUB\Package;

use App\PUB\Errors\PubErrorReporter;
use App\PUB\Package\Pinterest\PinterestImagePackager;
use App\PUB\Package\Pinterest\PinterestVideoPackager;
use App\PUB\Package\YouTube\YouTubeVideoPackager;
use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComDisposition;
use App\PUB\PubCom\PubComManagerContract;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;
use App\PUB\Repos\PdoPubAssetRepository;
use PDO;
use RuntimeException;
use Throwable;

/**
 * PACKAGE MANAGER
 *
 * Department head for every asset currently sitting at Packing.
 *
 * PackageManager does not understand Pinterest payload fields,
 * YouTube thumbnail rules, or any other specialist package detail.
 *
 * It:
 *
 *   - looks at durable pub_assets rows marked "packing"
 *   - reads only enough routing information to choose a Packager
 *   - wakes that Packager lazily
 *   - lets the Packager inspect its own assignment
 *   - interprets PubCom signals
 *   - persists normal PENDING notes
 *   - hands a completed package array unchanged to the repository
 *   - marks successful assets "packed"
 *   - reports unexpected PACKAGE failures centrally
 *
 * PACKAGERS own product/channel-specific qualification and package shape.
 *
 * A PENDING Packager response means:
 *
 *   valid work
 *   + required dependency not available yet
 *   + leave the row at pipeline_stage=packing
 *   + try again on a later PackageManager pass
 */
final class PackageManager implements PubComManagerContract
{
    private PubErrorReporter $errors;
    private PdoPubAssetRepository $assets;

    /**
     * Lazily awakened Package specialists, cached for this request.
     *
     * @var array<string, PubComWorkerContract>
     */
    private array $packagers = [];

    private ?string $publicBaseUrl = null;


    public function __construct(
        private PDO $pdo,
        private string $projectRoot,
    ) {
        $this->projectRoot =
            rtrim(
                trim(
                    $this->projectRoot
                ),
                DIRECTORY_SEPARATOR
            );


        if ($this->projectRoot === '') {
            throw new RuntimeException(
                'Package Manager requires project root.'
            );
        }


        $this->assets =
            new PdoPubAssetRepository(
                $this->pdo
            );


        $this->errors =
            new PubErrorReporter(
                $this->projectRoot
                . '/app/PUB/Errors/pub_errors.log'
            );
    }


    public function readiness(): PubComSignal
    {
        return PubComSignal::ready(
            'Package Manager is ready.',
            [
                'department' =>
                    'package',

                'manager' =>
                    self::class,
            ]
        );
    }


    /**
     * PACKAGE PubCom policy.
     *
     * PENDING is a normal wait condition. The unit stays in Packing,
     * receives a stage_note, and the Manager continues to other boxes.
     */
    public function handleSignal(
        PubComSignal $signal
    ): PubComDisposition {
        if ($signal->isReady()) {
            return PubComDisposition::make(
                $signal,
                PubComDisposition::CONTINUE,
                PubComDisposition::DISPLAY_NONE,
                false
            );
        }


        if ($signal->isNotice()) {
            return PubComDisposition::make(
                $signal,
                PubComDisposition::CONTINUE,
                PubComDisposition::DISPLAY_TOAST,
                true
            );
        }


        if ($signal->isPending()) {
            return PubComDisposition::make(
                $signal,
                PubComDisposition::SKIP_UNIT,
                PubComDisposition::DISPLAY_NONE,
                true
            );
        }


        if ($signal->isIneligible()) {
            return PubComDisposition::make(
                $signal,
                PubComDisposition::SKIP_UNIT,
                PubComDisposition::DISPLAY_TOAST,
                true
            );
        }


        if ($signal->isUnavailable()) {
            return PubComDisposition::make(
                $signal,
                PubComDisposition::STOP_LINE,
                PubComDisposition::DISPLAY_POPUP,
                true
            );
        }


        throw new RuntimeException(
            "PACKAGE received an unsupported PubCom signal type '{$signal->type()}'."
        );
    }


    /**
     * Wake Package and process every durable asset currently marked packing.
     *
     * The endpoint does not need to send the boxes themselves. The database
     * is authoritative once CREATE has produced pub_assets rows.
     *
     * @return array{
     *   packed: array<int, array<string, mixed>>,
     *   pending: array<int, array<string, mixed>>,
     *   failed: array<int, array<string, mixed>>
     * }
     */
    /**
     * Hand exactly one reviewed asset to Package.
     *
     * This is the Review -> Package handoff used by the asset editor.
     * It does NOT sweep other rows already waiting at Packing.
     */
    public function sendToPacking(
        int $pubAssetId
    ): array {
        $this->assets
            ->markPacking(
                $pubAssetId
            );


        $asset =
            $this->assets
                ->getPackingById(
                    $pubAssetId
                );


        if ($asset === null) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} could not be loaded at Packing."
            );
        }


        return $this->processAssets([
            $asset,
        ]);
    }


    /**
     * Retry/process every durable asset currently waiting at Packing.
     *
     * This is the department-wide workbench action. It remains separate
     * from sendToPacking(), which handles one reviewed asset only.
     */
    public function processPacking(): array
    {
        return $this->processAssets(
            $this->assets
                ->listPacking()
        );
    }


    /**
     * Process the exact Packing rows supplied by the Manager entry point.
     *
     * @param array<int, array<string, mixed>> $assets
     *
     * @return array{
     *   packed: array<int, array<string, mixed>>,
     *   pending: array<int, array<string, mixed>>,
     *   failed: array<int, array<string, mixed>>
     * }
     */
    private function processAssets(
        array $assets
    ): array
    {
        $packed = [];
        $pending = [];
        $failed = [];

        /*
         * STOP_LINE applies only to the affected downstream media line.
         * Example: pinterest.image may stop while a future youtube.video
         * line continues.
         */
        $stoppedLines = [];


        foreach (
            $assets
            as $index => $asset
        ) {
            $pubAssetId =
                (int)(
                    $asset[
                        'pub_asset_id'
                    ]
                    ?? 0
                );

            $lineKey = null;
            $pubComChannel = null;


            try {
                if ($pubAssetId <= 0) {
                    throw new RuntimeException(
                        'PACKAGE received a packing row without pub_asset_id.'
                    );
                }


                $lineKey =
                    $this->lineKeyForAsset(
                        $asset
                    );


                if (
                    isset(
                        $stoppedLines[
                            $lineKey
                        ]
                    )
                ) {
                    $failed[] = [
                        'index' =>
                            $index,

                        'pub_asset_id' =>
                            $pubAssetId,

                        'line' =>
                            $lineKey,

                        ...$stoppedLines[
                            $lineKey
                        ],
                    ];


                    continue;
                }


                $packager =
                    $this->packagerForAsset(
                        $asset
                    );


                $gate =
                    $this->authorizePackager(
                        $packager,
                        $asset
                    );


                $pubComChannel =
                    $gate[
                        'channel'
                    ];

                $disposition =
                    $gate[
                        'disposition'
                    ];


                if (
                    !$disposition
                        ->shouldContinue()
                ) {
                    $signal =
                        $disposition
                            ->signal();


                    if ($signal->isPending()) {
                        $state =
                            $this->assets
                                ->markPackingPending(
                                    $pubAssetId,
                                    $signal->message()
                                );


                        $pending[] = [
                            'index' =>
                                $index,

                            'pub_asset_id' =>
                                $pubAssetId,

                            'line' =>
                                $lineKey,

                            'state' =>
                                $state,

                            'pubcom' =>
                                $pubComChannel
                                    ->dispositionsAsArray(),
                        ];


                        continue;
                    }


                    $failure = [
                        'index' =>
                            $index,

                        'pub_asset_id' =>
                            $pubAssetId,

                        'line' =>
                            $lineKey,

                        'error' =>
                            $signal->message(),

                        'pubcom' =>
                            $pubComChannel
                                ->dispositionsAsArray(),
                    ];


                    $failed[] =
                        $failure;


                    if (
                        $disposition
                            ->shouldStopLine()
                    ) {
                        $stoppedLines[
                            $lineKey
                        ] = [
                            'error' =>
                                $signal->message(),

                            'pubcom' =>
                                $pubComChannel
                                    ->dispositionsAsArray(),
                        ];
                    }


                    if (
                        $disposition
                            ->shouldStopJob()
                    ) {
                        break;
                    }


                    continue;
                }


                /*
                 * Packager owns the package shape.
                 *
                 * Manager deliberately does not open/map the returned array.
                 */
                $package =
                    $packager
                        ->pack(
                            $asset
                        );


                $state =
                    $this->assets
                        ->markPacked(
                            $pubAssetId,
                            $package
                        );


                $packed[] = [
                    'index' =>
                        $index,

                    'pub_asset_id' =>
                        $pubAssetId,

                    'line' =>
                        $lineKey,

                    'state' =>
                        $state,

                    'package' =>
                        $package,

                    'pubcom' =>
                        $pubComChannel
                            ->dispositionsAsArray(),
                ];

            } catch (Throwable $e) {
                if ($pubAssetId > 0) {
                    try {
                        $this->assets
                            ->markError(
                                $pubAssetId,
                                'package',
                                'package_failure',
                                $e->getMessage()
                            );
                    } catch (Throwable) {
                        /*
                         * Preserve the original PACKAGE exception.
                         */
                    }
                }


                $failure =
                    $this->errors
                        ->report(
                            $e,
                            [
                                'stage' =>
                                    'package',

                                'pub_run_id' =>
                                    $asset[
                                        'pub_run_id'
                                    ]
                                    ?? null,

                                'pub_asset_id' =>
                                    $pubAssetId > 0
                                        ? $pubAssetId
                                        : null,

                                'asset_type' =>
                                    $asset[
                                        'asset_type'
                                    ]
                                    ?? null,

                                'source_type' =>
                                    $asset[
                                        'source_type'
                                    ]
                                    ?? null,

                                'source_id' =>
                                    $asset[
                                        'source_id'
                                    ]
                                    ?? null,

                                'code' =>
                                    'package_failure',

                                'diagnostics' => [
                                    'batch_index' =>
                                        $index,

                                    'line' =>
                                        $lineKey,
                                ],
                            ]
                        );


                $failed[] = [
                    'index' =>
                        $index,

                    ...$failure,

                    'pubcom' =>
                        $pubComChannel
                            ? $pubComChannel
                                ->dispositionsAsArray()
                            : [],
                ];
            }
        }


        return [
            'packed' =>
                $packed,

            'pending' =>
                $pending,

            'failed' =>
                $failed,
        ];
    }


    /**
     * Give one Packager a private PubCom channel, ask whether the
     * worker is operational, then let that specialist inspect its
     * exact asset.
     *
     * @return array{
     *   channel: PubComChannel,
     *   disposition: PubComDisposition
     * }
     */
    private function authorizePackager(
        PubComWorkerContract $packager,
        array $asset
    ): array {
        $channel =
            new PubComChannel(
                $this
            );


        $packager->connectPubCom(
            $channel
        );


        $readiness =
            $channel->report(
                $packager->readiness()
            );


        if (
            !$readiness
                ->shouldContinue()
        ) {
            return [
                'channel' =>
                    $channel,

                'disposition' =>
                    $readiness,
            ];
        }


        $preflight =
            $channel->report(
                $packager->preflight(
                    $asset
                )
            );


        return [
            'channel' =>
                $channel,

            'disposition' =>
                $preflight,
        ];
    }


    /**
     * Downstream routing collapses CREATE recipe detail into the
     * physical media class the courier actually cares about.
     */
    private function lineKeyForAsset(
        array $asset
    ): string {
        $channel =
            strtolower(
                trim(
                    (string)(
                        $asset[
                            'channel'
                        ]
                        ?? ''
                    )
                )
            );

        $mimeType =
            strtolower(
                trim(
                    (string)(
                        $asset[
                            'mime_type'
                        ]
                        ?? ''
                    )
                )
            );


        if (
            $channel === 'pinterest'
            && str_starts_with(
                $mimeType,
                'image/'
            )
        ) {
            return 'pinterest.image';
        }


        if (
            $channel === 'pinterest'
            && str_starts_with(
                $mimeType,
                'video/'
            )
        ) {
            return 'pinterest.video';
        }


        if (
            $channel === 'youtube'
            && str_starts_with(
                $mimeType,
                'video/'
            )
        ) {
            return 'youtube.video';
        }


        throw new RuntimeException(
            "PACKAGE has no packing route for channel '{$channel}' and mime_type '{$mimeType}'."
        );
    }


    /**
     * Wake only the specialist required for this physical shipping line.
     */
    private function packagerForAsset(
        array $asset
    ): PubComWorkerContract {
        $lineKey =
            $this->lineKeyForAsset(
                $asset
            );


        if (
            isset(
                $this->packagers[
                    $lineKey
                ]
            )
        ) {
            return $this->packagers[
                $lineKey
            ];
        }


        $packager =
            match (
                $lineKey
            ) {
                'pinterest.image' =>
                    new PinterestImagePackager(
                        $this->publicBaseUrl(),
                        $this->pdo
                    ),

                'pinterest.video' =>
                    new PinterestVideoPackager(
                        $this->publicBaseUrl()
                    ),

                'youtube.video' =>
                    new YouTubeVideoPackager(),

                default =>
                    throw new RuntimeException(
                        "PACKAGE has no Packager registered for line '{$lineKey}'."
                    ),
            };


        $this->packagers[
            $lineKey
        ] =
            $packager;


        return $packager;
    }


    /**
     * Determine the public ColorFix origin only when a Packager needs it.
     */
    private function publicBaseUrl(): string
    {
        if ($this->publicBaseUrl !== null) {
            return $this->publicBaseUrl;
        }


        $forwardedProto =
            strtolower(
                trim(
                    (string)(
                        $_SERVER[
                            'HTTP_X_FORWARDED_PROTO'
                        ]
                        ?? ''
                    )
                )
            );


        if (
            !in_array(
                $forwardedProto,
                [
                    'http',
                    'https',
                ],
                true
            )
        ) {
            $forwardedProto =
                !empty(
                    $_SERVER[
                        'HTTPS'
                    ]
                )
                &&
                strtolower(
                    (string)(
                        $_SERVER[
                            'HTTPS'
                        ]
                        ?? ''
                    )
                ) !== 'off'
                    ? 'https'
                    : 'http';
        }


        $host =
            trim(
                (string)(
                    $_SERVER[
                        'HTTP_HOST'
                    ]
                    ?? ''
                )
            );


        if ($host === '') {
            throw new RuntimeException(
                'PACKAGE could not determine the public host.'
            );
        }


        $this->publicBaseUrl =
            $forwardedProto
            . '://'
            . $host;


        return $this->publicBaseUrl;
    }
}
