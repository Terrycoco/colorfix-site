<?php
declare(strict_types=1);

namespace App\SYSTEM\Services;

use App\SYSTEM\Repos\PdoAdminUserRepository;
use InvalidArgumentException;
use PDO;

final class AdminAuthService
{
    public function __construct(private PdoAdminUserRepository $users, private AdminSession $session) {}

    public static function fromPdo(PDO $pdo): self
    {
        return new self(new PdoAdminUserRepository($pdo), new AdminSession());
    }

    public function login(string $email, string $password, bool $remember, bool $persistentDevice = false): ?array
    {
        $user = $this->users->findByEmail($email);
        // Still perform a password check for unknown accounts.
        $hash = $user['password_hash'] ?? password_hash('unknown-admin-account', PASSWORD_DEFAULT);
        if (!password_verify($password, $hash) || !$user || (int)$user['is_active'] !== 1) return null;
        $this->session->establish($user, $remember, $persistentDevice);
        return $this->publicUser($user);
    }

    public function currentUser(bool $releaseSession = false): ?array
    {
        try {
            $identity = $this->session->identity();
            if (!$identity) return null;
            $user = $this->users->findById((int)$identity['admin_user_id']);
            if (!$user || (int)$user['is_active'] !== 1
                || (int)$user['session_version'] !== (int)$identity['session_version']) {
                $this->session->clear();
                return null;
            }
            $this->session->refreshCookie();
            return $this->publicUser($user);
        } finally {
            // Read-only authentication must not serialize unrelated API work
            // behind the same browser's PHP session-file lock.
            if ($releaseSession) $this->session->close();
        }
    }

    public function rememberDevice(): bool
    {
        try {
            if (!$this->currentUser()) return false;
            $this->session->rememberDevice();
            return true;
        } finally {
            $this->session->close();
        }
    }

    public function changePassword(string $current, string $replacement): bool
    {
        $public = $this->currentUser();
        if (!$public) return false;
        $user = $this->users->findById($public['id']);
        if (!$user || !password_verify($current, $user['password_hash'])) return false;
        self::validatePassword($replacement);
        $remember = (bool)($this->session->identity()['remember'] ?? false);
        $persistentDevice = (bool)($this->session->identity()['persistent_device'] ?? false);
        $this->users->replacePassword((int)$user['id'], (int)$user['session_version'], password_hash($replacement, PASSWORD_DEFAULT));
        $this->session->establish($this->users->findById((int)$user['id']), $remember, $persistentDevice);
        return true;
    }

    public function logout(bool $allDevices = false): void
    {
        if ($allDevices && ($identity = $this->session->identity()) && $this->currentUser()) {
            $this->users->revokeAll((int)$identity['admin_user_id'], (int)$identity['session_version']);
        }
        $this->session->clear();
    }

    public static function validatePassword(string $password): void
    {
        $characters = preg_match_all('/./us', $password);
        if ($characters === false) throw new InvalidArgumentException('Password must be valid UTF-8 text.');
        if ($characters < 10) throw new InvalidArgumentException('Password is too short. Use at least 10 characters.');
        if (strlen($password) > 72) throw new InvalidArgumentException('Password is too long. Use at most 72 bytes (72 characters with ordinary letters, numbers and symbols).');
    }

    private function publicUser(array $user): array
    {
        return ['id' => (int)$user['id'], 'email' => $user['email'], 'is_admin' => true];
    }
}
