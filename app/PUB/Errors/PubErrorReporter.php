<?php
declare(strict_types=1);

namespace App\PUB\Errors;

use App\PUB\Repos\PdoPubErrorRepository;
use Throwable;

/**
 * CENTRAL PUB ERROR REPORTER
 *
 * Shared by every PUB stage:
 *
 *   ANALYZE
 *   CREATE
 *   PACKAGE
 *   SCHEDULE
 *   DISPATCH
 *
 * Responsibilities:
 *
 *   1. Normalize a failure into one standard PUB
 *      failure envelope for endpoints / admin UI.
 *
 *   2. Write a detailed JSON-line record to the
 *      centralized PUB error log.
 *
 *   3. Write the failure to pub_errors when a
 *      database repository is available.
 *
 * Reporting is best-effort.
 *
 * A reporting failure must NEVER replace or hide
 * the original PUB failure.
 */
final class PubErrorReporter
{
    public function __construct(
        private string $logFile,
        private ?PdoPubErrorRepository $repository = null,
        private ?CriticalErrorNotifier $criticalNotifier = null
    ) {}


    /**
     * Report one PUB failure.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function report(
        Throwable $error,
        array $context = []
    ): array {
        $code =
            $this->nullableString(
                $context[
                    'code'
                ]
                ?? null
            )
            ?? PubErrorCode::PUB_FAILURE;

        $policy =
            PubErrorPolicy::for(
                $code
            );


        /*
         * Callers report WHAT happened. Central policy decides severity
         * and whether Terry should be emailed.
         */
        $context[
            'code'
        ] =
            $code;

        $context[
            'severity'
        ] =
            $policy[
                'severity'
            ];


        $failure =
            $this->failureEnvelope(
                $error,
                $context
            );

        $this->writeLog(
            $failure,
            $error,
            $context
        );

        $errorId =
            $this->writeDatabase(
                $failure,
                $error,
                $context
            );


        if (
            $policy[
                'email'
            ]
        ) {
            $this->notifyCritical(
                $failure,
                $context,
                $errorId
            );
        }


