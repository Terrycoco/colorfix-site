<?php
declare(strict_types=1);

namespace App\PUB\Dispatch\Auth;

use App\Lib\SecretBox;
use App\PUB\Dispatch\ChannelConnectionContract;
use PDO;
use RuntimeException;
use Throwable;

/**
 * YOUTUBE AUTH SERVICE
 *
 * Dispatch's Google / YouTube badge office.
 *
 * Owns every credential/auth concern:
 *   - OAuth client ID/secret + redirect URI
 *   - OAuth authorization URL + scope
 *   - authorization-code exchange
 *   - encryption/decryption of Google tokens
 *   - automatic access-token refresh
 *   - safe auth status
 *   - authenticated provider test
 *   - disconnect/error state
 *
 * Does NOT know how to upload a video, thumbnail, or publish to YouTube.
 */
final class YouTubeAuthService implements ChannelAuthContract, ChannelConnectionContract
{
    private PdoPubChannelAuthRepository $authRepo;
    private SecretBox $secretBox;
    private YouTubeAuthConfig $config;

    public function __construct(PDO $pdo, ?SecretBox $secretBox = null)
    {
        $this->authRepo = new PdoPubChannelAuthRepository($pdo);
        $this->secretBox = $secretBox ?? new SecretBox();
        $this->config = new YouTubeAuthConfig();
    }

    public function channelKey(): string
    {
        return 'youtube';
    }

    /**
     * Badge requested by a YouTube Shipper.
     * Returns a usable access token, refreshing it first when necessary.
     */
    public function validAccessToken(): string
    {
        $channel = $this->authRepo->upsertYouTubeChannel();
        $tokens = $this->decryptTokens($channel);

        $accessToken = trim((string)($tokens['access_token'] ?? ''));
        $refreshToken = trim((string)($tokens['refresh_token'] ?? ''));

        if ($accessToken === '' && $refreshToken === '') {
            throw new RuntimeException('YouTube is not connected.');
        }

        if ($accessToken !== '' && !$this->accessTokenNeedsRefresh($channel)) {
            return $accessToken;
        }

        if ($refreshToken === '') {
            throw new RuntimeException('YouTube access token expired and no refresh token is available. Reconnect YouTube.');
        }

        return $this->refreshAccessToken($channel, $tokens);
    }

    /** Backward-friendly name for connection/admin callers. */
    public function accessToken(): string
    {
        return $this->validAccessToken();
    }

    public function status(): array
    {
        $channel = $this->authRepo->upsertYouTubeChannel();
        $metadata = $this->metadata($channel);

        $badgeStatus = 'connected';
        try {
            $this->validAccessToken();
            $channel = $this->authRepo->upsertYouTubeChannel();
            $metadata = $this->metadata($channel);
        } catch (Throwable $e) {
            $badgeStatus = $e->getMessage();
        }

        return [
            'channel' => [
                'publishing_channel_id' => $channel['publishing_channel_id'] ?? null,
                'channel_key' => $channel['channel_key'] ?? YouTubeAuthConfig::CHANNEL_KEY,
                'label' => $channel['label'] ?? 'YouTube',
                'status' => $channel['status'] ?? 'pending_auth',
                'auth_expires_at' => $channel['auth_expires_at'] ?? null,
                'auth_refreshed_at' => $channel['auth_refreshed_at'] ?? null,
            ],
            'auth' => $metadata['auth'] ?? [
                'status' => $channel['status'] ?? 'pending_auth',
            ],
            'connection' => $badgeStatus,
            'scopes_requested' => YouTubeAuthConfig::SCOPES,
        ];
    }

