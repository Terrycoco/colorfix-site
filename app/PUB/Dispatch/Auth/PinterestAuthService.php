<?php
declare(strict_types=1);

namespace App\PUB\Dispatch\Auth;

use App\Lib\SecretBox;
use PDO;
use RuntimeException;
use Throwable;

/**
 * PINTEREST AUTH SERVICE
 *
 * Dispatch's Pinterest badge office.
 *
 * Owns every credential/auth concern:
 *   - OAuth client ID/secret + redirect URI
 *   - OAuth URLs/scopes
 *   - authorization-code exchange
 *   - encryption/decryption of provider tokens
 *   - access-token expiry checks
 *   - safe auth status
 *   - authenticated badge test
 *   - disconnect/error state
 *
 * Does NOT know how to ship a Pin or which board receives it.
 */
final class PinterestAuthService implements ChannelAuthContract
{
    private PdoPubChannelAuthRepository $authRepo;
    private SecretBox $secretBox;
    private PinterestAuthConfig $config;

    public function __construct(PDO $pdo, ?SecretBox $secretBox = null)
    {
        $this->authRepo = new PdoPubChannelAuthRepository($pdo);
        $this->secretBox = $secretBox ?? new SecretBox();
        $this->config = new PinterestAuthConfig();
    }

    public function channelKey(): string
    {
        return 'pinterest';
    }

    /**
     * Badge requested by a Pinterest Shipper.
     * Returns only a currently usable access token.
     */
    public function validAccessToken(): string
    {
        $channel = $this->authRepo->upsertPinterestChannel();
        $expires = trim((string)($channel['auth_expires_at'] ?? ''));

        if ($expires !== '' && strtotime($expires) !== false && strtotime($expires) <= time()) {
            throw new RuntimeException('Pinterest access token is expired.');
        }

        $tokens = $this->secretBox->decryptJson(
            $channel['encrypted_auth_payload'] ?? null,
            $channel['auth_nonce'] ?? null,
            $channel['auth_tag'] ?? null
        );

        $accessToken = trim((string)($tokens['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new RuntimeException('Pinterest is not connected.');
        }

        return $accessToken;
    }

    /** Backward-friendly name for connection/admin callers. */
    public function accessToken(): string
    {
        return $this->validAccessToken();
    }

    public function status(): array
    {
        $channel = $this->authRepo->upsertPinterestChannel();
        $metadata = $this->metadata($channel);

        $badgeStatus = 'connected';
        try {
            $this->validAccessToken();
        } catch (Throwable $e) {
            $badgeStatus = $e->getMessage();
        }

        return [
            'channel' => [
                'publishing_channel_id' => $channel['publishing_channel_id'] ?? null,
                'channel_key' => $channel['channel_key'] ?? PinterestAuthConfig::CHANNEL_KEY,
                'label' => $channel['label'] ?? 'Pinterest',
                'status' => $channel['status'] ?? 'pending_auth',
                'auth_expires_at' => $channel['auth_expires_at'] ?? null,
                'auth_refreshed_at' => $channel['auth_refreshed_at'] ?? null,
            ],
            'auth' => $metadata['auth'] ?? [
                'status' => $channel['status'] ?? 'pending_auth',
            ],
            'connection' => $badgeStatus,
            'destinations' => $metadata['destinations'] ?? [],
            'scopes_requested' => PinterestAuthConfig::SCOPES,
        ];
    }

    public function authorizationUrl(string $state): string
    {
        $state = trim($state);
        if ($state === '') {
            throw new RuntimeException('Pinterest authorization requires OAuth state.');
        }

        return PinterestAuthConfig::AUTH_URL . '?' . http_build_query([
            'client_id' => $this->config->clientId(),
            'redirect_uri' => $this->config->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', PinterestAuthConfig::SCOPES),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function handleCallback(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new RuntimeException('Pinterest OAuth callback did not include a code.');
        }

        $channel = $this->authRepo->upsertPinterestChannel();
        $token = $this->exchangeCode($code);
        $expiresAt = $this->expiresAt($token);
        $grantedScopes = $this->scopesFromToken($token);
        $metadata = $this->metadata($channel);

        $metadata['auth'] = [
            'status' => 'connected',
            'connected_at' => gmdate('c'),
            'granted_scopes' => $grantedScopes,
            'last_auth_error' => null,
        ];

        $encrypted = $this->secretBox->encryptJson([
            'access_token' => $token['access_token'] ?? '',
            'refresh_token' => $token['refresh_token'] ?? null,
            'token_type' => $token['token_type'] ?? 'bearer',
            'scope' => $token['scope'] ?? implode(',', $grantedScopes),
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
            'channel' => $this->authRepo->findChannelByKey(PinterestAuthConfig::CHANNEL_KEY),
            'granted_scopes' => $grantedScopes,
            'expires_at' => $expiresAt,
        ];
    }

    public function testConnection(): array
    {
        $testedAt = gmdate('c');

        try {
            $response = $this->request('GET', '/boards?page_size=1&privacy=public_and_secret');
            $items = $this->extractBoards($response);

            return [
                'ok' => true,
                'channel' => 'pinterest',
                'tested_at' => $testedAt,
                'message' => 'Pinterest connection is working.',
                'provider_response' => [
                    'items_returned' => count($items),
                ],
            ];
        } catch (Throwable $e) {
            $this->markAuthError($e->getMessage());
            throw $e;
        }
    }

    public function disconnect(): void
    {
        $channel = $this->authRepo->upsertPinterestChannel();
        $metadata = $this->metadata($channel);
        $metadata['auth'] = [
            'status' => 'not_connected',
            'connected_at' => null,
            'granted_scopes' => [],
            'last_auth_error' => null,
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
        $message = trim($message) ?: 'Pinterest authorization failed.';
        $channel = $this->authRepo->upsertPinterestChannel();
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

    private function exchangeCode(string $code): array
    {
        $ch = curl_init(PinterestAuthConfig::TOKEN_URL);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize Pinterest token exchange.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode(
                    $this->config->clientId() . ':' . $this->config->clientSecret()
                ),
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->config->redirectUri(),
            ]),
            CURLOPT_TIMEOUT => PinterestAuthConfig::REQUEST_TIMEOUT_SECONDS,
        ]);

        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Pinterest token exchange failed: ' . $curlError);
        }

        $decoded = json_decode((string)$raw, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['access_token'])) {
            $message = is_array($decoded)
                ? json_encode($decoded, JSON_UNESCAPED_SLASHES)
                : (string)$raw;
            throw new RuntimeException("Pinterest token exchange failed ({$status}): {$message}");
        }

        return $decoded;
    }

