<?php
declare(strict_types=1);

namespace App\PALETTES\Endpoints;

use App\PALETTES\Managers\PVManager;
use App\REX\Services\RexPlaylistExperienceSyncService;
use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class AdminPVCreateEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
                http_response_code(405);
                echo json_encode(['ok' => false, 'error' => 'POST required.']);
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
                throw new InvalidArgumentException('JSON object required.');
            }

            $projectPaletteIds = [];
            foreach (($input['project_palette_ids'] ?? []) as $value) {
                $id = (int)$value;
                if ($id > 0) {
                    $projectPaletteIds[] = $id;
                }
            }

            $pdo->beginTransaction();
            $item = (new PVManager($pdo))->createPV(
                savedPaletteId: (int)($input['saved_palette_id'] ?? 0),
                experience: (string)($input['experience'] ?? $input['format'] ?? ''),
                title: (string)($input['title'] ?? ''),
                projectId: (int)($input['project_id'] ?? 0),
                projectPaletteIds: $projectPaletteIds,
            );

            if (is_array($input['viewer_fields'] ?? null)) {
                $repo = new \App\PALETTES\Repos\PdoPVRepository($pdo);
                $pvId = (int)$item['palette_viewer_id'];
                $repo->updateHeader($pvId, $input['viewer_fields']);
                $item = array_replace($item, $repo->findGridRowById($pvId) ?? []);
            }
            // A copied project PV must be reachable as soon as its save succeeds.
            (new RexPlaylistExperienceSyncService($pdo))->syncProjectPlaylistsForSavedPalette(
                (int)($item['saved_palette_id'] ?? 0)
            );
            $pdo->commit();

            echo json_encode(['ok' => true, 'item' => $item]);
        } catch (InvalidArgumentException|JsonException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
}
