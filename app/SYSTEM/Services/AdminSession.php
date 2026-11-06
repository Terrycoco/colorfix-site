<?php
declare(strict_types=1);

namespace App\SYSTEM\Services;

use RuntimeException;

final class AdminSession
{
    public const COOKIE = 'cf_admin_session';
    public const STANDARD_TTL = 8 * 60 * 60;
    public const REMEMBER_TTL = 30 * 24 * 60 * 60;
    // Browsers bound persistent cookies; renew this transport lifetime on use.
    // The server-side SAS device identity has no time-based expiration.
    public const DEVICE_COOKIE_TTL = 400 * 24 * 60 * 60;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (session_name() !== self::COOKIE) throw new RuntimeException('Unexpected session configuration.');
            return;
        }
        // Remembered access must survive host /tmp cleanup and site deployments.
        $namespace = substr(hash('sha256', dirname(__DIR__, 3)), 0, 16);
        $legacyDir = sys_get_temp_dir() . '/colorfix-admin-' . $namespace;
        $dir = getenv('COLORFIX_ADMIN_SESSION_PATH')
            ?: dirname(__DIR__, 5) . '/.colorfix/admin-sessions-' . $namespace;
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to initialize admin sessions.');
        }
        session_save_path($dir);
        session_name(self::COOKIE);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        // Emit cookies only after authentication. Anonymous concurrent requests
        // must not overwrite a remembered login with a fresh session cookie.
        ini_set('session.use_cookies', '0');
        ini_set('session.gc_maxlifetime', (string) self::REMEMBER_TTL);
        // Native GC cannot distinguish an ordinary session from a non-expiring
        // SAS device. This private store enforces ordinary expiry in identity().
        ini_set('session.gc_probability', '0');
        $incoming = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($incoming) && preg_match('/^[a-zA-Z0-9,-]{22,256}$/D', $incoming)) {
            $this->migrateSession($legacyDir, $dir, $incoming);
            session_id($incoming);
        }
        if (!session_start()) throw new RuntimeException('Unable to start admin session.');
    }

    public function establish(array $user, bool $remember, bool $persistentDevice = false): void
    {
        if (!session_regenerate_id(true)) throw new RuntimeException('Unable to renew admin session.');
        $_SESSION = [
            'admin_user_id' => (int)$user['id'],
            'session_version' => (int)$user['session_version'],
            'expires_at' => $persistentDevice ? null : time() + ($remember ? self::REMEMBER_TTL : self::STANDARD_TTL),
            'remember' => $remember || $persistentDevice,
            'persistent_device' => $persistentDevice,
        ];
        setcookie(self::COOKIE, session_id(), $this->cookieOptions($persistentDevice
            ? time() + self::DEVICE_COOKIE_TTL : ($remember ? $_SESSION['expires_at'] : 0)));
        $this->clearLegacyCookies();
    }

    public function identity(): ?array
    {
        if (empty($_SESSION['admin_user_id'])) return null;
        if (empty($_SESSION['persistent_device']) && ($_SESSION['expires_at'] ?? 0) <= time()) return null;
        return $_SESSION;
    }

    public function refreshCookie(): void
    {
        $identity = $this->identity();
        if ($identity && !empty($identity['persistent_device'])) {
            setcookie(self::COOKIE, session_id(), $this->cookieOptions(time() + self::DEVICE_COOKIE_TTL));
        } elseif ($identity && !empty($identity['remember'])) {
            // Preserve the original 30-day deadline, rather than extending it
            // indefinitely or downgrading it to a browser-session cookie.
            setcookie(self::COOKIE, session_id(), $this->cookieOptions((int)$identity['expires_at']));
        }
    }

    public function rememberDevice(): void
    {
        if (!$this->identity()) throw new RuntimeException('A verified session is required.');
        $_SESSION['persistent_device'] = true;
        $_SESSION['expires_at'] = null;
        $_SESSION['remember'] = true;
        $this->refreshCookie();
    }

    public function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    }

    private function migrateSession(string $legacyDir, string $dir, string $id): void
    {
        $old = $legacyDir . '/sess_' . $id;
        $new = $dir . '/sess_' . $id;
        if ($legacyDir === $dir || is_file($new) || !is_file($old) || is_link($old)) return;
        $source = fopen($old, 'r');
        if (!$source) return;
        try {
            if (!flock($source, LOCK_EX)) return;
            clearstatcache(true, $old);
            // Another request may have finished migration while this one waited.
            if (!is_file($old)) return;
            $destination = @fopen($new, 'x');
            if ($destination) {
                chmod($new, 0600);
                try {
                    flock($destination, LOCK_EX);
                    if (stream_copy_to_stream($source, $destination) === false) {
                        throw new RuntimeException('Unable to migrate admin session.');
                    }
                } finally {
                    flock($destination, LOCK_UN);
                    fclose($destination);
                }
                // Do not leave a second copy that could restore a logged-out ID.
                unlink($old);
            }
        } finally {
            flock($source, LOCK_UN);
            fclose($source);
        }
    }

    public function clear(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        setcookie(self::COOKIE, '', $this->cookieOptions(time() - 3600));
        $this->clearLegacyCookies();
    }

    private function cookieOptions(int $expires): array
    {
        return [
            'expires' => $expires, 'path' => '/', 'domain' => '', 'httponly' => true, 'samesite' => 'Lax',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https',
        ];
    }

    private function clearLegacyCookies(): void
    {
        foreach (['cf_admin', 'cf_admin_global', 'cf_device_token'] as $name) {
            $options = $this->cookieOptions(time() - 3600);
            setcookie($name, '', $options);
            if (preg_match('/(?:^|\.)terrymarr\.com$/', (string)($_SERVER['HTTP_HOST'] ?? ''))) {
                $options['domain'] = '.terrymarr.com';
                setcookie($name, '', $options);
            }
        }
    }
}
