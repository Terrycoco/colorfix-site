<?php
declare(strict_types=1);

namespace App\PUB\Dispatch;

use App\PUB\Dispatch\Pinterest\PinterestImageShipper;
use App\PUB\Dispatch\Pinterest\PinterestShippingConfig;
use App\PUB\Dispatch\Pinterest\PinterestVideoShipper;
use App\PUB\Errors\PubErrorReporter;
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
 * DISPATCH MANAGER
 *
 * Department head for Shipping.
 *
 * DispatchManager knows PUB workflow, not external API anatomy.
 *
 * It:
 *   - receives assets already selected for pipeline_stage=shipping
 *   - reads only channel + mime_type to choose a shipping line
 *   - wakes only the required Shipper
 *   - hands the sealed package to that specialist unchanged
 *   - interprets PubCom readiness/preflight signals
 *   - persists the returned shipping receipt
 *   - moves successful assets shipping -> shipped
 *   - stamps dispatched_at through the repository
 *   - records unexpected DISPATCH failures centrally
 *
 * It does NOT:
 *   - open/map Pinterest payload fields
 *   - know board IDs or OAuth tokens
 *   - perform API requests
 *   - rewrite pub_assets.package
 */
final class DispatchManager implements PubComManagerContract
{
    private PubErrorReporter $errors;
    private PdoPubAssetRepository $assets;

    /**
     * Lazily awakened shipping specialists.
     *
     * @var array<string, PubComWorkerContract>
     */
    private array $shippers = [];