    private function request(string $method, string $path): array
    {
        $ch = curl_init($this->config->apiUrl($path));
        if ($ch === false) {
            throw new RuntimeException('Could not initialize Pinterest auth test request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->validAccessToken(),
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => PinterestAuthConfig::REQUEST_TIMEOUT_SECONDS,
        ]);

        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Pinterest API request failed: ' . $curlError);
        }

        $decoded = json_decode((string)$raw, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded)
                ? json_encode($decoded, JSON_UNESCAPED_SLASHES)
                : (string)$raw;
            throw new RuntimeException("Pinterest API error {$status}: {$message}");
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function extractBoards(array $response): array
    {
        if (isset($response['items']) && is_array($response['items'])) return $response['items'];
        if (isset($response['data']) && is_array($response['data'])) return $response['data'];
        return [];
    }

    private function expiresAt(array $token): ?string
    {
        $expiresIn = isset($token['expires_in']) ? (int)$token['expires_in'] : 0;
        return $expiresIn > 0 ? gmdate('Y-m-d H:i:s', time() + $expiresIn) : null;
    }

    private function scopesFromToken(array $token): array
    {
        $scope = trim((string)($token['scope'] ?? ''));
        if ($scope === '') return PinterestAuthConfig::SCOPES;
        return array_values(array_filter(preg_split('/[\s,]+/', $scope) ?: []));
    }

    private function metadata(array $row): array
    {
        $value = $row['metadata_json'] ?? [];
        if (is_array($value)) return $value;
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
