<?php
declare(strict_types=1);

namespace App\SYSTEM\Http;

final class AdminHttp
{
    public static function respond(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function requireSameOrigin(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
            self::respond(['ok' => false, 'error' => 'Cross-site request refused.'], 403);
        }
        if ($origin !== null) {
            $parts = parse_url($origin);
            $originHost = strtolower((string)($parts['host'] ?? ''))
                . (isset($parts['port']) ? ':' . $parts['port'] : '');
            if ($originHost === '' || $originHost !== $host) {
                self::respond(['ok' => false, 'error' => 'Cross-origin request refused.'], 403);
            }
        }
    }

    public static function input(): array
    {
        if (str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
            $data = json_decode(file_get_contents('php://input') ?: '{}', true);
            if (!is_array($data)) self::respond(['ok' => false, 'error' => 'Invalid JSON.'], 400);
            return $data;
        }
        return $_POST;
    }
}
