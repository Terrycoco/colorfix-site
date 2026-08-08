<?php
declare(strict_types=1);

namespace App\Repos;

use PDO;
use PDOException;

final class PdoUrlReservationRepository
{
    public function __construct(private PDO $pdo) {}

    public function listTypes(bool $includeInactive = true): array
    {
        $sql = 'SELECT * FROM url_reservation_types';
        if (!$includeInactive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY label ASC, id ASC';
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findTypeByKey(string $typeKey): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM url_reservation_types WHERE type_key = :type_key LIMIT 1');
        $stmt->execute([':type_key' => trim($typeKey)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findTypeById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM url_reservation_types WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function insertReservation(array $data): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO url_reservations (
                token,
                reservation_type_id,
                resource_id,
                experience_key,
                source_key,
                params_json,
                label,
                og_title,
                og_description,
                og_image_url,
                expires_at
             ) VALUES (
                :token,
                :reservation_type_id,
                :resource_id,
                :experience_key,
                :source_key,
                :params_json,
                :label,
                :og_title,
                :og_description,
                :og_image_url,
                :expires_at
             )"
        );
        $stmt->execute([
            ':token' => $data['token'],
            ':reservation_type_id' => (int)$data['reservation_type_id'],
            ':resource_id' => (int)$data['resource_id'],
            ':experience_key' => $data['experience_key'] ?? null,
            ':source_key' => $data['source_key'] ?? null,
            ':params_json' => $data['params_json'] ?? null,
            ':label' => $data['label'] ?? null,
            ':og_title' => $data['og_title'] ?? null,
            ':og_description' => $data['og_description'] ?? null,
            ':og_image_url' => $data['og_image_url'] ?? null,
            ':expires_at' => $data['expires_at'] ?? null,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.*,
                    t.type_key,
                    t.label AS type_label,
                    t.resolver_key,
                    t.delivery_mode,
                    t.route_template,
                    t.parameter_schema_json,
                    t.is_active AS type_is_active
               FROM url_reservations r
               INNER JOIN url_reservation_types t
                 ON t.id = r.reservation_type_id
              WHERE r.token = :token
              LIMIT 1"
        );
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function tokenExists(string $token): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM url_reservations WHERE token = :token LIMIT 1');
        $stmt->execute([':token' => $token]);
        return (bool)$stmt->fetchColumn();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.*,
                    t.type_key,
                    t.label AS type_label,
                    t.resolver_key,
                    t.delivery_mode,
                    t.route_template,
                    t.parameter_schema_json,
                    t.is_active AS type_is_active
               FROM url_reservations r
               INNER JOIN url_reservation_types t
                 ON t.id = r.reservation_type_id
              WHERE r.id = :id
              LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listReservations(array $filters = [], int $limit = 200): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['type_key'])) {
            $where[] = 't.type_key = :type_key';
            $params[':type_key'] = trim((string)$filters['type_key']);
        }
        if (!empty($filters['resource_id'])) {
            $where[] = 'r.resource_id = :resource_id';
            $params[':resource_id'] = (int)$filters['resource_id'];
        }
        if (array_key_exists('active', $filters) && $filters['active'] !== '') {
            $where[] = 'r.is_active = :is_active';
            $params[':is_active'] = (int)(bool)$filters['active'];
        }

        $limit = max(1, min(500, $limit));
        $sql = "SELECT r.*,
                       t.type_key,
                       t.label AS type_label,
                       t.resolver_key,
                       t.delivery_mode,
                       t.is_active AS type_is_active
                  FROM url_reservations r
                  INNER JOIN url_reservation_types t
                    ON t.id = r.reservation_type_id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY r.created_at DESC, r.id DESC LIMIT {$limit}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findActiveProjectExperienceReservation(int $projectId, string $experienceKey, bool $sourceKeyIsNull = true): ?array
    {
        $sql = "SELECT r.*,
                       t.type_key,
                       t.label AS type_label,
                       t.resolver_key,
                       t.delivery_mode,
                       t.is_active AS type_is_active
                  FROM url_reservations r
                  INNER JOIN url_reservation_types t
                    ON t.id = r.reservation_type_id
                 WHERE t.type_key = 'project_experience'
                   AND r.resource_id = :resource_id
                   AND r.experience_key = :experience_key
                   AND r.is_active = 1
                   AND r.revoked_at IS NULL
                   AND (r.expires_at IS NULL OR r.expires_at >= NOW())";
        if ($sourceKeyIsNull) {
            $sql .= ' AND r.source_key IS NULL';
        }
        $sql .= ' ORDER BY r.created_at DESC, r.id DESC LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':resource_id' => $projectId,
            ':experience_key' => strtolower(trim($experienceKey)),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function revokeActiveProjectExperienceReservations(int $projectId, string $experienceKey, bool $sourceKeyIsNull = true): int
    {
        $sql = "UPDATE url_reservations r
                INNER JOIN url_reservation_types t
                  ON t.id = r.reservation_type_id
                   SET r.is_active = 0,
                       r.revoked_at = COALESCE(r.revoked_at, NOW()),
                       r.updated_at = NOW()
                 WHERE t.type_key = 'project_experience'
                   AND r.resource_id = :resource_id
                   AND r.experience_key = :experience_key
                   AND r.is_active = 1
                   AND r.revoked_at IS NULL";
        if ($sourceKeyIsNull) {
            $sql .= ' AND r.source_key IS NULL';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':resource_id' => $projectId,
            ':experience_key' => strtolower(trim($experienceKey)),
        ]);
        return $stmt->rowCount();
    }

    /**
     * @param int[] $ids
     */
    public function deleteReservationsByIds(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if (!$ids) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM url_reservations WHERE id IN ({$placeholders})");
        $stmt->execute($ids);
        return $stmt->rowCount();
    }

    public function updateMutableFields(int $id, array $fields): void
    {
        $allowed = ['label', 'og_title', 'og_description', 'og_image_url', 'expires_at', 'is_active'];
        $set = [];
        $params = [':id' => $id];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $set[] = "{$key} = :{$key}";
            $params[":{$key}"] = $key === 'is_active' ? (int)(bool)$value : $value;
        }
        if (!$set) {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE url_reservations SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE id = :id');
        $stmt->execute($params);
    }

    public function updateTypeForMaintenance(string $typeKey, array $fields): void
    {
        $allowed = ['resolver_key', 'is_active'];
        $set = [];
        $params = [':type_key' => trim($typeKey)];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $set[] = "{$key} = :{$key}";
            $params[":{$key}"] = $key === 'is_active' ? (int)(bool)$value : $value;
        }
        if (!$set) {
            return;
        }
        $stmt = $this->pdo->prepare('UPDATE url_reservation_types SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE type_key = :type_key');
        $stmt->execute($params);
    }

    public function revoke(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE url_reservations
                SET is_active = 0,
                    revoked_at = COALESCE(revoked_at, NOW()),
                    updated_at = NOW()
              WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() > 0;
    }

    public function isDuplicateTokenError(PDOException $e): bool
    {
        return (string)$e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate entry');
    }
}
