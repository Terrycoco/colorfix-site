<?php
declare(strict_types=1);

namespace App\PALETTES\Endpoints;

use App\PALETTES\Managers\PVManager;
use InvalidArgumentException;
use PDO;
use Throwable;

final class AdminPVListEndpoint
{
    public static function handle(
        PDO $pdo
    ): void {
        header(
            'Content-Type: application/json; charset=utf-8'
        );
        header(
            'Cache-Control: no-store'
        );

        try {
            if (
                ($_SERVER['REQUEST_METHOD'] ?? 'GET')
                !== 'GET'
            ) {
                http_response_code(
                    405
                );

                echo json_encode([
                    'ok' => false,
                    'error' => 'GET required.',
                ]);

                return;
            }

            $rawIds =
                trim(
                    (string)(
                        $_GET[
                            'saved_palette_ids'
                        ]
                        ?? ''
                    )
                );

            if ($rawIds === '') {
                echo json_encode([
                    'ok' => true,
                    'items' => [],
                ]);

                return;
            }

            $ids = [];

            foreach (
                explode(
                    ',',
                    $rawIds
                )
                as $value
            ) {
                $id =
                    (int)trim(
                        $value
                    );

                if ($id > 0) {
                    $ids[] =
                        $id;
                }
            }

            $ids =
                array_values(
                    array_unique(
                        $ids
                    )
                );

            if ($ids === []) {
                throw new InvalidArgumentException(
                    'saved_palette_ids must contain valid IDs.'
                );
            }

            $items =
                (
                    new PVManager(
                        $pdo
                    )
                )
                ->listPVsForSavedPalettes(
                    $ids
                );

            echo json_encode([
                'ok' => true,
                'items' => $items,
            ]);

        } catch (
            InvalidArgumentException $e
        ) {
            http_response_code(
                422
            );

            echo json_encode([
                'ok' => false,
                'error' =>
                    $e->getMessage(),
            ]);

        } catch (
            Throwable $e
        ) {
            http_response_code(
                500
            );

            echo json_encode([
                'ok' => false,
                'error' =>
                    $e->getMessage(),
            ]);
        }
    }
}
