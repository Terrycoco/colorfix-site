<?php
declare(strict_types=1);

namespace App\PROJECTS\Endpoints;

use App\PROJECTS\Repos\PdoProjectPhotoRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class ProjectPhotosEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        try {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if (!in_array($method, ['GET', 'POST'], true)) {
                http_response_code(405);
                echo json_encode(['ok' => false, 'error' => 'GET or POST required.']);
                return;
            }
            $input = $method === 'POST'
                ? json_decode(file_get_contents('php://input') ?: '', true, 512, JSON_THROW_ON_ERROR)
                : $_GET;
            if (!is_array($input)) { throw new InvalidArgumentException('JSON object required.'); }
            $projectId = (int)($input['project_id'] ?? 0);
            if ($projectId <= 0) { throw new InvalidArgumentException('project_id required.'); }
            $repo = new PdoProjectPhotoRepository($pdo);
            if (!$repo->schemaAvailable()) { throw new RuntimeException('Project photo migration has not been installed.'); }
            if ($method === 'POST') {
                if (!is_array($input['photos'] ?? null)) { throw new InvalidArgumentException('photos must be an array.'); }
                $repo->save($projectId, $input['photos'], (string)($input['revision'] ?? ''));
            }
            $photos = $repo->availablePhotos($projectId);
            echo json_encode([
                'ok' => true, 'photos' => $photos, 'palettes' => $repo->palettes($projectId),
                'revision' => $repo->revision($projectId),
                'library_owns_palette' => $repo->libraryOwnsPalette(),
                'rooms_assignable' => $repo->roomsAssignable(),
            ], JSON_UNESCAPED_SLASHES);
        } catch (InvalidArgumentException|\JsonException $e) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => $e->getMessage(),
                'code' => $e->getCode() === 409 ? 'project_photos_conflict' : null]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
}
