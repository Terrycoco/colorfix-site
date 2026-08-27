<?php
declare(strict_types=1);

namespace App\PUB\Dispatch\Pinterest;

use App\PUB\PubCom\PubComChannel;
use App\PUB\PubCom\PubComSignal;
use App\PUB\PubCom\PubComWorkerContract;
use RuntimeException;

/**
 * PINTEREST VIDEO SHIPPER
 *
 * Registered specialist slot for Pinterest video shipping.
 *
 * Pinterest image and video intentionally remain separate specialists
 * because their shipping protocols may differ even though they share
 * the same Pinterest connection/configuration.
 *
 * The actual video protocol is NOT implemented yet. Keeping this worker
 * explicit prevents DispatchManager from accumulating image/video API
 * branching later.
 */
final class PinterestVideoShipper implements PubComWorkerContract
{
    private ?PubComChannel $pubComChannel = null;


    public function __construct(
        private PinterestShippingConfig $config
    ) {}


    public function connectPubCom(
        PubComChannel $channel
    ): void {
        $this->pubComChannel =
            $channel;
    }


    public function readiness(): PubComSignal
    {
        /*
         * Do not accidentally ship a video using the image protocol.
         */
        return PubComSignal::unavailable(
            'pinterest_video_shipper_not_implemented',
            'Pinterest Video Shipper is not implemented yet.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    public function preflight(
        array $package
    ): PubComSignal {
        return PubComSignal::unavailable(
            'pinterest_video_shipper_not_implemented',
            'Pinterest Video Shipper is not implemented yet.',
            [
                'worker' =>
                    self::class,
            ]
        );
    }


    public function ship(
        array $package
    ): array {
        throw new RuntimeException(
            'Pinterest Video Shipper is not implemented yet.'
        );
    }
}
