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
 * The HTTP request identifies only the source. It does NOT choose an
 * output type, Analyzer, channel, or run mode. AnalyzeManager owns the
 * complete factory event after the doorbell rings.
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
        $pubRunId = 0;

        try {
            $data = self::requestJson();

            $sourceType = strtolower(
                trim((string)($data['source_type'] ?? ''))
            );

            $sourceId = (int)($data['source_id'] ?? 0);

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

            $manager = new AnalyzeManager(
                $pdo,
                $projectRoot
            );

            $result = $manager->analyze(
                $sourceType,
                $sourceId
            );

            $pubRunId = (int)($result['pub_run_id'] ?? 0);

            $boxes = is_array($result['boxes'] ?? null)
                ? array_values($result['boxes'])
                : [];

            $failed = is_array($result['failed'] ?? null)
                ? array_values($result['failed'])
                : [];

            $skipped = is_array($result['skipped'] ?? null)
                ? array_values($result['skipped'])
                : [];

            $pubCom = is_array($result['pubcom'] ?? null)
                ? array_values($result['pubcom'])
                : [];

            $existingAssetMatches =
                is_array(
                    $result['existing_asset_matches']
                    ?? null
                )
                    ? array_values(
                        $result['existing_asset_matches']
                    )
                    : [];

            self::sendJson(200, [
                'ok' => true,
                'pub_run_id' => $pubRunId,
                'source_type' =>
                    (string)($result['source_type'] ?? $sourceType),
                'source_id' =>
                    (int)($result['source_id'] ?? $sourceId),
                'output_types' =>
                    is_array($result['output_types'] ?? null)
                        ? array_values($result['output_types'])
                        : [],
                'box_count' => count($boxes),
                'failed_count' => count($failed),
                'skipped_count' => count($skipped),

                /*
                 * AnalyzeManager found logical predecessors and already
                 * prefilled durable outside copy into matching Boxes.
                 */
                'existing_asset_match_count' =>
                    count($existingAssetMatches),
                'existing_asset_matches' =>
                    $existingAssetMatches,

                'boxes' => $boxes,
                'failed' => $failed,
                'skipped' => $skipped,
                'pubcom' => $pubCom,
            ]);

        } catch (Throwable $e) {
            $failure = $errorReporter->report(
                $e,
                [
                    'stage' => 'analyze',
                    'pub_run_id' =>
                        $pubRunId > 0
                            ? $pubRunId
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
                'error_class' => get_class($e),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
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
