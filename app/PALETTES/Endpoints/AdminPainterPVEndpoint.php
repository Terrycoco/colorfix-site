<?php
declare(strict_types=1);

namespace App\PALETTES\Endpoints;

use App\PALETTES\Repos\PdoPVRepository;
use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;
use Throwable;

final class AdminPainterPVEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        try {
            $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            $repo = new PdoPVRepository($pdo);

            if ($method === 'GET') {
                $pvId = (int)($_GET['id'] ?? 0);
                if ($pvId <= 0) {
                    throw new InvalidArgumentException('palette_viewer_id required');
                }

                $item = $repo->findPainterAdminDocument($pvId);
                if ($item === null) {
                    throw new RuntimeException('Painter Viewer not found.');
                }

                echo json_encode([
                    'ok' => true,
                    'item' => $item,
                ]);
                return;
            }

            if ($method === 'POST') {
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

                $viewer = is_array($input['viewer'] ?? null)
                    ? $input['viewer']
                    : $input;

                $pvId = (int)($viewer['palette_viewer_id'] ?? $viewer['id'] ?? 0);
                $projectId = (int)($viewer['project_id'] ?? 0);
                $title = trim((string)($viewer['title'] ?? ''));

                if ($pvId <= 0) {
                    throw new InvalidArgumentException('palette_viewer_id required');
                }
                if ($projectId <= 0) {
                    throw new InvalidArgumentException('project_id required');
                }
                if ($title === '') {
                    throw new InvalidArgumentException('title required');
                }

                $projectPaletteIds = [];
                foreach (($input['project_palette_ids'] ?? []) as $value) {
                    $id = (int)$value;
                    if ($id > 0) {
                        $projectPaletteIds[] = $id;
                    }
                }

                $repo->savePainterAdminDocument(
                    pvId: $pvId,
                    projectId: $projectId,
                    projectPaletteIds: $projectPaletteIds,
                    kickerText: self::optionalText($viewer['kicker_text'] ?? null),
                    title: $title,
                    intro: self::optionalText($viewer['intro'] ?? null),
                    notes: self::optionalText($viewer['notes'] ?? null),
                    isActive: (int)($viewer['is_active'] ?? 1) === 1,
                    ctaLabel: self::optionalText($viewer['cta_label'] ?? null),
                );

                $item = $repo->findPainterAdminDocument($pvId);
                if ($item === null) {
                    throw new RuntimeException('Painter Viewer could not be reloaded.');
                }

                echo json_encode([
                    'ok' => true,
                    'item' => $item,
                ]);
                return;
            }

            http_response_code(405);
            echo json_encode([
                'ok' => false,
                'error' => 'GET or POST required.',
            ]);
        } catch (InvalidArgumentException|JsonException $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private static function optionalText(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));
        return $text === '' ? null : $text;
    }
}
