<?php
declare(strict_types=1);

namespace App\GALLERY\Endpoints;

use App\GALLERY\Repos\PdoItemRepository;
use PDO;
use PDOException;
use Throwable;

/** SYSTEM authorization is required by the public doorbell. */
final class DeleteItemEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            self::reply(405, ['success' => false, 'error' => 'POST required.']);
            return;
        }
        if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
            self::reply(415, ['success' => false, 'error' => 'JSON content type required.']);
            return;
        }
        try {
            $data = json_decode((string)file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
            $id = is_array($data) ? filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if (!$id) {
                self::reply(400, ['success' => false, 'error' => 'A positive item ID is required.']);
                return;
            }
            if (!(new PdoItemRepository($pdo))->deleteById((int)$id)) {
                self::reply(404, ['success' => false, 'error' => 'Item not found.']);
                return;
            }
            self::reply(200, ['success' => true, 'id' => (int)$id]);
        } catch (\JsonException) {
            self::reply(400, ['success' => false, 'error' => 'Invalid JSON.']);
        } catch (PDOException $error) {
            error_log('GALLERY item delete failed: ' . $error->getMessage());
            $referenced = str_starts_with((string)$error->getCode(), '23');
            self::reply($referenced ? 409 : 500, ['success' => false, 'error' => $referenced
                ? 'This item is referenced by another record and cannot be deleted.' : 'Unable to delete item.']);
        } catch (Throwable $error) {
            error_log('GALLERY item delete failed: ' . $error->getMessage());
            self::reply(500, ['success' => false, 'error' => 'Unable to delete item.']);
        }
    }

    private static function reply(int $status, array $body): void
    {
        http_response_code($status);
        echo json_encode($body, JSON_UNESCAPED_SLASHES);
    }
}
