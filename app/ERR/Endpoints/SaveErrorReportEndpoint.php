<?php
declare(strict_types=1);

namespace App\ERR\Endpoints;

use App\ERR\Repos\PdoErrorRepository;
use InvalidArgumentException;
use PDO;
use Throwable;

/** Save endpoint for explicitly permitted ERR admin fields. */
final class SaveErrorReportEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            echo json_encode(['ok' => false, 'error' => 'POST required.']);
            return;
        }

        try {
            $payload = json_decode(file_get_contents('php://input') ?: '', true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || !is_array($payload['changes'] ?? null)) {
                throw new InvalidArgumentException('Invalid edit request.');
            }
            (new PdoErrorRepository($pdo))->save(
                (string)($payload['view'] ?? ''),
                (int)($payload['id'] ?? 0),
                $payload['changes']
            );
            echo json_encode(['ok' => true]);
        } catch (InvalidArgumentException|\JsonException $error) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => $error->getMessage()]);
        } catch (Throwable $error) {
            error_log('ERR drawer save failed: ' . $error->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not save ERR record.']);
        }
    }
}
