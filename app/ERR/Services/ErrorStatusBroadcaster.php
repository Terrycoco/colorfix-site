<?php
declare(strict_types=1);
namespace App\ERR\Services;

use PDO;
use Throwable;

/** Best-effort interactive statuses. Never creates err_log incidents. */
final class ErrorStatusBroadcaster
{
    public function __construct(private PDO $pdo, private ?string $sessionToken) {}

    public static function tokenFromRequest(): ?string
    {
        $value = (string)($_SERVER['HTTP_X_ERR_STATUS_SESSION'] ?? '');
        return preg_match('/\A[a-f0-9]{32}\z/D', $value) ? $value : null;
    }

    public function broadcast(string $operationId, string $title, string $status, string $message, string $detail = ''): void
    {
        if ($this->sessionToken === null) return;
        if (!in_array($status, ['attempting', 'accepted', 'waiting', 'success', 'failed'], true)) {
            throw new \InvalidArgumentException('Invalid ERR broadcast status.');
        }
        try {
            $stmt = $this->pdo->prepare('INSERT INTO err_status_events
                (session_token, operation_id, title, status, message, detail)
                VALUES (:session_token, :operation_id, :title, :status, :message, :detail)');
            $stmt->execute([
                'session_token' => hash('sha256', $this->sessionToken),
                'operation_id' => substr($operationId, 0, 100),
                'title' => substr($title, 0, 180),
                'status' => $status,
                'message' => substr($message, 0, 1000),
                'detail' => substr($detail, 0, 2000),
            ]);
        } catch (Throwable $e) {
            error_log('ERR status broadcast unavailable: ' . $e->getMessage());
        }
    }

    public function latest(): ?array
    {
        if ($this->sessionToken === null) return null;
        $stmt = $this->pdo->prepare('SELECT id, operation_id, title, status, message, detail
            FROM err_status_events WHERE session_token = :session_token
            AND created_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)
            ORDER BY id DESC LIMIT 1');
        $stmt->execute(['session_token' => hash('sha256', $this->sessionToken)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return [
            'id' => (int)$row['id'], 'operationId' => $row['operation_id'],
            'title' => $row['title'], 'status' => $row['status'],
            'message' => $row['message'], 'detail' => $row['detail'],
        ];
    }
}
