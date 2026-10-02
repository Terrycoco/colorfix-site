<?php
declare(strict_types=1);

namespace App\PROJECTS\Repos;

use PDO;
use RuntimeException;

final class PdoProjectDocumentRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByProjectId(int $projectId): array
    {
        if ($projectId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT
                id,
                project_id,
                scope_id,
                document_type,
                template_key,
                title,
                content_html,
                status,
                approval_required,
                sent_at,
                accepted_at,
                locked_at,
                created_at,
                updated_at
             FROM project_documents
             WHERE project_id = :project_id
             ORDER BY created_at DESC, id DESC'
        );

        $stmt->execute([
            ':project_id' => $projectId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $documentId): ?array
    {
        if ($documentId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT
                id,
                project_id,
                scope_id,
                document_type,
                template_key,
                title,
                content_html,
                status,
                approval_required,
                sent_at,
                accepted_at,
                locked_at,
                created_at,
                updated_at
             FROM project_documents
             WHERE id = :document_id
             LIMIT 1'
        );

        $stmt->execute([
            ':document_id' => $documentId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Loads a document row and locks it until the current transaction ends.
     * Used for approval so concurrent requests cannot both create side effects.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdForUpdate(int $documentId): ?array
    {
        if ($documentId <= 0) {
            return null;
        }

        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException(
                'findByIdForUpdate requires an active transaction.'
            );
        }

        $stmt = $this->pdo->prepare(
            'SELECT
                id,
                project_id,
                scope_id,
                document_type,
                template_key,
                title,
                content_html,
                status,
                approval_required,
                sent_at,
                accepted_at,
                locked_at,
                created_at,
                updated_at
             FROM project_documents
             WHERE id = :document_id
             LIMIT 1
             FOR UPDATE'
        );

        $stmt->execute([
            ':document_id' => $documentId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findReusableDraft(
        int $projectId,
        int $scopeId,
        string $templateKey
    ): ?array {
        if (
            $projectId <= 0
            || $scopeId <= 0
            || trim($templateKey) === ''
        ) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT
                id,
                project_id,
                scope_id,
                document_type,
                template_key,
                title,
                content_html,
                status,
                approval_required,
                sent_at,
                accepted_at,
                locked_at,
                created_at,
                updated_at
             FROM project_documents
             WHERE project_id = :project_id
               AND scope_id = :scope_id
               AND template_key = :template_key
               AND status = \'draft\'
               AND sent_at IS NULL
               AND accepted_at IS NULL
               AND locked_at IS NULL
             ORDER BY id DESC
             LIMIT 1'
        );

        $stmt->execute([
            ':project_id' => $projectId,
            ':scope_id' => $scopeId,
            ':template_key' => $templateKey,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function createDraft(
        int $projectId,
        int $scopeId,
        string $documentType,
        string $templateKey,
        string $title,
        string $contentHtml,
        bool $approvalRequired = false
    ): int {
        if ($projectId <= 0 || $scopeId <= 0) {
            throw new RuntimeException(
                'Valid Project and Scope IDs required.'
            );
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO project_documents (
                project_id,
                scope_id,
                document_type,
                template_key,
                title,
                content_html,
                status,
                approval_required
             ) VALUES (
                :project_id,
                :scope_id,
                :document_type,
                :template_key,
                :title,
                :content_html,
                \'draft\',
                :approval_required
             )'
        );

        $stmt->execute([
            ':project_id' => $projectId,
            ':scope_id' => $scopeId,
            ':document_type' => $documentType,
            ':template_key' => $templateKey,
            ':title' => $title,
            ':content_html' => $contentHtml,
            ':approval_required' => $approvalRequired ? 1 : 0,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function updateDraft(
        int $documentId,
        string $documentType,
        string $title,
        string $contentHtml,
        bool $approvalRequired = false
    ): void {
        if ($documentId <= 0) {
            throw new RuntimeException(
                'Valid document ID required.'
            );
        }

        $stmt = $this->pdo->prepare(
            'UPDATE project_documents
                SET document_type = :document_type,
                    title = :title,
                    content_html = :content_html,
                    approval_required = :approval_required
              WHERE id = :document_id
                AND status = \'draft\'
                AND sent_at IS NULL
                AND accepted_at IS NULL
                AND locked_at IS NULL'
        );

        $stmt->execute([
            ':document_id' => $documentId,
            ':document_type' => $documentType,
            ':title' => $title,
            ':content_html' => $contentHtml,
            ':approval_required' => $approvalRequired ? 1 : 0,
        ]);

        if ($stmt->rowCount() === 0) {
            $document = $this->findById($documentId);

            if (!$document) {
                throw new RuntimeException('Project document was not found.');
            }

            if ($document['locked_at'] !== null || $document['sent_at'] !== null) {
                throw new RuntimeException(
                    'Sent project documents are locked and cannot be overwritten.'
                );
            }
        }
    }

    /**
     * Records client acceptance of a document that requires approval.
     *
     * Repeated calls are idempotent: if the document was already accepted,
     * the existing document row is returned unchanged.
     *
     * @return array<string, mixed>
     */
    public function accept(int $documentId): array
    {
        if ($documentId <= 0) {
            throw new RuntimeException(
                'Valid document ID required.'
            );
        }

        $stmt = $this->pdo->prepare(
            'UPDATE project_documents
                SET accepted_at = CURRENT_TIMESTAMP
              WHERE id = :document_id
                AND approval_required = 1
                AND accepted_at IS NULL'
        );

        $stmt->execute([
            ':document_id' => $documentId,
        ]);

        $document = $this->findById($documentId);

        if (!$document) {
            throw new RuntimeException(
                'Project document was not found.'
            );
        }

        if (empty($document['approval_required'])) {
            throw new RuntimeException(
                'This project document does not require approval.'
            );
        }

        if ($document['accepted_at'] === null) {
            throw new RuntimeException(
                'Project document could not be accepted.'
            );
        }

        return $document;
    }

    /**
     * Freezes the current document snapshot. Repeated calls are idempotent.
     *
     * @return array<string, mixed>
     */
    public function freeze(int $documentId): array
    {
        if ($documentId <= 0) {
            throw new RuntimeException(
                'Valid document ID required.'
            );
        }

        $stmt = $this->pdo->prepare(
            'UPDATE project_documents
                SET locked_at = COALESCE(locked_at, CURRENT_TIMESTAMP)
              WHERE id = :document_id'
        );

        $stmt->execute([
            ':document_id' => $documentId,
        ]);

        $document = $this->findById($documentId);

        if (!$document) {
            throw new RuntimeException(
                'Project document was not found.'
            );
        }

        if ($document['locked_at'] === null) {
            throw new RuntimeException(
                'Project document could not be frozen.'
            );
        }

        return $document;
    }

    /**
     * Sets or clears the manually recorded sent date.
     *
     * @return array<string, mixed>
     */
    public function setSentDate(
        int $documentId,
        ?string $sentDate
    ): array {
        if ($documentId <= 0) {
            throw new RuntimeException(
                'Valid document ID required.'
            );
        }

        $sentAt = null;

        if ($sentDate !== null && trim($sentDate) !== '') {
            $value = trim($sentDate);

            $date = \DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $value
            );

            if (
                !$date
                || $date->format('Y-m-d') !== $value
            ) {
                throw new RuntimeException(
                    'Sent date must be a valid YYYY-MM-DD date.'
                );
            }

            $sentAt = $value . ' 00:00:00';
        }

        $stmt = $this->pdo->prepare(
            'UPDATE project_documents
                SET sent_at = :sent_at
              WHERE id = :document_id'
        );

        $stmt->bindValue(
            ':document_id',
            $documentId,
            PDO::PARAM_INT
        );

        if ($sentAt === null) {
            $stmt->bindValue(
                ':sent_at',
                null,
                PDO::PARAM_NULL
            );
        } else {
            $stmt->bindValue(
                ':sent_at',
                $sentAt,
                PDO::PARAM_STR
            );
        }

        $stmt->execute();

        $document = $this->findById($documentId);

        if (!$document) {
            throw new RuntimeException(
                'Project document was not found.'
            );
        }

        return $document;
    }

}
