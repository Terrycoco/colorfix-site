<?php
declare(strict_types=1);

namespace App\PUB\Endpoints;

use App\PUB\Analyze\AnalyzeManager;
use App\PUB\Errors\PubErrorReporter;
use PDO;
use RuntimeException;
use Throwable;

/**
 * ANALYZE ENDPOINT
 *
 * Public doorbell for the ANALYZE department.
 *
 * This endpoint owns ONLY HTTP/request concerns:
 *   - accept one ANALYZE request
 *   - validate that the request is complete
 *   - wake AnalyzeManager
 *   - hand the Manager the routing parameters
 *   - translate the Manager result back to HTTP/JSON
 *
 * It must NOT:
 *   - instantiate Procurement
 *   - instantiate any Analyzer
 *   - choose an Analyzer
 *   - inspect source material
 *   - decide ANALYZE workflow
 *
 * AnalyzeManager owns everything after the doorbell rings.
 */
final class AnalyzeEndpoint
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

        $projectRoot = dirname(__DIR__, 3);

        $errorReporter = new PubErrorReporter(
            $projectRoot . '/app/PUB/Errors/pub_errors.log'
        );

        $sourceType = '';
        $sourceId = 0;
        $outputType = '';
        $runMode = 'check';
        $overwritePubRunId = 0;

        try {
            $data = self::requestJson();

            $sourceType = strtolower(
                trim((string)($data['source_type'] ?? ''))
            );

            $sourceId = (int)($data['source_id'] ?? 0);

            $outputTypeRaw =
                $data['output_type']
                ?? (
                    is_array($data['outputs'] ?? null)
                        ? ($data['outputs'][0] ?? '')
                        : ''
                );

            $outputType = strtolower(
                trim((string)$outputTypeRaw)
            );

            $runMode = strtolower(
                trim((string)($data['run_mode'] ?? 'check'))
            );

            $overwritePubRunId = (int)(
                $data['overwrite_pub_run_id']
                ?? 0
            );

            /*
             * REQUEST COMPLETENESS ONLY.
             *
             * The endpoint checks whether the caller supplied enough
             * information to ring the correct doorbell. It does not
             * make ANALYZE-stage decisions.
             */
            if ($sourceType === '') {
                throw new RuntimeException(
                    'source_type required'
                );
            }

            if ($sourceId <= 0) {
                throw new RuntimeException(
                    'Valid source_id required'
                );
            }

            if ($outputType === '') {
                throw new RuntimeException(
                    'output_type required'
                );
            }

            if (!in_array(
                $runMode,
                ['check', 'new', 'overwrite'],
                true
            )) {
                throw new RuntimeException(
                    'Invalid run_mode.'
                );
            }

            if (
                $runMode === 'overwrite'
                && $overwritePubRunId <= 0
            ) {
                throw new RuntimeException(
                    'overwrite_pub_run_id required for overwrite.'
                );
            }

            /*
             * WAKE ANALYZE MANAGER.
             *
             * AnalyzeManager is intentionally created only when an
             * ANALYZE request arrives. The Manager is responsible for
             * waking Procurement and the one specialist Analyzer needed
             * for this assignment.
             *
             * NOTE: AnalyzeManager's constructor will be refactored to
             * accept only shared facility resources (PDO/project root)
             * and lazily create its department staff.
             */
            $manager = new AnalyzeManager(
                $pdo,
                $projectRoot
            );

            $result = $manager->analyze(
                $sourceType,
                $sourceId,
                $outputType,
                $runMode,
                $overwritePubRunId
            );

            /*
             * The Manager decided an existing job blocks this request.
             * The endpoint only converts that decision to HTTP.
             */
            if (($result['code'] ?? '') === 'existing_pub_run') {
                $existingRunId = (int)(
                    $result['pub_run_id']
                    ?? 0
                );

                self::sendJson(409, [
                    'ok' => false,
                    'code' => 'existing_pub_run',
                    'error' =>
                        "PUB job #{$existingRunId} already exists for this source and output.",
                    'existing_run' =>
                        $result['existing_run']
                        ?? null,
                    'can_overwrite' =>
                        (bool)($result['can_overwrite'] ?? false),
                    'blocking_assets' =>
                        $result['blocking_assets']
                        ?? [],
                    'asset_count' =>
                        (int)($result['asset_count'] ?? 0),
                ]);

                return;
            }

            $boxes = is_array($result['boxes'] ?? null)
                ? array_values($result['boxes'])
                : [];

            $failed = is_array($result['failed'] ?? null)
                ? array_values($result['failed'])
                : [];

            $pubCom = is_array($result['pubcom'] ?? null)
                ? array_values($result['pubcom'])
                : [];

            self::sendJson(200, [
                'ok' => true,
                'pub_run_id' =>
                    (int)($result['pub_run_id'] ?? 0),
                'run_mode' =>
                    (string)($result['run_mode'] ?? $runMode),
                'overwrote_existing_job' =>
                    (bool)($result['overwrote_existing_job'] ?? false),
                'deleted_asset_count' =>
                    (int)($result['deleted_asset_count'] ?? 0),
                'source_type' =>
                    (string)($result['source_type'] ?? $sourceType),
                'source_id' =>
                    (int)($result['source_id'] ?? $sourceId),
                'output_type' =>
                    (string)($result['output_type'] ?? $outputType),
                'box_count' =>
                    count($boxes),
                'failed_count' =>
                    count($failed),
                'boxes' =>
                    $boxes,
                'failed' =>
                    $failed,
                'pubcom' =>
                    $pubCom,
            ]);

        } catch (Throwable $e) {
            $failure = $errorReporter->report(
                $e,
                [
                    'stage' => 'analyze',
                    'pub_run_id' =>
                        $overwritePubRunId > 0
                            ? $overwritePubRunId
                            : null,
                    'source_type' =>
                        $sourceType !== ''
                            ? $sourceType
                            : null,
                    'source_id' =>
                        $sourceId > 0
                            ? $sourceId
                            : null,
                    'code' => 'analyze_failure',
                ]
            );

            self::sendJson(500, [
                'ok' => false,
                'error' =>
                    $failure['error']
                    ?? $e->getMessage(),
                'code' =>
                    $failure['code']
                    ?? 'analyze_failure',
                'pub_run_id' =>
                    $failure['pub_run_id']
                    ?? null,
                'error_class' =>
                    get_class($e),
                'error_file' =>
                    $e->getFile(),
                'error_line' =>
                    $e->getLine(),
            ]);
        }
    }


    /**
     * @return array<string, mixed>
     */
    private static function requestJson(): array
    {
        $raw = file_get_contents('php://input') ?: '';

        $data = json_decode(
            $raw,
            true
        );

        if (!is_array($data)) {
            throw new RuntimeException(
                'Valid JSON body required.'
            );
        }

        return $data;
    }


    private static function sendJson(
        int $status,
        array $payload
    ): void {
        http_response_code($status);

        if (!headers_sent()) {
            header(
                'Content-Type: application/json; charset=UTF-8'
            );
        }

        $json = json_encode(
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