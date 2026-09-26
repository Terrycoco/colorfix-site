<?php
declare(strict_types=1);

namespace App\DOCUMENTS\Repos;

use PDO;
use RuntimeException;

final class PdoDocumentTemplateRepository
{
    public function __construct(
        private PDO $pdo
    ) {}


    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $stmt =
            $this->pdo->query(
                'SELECT
                    id,
                    template_key,
                    template_type,
                    label,
                    description,
                    title_template,
                    text_template,
                    html_template,
                    is_active,
                    created_at,
                    updated_at
                 FROM document_templates
                 ORDER BY template_type ASC, label ASC, id ASC'
            );

        return $stmt->fetchAll(
            PDO::FETCH_ASSOC
        ) ?: [];
    }


    /**
     * @return array<string, mixed>|null
     */
    public function findById(
        int $id
    ): ?array {
        if ($id <= 0) {
            return null;
        }

        $stmt =
            $this->pdo->prepare(
                'SELECT
                    id,
                    template_key,
                    template_type,
                    label,
                    description,
                    title_template,
                    text_template,
                    html_template,
                    is_active,
                    created_at,
                    updated_at
                 FROM document_templates
                 WHERE id = :id
                 LIMIT 1'
            );

        $stmt->execute([
            ':id' => $id,
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
    public function findActiveByKey(
        string $templateKey
    ): ?array {
        $templateKey = trim($templateKey);

        if ($templateKey === '') {
            return null;
        }

        $stmt =
            $this->pdo->prepare(
                'SELECT
                    id,
                    template_key,
                    template_type,
                    label,
                    description,
                    title_template,
                    text_template,
                    html_template,
                    is_active,
                    created_at,
                    updated_at
                 FROM document_templates
                 WHERE template_key = :template_key
                   AND is_active = 1
                 LIMIT 1'
            );

        $stmt->execute([
            ':template_key' =>
                $templateKey,
        ]);

        $row =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        return $row ?: null;
    }


    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function save(
        array $payload
    ): array {
        $id =
            isset($payload['id'])
                ? (int)$payload['id']
                : 0;

        $templateKey =
            trim(
                (string)(
                    $payload['template_key']
                    ?? ''
                )
            );

        $templateType =
            trim(
                (string)(
                    $payload['template_type']
                    ?? 'document'
                )
            );

        $label =
            trim(
                (string)(
                    $payload['label']
                    ?? ''
                )
            );

        if ($templateKey === '') {
            throw new RuntimeException(
                'template_key required.'
            );
        }

        if ($templateType === '') {
            throw new RuntimeException(
                'template_type required.'
            );
        }

        if ($label === '') {
            throw new RuntimeException(
                'label required.'
            );
        }

        $values = [
            ':template_key' =>
                $templateKey,

            ':template_type' =>
                $templateType,

            ':label' =>
                $label,

            ':description' =>
                $this->nullableText(
                    $payload['description']
                    ?? null
                ),

            ':title_template' =>
                $this->nullableText(
                    $payload['title_template']
                    ?? null
                ),

            ':text_template' =>
                $this->nullableText(
                    $payload['text_template']
                    ?? null
                ),

            ':html_template' =>
                $this->nullableText(
                    $payload['html_template']
                    ?? null
                ),

            ':is_active' =>
                !empty(
                    $payload['is_active']
                )
                    ? 1
                    : 0,
        ];

        if ($id > 0) {
            if (
                $this->findById($id)
                === null
            ) {
                throw new RuntimeException(
                    "Document template {$id} was not found."
                );
            }

            $stmt =
                $this->pdo->prepare(
                    'UPDATE document_templates
                     SET
                        template_key = :template_key,
                        template_type = :template_type,
                        label = :label,
                        description = :description,
                        title_template = :title_template,
                        text_template = :text_template,
                        html_template = :html_template,
                        is_active = :is_active
                     WHERE id = :id'
                );

            $values[':id'] =
                $id;

            $stmt->execute(
                $values
            );

            $saved =
                $this->findById(
                    $id
                );

            if ($saved === null) {
                throw new RuntimeException(
                    'Saved document template could not be reloaded.'
                );
            }

            return $saved;
        }

        $stmt =
            $this->pdo->prepare(
                'INSERT INTO document_templates (
                    template_key,
                    template_type,
                    label,
                    description,
                    title_template,
                    text_template,
                    html_template,
                    is_active
                 ) VALUES (
                    :template_key,
                    :template_type,
                    :label,
                    :description,
                    :title_template,
                    :text_template,
                    :html_template,
                    :is_active
                 )'
            );

        $stmt->execute(
            $values
        );

        $newId =
            (int)$this->pdo
                ->lastInsertId();

        $saved =
            $this->findById(
                $newId
            );

        if ($saved === null) {
            throw new RuntimeException(
                'Created document template could not be reloaded.'
            );
        }

        return $saved;
    }


    private function nullableText(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $text =
            (string)$value;

        return $text === ''
            ? null
            : $text;
    }
}
