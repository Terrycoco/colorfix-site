<?php
declare(strict_types=1);

namespace App\PALETTES\Endpoints;

use App\PALETTES\Managers\PaletteManager;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class AdminPaletteDetailEndpoint
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

            $paletteId = (int)($_GET['id'] ?? 0);

            $item = (new PaletteManager($pdo))
                ->getEditorItem($paletteId);

            echo json_encode([
                'ok' => true,
                'item' => $item,
            ]);
        } catch (InvalidArgumentException $e) {
            http_response_code(422);

            echo json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ]);
        } catch (RuntimeException $e) {
            http_response_code(404);

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
