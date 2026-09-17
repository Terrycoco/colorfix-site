<?php
declare(strict_types=1);

namespace App\PALETTES\Endpoints;

use App\PALETTES\Managers\PaletteManager;
use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class AdminPaletteDeleteEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header(
            'Content-Type: application/json; charset=utf-8'
        );
        header('Cache-Control: no-store');

        try {
            if (
                ($_SERVER['REQUEST_METHOD'] ?? 'GET')
                !== 'POST'
            ) {
                http_response_code(405);

                echo json_encode([
                    'ok' => false,
                    'error' => 'POST required.',
                ]);

                return;
            }

            $raw = file_get_contents('php://input');

            $input = json_decode(
                $raw !== false ? $raw : '',
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (!is_array($input)) {
                throw new InvalidArgumentException(
                    'JSON object required.'
                );
            }

            $paletteId = (int)(
                $input['palette_id'] ?? 0
            );

            $item = (new PaletteManager($pdo))
                ->deletePaletteCombo($paletteId);

            echo json_encode([
                'ok' => true,
                'item' => $item,
            ]);
        } catch (
            InvalidArgumentException|JsonException $e
        ) {
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