    public function authorizationUrl(string $state): string
    {
        $state = trim($state);
        if ($state === '') {
            throw new RuntimeException('YouTube authorization requires OAuth state.');
        }

        return YouTubeAuthConfig::AUTH_URL . '?' . http_build_query([
            'client_id' => $this->config->clientId(),
            'redirect_uri' => $this->config->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', YouTubeAuthConfig::SCOPES),
            'state' => $state,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function handleCallback(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new RuntimeException('YouTube OAuth callback did not include a code.');
        }

        $channel = $this->authRepo->upsertYouTubeChannel();
        $existingTokens = $this->decryptTokensQuietly($channel);
        $token = $this->exchangeCode($code);

        $refreshToken = trim((string)($token['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            $refreshToken = trim((string)($existingTokens['refresh_token'] ?? ''));
        }
        if ($refreshToken === '') {
            throw new RuntimeException(
                'Google did not return a refresh token. Reconnect with consent, or remove the app grant from the Google account and try again.'
            );
        }

        $grantedScopes = $this->scopesFromToken($token, $existingTokens);
        $expiresAt = $this->expiresAt($token);
        $metadata = $this->metadata($channel);
        $existingAuth = is_array($metadata['auth'] ?? null) ? $metadata['auth'] : [];

        $metadata['auth'] = array_merge($existingAuth, [
            'status' => 'connected',
            'connected_at' => gmdate('c'),
            'granted_scopes' => $grantedScopes,
            'last_auth_error' => null,
            'last_auth_error_at' => null,
        ]);

        $encrypted = $this->secretBox->encryptJson([
            'access_token' => $token['access_token'] ?? '',
            'refresh_token' => $refreshToken,
            'token_type' => $token['token_type'] ?? 'Bearer',
            'scope' => $token['scope'] ?? implode(' ', $grantedScopes),
            'expires_in' => $token['expires_in'] ?? null,
            'received_at' => gmdate('c'),
        ]);

        $this->authRepo->updateChannelAuth(
            (int)$channel['publishing_channel_id'],
            $encrypted,
            $expiresAt,
            $metadata,
            'connected'
        );

        return [
            'channel' => $this->authRepo->findChannelByKey(YouTubeAuthConfig::CHANNEL_KEY),
            'granted_scopes' => $grantedScopes,
            'expires_at' => $expiresAt,
        ];
    }

    public function testConnection(): array
    {
        $testedAt = gmdate('c');

        try {
            $tokenInfo = $this->tokenInfo($this->validAccessToken());
            $grantedScopes = $this->scopesFromScopeString((string)($tokenInfo['scope'] ?? ''));
            $missingScopes = array_values(array_diff(YouTubeAuthConfig::SCOPES, $grantedScopes));

            if ($missingScopes !== []) {
                throw new RuntimeException(
                    'YouTube authorization is missing required scope(s): ' . implode(', ', $missingScopes)
                );
            }

            return [
                'ok' => true,
                'channel' => 'youtube',
                'tested_at' => $testedAt,
                'message' => 'YouTube connection is working.',
                'provider_response' => [
                    'expires_in' => isset($tokenInfo['expires_in']) ? (int)$tokenInfo['expires_in'] : null,
                    'granted_scopes' => $grantedScopes,
                ],
            ];
        } catch (Throwable $e) {
            $this->markAuthError($e->getMessage());
            throw $e;
        }
    }

    public function disconnect(): void
    {
        $channel = $this->authRepo->upsertYouTubeChannel();
        $metadata = $this->metadata($channel);
        $metadata['auth'] = [
            'status' => 'not_connected',
            'connected_at' => null,
            'granted_scopes' => [],
            'last_auth_error' => null,
            'last_auth_error_at' => null,
            'disconnected_at' => gmdate('c'),
        ];

        $this->authRepo->clearChannelAuth(
            (int)$channel['publishing_channel_id'],
            $metadata,
            'pending_auth'
        );
    }

    public function markAuthError(string $message): void
    {
        $message = trim($message) ?: 'YouTube authorization failed.';
        $channel = $this->authRepo->upsertYouTubeChannel();
        $metadata = $this->metadata($channel);
        $metadata['auth'] = array_merge(
            is_array($metadata['auth'] ?? null) ? $metadata['auth'] : [],
            [
                'status' => 'error',
                'last_auth_error' => $message,
                'last_auth_error_at' => gmdate('c'),
            ]
        );

        $this->authRepo->updateAuthMetadata(
            (int)$channel['publishing_channel_id'],
            $metadata,
            'auth_error'
        );
    }

    private function refreshAccessToken(array $channel, array $existingTokens): string
    {
        $refreshToken = trim((string)($existingTokens['refresh_token'] ?? ''));
        if ($refreshToken === '') {
            throw new RuntimeException('YouTube refresh token is missing. Reconnect YouTube.');
        }

        $token = $this->requestToken([
            'client_id' => $this->config->clientId(),
            'client_secret' => $this->config->clientSecret(),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ], 'refresh');

        $accessToken = trim((string)($token['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new RuntimeException('Google refresh response did not contain an access token.');
        }

        $grantedScopes = $this->scopesFromToken($token, $existingTokens);
        $expiresAt = $this->expiresAt($token);
        $metadata = $this->metadata($channel);
        $existingAuth = is_array($metadata['auth'] ?? null) ? $metadata['auth'] : [];

        $metadata['auth'] = array_merge($existingAuth, [
            'status' => 'connected',
            'granted_scopes' => $grantedScopes,
            'last_auth_error' => null,
            'last_auth_error_at' => null,
            'last_token_refresh_at' => gmdate('c'),
        ]);

        $encrypted = $this->secretBox->encryptJson([
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => $token['token_type'] ?? ($existingTokens['token_type'] ?? 'Bearer'),
            'scope' => $token['scope'] ?? ($existingTokens['scope'] ?? implode(' ', $grantedScopes)),
            'expires_in' => $token['expires_in'] ?? null,
            'received_at' => gmdate('c'),
        ]);

        $this->authRepo->updateChannelAuth(
            (int)$channel['publishing_channel_id'],
            $encrypted,
            $expiresAt,
            $metadata,
            'connected'
        );

        return $accessToken;
    }

    private function exchangeCode(string $code): array
    {
        return $this->requestToken([
            'client_id' => $this->config->clientId(),
            'client_secret' => $this->config->clientSecret(),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->config->redirectUri(),
        ], 'exchange');
    }

    private function requestToken(array $fields, string $operation): array
    {
        $ch = curl_init(YouTubeAuthConfig::TOKEN_URL);
        if ($ch === false) {
            throw new RuntimeException("Could not initialize Google token {$operation}.");
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_TIMEOUT => YouTubeAuthConfig::REQUEST_TIMEOUT_SECONDS,
        ]);

        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("Google token {$operation} failed: {$curlError}");
        }

        $decoded = json_decode((string)$raw, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['access_token'])) {
            $message = is_array($decoded)
                ? json_encode($decoded, JSON_UNESCAPED_SLASHES)
                : (string)$raw;
            throw new RuntimeException("Google token {$operation} failed ({$status}): {$message}");
        }

        return $decoded;
    }

    private function tokenInfo(string $accessToken): array
    {
        $url = YouTubeAuthConfig::TOKEN_INFO_URL . '?' . http_build_query([
            'access_token' => $accessToken,
        ], '', '&', PHP_QUERY_RFC3986);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize Google token test.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_TIMEOUT => YouTubeAuthConfig::REQUEST_TIMEOUT_SECONDS,
        ]);

        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Google token test failed: ' . $curlError);
        }

        $decoded = json_decode((string)$raw, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded) || isset($decoded['error'])) {
            $message = is_array($decoded)
                ? json_encode($decoded, JSON_UNESCAPED_SLASHES)
                : (string)$raw;
            throw new RuntimeException("Google token test failed ({$status}): {$message}");
        }

