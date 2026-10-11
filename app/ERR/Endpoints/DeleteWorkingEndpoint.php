<?php
declare(strict_types=1);

namespace App\ERR\Endpoints;

use App\ERR\Repos\PdoErrorRepository;
use PDO;
use Throwable;

final class DeleteWorkingEndpoint
{
    public static function respond(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'POST required.']);
            return;
        }
        try {
            $input = json_decode(file_get_contents('php://input') ?: '', true, 512, JSON_THROW_ON_ERROR);
            $id = is_array($input) ? (int)($input['working_id'] ?? 0) : 0;
            if ($id <= 0) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'Valid Working ID required.']);
                return;
            }
            if (!(new PdoErrorRepository($pdo))->manuallyClearWorking($id)) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Working record no longer exists.']);
                return;
            }
            echo json_encode(['ok' => true]);
        } catch (\RuntimeException $failure) {
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => $failure->getMessage()]);
        } catch (Throwable $failure) {
            error_log('ERR manual Working deletion failed: ' . $failure->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not delete the Working record.']);
        }
    }
}
