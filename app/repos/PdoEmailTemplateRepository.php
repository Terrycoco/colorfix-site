<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoEmailTemplateRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function findByKey(string $key): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM email_templates
             WHERE template_key = :key
             LIMIT 1'
        );

        $stmt->execute([
            'key' => trim($key),
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function findByKeyAndType(string $key, string $templateType): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM email_templates
             WHERE template_key = :key
               AND template_type = :template_type
             LIMIT 1'
        );

        $stmt->execute([
            'key' => trim($key),
            'template_type' => trim($templateType),
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Existing behavior: email templates only.
     */
    public function listAll(): array
    {
        return $this->listAllByType('email');
    }

    /**
     * Existing behavior: active email templates only.
     */
    public function listActive(): array
    {
        return $this->listActiveByType('email');
    }

    public function listAllByType(string $templateType): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM email_templates
             WHERE template_type = :template_type
             ORDER BY label ASC, template_key ASC'
        );

        $stmt->execute([
            'template_type' => trim($templateType),
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listActiveByType(string $templateType): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT *
             FROM email_templates
             WHERE template_type = :template_type
               AND COALESCE(is_active, 1) = 1
             ORDER BY label ASC, template_key ASC'
        );

        $stmt->execute([
            'template_type' => trim($templateType),
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function upsert(array $payload): void
    {
        $sql = <<<SQL
            INSERT INTO email_templates (
                template_key,
                template_type,
                label,
                description,
                subject_template,
                message_template,
                html_template,
                is_active
            )
            VALUES (
                :template_key,
                :template_type,
                :label,
                :description,
                :subject_template,
                :message_template,
                :html_template,
                :is_active
            )
            ON DUPLICATE KEY UPDATE
              template_type = VALUES(template_type),
              label = VALUES(label),
              description = VALUES(description),
              subject_template = VALUES(subject_template),
              message_template = VALUES(message_template),
              html_template = VALUES(html_template),
              is_active = VALUES(is_active),
              updated_at = CURRENT_TIMESTAMP
        SQL;

        $stmt = $this->pdo->prepare($sql);

        $stmt->execute([
            'template_key' => trim((string)($payload['template_key'] ?? '')),
            'template_type' => trim((string)($payload['template_type'] ?? 'email')) ?: 'email',
            'label' => trim((string)($payload['label'] ?? '')),
            'description' => $payload['description'] ?? null,
            'subject_template' => $payload['subject_template'] ?? null,
            'message_template' => $payload['message_template'] ?? null,
            'html_template' => $payload['html_template'] ?? null,
            'is_active' => isset($payload['is_active'])
                ? (int)(bool)$payload['is_active']
                : 1,
        ]);
    }
}