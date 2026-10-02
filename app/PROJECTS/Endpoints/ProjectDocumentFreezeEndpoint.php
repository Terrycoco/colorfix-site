<?php
declare(strict_types=1);

namespace App\PROJECTS\Endpoints;

use App\PROJECTS\Repos\PdoProjectDocumentRepository;
use PDO;
use Throwable;

final class ProjectDocumentFreezeEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                throw new \InvalidArgumentException('POST required.');
            }

            $payload = json_decode(
                (string)file_get_contents('php://input'),
                true
            );

            if (!is_array($payload)) {
                throw new \InvalidArgumentException(
                    'Valid JSON body is required.'
                );
            }

            $documentId =
                (int)($payload['document_id'] ?? 0);

            if ($documentId <= 0) {
                throw new \InvalidArgumentException(
                    'Valid document ID required.'
                );
            }

            $repo =
                new PdoProjectDocumentRepository($pdo);

            $document =
                $repo->freeze($documentId);

            echo json_encode([
                'ok' => true,
                'document' => $document,
            ], JSON_UNESCAPED_SLASHES);

        } catch (Throwable $e) {
            http_response_code(400);

            echo json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES);
        }
    }
}
