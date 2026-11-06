<?php
declare(strict_types=1);

// CLI-only provisioning/recovery; do not expose this through an API.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../api/autoload.php';

use App\SYSTEM\Repos\PdoAdminUserRepository;
use App\SYSTEM\Services\AdminAuthService;

$options = getopt('', ['email:', 'password-file:']);
$email = strtolower(trim((string)($options['email'] ?? '')));
$path = (string)($options['password-file'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php scripts/system-admin-password.php --email=you@example.com [--password-file=/private/path/password.txt]\n");
    exit(1);
}
try {
    if ($path !== '') {
        if (!is_file($path) || !is_readable($path)) throw new RuntimeException('Cannot read password file.');
        $password = rtrim(file_get_contents($path), "\r\n");
    } else {
        if (!stream_isatty(STDIN)) throw new RuntimeException('Run in an interactive terminal, or supply --password-file.');
        $terminalState = trim((string)shell_exec('stty -g'));
        if ($terminalState === '') throw new RuntimeException('Cannot configure hidden password entry.');
        $unused = [];
        exec('stty -echo', $unused, $status);
        if ($status !== 0) throw new RuntimeException('Cannot hide password entry.');
        register_shutdown_function(static function () use ($terminalState): void {
            shell_exec('stty ' . escapeshellarg($terminalState));
        });
        try {
            fwrite(STDOUT, "Use at least 10 characters; maximum 72 bytes. Typing will be hidden.\n");
            fwrite(STDOUT, 'New admin password: ');
            $password = rtrim((string)fgets(STDIN), "\r\n");
            fwrite(STDOUT, "\n");
            AdminAuthService::validatePassword($password);
            fwrite(STDOUT, 'Confirm password: ');
            $confirmation = rtrim((string)fgets(STDIN), "\r\n");
        } finally {
            shell_exec('stty ' . escapeshellarg($terminalState));
            fwrite(STDOUT, "\n");
        }
        if (!hash_equals($password, $confirmation)) throw new RuntimeException('Passwords do not match. Nothing changed.');
        unset($confirmation);
    }
    AdminAuthService::validatePassword($password);
    $hash = password_hash($password, PASSWORD_DEFAULT);
    require __DIR__ . '/../api/db.php';
    $repo = new PdoAdminUserRepository($pdo);
    if (($existing = $repo->findByEmail($email)) && (int)$existing['is_active'] !== 1) {
        throw new RuntimeException('Account is disabled. Nothing changed.');
    }
    $repo->setPasswordByEmail($email, $hash);
    $saved = $repo->findByEmail($email);
    if (!$saved || !password_verify($password, $saved['password_hash']) || (int)$saved['is_active'] !== 1) {
        throw new RuntimeException('Password verification failed. Do not deploy the login cutover.');
    }
    unset($password, $hash);
    echo "Admin password stored and verified.\n";
    echo "New SYSTEM sessions for this account are revoked; the old live login is unchanged until cutover.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Admin setup failed: " . $e->getMessage() . "\n");
    exit(1);
}
