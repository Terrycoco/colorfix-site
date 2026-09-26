<?php
declare(strict_types=1);

namespace App\PROJECTS\Endpoints;

use App\PROJECTS\Managers\ProjectDocumentManager;
use PDO;
use Throwable;

final class ProjectDocumentGenerateEndpoint
{
    public static function handle(
        PDO $pdo
    ): void {
        try {
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
                throw new \RuntimeException(
                    'Invalid JSON body.'
                );
            }

            $manager =
                new ProjectDocumentManager(
                    $pdo
                );

            $document =
                $manager
                    ->generate(
                        $payload
                    );

            self::respond([
                'ok' => true,
                'document' =>
                    $document,
            ]);

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
        http_response_code($status);
        header(
            'Content-Type: application/json; charset=utf-8'
        );

        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );
    }
}
