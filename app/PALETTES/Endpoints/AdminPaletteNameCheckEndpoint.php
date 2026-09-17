<?php
declare(strict_types=1);

namespace App\PALETTES\Endpoints;

use App\PALETTES\Managers\PaletteManager;
use InvalidArgumentException;
use PDO;
use Throwable;

final class AdminPaletteNameCheckEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
                http_response_code(405);

                echo json_encode([
                    'ok' => false,
                    'error' => 'GET required.',
                ]);

                return;
            }

            $nickname = trim(
                (string)($_GET['nickname'] ?? '')
            );

            $result = (new PaletteManager($pdo))
                ->checkInternalName($nickname);

            echo json_encode([
                'ok' => true,
                ...$result,
            ]);
        } catch (InvalidArgumentException $e) {
            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            http_response_code(500);

            echo json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
