<?php
declare(strict_types=1);

namespace App\PUB\Endpoints;

use App\PUB\Dispatch\Auth\ChannelAuthContract;
use App\PUB\Dispatch\Auth\YouTubeAuthService;
use App\PUB\Dispatch\Pinterest\PinterestConnectionService;
use App\PUB\Errors\PubErrorReporter;
use PDO;
use RuntimeException;
use Throwable;

/**
 * CHANNEL CONNECTION ENDPOINT
 *
 * PUB-facing controller for external channel connections.
 *
 * Current channels:
 *   pinterest
 *   youtube
 *
 * Supported actions:
 *
 *   GET
 *     ?channel=pinterest
 *     ?channel=youtube
 *     ?channel=pinterest&action=status
 *     ?channel=youtube&action=status
 *
 *   POST JSON
 *     { "channel": "pinterest", "action": "test" }
 *     { "channel": "youtube", "action": "test" }
 *     { "channel": "pinterest", "action": "sync" }
 *     { "channel": "pinterest", "action": "disconnect" }
 *     { "channel": "youtube", "action": "disconnect" }
 *
 * OAuth browser redirects are handled by separate tiny public doors.
 * Real OAuth/auth behavior lives under app/PUB/Dispatch/Auth.
 */
final class ChannelConnectionEndpoint
{
    public static function handle(PDO $pdo): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            self::sendJson(200, [
                'ok' => true,
            ]);
            return;
        }

        $projectRoot = dirname(__DIR__, 3);

        $errors = new PubErrorReporter(
            $projectRoot . '/app/PUB/Errors/pub_errors.log'
        );

        try {
            $method = strtoupper(
                (string)($_SERVER['REQUEST_METHOD'] ?? 'GET')
            );

            if ($method === 'GET') {
                $channel = trim(
                    (string)($_GET['channel'] ?? 'pinterest')
                );

                $action = strtolower(
                    trim((string)($_GET['action'] ?? 'status'))
                );

                if ($action !== 'status') {
                    throw new RuntimeException(
                        'GET supports only the status action.'
                    );
                }

                $connection = self::connectionFor(
                    $pdo,
                    $channel
                );

                self::sendJson(200, [
                    'ok' => true,
                    'channel' => $connection->channelKey(),
                    'item' => $connection->status(),
                ]);

                return;
            }

            if ($method !== 'POST') {
                self::sendJson(405, [
                    'ok' => false,
                    'error' => 'GET or POST only.',
                ]);
                return;
            }

            $data = self::requestJson();

            $channel = trim(
                (string)($data['channel'] ?? 'pinterest')
            );

            $action = strtolower(
                trim((string)($data['action'] ?? ''))
            );

            if ($action === '') {
                throw new RuntimeException(
                    'Channel connection action is required.'
                );
            }

            $connection = self::connectionFor(
                $pdo,
                $channel
            );

            switch ($action) {
                case 'status':
                    $result = $connection->status();
                    break;

                case 'test':
                    $result = $connection->testConnection();
                    break;

                case 'disconnect':
                    $connection->disconnect();
                    $result = $connection->status();
                    break;

                case 'sync':
                    if (!method_exists($connection, 'syncBoards')) {
                        throw new RuntimeException(
                            "Channel '{$channel}' does not support destination sync."
                        );
                    }

                    /** @var callable $sync */
                    $sync = [
                        $connection,
                        'syncBoards',
                    ];

                    $result = $sync();
                    break;

                default:
                    throw new RuntimeException(
                        "Unsupported channel connection action '{$action}'."
                    );
            }

            self::sendJson(200, [
                'ok' => true,
                'channel' => $connection->channelKey(),
                'action' => $action,
                'item' => $result,
            ]);

        } catch (Throwable $e) {
            $failure = $errors->report(
                $e,
                [
                    'stage' => 'dispatch',
                    'code' => 'channel_connection_failure',
                    'diagnostics' => [
                        'channel' => $_GET['channel'] ?? null,
                        'action' => $_GET['action'] ?? null,
                    ],
                ]
            );

            self::sendJson(500, [
                'ok' => false,
                'error' => $failure['error'] ?? $e->getMessage(),
                'code' => $failure['code'] ?? 'channel_connection_failure',
            ]);
        }
    }

    private static function connectionFor(
        PDO $pdo,
        string $channel
    ): ChannelAuthContract {
        $channel = strtolower(
            trim($channel)
        );

        return match ($channel) {
            'pinterest' =>
                new PinterestConnectionService(
                    $pdo
                ),

            'youtube' =>
                new YouTubeAuthService(
                    $pdo
                ),

            default =>
                throw new RuntimeException(
                    "PUB has no connection service registered for channel '{$channel}'."
                ),
        };
    }

    private static function requestJson(): array
    {
        $raw = file_get_contents(
            'php://input'
        );

        if (
            $raw === false
            || trim($raw) === ''
        ) {
            return [];
        }

        $decoded = json_decode(
            $raw,
            true
        );

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'Request body must be valid JSON.'
            );
        }

        return $decoded;
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

        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
        );
    }
}
