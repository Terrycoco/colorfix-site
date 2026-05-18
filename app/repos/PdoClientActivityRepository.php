<?php
declare(strict_types=1);

namespace App\Repos;

use App\Lib\AppTime;
use PDO;

final class PdoClientActivityRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function create(array $payload): int
    {
        $sql = <<<SQL
            INSERT INTO client_activity (
                client_id,
                activity_type,
                summary,
                details,
                related_client_email_id,
                metadata_json,
                occurred_at
            ) VALUES (
                :client_id,
                :activity_type,
                :summary,
                :details,
                :related_client_email_id,
                :metadata_json,
                :occurred_at
            )
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'client_id' => (int)($payload['client_id'] ?? 0),
            'activity_type' => (string)($payload['activity_type'] ?? ''),
            'summary' => $payload['summary'] ?? null,
            'details' => $payload['details'] ?? null,
            'related_client_email_id' => $payload['related_client_email_id'] ?? null,
            'metadata_json' => $payload['metadata_json'] ?? null,
            'occurred_at' => $payload['occurred_at'] ?? AppTime::now(),
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function listByClient(int $clientId, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT
                ca.*,
                ce.direction AS email_direction,
                ce.status AS email_status,
                ce.subject AS email_subject,
                ce.to_email AS email_to_email,
                ce.cc_emails AS email_cc_emails,
                ce.bcc_emails AS email_bcc_emails,
                ce.text_body AS email_text_body,
                ce.html_body AS email_html_body,
                ce.sent_at AS email_sent_at,
                ce.received_at AS email_received_at
             FROM client_activity ca
             LEFT JOIN client_emails ce
               ON ce.client_email_id = ca.related_client_email_id
             WHERE ca.client_id = :client_id
             ORDER BY ca.occurred_at DESC, ca.client_activity_id DESC
             LIMIT {$limit}"
        );
        $stmt->execute(['client_id' => $clientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function deleteById(int $activityId, ?int $clientId = null): bool
    {
        $sql = 'DELETE FROM client_activity WHERE client_activity_id = :activity_id';
        $params = ['activity_id' => $activityId];
        if ($clientId !== null && $clientId > 0) {
            $sql .= ' AND client_id = :client_id';
            $params['client_id'] = $clientId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }
}
