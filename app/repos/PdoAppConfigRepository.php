<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;

final class PdoAppConfigRepository
{
    public function __construct(
        private PDO $pdo
    ) {}

    public function getJson(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT config_json FROM app_configs WHERE config_key = :key LIMIT 1');
        $stmt->execute(['key' => $key]);
        $raw = $stmt->fetchColumn();
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    public function setJson(string $key, array $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('Failed to encode app config JSON');
        }

        $sql = <<<SQL
            INSERT INTO app_configs (config_key, config_json)
            VALUES (:key, :config_json)
            ON DUPLICATE KEY UPDATE
              config_json = VALUES(config_json),
              updated_at = CURRENT_TIMESTAMP
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'key' => $key,
            'config_json' => $json,
        ]);
    }

    public function delete(string $key): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM app_configs WHERE config_key = :key');
        $stmt->execute(['key' => $key]);
    }
}