        return $failure;
    }


    /**
     * Standard failure object returned upward through PUB.
     *
     * Keep this deliberately small.
     *
     * The filed pub_asset_order remains the detailed
     * forensic record for a specific asset.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function failureEnvelope(
        Throwable $error,
        array $context
    ): array {
        return [
            'stage' =>
                $this->nullableString(
                    $context['stage']
                    ?? null
                ),

            'pub_run_id' =>
                $this->nullablePositiveInt(
                    $context['pub_run_id']
                    ?? null
                ),

            'pub_asset_id' =>
                $this->nullablePositiveInt(
                    $context['pub_asset_id']
                    ?? null
                ),

            'proposal_key' =>
                $this->nullableString(
                    $context['proposal_key']
                    ?? null
                ),

            'asset_type' =>
                $this->nullableString(
                    $context['asset_type']
                    ?? null
                ),

            'creator_key' =>
                $this->nullableString(
                    $context['creator_key']
                    ?? null
                ),

            'source_type' =>
                $this->nullableString(
                    $context['source_type']
                    ?? null
                ),

            'source_id' =>
                $this->nullablePositiveInt(
                    $context['source_id']
                    ?? null
                ),

            'code' =>
                $this->nullableString(
                    $context['code']
                    ?? null
                )
                ?? 'pub_failure',

            'error' =>
                $error->getMessage(),
        ];
    }


    /**
     * Permanent database record used by the PUB Errors UI.
     *
     * @param array<string, mixed> $failure
     * @param array<string, mixed> $context
     */
    private function writeDatabase(
        array $failure,
        Throwable $error,
        array $context
    ): ?int {
        if (
            $this->repository === null
        ) {
            return null;
        }

        try {
            return
                $this->repository->insert(
                    $failure,
                    $error,
                    $context
                );

        } catch (
            Throwable
        ) {
            /*
             * Never allow database reporting failure
             * to hide the original PUB failure.
             *
             * The JSON-line file remains the fallback.
             */
            return null;
        }
    }


    /**
     * Email only errors whose central policy explicitly requires it.
     *
     * Notification failure is recorded on the existing pub_errors row;
     * it is never recursively reported as a second PUB error.
     *
     * @param array<string, mixed> $failure
     * @param array<string, mixed> $context
     */
    private function notifyCritical(
        array $failure,
        array $context,
        ?int $errorId
    ): void {
        if (
            $this->criticalNotifier === null
        ) {
            return;
        }


        try {
            $this->criticalNotifier
                ->send(
                    $failure,
                    $context
                );


            if (
                $this->repository !== null
                && $errorId !== null
                && $errorId > 0
            ) {
                $this->repository
                    ->markNotified(
                        $errorId
                    );
            }

        } catch (
            Throwable $notificationError
        ) {
            if (
                $this->repository !== null
                && $errorId !== null
                && $errorId > 0
            ) {
                try {
                    $this->repository
                        ->markNotificationError(
                            $errorId,
                            $notificationError
                                ->getMessage()
                        );

                } catch (
                    Throwable
                ) {
                    /*
                     * Do not let a notification-status write obscure
                     * the original PUB failure.
                     */
                }
            }


            error_log(
                '[PUB] Critical error notification failed: '
                . $notificationError
                    ->getMessage()
            );
        }
    }


    /**
     * Detailed forensic log record.
     *
     * One JSON object per line keeps the file easy
     * to inspect manually and easy to parse later.
     *
     * @param array<string, mixed> $failure
     * @param array<string, mixed> $context
     */
    private function writeLog(
        array $failure,
        Throwable $error,
        array $context
    ): void {
        try {
            $directory =
                dirname(
                    $this->logFile
                );

            if (
                !is_dir(
                    $directory
                )
            ) {
                @mkdir(
                    $directory,
                    0775,
                    true
                );
            }

            $record = [
                'logged_at' =>
                    date(
                        'c'
                    ),

                ...$failure,

                'severity' =>
                    $this->nullableString(
                        $context[
                            'severity'
                        ]
                        ?? null
                    )
                    ?? 'error',

                'channel' =>
                    $this->nullableString(
                        $context[
                            'channel'
                        ]
                        ?? null
                    ),

                'exception_class' =>
                    $error::class,

                'exception_file' =>
                    $error->getFile(),

                'exception_line' =>
                    $error->getLine(),

                'trace' =>
                    $error->getTraceAsString(),
            ];


            /*
             * Allow callers to attach small additional
             * diagnostic values without changing the
             * standard UI envelope.
             *
             * Do NOT automatically dump entire sealed
             * boxes here. The filed order already holds
             * that data once an asset is reserved.
             */
            $diagnostics =
                $context['diagnostics']
                ?? null;

            if (
                is_array(
                    $diagnostics
                )
                && $diagnostics !== []
            ) {
                $record['diagnostics'] =
                    $diagnostics;
            }


            $json =
                json_encode(
                    $record,
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                );

            if (
                !is_string(
                    $json
                )
            ) {
                return;
            }


            @file_put_contents(
                $this->logFile,
                $json
                . PHP_EOL,
                FILE_APPEND
                | LOCK_EX
            );

        } catch (
            Throwable
        ) {
            /*
             * Never allow reporting infrastructure
             * to hide the original PUB failure.
             */
        }
    }


    private function nullableString(
        mixed $value
    ): ?string {
        if (
            $value === null
        ) {
            return null;
        }

        $value =
            trim(
                (string)$value
            );

        return
            $value !== ''
                ? $value
                : null;
    }


    private function nullablePositiveInt(
        mixed $value
    ): ?int {
        $value =
            (int)$value;

        return
            $value > 0
                ? $value
                : null;
    }
}
