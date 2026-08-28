<?php
declare(strict_types=1);

namespace App\PUB\Dispatch\Auth;

use App\Lib\EnvLoader;
use RuntimeException;

/**
 * YOUTUBE AUTH CONFIG
 *
 * Dispatch/Auth's filing cabinet for Google / YouTube OAuth configuration.
 * Shipping code never reads OAuth client credentials directly.
 */
final class YouTubeAuthConfig
{
    public const CHANNEL_KEY = 'youtube_colorfix';
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const TOKEN_INFO_URL = 'https://oauth2.googleapis.com/tokeninfo';
    public const API_BASE = 'https://www.googleapis.com/youtube/v3';
    public const REQUEST_TIMEOUT_SECONDS = 30;
    public const EXPIRY_SAFETY_SECONDS = 120;

    public const SCOPES = [
        'https://www.googleapis.com/auth/youtube.upload',
    ];

    public function clientId(): string
    {
        return $this->requiredEnv('GOOGLE_YOUTUBE_CLIENT_ID');
    }

    public function clientSecret(): string
    {
        return $this->requiredEnv('GOOGLE_YOUTUBE_CLIENT_SECRET');
    }

    public function redirectUri(): string
    {
        return $this->requiredEnv('GOOGLE_YOUTUBE_REDIRECT_URI');
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