    private ?PinterestShippingConfig $pinterestConfig = null;


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
                'Dispatch Manager requires project root.'
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
            'Dispatch Manager is ready.',
            [
                'department' =>
                    'dispatch',

                'manager' =>
                    self::class,
            ]
        );
    }


    /**
     * DISPATCH PubCom policy.
     *
     * A malformed package is one unit's problem.
     * An unavailable external shipping station stops that line.
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
            "DISPATCH received an unsupported PubCom signal type '{$signal->type()}'."
        );
    }


    /**
     * Ship every asset already selected by Schedule.
     *
     * For the current vertical slice, manually setting an asset to
     * pipeline_stage=shipping simulates Schedule's selection step.
     *
     * Repository methods expected:
     *
     *   listShipping()
     *   markShipped(pub_asset_id, receipt)
     *
     * @return array{
     *   shipped: array<int, array<string, mixed>>,
     *   failed: array<int, array<string, mixed>>
     * }
     */
    public function processShipping(): array
    {
        $shipped = [];
        $failed = [];
        $stoppedLines = [];


        foreach (
            $this->assets
                ->listShipping()
            as $index => $asset
        ) {
            $result =
                $this->processOneAsset(
                    $asset,
                    $index,
                    $stoppedLines
                );


            if (
                isset(
                    $result[
                        'shipped'
                    ]
                )
            ) {
                $shipped[] =
                    $result[
                        'shipped'
                    ];
            }


            if (
                isset(
                    $result[
                        'failed'
                    ]
                )
            ) {
                $failed[] =
                    $result[
                        'failed'
                    ];
            }


            if (
                !empty(
                    $result[
                        'stop_job'
                    ]
                )
            ) {
                break;
            }
        }


        return [
            'shipped' =>
                $shipped,

            'failed' =>
                $failed,
        ];
    }


    /**
     * Ship exactly one selected asset.
     *
     * Useful for an admin/manual shipping button and later for
     * Schedule handing one chosen pub_asset_id into Dispatch.
     *
     * Repository method expected:
     *
     *   getShippingById(pub_asset_id)
     */
    public function shipOne(
        int $pubAssetId
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Dispatch requires a valid pub_asset_id.'
            );
        }


        $asset =
            $this->assets
                ->getShippingById(
                    $pubAssetId
                );


        if ($asset === null) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} is not waiting at Shipping."
            );
        }


        $stoppedLines = [];


        $result =
            $this->processOneAsset(
                $asset,
                0,
                $stoppedLines
            );


        return [
            'ok' =>
                isset(
                    $result[
                        'shipped'
                    ]
                ),

            ...$result,
        ];
    }


    private function processOneAsset(
        array $asset,
        int $index,
        array &$stoppedLines
    ): array {
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
                    'DISPATCH received a shipping row without pub_asset_id.'
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
                return [
                    'failed' => [
                        'index' =>
                            $index,

                        'pub_asset_id' =>
                            $pubAssetId,

                        'line' =>
                            $lineKey,

                        ...$stoppedLines[
                            $lineKey
                        ],
                    ],
                ];
            }


            $package =
                $this->packageFromAsset(
                    $asset
                );


            $shipper =
                $this->shipperForAsset(
                    $asset
                );


            $gate =
                $this->authorizeShipper(
                    $shipper,
                    $package
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


                $failure = [
                    'index' =>
                        $index,

                    'pub_asset_id' =>
                        $pubAssetId,

                    'line' =>
                        $lineKey,

                    'error' =>
                        $signal
                            ->message(),

                    'pubcom' =>
                        $pubComChannel
                            ->dispositionsAsArray(),
                ];


                if (
                    $disposition
                        ->shouldStopLine()
                ) {
                    $stoppedLines[
                        $lineKey
                    ] = [
                        'error' =>
                            $signal
                                ->message(),

                        'pubcom' =>
                            $pubComChannel
                                ->dispositionsAsArray(),
                    ];
                }


                return [
                    'failed' =>
                        $failure,

                    'stop_job' =>
                        $disposition
                            ->shouldStopJob(),
                ];
            }


            /*
             * SEALED PACKAGE BOUNDARY.
             *
             * DispatchManager does not open or map the package.
             */
            $receipt =
                $shipper
                    ->ship(
                        $package
                    );


            if (
                !is_array(
                    $receipt
                )
                || $receipt === []
            ) {
                throw new RuntimeException(
                    'Shipping specialist returned no receipt.'
                );
            }


            /*
             * Repository owns shipping_receipt/dispatched_at/stage fields.
             *
             * DispatchManager hands the specialist receipt through unchanged.
             */
            $state =
                $this->assets
                    ->markShipped(
                        $pubAssetId,
                        $receipt
                    );


            return [
                'shipped' => [
                    'index' =>
                        $index,

                    'pub_asset_id' =>
                        $pubAssetId,

                    'line' =>
                        $lineKey,

                    'state' =>
                        $state,

                    'shipping_receipt' =>
                        $receipt,

                    'pubcom' =>
                        $pubComChannel
                            ->dispositionsAsArray(),
                ],
            ];

        } catch (Throwable $e) {
            if ($pubAssetId > 0) {
                try {
                    $this->assets
                        ->markError(
                            $pubAssetId,
                            'dispatch',
                            'dispatch_failure',
                            $e->getMessage()
                        );
                } catch (Throwable) {
                    /*
                     * Preserve the original DISPATCH exception.
                     */
                }
            }


            $failure =
                $this->errors
                    ->report(
                        $e,
                        [
                            'stage' =>
                                'dispatch',

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
                                'dispatch_failure',

                            'diagnostics' => [
                                'batch_index' =>
                                    $index,

                                'line' =>
                                    $lineKey,
                            ],
                        ]
                    );


            return [
                'failed' => [
                    'index' =>
                        $index,

                    ...$failure,

                    'pubcom' =>
                        $pubComChannel
                            ? $pubComChannel
                                ->dispositionsAsArray()
                            : [],
                ],
            ];
        }
    }


    private function authorizeShipper(
        PubComWorkerContract $shipper,
        array $package
    ): array {
        $channel =
            new PubComChannel(
                $this
            );


        $shipper->connectPubCom(
            $channel
        );


        $readiness =
            $channel->report(
                $shipper->readiness()
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
                $shipper->preflight(
                    $package
                )
            );


        return [
            'channel' =>
                $channel,

            'disposition' =>
                $preflight,
        ];
    }


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


        throw new RuntimeException(
            "DISPATCH has no shipping route for channel '{$channel}' and mime_type '{$mimeType}'."
        );
    }


    private function shipperForAsset(
        array $asset
    ): PubComWorkerContract {
        $lineKey =
            $this->lineKeyForAsset(
                $asset
            );


        if (
            isset(
                $this->shippers[
                    $lineKey
                ]
            )
        ) {
            return $this->shippers[
                $lineKey
            ];
        }


        $shipper =
            match (
                $lineKey
            ) {
                'pinterest.image' =>
                    new PinterestImageShipper(
                        $this->pinterestConfig()
                    ),

                'pinterest.video' =>
                    new PinterestVideoShipper(
                        $this->pinterestConfig()
                    ),

                default =>
                    throw new RuntimeException(
                        "DISPATCH has no Shipper registered for line '{$lineKey}'."
                    ),
            };


        $this->shippers[
            $lineKey
        ] =
            $shipper;


        return $shipper;
    }


    private function pinterestConfig(): PinterestShippingConfig
    {
        if (
            $this->pinterestConfig ===
            null
        ) {
            $this->pinterestConfig =
                new PinterestShippingConfig(
                    $this->pdo
                );
        }


        return $this->pinterestConfig;
    }


    private function packageFromAsset(
        array $asset
    ): array {
        $package =
            $asset[
                'package'
            ]
            ?? null;


        if (is_array($package)) {
            if ($package === []) {
                throw new RuntimeException(
                    'DISPATCH received an empty package.'
                );
            }


            return $package;
        }


        if (
            is_string(
                $package
            )
            && trim(
                $package
            ) !== ''
        ) {
            $decoded =
                json_decode(
                    $package,
                    true
                );


            if (
                is_array(
                    $decoded
                )
                && $decoded !== []
            ) {
                return $decoded;
            }
        }


        throw new RuntimeException(
            'DISPATCH received an asset without a usable sealed package.'
        );
    }
}
