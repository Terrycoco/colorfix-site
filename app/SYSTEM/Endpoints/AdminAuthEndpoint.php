<?php
declare(strict_types=1);

namespace App\SYSTEM\Endpoints;

use App\SYSTEM\Http\AdminHttp;
use App\SYSTEM\Services\AdminAuthService;
use App\SYSTEM\Services\AdminLoginThrottle;
use InvalidArgumentException;
use PDO;
use Throwable;

final class AdminAuthEndpoint
{
    public static function handle(PDO $pdo, string $action): never
    {
        try {
            $expectedMethod = in_array($action, ['session', 'device-auth'], true) ? 'GET' : 'POST';
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $expectedMethod) {
                header('Allow: ' . $expectedMethod);
                AdminHttp::respond(['ok' => false, 'error' => $expectedMethod . ' required.'], 405);
            }
            if ($expectedMethod === 'POST') AdminHttp::requireSameOrigin();
            $auth = AdminAuthService::fromPdo($pdo);
            if ($action === 'login') {
                $input = AdminHttp::input();
                if (!(new AdminLoginThrottle())->attempt((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'))) {
                    header('Retry-After: 900');
                    AdminHttp::respond(['success' => false, 'message' => 'Too many attempts. Try again in 15 minutes.'], 429);
                }
                $user = $auth->login(
                    (string)($input['email'] ?? ''), (string)($input['password'] ?? ''),
                    filter_var($input['remember'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    ($input['session_mode'] ?? '') === 'sas'
                );
                if (!$user) AdminHttp::respond(['success' => false, 'message' => 'Invalid credentials.'], 401);
                // The HttpOnly session cookie is the only browser credential.
                AdminHttp::respond(['success' => true, 'user' => $user]);
            }

            $user = $auth->currentUser();
            if (!$user) AdminHttp::respond(['ok' => false, 'error' => 'Unauthorized'], 401);
            if (in_array($action, ['session', 'device-auth', 'device-register'], true)) {
                // Compatibility routes validate the session; client-supplied tokens
                // can no longer create or restore admin access.
                AdminHttp::respond(['ok' => true, 'user' => $user]);
            }
            if ($action === 'change-password') {
                $input = AdminHttp::input();
                if (!$auth->changePassword((string)($input['current_password'] ?? ''), (string)($input['new_password'] ?? ''))) {
                    AdminHttp::respond(['ok' => false, 'error' => 'Current password is incorrect.'], 403);
                }
                AdminHttp::respond(['ok' => true, 'message' => 'Password changed. Other devices signed out.']);
            }
            if (in_array($action, ['logout', 'logout-all'], true)) {
                $auth->logout($action === 'logout-all');
                AdminHttp::respond(['ok' => true]);
            }
            AdminHttp::respond(['ok' => false, 'error' => 'Unknown action.'], 404);
        } catch (InvalidArgumentException $e) {
            AdminHttp::respond(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            error_log('SYSTEM authentication: ' . $e->getMessage());
            AdminHttp::respond(['ok' => false, 'message' => 'Authentication unavailable.', 'error' => 'Authentication unavailable.'], 503);
        }
    }
}
