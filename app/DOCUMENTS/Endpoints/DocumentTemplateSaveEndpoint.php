<?php
declare(strict_types=1);

namespace App\DOCUMENTS\Endpoints;

use App\DOCUMENTS\Repos\PdoDocumentTemplateRepository;
use PDO;
use RuntimeException;
use Throwable;

final class DocumentTemplateSaveEndpoint
{
    public static function handle(
        PDO $pdo
    ): void {
        try {
            if (
                strtoupper(
                    (string)(
                        $_SERVER['REQUEST_METHOD']
                        ?? ''
                    )
                ) !== 'POST'
            ) {
                self::respond([
                    'ok' => false,
                    'error' =>
                        'POST required.',
                ], 405);

                return;
            }

            $raw =
                file_get_contents(
                    'php://input'
                );

            $payload =
                json_decode(
                    $raw ?: '{}',
                    true
                );

            if (!is_array($payload)) {
                throw new RuntimeException(
                    'Invalid JSON payload.'
                );
            }

            $repo =
                new PdoDocumentTemplateRepository(
                    $pdo
                );

            self::respond([
                'ok' => true,
                'template' =>
                    $repo->save(
                        $payload
                    ),
            ]);

        } catch (RuntimeException $e) {
            self::respond([
                'ok' => false,
                'error' =>
                    $e->getMessage(),
            ], 400);

        } catch (Throwable $e) {
            self::respond([
                'ok' => false,
                'error' =>
                    $e->getMessage(),
            ], 500);
        }
    }


    /**
     * @param array<string, mixed> $payload
     */
    private static function respond(
        array $payload,
        int $status = 200
    ): void {
        http_response_code(
            $status
        );

        header(
            'Content-Type: application/json; charset=utf-8'
        );

        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );
    }
}
