<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoTrackingLookupRepository
{
    private const TABLES = [
        'audiences' => 'tracking_audiences',
        'sources' => 'tracking_sources',
        'events' => 'tracking_event_types',
    ];

    public function __construct(
        private PDO $pdo
    ) {}

    public function listActive(string $lookup): array
    {
        $table = self::TABLES[$lookup] ?? null;
        if ($table === null) {
            throw new \InvalidArgumentException('Invalid tracking lookup');
        }

        $stmt = $this->pdo->query("
            SELECT `key`, label, definition, sort_order, is_active
              FROM {$table}
             WHERE is_active = 1
             ORDER BY sort_order, label
        ");

        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function isActiveEventKey(string $key): bool
    {
        $normalized = strtolower(trim($key));
        if ($normalized === '' || preg_match('/^[a-z0-9_-]{1,100}$/', $normalized) !== 1) {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM tracking_event_types WHERE `key` = :event_key AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['event_key' => $normalized]);

        return (bool)$stmt->fetchColumn();
    }

    public function saveEvent(array $payload): string
    {
        $key = strtolower(trim((string)($payload['key'] ?? '')));
        $label = trim((string)($payload['label'] ?? ''));
        $definition = trim((string)($payload['definition'] ?? ''));
        $sortOrder = isset($payload['sort_order']) && $payload['sort_order'] !== ''
            ? (int)$payload['sort_order']
            : 100;

        if ($key === '' || preg_match('/^[a-z0-9_-]{1,100}$/', $key) !== 1) {
            throw new \InvalidArgumentException('Event key must use lowercase letters, numbers, underscores, or hyphens.');
        }
        if ($label === '') {
            throw new \InvalidArgumentException('Event label required.');
        }

        $sql = <<<SQL
            INSERT INTO tracking_event_types (`key`, label, definition, sort_order, is_active)
            VALUES (:event_key, :label, :definition, :sort_order, 1)
            ON DUPLICATE KEY UPDATE
              label = VALUES(label),
              definition = VALUES(definition),
              sort_order = VALUES(sort_order),
              is_active = 1,
              updated_at = CURRENT_TIMESTAMP
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'event_key' => $key,
            'label' => $label,
            'definition' => $definition !== '' ? $definition : null,
            'sort_order' => $sortOrder,
        ]);

        return $key;
    }
}
