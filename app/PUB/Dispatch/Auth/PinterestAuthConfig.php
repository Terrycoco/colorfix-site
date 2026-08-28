<?php
declare(strict_types=1);

namespace App\PUB\Dispatch\Auth;

use App\Lib\EnvLoader;
use RuntimeException;

/**
 * PINTEREST AUTH CONFIG
 *
 * The Dispatch/Auth filing cabinet for Pinterest OAuth configuration.
 * Shipping code does not know these values exist.
 */
final class PinterestAuthConfig
{
    public const CHANNEL_KEY = 'pinterest_colorfix_makeovers';
    public const AUTH_URL = 'https://www.pinterest.com/oauth/';
    public const TOKEN_URL = 'https://api.pinterest.com/v5/oauth/token';
    public const API_BASE = 'https://api.pinterest.com/v5';
    public const REQUEST_TIMEOUT_SECONDS = 30;

    public const SCOPES = [
        'boards:read',
        'boards:write',
        'pins:read',
        'pins:write',
    ];

    public function clientId(): string
    {
        return $this->requiredEnv('PINTEREST_APP_ID');
    }

    public function clientSecret(): string
    {
        return $this->requiredEnv('PINTEREST_APP_SECRET');
    }

    public function redirectUri(): string
    {
        return $this->requiredEnv('PINTEREST_REDIRECT_URI');
    }

    public function apiUrl(string $path): string
    {
        return rtrim(self::API_BASE, '/') . '/' . ltrim(trim($path), '/');
    }

    private function requiredEnv(string $key): string
    {
        $value = trim((string)(EnvLoader::get($key) ?? ''));
        if ($value === '') {
            throw new RuntimeException("Missing required config: {$key}");
        }
        return $value;
    }
}
