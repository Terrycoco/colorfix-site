<?php
declare(strict_types=1);

namespace App\PUB\Dispatch;

use RuntimeException;

/**
 * STANDARD DISPATCH SHIPPER RESULT
 *
 * A Shipper may finish the shipment itself or hand the long-running
 * delivery to Dispatch's generic driving company.
 *
 * DispatchManager understands only these two outcomes:
 *
 *   completed
 *     Final external receipt is present now.
 *
 *   in_progress
 *     Dispatch still owns the box. The final result will arrive later
 *     through DispatchDesk.
 *
 * The Manager does not know whether a driver, callback, queue, or some
 * future mechanism is responsible for an in-progress shipment.
 */
final class DispatchShipmentResult
{
    public const COMPLETED = 'completed';
    public const IN_PROGRESS = 'in_progress';


    public static function completed(
        array $receipt
    ): array {
        if ($receipt === []) {
            throw new RuntimeException(
                'Completed Dispatch shipment requires a receipt.'
            );
        }


        return [
            'status' =>
                self::COMPLETED,

            'receipt' =>
                $receipt,

            'details' =>
                [],
        ];
    }


    public static function inProgress(
        array $details = []
    ): array {
        return [
            'status' =>
                self::IN_PROGRESS,

            'receipt' =>
                null,

            'details' =>
                $details,
        ];
    }


    /**
     * Validate one specialist return value.
     */
    public static function normalize(
        array $result
    ): array {
        $status =
            strtolower(
                trim(
                    (string)(
                        $result[
                            'status'
                        ]
                        ?? ''
                    )
                )
            );


        if ($status === self::COMPLETED) {
            $receipt =
                is_array(
                    $result[
                        'receipt'
                    ]
                    ?? null
                )
                    ? $result[
                        'receipt'
                    ]
                    : [];


            return self::completed(
                $receipt
            );
        }


        if ($status === self::IN_PROGRESS) {
            $details =
                is_array(
                    $result[
                        'details'
                    ]
                    ?? null
                )
                    ? $result[
                        'details'
                    ]
                    : [];


            return self::inProgress(
                $details
            );
        }


        throw new RuntimeException(
            'Shipping specialist returned an unsupported Dispatch shipment status.'
        );
    }
}
