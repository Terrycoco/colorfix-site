<?php
declare(strict_types=1);

namespace App\PROJECTS\Endpoints;

use App\PROJECTS\Managers\ProjectDocumentApprovalManager;
use App\REX\Repos\PdoRexReservationRepository;
use App\REX\Resolvers\RexResolverRegistryFactory;
use App\REX\Services\RexResolver;
use App\REX\Services\RexReserver;

use PDO;
use Throwable;

final class ProjectDocumentApproveEndpoint
{
    public static function handle(PDO $pdo): void
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                throw new \InvalidArgumentException('POST required.');
            }

            $raw = file_get_contents('php://input');
            $payload = json_decode((string)$raw, true);

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

            $token = $payload['rex_token'] ?? null;

            if (!is_string($token) || trim($token) === '') {
                throw new \InvalidArgumentException('REX token is required.');
            }

            $reservations = new PdoRexReservationRepository($pdo);
            $reservation = $reservations->findByToken($token);

            // Inactive links must not authorize approval through a fallback.
            if (
                $reservation === null
                || $reservation->status !== RexReserver::STATUS_ACTIVE
                || $reservation->revokedAt !== null
            ) {
                throw new \InvalidArgumentException('An active document REX is required.');
            }

            $resolver = new RexResolver(
                $reservations,
                RexResolverRegistryFactory::build($pdo)
            );
            $resolved = $resolver->resolveToken($token);

            if (
                $resolved->resolverKey !== 'document'
                || $resolved->resourceType !== 'doc'
                || $resolved->resourceId !== $documentId
                || (int)($resolved->destination['document']['id'] ?? 0) !== $documentId
            ) {
                throw new \InvalidArgumentException('REX does not authorize this document.');
            }

            $manager =
                new ProjectDocumentApprovalManager($pdo);

            $document =
                $manager->approve($documentId);

            echo json_encode([
                'ok' => true,
                'data' => [
                    'document' => $document,
                ],
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