        return $decoded;
    }

    private function accessTokenNeedsRefresh(array $channel): bool
    {
        $expires = trim((string)($channel['auth_expires_at'] ?? ''));
        if ($expires === '') {
            return true;
        }

        /*
         * auth_expires_at is stored as a UTC timestamp.
         *
         * Never let PHP's application/default timezone reinterpret that
         * timezone-less database value. Convert it explicitly as UTC, then
         * compare epoch seconds.
         */
        try {
            $expiresAt =
                new \DateTimeImmutable(
                    $expires,
                    new \DateTimeZone('UTC')
                );
        } catch (Throwable) {
            return true;
        }

        return $expiresAt->getTimestamp()
            <= (time() + YouTubeAuthConfig::EXPIRY_SAFETY_SECONDS);
    }

    private function decryptTokens(array $channel): array
    {
        try {
            return $this->secretBox->decryptJson(
                $channel['encrypted_auth_payload'] ?? null,
                $channel['auth_nonce'] ?? null,
                $channel['auth_tag'] ?? null
            );
        } catch (Throwable $e) {
            throw new RuntimeException('YouTube authorization could not be read. Reconnect YouTube.', 0, $e);
        }
    }

    private function decryptTokensQuietly(array $channel): array
    {
        try {
            return $this->secretBox->decryptJson(
                $channel['encrypted_auth_payload'] ?? null,
                $channel['auth_nonce'] ?? null,
                $channel['auth_tag'] ?? null
            );
        } catch (Throwable) {
            return [];
        }
    }

    private function expiresAt(array $token): ?string
    {
        $expiresIn = isset($token['expires_in']) ? (int)$token['expires_in'] : 0;
        return $expiresIn > 0 ? gmdate('Y-m-d H:i:s', time() + $expiresIn) : null;
    }

    private function scopesFromToken(array $token, array $fallbackTokens = []): array
    {
        $scope = trim((string)($token['scope'] ?? ''));
        if ($scope === '') {
            $scope = trim((string)($fallbackTokens['scope'] ?? ''));
        }
        if ($scope === '') {
            return YouTubeAuthConfig::SCOPES;
        }
        return $this->scopesFromScopeString($scope);
    }

    private function scopesFromScopeString(string $scope): array
    {
        return array_values(array_filter(preg_split('/\s+/', trim($scope)) ?: []));
    }

    private function metadata(array $row): array
    {
        $value = $row['metadata_json'] ?? [];
        if (is_array($value)) return $value;
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
