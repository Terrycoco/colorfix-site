<?php
declare(strict_types=1);

namespace App\Services;

use App\Lib\EnvLoader;
use App\Lib\SecretBox;
use App\Repos\PdoPublisherRepository;
use RuntimeException;

final class YouTubeOAuthService
{
    private const CHANNEL_KEY = 'youtube_colorfix';
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPES = ['https://www.googleapis.com/auth/youtube.upload'];

    public function __construct(
        private PdoPublisherRepository $publisherRepo,
        private ?SecretBox $secretBox = null
    ) {
        $this->secretBox = $secretBox ?? new SecretBox();
    }

    public function authorizationUrl(string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $this->requiredConfig('GOOGLE_YOUTUBE_CLIENT_ID'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function handleCallback(string $code): array
    {
        $channel = $this->publisherRepo->upsertYouTubeChannel();
        $token = $this->exchangeCode($code);
        $grantedScopes = $this->scopesFromToken($token);
        $existingTokens = $this->existingTokens($channel);
        $refreshToken = trim((string)($token['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            $refreshToken = trim((string)($existingTokens['refresh_token'] ?? ''));
        }
        if ($refreshToken === '') {
            throw new RuntimeException('Google did not return a refresh token. Reconnect with consent, or remove the app grant from the Google account and try again.');
        }

        $metadata = $this->metadata($channel);
        $metadata['auth'] = [
            'status' => 'connected',
            'connected_at' => gmdate('c'),
            'granted_scopes' => $grantedScopes,
            'last_auth_error' => null,
            'last_auth_error_at' => null,
        ];

        $encrypted = $this->secretBox->encryptJson([
            'access_token' => $token['access_token'] ?? '',
            'refresh_token' => $refreshToken,
            'token_type' => $token['token_type'] ?? 'Bearer',
            'scope' => $token['scope'] ?? implode(' ', $grantedScopes),
            'expires_in' => $token['expires_in'] ?? null,
            'received_at' => gmdate('c'),
        ]);

        $this->publisherRepo->updateChannelAuth(
            (int)$channel['publishing_channel_id'],
            $encrypted,
            $this->expiresAt($token),
            $metadata,
            'connected'
        );

        error_log('YouTube OAuth connected channel ' . self::CHANNEL_KEY . ' scopes=' . implode(',', $grantedScopes));

        return [
            'channel' => $this->publisherRepo->findChannelByKey(self::CHANNEL_KEY),
            'granted_scopes' => $grantedScopes,
            'expires_at' => $this->expiresAt($token),
        ];
    }

    public function markAuthError(string $message): void
    {
        $channel = $this->publisherRepo->upsertYouTubeChannel();
        $metadata = $this->metadata($channel);
        $metadata['auth'] = array_merge($metadata['auth'] ?? [], [
            'status' => 'error',
            'last_auth_error' => $message,
            'last_auth_error_at' => gmdate('c'),
        ]);
        $this->publisherRepo->updateChannelMetadata((int)$channel['publishing_channel_id'], $metadata, 'auth_error');
        error_log('YouTube OAuth error: ' . $message);
    }

    public function status(): array
    {
        $channel = $this->publisherRepo->upsertYouTubeChannel();
        $metadata = $this->metadata($channel);
        $auth = $metadata['auth'] ?? [];
        return [
            'channel' => [
                'publishing_channel_id' => $channel['publishing_channel_id'] ?? null,
                'channel_key' => $channel['channel_key'] ?? self::CHANNEL_KEY,
                'label' => $channel['label'] ?? 'ColorFix YouTube',
                'platform' => $channel['platform'] ?? 'youtube',
                'status' => $channel['status'] ?? 'pending_auth',
                'auth_expires_at' => $channel['auth_expires_at'] ?? null,
                'auth_refreshed_at' => $channel['auth_refreshed_at'] ?? null,
            ],
            'auth' => [
                'status' => $auth['status'] ?? ($channel['status'] === 'connected' ? 'connected' : 'not_connected'),
                'connected_at' => $auth['connected_at'] ?? null,
                'granted_scopes' => $auth['granted_scopes'] ?? [],
                'last_auth_error' => $auth['last_auth_error'] ?? null,
                'last_auth_error_at' => $auth['last_auth_error_at'] ?? null,
            ],
            'scopes_requested' => self::SCOPES,
        ];
    }

    private function exchangeCode(string $code): array
    {
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $this->requiredConfig('GOOGLE_YOUTUBE_CLIENT_ID'),
                'client_secret' => $this->requiredConfig('GOOGLE_YOUTUBE_CLIENT_SECRET'),
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirectUri(),
            ]),
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Google token exchange failed: ' . $err);
        }
        $decoded = json_decode((string)$raw, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['access_token'])) {
            $message = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_SLASHES) : (string)$raw;
            throw new RuntimeException("Google token exchange failed ({$status}): {$message}");
        }
        return $decoded;
    }

    private function existingTokens(array $channel): array
    {
        try {
            return $this->secretBox->decryptJson(
                $channel['encrypted_auth_payload'] ?? null,
                $channel['auth_nonce'] ?? null,
                $channel['auth_tag'] ?? null
            );
        } catch (\Throwable) {
            return [];
        }
    }

    private function requiredConfig(string $key): string
    {
        $value = trim((string)(EnvLoader::get($key) ?? ''));
        if ($value === '') {
            throw new RuntimeException("Missing required config: {$key}");
        }
        return $value;
    }

    private function redirectUri(): string
    {
        return $this->requiredConfig('GOOGLE_YOUTUBE_REDIRECT_URI');
    }

    private function expiresAt(array $token): ?string
    {
        $expiresIn = isset($token['expires_in']) ? (int)$token['expires_in'] : 0;
        return $expiresIn > 0 ? gmdate('Y-m-d H:i:s', time() + $expiresIn) : null;
    }

    private function scopesFromToken(array $token): array
    {
        $scope = trim((string)($token['scope'] ?? ''));
        if ($scope === '') return self::SCOPES;
        return array_values(array_filter(preg_split('/\s+/', $scope) ?: []));
    }

    private function metadata(array $row): array
    {
        $value = $row['metadata_json'] ?? [];
        if (is_array($value)) return $value;
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
