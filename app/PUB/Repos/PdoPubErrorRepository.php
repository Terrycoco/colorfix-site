<?php
declare(strict_types=1);

namespace App\PUB\Repos;

use PDO;
use Throwable;

final class PdoPubErrorRepository
{
    public function __construct(
        private PDO $pdo
    ) {}


    /**
     * @param array<string, mixed> $failure
     * @param array<string, mixed> $context
     */
    public function insert(
        array $failure,
        Throwable $error,
        array $context = []
    ): int {
        $diagnostics =
            $context['diagnostics']
            ?? null;

        $diagnosticsJson =
            is_array($diagnostics)
            && $diagnostics !== []
                ? json_encode(
                    $diagnostics,
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR
                )
                : null;


        $stmt =
            $this->pdo->prepare(
                'INSERT INTO pub_errors (
                    severity,
                    stage,
                    pub_run_id,
                    pub_asset_id,
                    proposal_key,
                    asset_type,
                    creator_key,
                    source_type,
                    source_id,
                    channel,
                    code,
                    error_message,
                    exception_class,
                    exception_file,
                    exception_line,
                    trace,
                    diagnostics_json,
                    occurred_at
                ) VALUES (
                    :severity,
                    :stage,
                    :pub_run_id,
                    :pub_asset_id,
                    :proposal_key,
                    :asset_type,
                    :creator_key,
                    :source_type,
                    :source_id,
                    :channel,
                    :code,
                    :error_message,
                    :exception_class,
                    :exception_file,
                    :exception_line,
                    :trace,
                    :diagnostics_json,
                    UTC_TIMESTAMP()
                )'
            );


        $stmt->execute([
            ':severity' =>
                $context['severity']
                ?? 'error',

            ':stage' =>
                $failure['stage']
                ?? null,

            ':pub_run_id' =>
                $failure['pub_run_id']
                ?? null,

            ':pub_asset_id' =>
                $failure['pub_asset_id']
                ?? null,

            ':proposal_key' =>
                $failure['proposal_key']
                ?? null,

            ':asset_type' =>
                $failure['asset_type']
                ?? null,

            ':creator_key' =>
                $failure['creator_key']
                ?? null,

            ':source_type' =>
                $failure['source_type']
                ?? null,

            ':source_id' =>
                $failure['source_id']
                ?? null,

            ':channel' =>
                $context['channel']
                ?? null,

            ':code' =>
                $failure['code']
                ?? 'pub_failure',

            ':error_message' =>
                $failure['error']
                ?? $error->getMessage(),

            ':exception_class' =>
                $error::class,

            ':exception_file' =>
                $error->getFile(),

            ':exception_line' =>
                $error->getLine(),

            ':trace' =>
                $error->getTraceAsString(),

            ':diagnostics_json' =>
                $diagnosticsJson,
        ]);


        return (int)$this->pdo->lastInsertId();
    }


    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRecent(
        int $limit = 200
    ): array {
        $limit =
            max(
                1,
                min(
                    $limit,
                    500
                )
            );


        $stmt =
            $this->pdo->query(
                'SELECT *
                   FROM pub_errors
                  ORDER BY occurred_at DESC, id DESC
                  LIMIT '
                . $limit
            );


        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );
    }


    public function markNotified(
        int $id
    ): void {
        $stmt =
            $this->pdo->prepare(
                'UPDATE pub_errors
                    SET notified_at = UTC_TIMESTAMP(),
                        notification_error = NULL
                  WHERE id = :id'
            );

        $stmt->execute([
            ':id' => $id,
        ]);
    }


    public function markNotificationError(
        int $id,
        string $message
    ): void {
        $stmt =
            $this->pdo->prepare(
                'UPDATE pub_errors
                    SET notification_error = :notification_error
                  WHERE id = :id'
            );

        $stmt->execute([
            ':id' =>
                $id,

            ':notification_error' =>
                $message,
        ]);
    }
}