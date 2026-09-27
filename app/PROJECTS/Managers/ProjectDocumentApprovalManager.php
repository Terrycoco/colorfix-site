<?php
declare(strict_types=1);

namespace App\PROJECTS\Managers;

use App\PROJECTS\Repos\PdoProjectDocumentRepository;
use App\PROJECTS\Repos\PdoProjectRepository;
use App\PROJECTS\Services\ProjectActivityService;
use PDO;
use RuntimeException;
use Throwable;

final class ProjectDocumentApprovalManager
{
    private PdoProjectDocumentRepository $documents;
    private PdoProjectRepository $projects;
    private ProjectActivityService $activity;

    public function __construct(
        private PDO $pdo
    ) {
        $this->documents =
            new PdoProjectDocumentRepository($this->pdo);

        $this->projects =
            new PdoProjectRepository($this->pdo);

        $this->activity =
            new ProjectActivityService($this->pdo);
    }

    /**
     * Approves a Project document and records the approval in Project Activity.
     *
     * The document row is locked for the duration of the transaction so a
     * repeated/double approval cannot create a second Project Activity event.
     *
     * @return array<string, mixed>
     */
    public function approve(int $documentId): array
    {
        if ($documentId <= 0) {
            throw new RuntimeException(
                'Valid document ID required.'
            );
        }

        $startedTransaction = false;

        try {
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $startedTransaction = true;
            }

            $document =
                $this->documents->findByIdForUpdate($documentId);

            if ($document === null) {
                throw new RuntimeException(
                    'Project document was not found.'
                );
            }

            if (empty($document['approval_required'])) {
                throw new RuntimeException(
                    'This project document does not require approval.'
                );
            }

            $projectId =
                (int)($document['project_id'] ?? 0);

            if ($projectId <= 0) {
                throw new RuntimeException(
                    'Project document has no valid project.'
                );
            }

            $project =
                $this->projects->findById($projectId);

            if ($project === null) {
                throw new RuntimeException(
                    'Project for this document was not found.'
                );
            }

            $clientName =
                trim((string)($project['client_name'] ?? ''));

            $documentTitle =
                trim((string)($document['title'] ?? ''));

            if ($documentTitle === '') {
                $documentTitle = 'Document';
            }

            // Already approved: return the original approval unchanged.
            // Because the row is locked, this also protects against double-clicks
            // and concurrent requests creating duplicate Project Activity events.
            if ($document['accepted_at'] !== null) {
                if ($startedTransaction) {
                    $this->pdo->commit();
                }

                $document['approved_by'] = $clientName;

                return $document;
            }

            $acceptedDocument =
                $this->documents->accept($documentId);

            $description =
                $clientName !== ''
                    ? "{$clientName} approved {$documentTitle}"
                    : "Approved {$documentTitle}";

            $acceptedAt =
                trim((string)($acceptedDocument['accepted_at'] ?? ''));

            $activityDate =
                $acceptedAt !== ''
                    ? substr($acceptedAt, 0, 10)
                    : null;

            $this->activity->log(
                projectId: $projectId,
                description: $description,
                eventType: 'document_approved',
                resourceType: 'project_document',
                resourceId: $documentId,
                activityDate: $activityDate
            );

            if ($startedTransaction) {
                $this->pdo->commit();
            }

            // Display-only identity for the public approval response.
            // The client name is owned by the Project/Client relationship,
            // not duplicated on project_documents.
            $acceptedDocument['approved_by'] =
                $clientName;

            return $acceptedDocument;
        } catch (Throwable $e) {
            if (
                $startedTransaction
                && $this->pdo->inTransaction()
            ) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }
}
