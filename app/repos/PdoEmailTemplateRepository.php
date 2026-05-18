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
            'SELECT * FROM email_templates WHERE template_key = :key LIMIT 1'
        );
        $stmt->execute(['key' => trim($key)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM email_templates ORDER BY label ASC, template_key ASC'
        );
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function listActive(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM email_templates WHERE COALESCE(is_active, 1) = 1 ORDER BY label ASC, template_key ASC'
        );
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    public function upsert(array $payload): void
    {
        $sql = <<<SQL
            INSERT INTO email_templates (
                template_key,
                label,
                description,
                subject_template,
                message_template,
                html_template,
                is_active
            )
            VALUES (
                :template_key,
                :label,
                :description,
                :subject_template,
                :message_template,
                :html_template,
                :is_active
            )
            ON DUPLICATE KEY UPDATE
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
            'label' => trim((string)($payload['label'] ?? '')),
            'description' => $payload['description'] ?? null,
            'subject_template' => $payload['subject_template'] ?? null,
            'message_template' => $payload['message_template'] ?? null,
            'html_template' => $payload['html_template'] ?? null,
            'is_active' => isset($payload['is_active']) ? (int)(bool)$payload['is_active'] : 1,
        ]);
    }
}
