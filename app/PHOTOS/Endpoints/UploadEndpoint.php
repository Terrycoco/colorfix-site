<?php
declare(strict_types=1);

namespace App\PHOTOS\Endpoints;

use App\PHOTOS\Services\PhotoUploadService;
use PDO;
use Throwable;

final class UploadEndpoint
{
    public static function handle(PDO $pdo): void
    {
        if (
            ($_SERVER['REQUEST_METHOD'] ?? 'GET')
            === 'OPTIONS'
        ) {
            http_response_code(200);
            exit;
        }

        header(
            'Content-Type: application/json; charset=UTF-8'
        );

        try {
            if (
                ($_SERVER['REQUEST_METHOD'] ?? '')
                !== 'POST'
            ) {
                self::respond(
                    405,
                    [
                        'ok' => false,
                        'error' => 'Use POST',
                    ]
                );
            }

            if (
                empty($_FILES['photo'])
                || !is_array($_FILES['photo'])
            ) {
                self::respond(
                    400,
                    [
                        'ok' => false,
                        'error' => 'photo required',
                    ]
                );
            }

            $tags = trim(
                (string)($_POST['tags'] ?? '')
            );

            if ($tags === '') {
                self::respond(
                    400,
                    [
                        'ok' => false,
                        'error' => 'At least one tag is required.',
                    ]
                );
            }

            $title = trim(
                (string)($_POST['title'] ?? '')
            );

            $altText = trim(
                (string)($_POST['alt_text'] ?? '')
            );

            $service =
                PhotoUploadService::fromPdo(
                    $pdo
                );

            $photo =
                $service->upload(
                    $_FILES['photo'],
                    [
                        'tags' => $tags,

                        'title' =>
                            $title !== ''
                                ? $title
                                : null,

                        'alt_text' =>
                            $altText !== ''
                                ? $altText
                                : null,
                    ]
                );

            self::respond(
                200,
                [
                    'ok' => true,
                    'photo' => $photo->toArray(),
                ]
            );

        } catch (Throwable $e) {
            self::respond(
                400,
                [
                    'ok' => false,
                    'error' => $e->getMessage(),
                ]
            );
        }
    }


    private static function respond(
        int $code,
        array $payload
    ): never {
        http_response_code($code);

        echo json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );

        exit;
    }
}