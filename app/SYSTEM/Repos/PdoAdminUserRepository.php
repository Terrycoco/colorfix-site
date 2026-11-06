<?php
declare(strict_types=1);

namespace App\SYSTEM\Repos;

use PDO;
use RuntimeException;

final class PdoAdminUserRepository
{
    public function __construct(private PDO $pdo) {}

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM admin_users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => strtolower(trim($email))]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM admin_users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function replacePassword(int $id, int $version, string $hash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE admin_users SET password_hash = :hash, session_version = session_version + 1 '
            . 'WHERE id = :id AND session_version = :version AND is_active = 1'
        );
        $stmt->execute(['hash' => $hash, 'id' => $id, 'version' => $version]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Account changed; sign in again.');
    }

    public function revokeAll(int $id, int $version): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE admin_users SET session_version = session_version + 1 WHERE id = :id AND session_version = :version'
        );
        $stmt->execute(['id' => $id, 'version' => $version]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Account changed; sign in again.');
    }

    /** CLI recovery/provisioning only; web requests cannot create admin accounts. */
    public function setPasswordByEmail(string $email, string $hash): void
    {
        $user = $this->findByEmail($email);
        if ($user) {
            // A reset must not silently reactivate a disabled account.
            $stmt = $this->pdo->prepare(
                'UPDATE admin_users SET password_hash = :hash, session_version = session_version + 1 WHERE id = :id'
            );
            $stmt->execute(['hash' => $hash, 'id' => $user['id']]);
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO admin_users (email, password_hash) VALUES (:email, :hash)');
            $stmt->execute(['email' => strtolower(trim($email)), 'hash' => $hash]);
        }
    }
}
