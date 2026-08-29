<?php
declare(strict_types=1);

namespace App\PUB\Dispatch;

use App\PUB\Dispatch\Auth\PinterestAuthService;
use App\PUB\Dispatch\Auth\YouTubeAuthService;
use App\PUB\Dispatch\Pinterest\PinterestImageShipper;
use App\PUB\Dispatch\Pinterest\PinterestShippingConfig;
use App\PUB\Dispatch\Pinterest\PinterestVideoShipper;
use App\PUB\Dispatch\YouTube\YouTubeShippingConfig;
use App\PUB\Dispatch\YouTube\YouTubeVideoShipper;
use App\PUB\Errors\PubErrorReporter;
use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComDisposition;
use App\PUB\PubCom\PubComManagerContract;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;
use App\PUB\Repos\PdoPubAssetRepository;
use App\PUB\Support\ProductionSignature;
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
 *   - receives one packed asset ID from Manual Send Now or Schedule
 *   - accepts custody by moving packed -> shipping
 *   - reads only channel + mime_type to choose a shipping line
 *   - wakes only the required Shipper
 *   - hands the sealed package to that specialist unchanged
 *   - interprets PubCom readiness/preflight signals
 *   - persists the returned shipping receipt
 *   - moves successful assets shipping -> shipped
 *   - moves failed shipments shipping -> error/dispatch
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
    private ?PinterestAuthService $pinterestAuth = null;
    private ?YouTubeShippingConfig $youtubeConfig = null;
    private ?YouTubeAuthService $youtubeAuth = null;


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
     * Recovery/administrative sweep for assets already in Dispatch custody.
     *
     * Normal one-box entry is shipOne(pub_asset_id), which accepts a
     * PACKED asset and owns the packed -> shipping transition.
     *
     * Schedule does NOT move rows to shipping. When an asset is due,
     * Schedule calls shipOne(pub_asset_id), exactly like Manual Send Now.
     *
     * Repository methods expected:
     *
     *   listShipping()
     *   finalizeShipment(pub_asset_id, receipt, production_signature)
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
     * Canonical one-box Dispatch entrypoint.
     *
     * Both Manual Send Now and Schedule call this exact method.
     *
     * DispatchManager owns custody:
     *
     *   packed -> shipping
     *
     * Once custody is accepted, every outcome must leave shipping:
     *
     *   success -> shipped
     *   failure -> error / dispatch
     *
     * Repository methods expected:
     *
     *   getById(pub_asset_id)
     *   markShipping(pub_asset_id)
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


        /*
         * Canonical first attempt:
         *
         *   packed -> shipping
         *
         * Explicit recovery:
         *
         *   error / dispatch -> shipping
         *
         * Schedule does not pre-mark anything as shipping. A retry also
         * comes through this same one-box Dispatch entrypoint.
         */
        $candidate =
            $this->assets
                ->getById(
                    $pubAssetId
                );


        if ($candidate === null) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} was not found."
            );
        }


        $stage =
            strtolower(
                trim(
                    (string)(
                        $candidate[
                            'pipeline_stage'
                        ]
                        ?? ''
                    )
                )
            );

        $errorStage =
            strtolower(
                trim(
                    (string)(
                        $candidate[
                            'error_stage'
                        ]
                        ?? ''
                    )
                )
            );

        $isDispatchRetry =
            $stage === 'error'
            && $errorStage === 'dispatch';


        if (
            $stage !== 'packed'
            && !$isDispatchRetry
        ) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} is not ready for Dispatch; expected packed or a Dispatch-stage error."
            );
        }


        /*
         * TAKE CUSTODY.
         *
         * The Dispatch department, not Schedule, owns this transition.
         */
        $this->assets
            ->markShipping(
                $pubAssetId
            );


        $asset =
            $this->assets
                ->getShippingById(
                    $pubAssetId
                );


        if ($asset === null) {
            /*
             * We already accepted custody. Never strand the row at shipping.
             */
            try {
                $this->assets
                    ->markError(
                        $pubAssetId,
                        'dispatch',
                        'dispatch_failure',
                        'Dispatch accepted the asset but could not reload it from the Shipping dock.'
                    );
            } catch (Throwable) {
                /*
                 * Preserve the primary lifecycle failure.
                 */
            }


            throw new RuntimeException(
                "PUB asset #{$pubAssetId} could not be loaded after entering Shipping."
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
                )
                || isset(
                    $result[
                        'in_progress'
                    ]
                ),

            ...$result,
        ];
    }


    /**
     * Accept a final delivery receipt from either a synchronous Shipper or
     * DispatchDesk after an asynchronous driver finishes.
     *
     * The caller does not write lifecycle state directly.
     */
    public function completeShipment(
        int $pubAssetId,
        array $receipt
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Dispatch completion requires a valid pub_asset_id.'
            );
        }


        if ($receipt === []) {
            throw new RuntimeException(
                'Dispatch completion requires a shipping receipt.'
            );
        }


        /*
         * Preserve only a permanent fingerprint of the FINAL Creator
         * ingredients that produced what actually left the building.
         *
         * The full pub_asset_orders row remains an in-house kitchen ticket
         * and is discarded by finalizeShipment() after the permanent
         * signature and shipping receipt are safely written.
         */
        $order =
            $this->assets
                ->getOrder(
                    $pubAssetId
                );


        if ($order === null) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} has no filed CREATE order to fingerprint at shipment."
            );
        }


        $ingredients =
            $order[
                'ingredients'
            ]
            ?? null;


        if (!is_array($ingredients)) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} has no valid filed ingredients to fingerprint at shipment."
            );
        }


        $productionSignature =
            ProductionSignature::fromIngredients(
                $ingredients
            );


        return $this->assets
            ->finalizeShipment(
                $pubAssetId,
                $receipt,
                $productionSignature
            );
    }


    /**
     * Accept a final failed/timeout report from DispatchDesk.
     *
     * A late failure report must never overwrite an asset that already
     * reached SHIPPED successfully.
     */
    public function failShipment(
        int $pubAssetId,
        string $code,
        string $message
    ): array {
        if ($pubAssetId <= 0) {
            throw new RuntimeException(
                'Dispatch failure requires a valid pub_asset_id.'
            );
        }


        $message =
            trim(
                $message
            );


        if ($message === '') {
            $message =
                'Dispatch shipment failed.';
        }


        $code =
            trim(
                $code
            );


        if ($code === '') {
            $code =
                'dispatch_failure';
        }


        $asset =
            $this->assets
                ->getById(
                    $pubAssetId
                );


        if ($asset === null) {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} was not found while settling Dispatch failure."
            );
        }


        $stage =
            strtolower(
                trim(
                    (string)(
                        $asset[
                            'pipeline_stage'
                        ]
                        ?? ''
                    )
                )
            );


        if ($stage === 'shipped') {
            return [
                'pub_asset_id' =>
                    $pubAssetId,

                'pipeline_stage' =>
                    'shipped',

                'ignored_late_failure' =>
                    true,
            ];
        }


        if (
            $stage === 'error'
            && strtolower(
                trim(
                    (string)(
                        $asset[
                            'error_stage'
                        ]
                        ?? ''
                    )
                )
            ) === 'dispatch'
        ) {
            return [
                'pub_asset_id' =>
                    $pubAssetId,

                'pipeline_stage' =>
                    'error',

                'error_stage' =>
                    'dispatch',

                'already_failed' =>
                    true,
            ];
        }


        if ($stage !== 'shipping') {
            throw new RuntimeException(
                "PUB asset #{$pubAssetId} is not awaiting a Dispatch result."
            );
        }


        $this->assets
            ->markError(
                $pubAssetId,
                'dispatch',
                $code,
                $message
            );


        return [
            'pub_asset_id' =>
                $pubAssetId,

            'pipeline_stage' =>
                'error',

            'error_stage' =>
                'dispatch',

            'error_code' =>
                $code,

            'error_message' =>
                $message,
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
                $stopped =
                    $stoppedLines[
                        $lineKey
                    ];


                $message =
                    trim(
                        (string)(
                            $stopped[
                                'error'
                            ]
                            ?? 'Dispatch shipping line is unavailable.'
                        )
                    );


                $this->assets
                    ->markError(
                        $pubAssetId,
                        'dispatch',
                        'dispatch_failure',
                        $message
                    );


                return [
                    'failed' => [
                        'index' =>
                            $index,

                        'pub_asset_id' =>
                            $pubAssetId,

                        'line' =>
                            $lineKey,

                        ...$stopped,
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


                /*
                 * Dispatch already owns the box.
                 * A failed readiness/preflight gate must not strand it
                 * at pipeline_stage=shipping.
                 */
                $this->assets
                    ->markError(
                        $pubAssetId,
                        'dispatch',
                        'dispatch_failure',
                        $signal
                            ->message()
                    );


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
            $shipment =
                DispatchShipmentResult::normalize(
                    $shipper
                        ->ship(
                            $pubAssetId,
                            $package
                        )
                );


            /*
             * ASYNCHRONOUS SPECIALIST.
             *
             * The Manager does not know HOW the specialist continues the
             * shipment. It only understands that Dispatch still owns the box
             * and the final receipt will arrive later through DispatchDesk.
             */
            if (
                $shipment[
                    'status'
                ] ===
                DispatchShipmentResult::IN_PROGRESS
            ) {
                return [
                    'in_progress' => [
                        'index' =>
                            $index,

                        'pub_asset_id' =>
                            $pubAssetId,

                        'line' =>
                            $lineKey,

                        'state' => [
                            'pub_asset_id' =>
                                $pubAssetId,

                            'pipeline_stage' =>
                                'shipping',
                        ],

                        'details' =>
                            $shipment[
                                'details'
                            ],

                        'pubcom' =>
                            $pubComChannel
                                ->dispositionsAsArray(),
                    ],
                ];
            }


            $receipt =
                $shipment[
                    'receipt'
                ];


            /*
             * Manager owns the PUB lifecycle outcome. The repository owns
             * the physical row write.
             */
            $state =
                $this->completeShipment(
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
                        $this->pinterestConfig(),
                        $this->pinterestAuth()
                    ),

                'pinterest.video' =>
                    new PinterestVideoShipper(
                        $this->pinterestConfig(),
                        $this->pinterestAuth()
                    ),

                'youtube.video' =>
                    new YouTubeVideoShipper(
                        $this->youtubeConfig(),
                        $this->youtubeAuth()
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


    private function pinterestAuth(): PinterestAuthService
    {
        if (
            $this->pinterestAuth ===
            null
        ) {
            $this->pinterestAuth =
                new PinterestAuthService(
                    $this->pdo
                );
        }


        return $this->pinterestAuth;
    }


    private function youtubeConfig(): YouTubeShippingConfig
    {
        if (
            $this->youtubeConfig ===
            null
        ) {
            $this->youtubeConfig =
                new YouTubeShippingConfig();
        }


        return $this->youtubeConfig;
    }


    private function youtubeAuth(): YouTubeAuthService
    {
        if (
            $this->youtubeAuth ===
            null
        ) {
            $this->youtubeAuth =
                new YouTubeAuthService(
                    $this->pdo
                );
        }


        return $this->youtubeAuth;
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
