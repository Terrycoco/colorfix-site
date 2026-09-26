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
    public function listByProjectId(
        int $projectId
    ): array {
        if ($projectId <= 0) {
            return [];
        }

        $stmt =
            $this->pdo->prepare(
                'SELECT
                    id,
                    project_id,
                    scope_id,
                    document_type,
                    template_key,
                    title,
                    content_html,
                    status,
                    sent_at,
                    accepted_at,
                    created_at,
                    updated_at
                 FROM project_documents
                 WHERE project_id = :project_id
                 ORDER BY created_at DESC, id DESC'
            );

        $stmt->execute([
            ':project_id' =>
                $projectId,
        ]);

        return
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            )
            ?: [];
    }


    /**
     * @return array<string, mixed>|null
     */
    public function findById(
        int $documentId
    ): ?array {
        if ($documentId <= 0) {
            return null;
        }

        $stmt =
            $this->pdo->prepare(
                'SELECT
                    id,
                    project_id,
                    scope_id,
                    document_type,
                    template_key,
                    title,
                    content_html,
                    status,
                    sent_at,
                    accepted_at,
                    created_at,
                    updated_at
                 FROM project_documents
                 WHERE id = :document_id
                 LIMIT 1'
            );

        $stmt->execute([
            ':document_id' =>
                $documentId,
        ]);

        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

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
            ||
            $scopeId <= 0
            ||
            trim($templateKey) === ''
        ) {
            return null;
        }

        $stmt =
            $this->pdo->prepare(
                'SELECT
                    id,
                    project_id,
                    scope_id,
                    document_type,
                    template_key,
                    title,
                    content_html,
                    status,
                    sent_at,
                    accepted_at,
                    created_at,
                    updated_at
                 FROM project_documents
                 WHERE project_id = :project_id
                   AND scope_id = :scope_id
                   AND template_key = :template_key
                   AND status = \'draft\'
                   AND sent_at IS NULL
                   AND accepted_at IS NULL
                 ORDER BY id DESC
                 LIMIT 1'
            );

        $stmt->execute([
            ':project_id' =>
                $projectId,

            ':scope_id' =>
                $scopeId,

            ':template_key' =>
                $templateKey,
        ]);

        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        return $row ?: null;
    }


    public function createDraft(
        int $projectId,
        int $scopeId,
        string $documentType,
        string $templateKey,
        string $title,
        string $contentHtml
    ): int {
        if (
            $projectId <= 0
            ||
            $scopeId <= 0
        ) {
            throw new RuntimeException(
                'Valid Project and Scope IDs required.'
            );
        }

        $stmt =
            $this->pdo->prepare(
                'INSERT INTO project_documents (
                    project_id,
                    scope_id,
                    document_type,
                    template_key,
                    title,
                    content_html,
                    status
                 ) VALUES (
                    :project_id,
                    :scope_id,
                    :document_type,
                    :template_key,
                    :title,
                    :content_html,
                    \'draft\'
                 )'
            );

        $stmt->execute([
            ':project_id' =>
                $projectId,

            ':scope_id' =>
                $scopeId,

            ':document_type' =>
                $documentType,

            ':template_key' =>
                $templateKey,

            ':title' =>
                $title,

            ':content_html' =>
                $contentHtml,
        ]);

        return
            (int)$this->pdo
                ->lastInsertId();
    }


    public function updateDraft(
        int $documentId,
        string $documentType,
        string $title,
        string $contentHtml
    ): void {
        if ($documentId <= 0) {
            throw new RuntimeException(
                'Valid document ID required.'
            );
        }

        $stmt =
            $this->pdo->prepare(
                'UPDATE project_documents
                    SET document_type = :document_type,
                        title = :title,
                        content_html = :content_html
                  WHERE id = :document_id
                    AND status = \'draft\'
                    AND sent_at IS NULL
                    AND accepted_at IS NULL'
            );

        $stmt->execute([
            ':document_id' =>
                $documentId,

            ':document_type' =>
                $documentType,

            ':title' =>
                $title,

            ':content_html' =>
                $contentHtml,
        ]);
    }
}
