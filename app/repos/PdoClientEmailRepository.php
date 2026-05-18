<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoClientEmailRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function create(array $payload): int
    {
        $sql = <<<SQL
            INSERT INTO client_emails (
                client_id,
                direction,
                status,
                purpose,
                template_key,
                from_email,
                to_email,
                cc_emails,
                bcc_emails,
                subject,
                text_body,
                html_body,
                provider_message_id,
                in_reply_to_message_id,
                sent_at,
                received_at
            ) VALUES (
                :client_id,
                :direction,
                :status,
                :purpose,
                :template_key,
                :from_email,
                :to_email,
                :cc_emails,
                :bcc_emails,
                :subject,
                :text_body,
                :html_body,
                :provider_message_id,
                :in_reply_to_message_id,
                :sent_at,
                :received_at
            )
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'client_id' => (int)($payload['client_id'] ?? 0),
            'direction' => (string)($payload['direction'] ?? 'outbound'),
            'status' => (string)($payload['status'] ?? 'sent'),
            'purpose' => $payload['purpose'] ?? null,
            'template_key' => $payload['template_key'] ?? null,
            'from_email' => (string)($payload['from_email'] ?? ''),
            'to_email' => (string)($payload['to_email'] ?? ''),
            'cc_emails' => $payload['cc_emails'] ?? null,
            'bcc_emails' => $payload['bcc_emails'] ?? null,
            'subject' => $payload['subject'] ?? null,
            'text_body' => $payload['text_body'] ?? null,
            'html_body' => $payload['html_body'] ?? null,
            'provider_message_id' => $payload['provider_message_id'] ?? null,
            'in_reply_to_message_id' => $payload['in_reply_to_message_id'] ?? null,
            'sent_at' => $payload['sent_at'] ?? null,
            'received_at' => $payload['received_at'] ?? null,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM client_emails WHERE client_email_id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listByClient(int $clientId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT *
             FROM client_emails
             WHERE client_id = :client_id
             ORDER BY COALESCE(received_at, sent_at, created_at) DESC, client_email_id DESC
             LIMIT {$limit}"
        );
        $stmt->execute(['client_id' => $clientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
