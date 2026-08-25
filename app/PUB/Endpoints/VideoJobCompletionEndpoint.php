<?php
declare(strict_types=1);

namespace App\PUB\Endpoints;

use App\PUB\Create\CreateManager;
use App\PUB\Errors\PubErrorReporter;
use InvalidArgumentException;
use JsonException;
use PDO;
use Throwable;

/**
 * VIDEO JOB COMPLETION ENDPOINT
 *
 * Second doorbell for the CREATE department.
 *
 * The render worker reports facts:
 *
 *   pub_video_job_id
 *   status
 *   output_rel_path
 *   output_file_size_bytes
 *   error_message
 *
 * This endpoint:
 *   - validates the HTTP payload
 *   - wakes CreateManager
 *   - hands the worker report to settleVideoJob()
 *   - returns the Manager's result
 *
 * It must NOT:
 *   - construct any Creator
 *   - decide which Creator owns the job
 *   - promote files
 *   - update pub_assets itself
 *   - update pub_video_jobs itself
 *
 * Worker authentication remains at the public API door before
 * this endpoint class is invoked.
 */
final class VideoJobCompletionEndpoint
{
    public static function handle(PDO $pdo): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            self::sendJson(200, [
                'ok' => true,
            ]);

            return;
        }

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            self::sendJson(405, [
                'ok' => false,
                'error' => 'POST only',
            ]);

            return;
        }

        $projectRoot =
            dirname(
                __DIR__,
                3
            );

        try {
            $payload =
                self::requestJson();

            $jobId =
                (int)(
                    $payload[
                        'pub_video_job_id'
                    ]
                    ?? 0
                );

            if ($jobId <= 0) {
                throw new InvalidArgumentException(
                    'pub_video_job_id required.'
                );
            }

            $status =
                strtolower(
                    trim(
                        (string)(
                            $payload[
                                'status'
                            ]
                            ?? ''
                        )
                    )
                );

            if (
                !in_array(
                    $status,
                    [
                        'complete',
                        'failed',
                    ],
                    true
                )
            ) {
                throw new InvalidArgumentException(
                    'status must be complete or failed.'
                );
            }

            $outputRelPath =
                isset(
                    $payload[
                        'output_rel_path'
                    ]
                )
                    ? trim(
                        (string)$payload[
                            'output_rel_path'
                        ]
                    )
                    : null;

            $outputFileSizeBytes =
                isset(
                    $payload[
                        'output_file_size_bytes'
                    ]
                )
                    ? (int)$payload[
                        'output_file_size_bytes'
                    ]
                    : null;

            $errorMessage =
                isset(
                    $payload[
                        'error_message'
                    ]
                )
                    ? trim(
                        (string)$payload[
                            'error_message'
                        ]
                    )
                    : null;

            if (
                $status === 'complete'
                && ($outputRelPath ?? '') === ''
            ) {
                throw new InvalidArgumentException(
                    'output_rel_path required for completed jobs.'
                );
            }

            /*
             * WAKE CREATE MANAGER.
             *
             * The Manager wakes only the equipment / specialist needed
             * to settle the returned job.
             */
            $manager =
                new CreateManager(
                    $pdo,
                    $projectRoot
                );

            $result =
                $manager->settleVideoJob(
                    $jobId,
                    $status,
                    $outputRelPath,
                    $outputFileSizeBytes,
                    $errorMessage
                );

            self::sendJson(200, [
                'ok' => true,

                'result' =>
                    $result,

                'job' =>
                    $result[
                        'job'
                    ]
                    ?? null,

                'asset' =>
                    $result[
                        'asset'
                    ]
                    ?? null,

                'run_progress' =>
                    $result[
                        'run_progress'
                    ]
                    ?? null,
            ]);

        } catch (
            InvalidArgumentException
            | JsonException $e
        ) {
            self::sendJson(400, [
                'ok' => false,
                'error' => $e->getMessage(),
            ]);

        } catch (Throwable $e) {
            $errorReporter =
                new PubErrorReporter(
                    $projectRoot
                    . '/app/PUB/Errors/pub_errors.log'
                );

            $errorReporter->report(
                $e,
                [
                    'stage' =>
                        'create',

                    'code' =>
                        'video_job_settlement_failure',

                    'pub_video_job_id' =>
                        isset($jobId)
                            ? $jobId
                            : null,
                ]
            );

            self::sendJson(500, [
                'ok' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }


    /**
     * @return array<string, mixed>
     */
    private static function requestJson(): array
    {
        $raw =
            file_get_contents(
                'php://input'
            );

        if (
            $raw === false
            || trim(
                $raw
            ) === ''
        ) {
            throw new InvalidArgumentException(
                'JSON body required.'
            );
        }

        $payload =
            json_decode(
                $raw,
                true,
                512,
                JSON_THROW_ON_ERROR
            );

        if (!is_array($payload)) {
            throw new InvalidArgumentException(
                'Valid JSON body required.'
            );
        }

        return $payload;
    }


    private static function sendJson(
        int $status,
        array $payload
    ): void {
        http_response_code(
            $status
        );

        if (!headers_sent()) {
            header(
                'Content-Type: application/json; charset=UTF-8'
            );
        }

        $json =
            json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES
            );

        if ($json === false) {
            echo '{"ok":false,"error":"Could not encode PUB response as JSON."}';
            return;
        }

        echo $json;
    }
}