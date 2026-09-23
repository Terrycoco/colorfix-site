<?php
declare(strict_types=1);

namespace App\REX\Resolvers;

use App\REX\Contracts\RexResolverInterface;
use App\REX\DTO\RexReservation;
use App\REX\DTO\RexReservationDescriptor;
use App\REX\DTO\RexResolutionBehavior;
use App\REX\DTO\RexResolutionRequest;
use App\REX\DTO\RexResolutionResult;
use App\REX\DTO\RexShareMetadata;
use PDO;
use RuntimeException;

final class DocumentResolver implements RexResolverInterface
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function resolve(
        RexResolutionRequest $request
    ): RexResolutionResult {
        if ($request->resourceType !== 'doc') {
            throw new RuntimeException(
                "Document resolver requires resource_type 'doc'."
            );
        }

        $documentId = $request->resourceId;

        if ($documentId <= 0) {
            throw new RuntimeException(
                'Document reservation requires a valid document ID.'
            );
        }

        $document = $this->findDocument($documentId);

        if (!$document) {
            throw new RuntimeException(
                "Project document {$documentId} was not found."
            );
        }

        return new RexResolutionResult(
            resolverKey: 'document',
            resourceType: 'doc',
            resourceId: $documentId,
            behavior: RexResolutionBehavior::RENDER,
            shareMetadata: new RexShareMetadata(
                title: $document['title'] ?? null,
                description: null,
                imageUrl: null,
            ),
            destination: [
                'document' => [
                    'id' => (int)$document['id'],
                    'project_id' => (int)$document['project_id'],
                    'scope_id' => $document['scope_id'] !== null
                        ? (int)$document['scope_id']
                        : null,

                    'document_type' => (string)$document['document_type'],
                    'title' => (string)$document['title'],
                    'content_html' => (string)$document['content_html'],
                    'status' => (string)$document['status'],

                    'sent_at' => $document['sent_at'],
                    'accepted_at' => $document['accepted_at'],
                ],
            ],
            analyticsMetadata: [
                'reservation_id' => $request->reservation->id,
                'document_id' => $documentId,
                'project_id' => (int)$document['project_id'],
                'document_type' => (string)$document['document_type'],
                'document_status' => (string)$document['status'],
            ],
        );
    }

    public function describe(
        RexReservation $reservation
    ): RexReservationDescriptor {
        return $this->descriptor(
            $reservation->resourceType,
            $reservation->resourceId
        );
    }

    public function previewDescribe(
        string $resourceType,
        int $resourceId,
        array $context
    ): RexReservationDescriptor {
        return $this->descriptor(
            $resourceType,
            $resourceId
        );
    }

    private function descriptor(
        string $resourceType,
        int $documentId
    ): RexReservationDescriptor {
        if ($resourceType !== 'doc') {
            throw new RuntimeException(
                "Document resolver requires resource_type 'doc'."
            );
        }

        if ($documentId <= 0) {
            throw new RuntimeException(
                'Document resolver requires a valid document ID.'
            );
        }

        $document = $this->findDocument($documentId);

        if (!$document) {
            throw new RuntimeException(
                "Project document {$documentId} was not found."
            );
        }

        $title = trim((string)($document['title'] ?? ''));

        return new RexReservationDescriptor(
            title: $title !== ''
                ? $title
                : "Document #{$documentId}",
            fields: [
                [
                    'label' => 'Document',
                    'value' => $title !== ''
                        ? $title
                        : "Document #{$documentId}",
                ],
                [
                    'label' => 'Document ID',
                    'value' => (string)$documentId,
                ],
                [
                    'label' => 'Type',
                    'value' => (string)$document['document_type'],
                ],
                [
                    'label' => 'Status',
                    'value' => (string)$document['status'],
                ],
                [
                    'label' => 'Project ID',
                    'value' => (string)$document['project_id'],
                ],
            ],
        );
    }

    private function findDocument(int $documentId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM project_documents
             WHERE id = :id
             LIMIT 1'
        );

        $stmt->execute([
            'id' => $documentId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}