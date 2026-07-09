<?php
declare(strict_types=1);

namespace App\Services;

use App\Lib\EnvLoader;
use App\Lib\SecretBox;
use App\Lib\UrlNormalizer;
use App\Repos\PdoPublisherRepository;
use App\Repos\PdoPublishingRepository;
use App\Services\Publishers\PinterestPublisher;
use RuntimeException;

final class PinterestOAuthService
{
    private const CHANNEL_KEY = 'pinterest_colorfix_makeovers';
    private const AUTH_URL = 'https://www.pinterest.com/oauth/';
    private const TOKEN_URL = 'https://api.pinterest.com/v5/oauth/token';
    private const API_BASE = 'https://api.pinterest.com/v5';
    private const API_SANDBOX_BASE = 'https://api-sandbox.pinterest.com/v5';
    private const SCOPES = ['boards:read', 'boards:write', 'pins:read', 'pins:write'];
    private const ADMIN_RETURN = '/admin/publisher';

    public function __construct(
        private PdoPublisherRepository $publisherRepo,
        private ?PdoPublishingRepository $publishingRepo = null,
        private ?SecretBox $secretBox = null
    ) {
        $this->secretBox = $secretBox ?? new SecretBox();
    }

    public function authorizationUrl(string $state): string
    {
        $clientId = $this->requiredConfig('PINTEREST_APP_ID');
        $redirectUri = $this->redirectUri();

        return self::AUTH_URL . '?' . http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function handleCallback(string $code): array
    {
        $channel = $this->publisherRepo->upsertPinterestChannel();
        $token = $this->exchangeCode($code);
        $expiresAt = $this->expiresAt($token);
        $metadata = $this->metadata($channel);
        $grantedScopes = $this->scopesFromToken($token);
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

        $this->publisherRepo->updateChannelAuth(
            (int)$channel['publishing_channel_id'],
            $encrypted,
            $expiresAt,
            $metadata,
            'connected'
        );

        return [
            'channel' => $this->publisherRepo->findChannelByKey(self::CHANNEL_KEY),
            'granted_scopes' => $grantedScopes,
            'expires_at' => $expiresAt,
        ];
    }

    public function markAuthError(string $message): void
    {
        $channel = $this->publisherRepo->upsertPinterestChannel();
        $metadata = $this->metadata($channel);
        $metadata['auth'] = array_merge($metadata['auth'] ?? [], [
            'status' => 'error',
            'last_auth_error' => $message,
            'last_auth_error_at' => gmdate('c'),
        ]);
        $this->publisherRepo->updateChannelMetadata((int)$channel['publishing_channel_id'], $metadata, 'auth_error');
    }

    public function status(): array
    {
        $channel = $this->publisherRepo->upsertPinterestChannel();
        $metadata = $this->metadata($channel);
        return [
            'channel' => [
                'publishing_channel_id' => $channel['publishing_channel_id'] ?? null,
                'channel_key' => $channel['channel_key'] ?? self::CHANNEL_KEY,
                'label' => $channel['label'] ?? 'Pinterest',
                'status' => $channel['status'] ?? 'pending_auth',
                'auth_expires_at' => $channel['auth_expires_at'] ?? null,
                'auth_refreshed_at' => $channel['auth_refreshed_at'] ?? null,
            ],
            'auth' => $metadata['auth'] ?? ['status' => $channel['status'] ?? 'pending_auth'],
            'destinations' => $metadata['destinations'] ?? [],
            'scopes_requested' => self::SCOPES,
        ];
    }

    public function syncBoards(): array
    {
        $channel = $this->publisherRepo->upsertPinterestChannel();
        $runId = $this->publisherRepo->createSyncRun((int)$channel['publishing_channel_id'], 'pinterest_boards', [
            'endpoints' => [
                '/boards?page_size=100&privacy=public_and_secret',
                '/boards?page_size=100&privacy=secret',
                '/boards?page_size=100&privacy=protected',
            ],
            'match_names' => ['ColorFix API Test', 'ColorFix Makeovers'],
        ]);

        try {
            $responses = [
                'public_and_secret' => $this->apiGet('/boards?page_size=100&privacy=public_and_secret'),
                'secret' => $this->apiGet('/boards?page_size=100&privacy=secret'),
                'protected' => $this->apiGet('/boards?page_size=100&privacy=protected'),
            ];
            $boards = $this->mergeBoards([
                ...$this->extractBoards($responses['public_and_secret']),
                ...$this->extractBoards($responses['secret']),
                ...$this->extractBoards($responses['protected']),
            ]);
            $metadata = $this->metadata($channel);
            $metadata['destinations'] = $metadata['destinations'] ?? [];
            $matches = [];

            foreach ([
                'test' => ['board_name' => 'ColorFix API Test', 'destination_key' => 'colorfix_api_test'],
                'production' => ['board_name' => 'ColorFix Makeovers', 'destination_key' => 'colorfix_makeovers'],
            ] as $environment => $expected) {
                $board = $this->findBoardByName($boards, $expected['board_name']);
                $destination = array_merge($metadata['destinations'][$environment] ?? [], $expected, [
                    'environment' => $environment,
                ]);
                if ($board) {
                    $destination['board_id'] = (string)($board['id'] ?? '');
                    $destination['board_url'] = $this->boardUrl($board);
                    $destination['board_slug'] = $this->boardSlug($board);
                    $destination['last_synced_at'] = gmdate('c');
                }
                $metadata['destinations'][$environment] = $destination;
                $matches[$environment] = [
                    'board_name' => $expected['board_name'],
                    'matched' => (bool)$board,
                    'board_id' => $destination['board_id'] ?? null,
                    'board_url' => $destination['board_url'] ?? null,
                ];
            }

            $metadata['last_board_sync'] = [
                'synced_at' => gmdate('c'),
                'matched' => $matches,
            ];
            $this->publisherRepo->updateChannelMetadata((int)$channel['publishing_channel_id'], $metadata, 'connected');
            $this->publisherRepo->finishSyncRun($runId, 'success', [
                'matched' => $matches,
                'board_count' => count($boards),
                'source_counts' => [
                    'public_and_secret' => count($this->extractBoards($responses['public_and_secret'])),
                    'secret' => count($this->extractBoards($responses['secret'])),
                    'protected' => count($this->extractBoards($responses['protected'])),
                ],
            ]);

            return [
                'ok' => true,
                'matches' => $matches,
                'board_count' => count($boards),
                'source_counts' => [
                    'public_and_secret' => count($this->extractBoards($responses['public_and_secret'])),
                    'secret' => count($this->extractBoards($responses['secret'])),
                    'protected' => count($this->extractBoards($responses['protected'])),
                ],
            ];
        } catch (\Throwable $e) {
            $this->publisherRepo->finishSyncRun($runId, 'failed', null, 'pinterest_api_error', $e->getMessage());
            throw $e;
        }
    }

    public function dryRunForOutput(int $outputId, string $environment = 'test'): array
    {
        $asset = $this->publishingJobForOutput($outputId);
        $channel = $this->publisherRepo->upsertPinterestChannel();
        $metadata = $this->metadata($channel);
        $destination = $metadata['destinations'][$environment] ?? null;
        if (!$destination) {
            throw new RuntimeException("Pinterest destination missing: {$environment}");
        }

        $asset['metadata_json'] = array_merge($this->metadata($asset), $destination, [
            'environment' => $environment,
        ]);
        $payload = (new PinterestPublisher())->buildPayload($asset, $channel);
        $payload = $this->applyEnvironmentPayloadOverrides($payload, $environment);
        $payload['alt_text'] = $asset['alt_text'] ?? null;
        return [
            'environment' => $environment,
            'destination' => $destination,
            'payload' => $payload,
            'readiness' => $this->validatePinPayload($payload, $environment),
        ];
    }

    public function publishTestPin(int $outputId): array
    {
        $preview = $this->dryRunForOutput($outputId, 'test');
        $readiness = $preview['readiness'];
        if (!$readiness['ready']) {
            throw new RuntimeException('Pin payload is not ready: ' . implode('; ', $readiness['errors']));
        }

        $asset = $this->publishingJobForOutput($outputId);
        $response = $this->apiPost('/pins', $preview['payload'], 'test');
        $pinId = (string)($response['id'] ?? '');
        $pinUrl = $this->pinUrl($pinId, $response);
        $metadata = $this->metadata($asset);
        $metadata['package_id'] = (int)($asset['package_id'] ?? $asset['publishing_asset_id'] ?? $outputId);
        $metadata['publish_output_id'] = $outputId;
        $metadata['test_publication'] = [
            'environment' => 'test',
            'board_name' => 'ColorFix API Test',
            'pinterest_pin_id' => $pinId ?: null,
            'pinterest_pin_url' => $pinUrl ?: null,
            'published_at' => gmdate('c'),
            'replaceable' => true,
            'locks_production' => false,
        ];

        $this->publisherRepo->createAttempt([
            'package_batch_id' => (int)($asset['package_batch_id'] ?? $asset['publishing_job_id'] ?? 0),
            'package_id' => (int)($asset['package_id'] ?? $asset['publishing_asset_id'] ?? $outputId),
            'publishing_channel_id' => $asset['publishing_channel_id'] ?? null,
            'platform' => 'pinterest',
            'environment' => 'test',
            'publisher_service' => 'PinterestPublisher',
            'status' => 'test_published',
            'request_payload_json' => $preview['payload'],
            'response_payload_json' => $response,
            'external_id' => $pinId,
            'external_url' => $pinUrl,
        ]);
        $this->publisherRepo->updatePublishingJobTestPublication((int)($asset['package_batch_id'] ?? $asset['publishing_job_id'] ?? 0), $metadata, $pinId, $pinUrl);

        return [
            'ok' => true,
            'pinterest_pin_id' => $pinId,
            'pinterest_pin_url' => $pinUrl,
            'response' => $response,
        ];
    }

    public function publishPublishingJob(array $asset, array $channel): array
    {
        $environment = (string)($asset['environment'] ?? 'test');
        $channelMetadata = $this->metadata($channel);
        $destination = $channelMetadata['destinations'][$environment] ?? null;
        if (!$destination) {
            throw new RuntimeException("Pinterest destination missing: {$environment}");
        }

        $assetMetadata = array_merge($this->metadata($asset), $destination, [
            'environment' => $environment,
        ]);
        $assetForPayload = array_merge($asset, [
            'metadata_json' => $assetMetadata,
            'image_url' => $asset['media_url'] ?? $asset['image_url'] ?? '',
            'destination_url' => UrlNormalizer::absoluteOrEmpty((string)($asset['tracked_destination_url'] ?? $asset['destination_url'] ?? '')),
        ]);

        $payload = (new PinterestPublisher())->buildPayload($assetForPayload, $channel);
        $payload = $this->applyEnvironmentPayloadOverrides($payload, $environment);
        if (!empty($asset['alt_text'])) {
            $payload['alt_text'] = $asset['alt_text'];
        }

        $readiness = $this->validatePinPayload($payload, $environment);
        if (!$readiness['ready']) {
            throw new RuntimeException('Pin payload is not ready: ' . implode('; ', $readiness['errors']));
        }

        $response = $this->apiPost('/pins', $payload, $environment);
        $pinId = (string)($response['id'] ?? '');
        $pinUrl = $this->pinUrl($pinId, $response);

        return [
            'success' => true,
            'platform' => 'pinterest',
            'environment' => $environment,
            'platform_post_id' => $pinId,
            'published_url' => $pinUrl,
            'published_at' => gmdate('Y-m-d H:i:s'),
            'retryable' => false,
            'error_type' => null,
            'error_code' => null,
            'error_message' => null,
            'request_payload' => $payload,
            'response_summary' => $response,
            'metadata_json' => array_merge($assetMetadata, [
                $environment . '_publication' => [
                    'environment' => $environment,
                    'board_name' => $destination['board_name'] ?? null,
                    'pinterest_pin_id' => $pinId ?: null,
                    'pinterest_pin_url' => $pinUrl ?: null,
                    'published_at' => gmdate('c'),
                    'locks_production' => $environment === 'production',
                ],
            ]),
        ];
    }

    private function publishingJobForOutput(int $outputId): array
    {
        if (!$this->publishingRepo) {
            throw new RuntimeException('Publishing repository unavailable.');
        }
        $output = $this->publishingRepo->getOutputForPublisher($outputId);
        if (!$output) {
            throw new RuntimeException("Publish output not found: {$outputId}");
        }
        $asset = $this->publisherRepo->findPublishingJobByPublishOutputId($outputId);
        if (!$asset) {
            throw new RuntimeException("Publishing asset not found: {$outputId}");
        }

        return array_merge($asset, [
            'title' => $asset['title'] ?: ($output['title'] ?? ''),
            'description' => $asset['description'] ?: ($output['description'] ?? ''),
            'image_url' => $asset['image_url'] ?: UrlNormalizer::absoluteOrEmpty((string)($output['asset_rel_path'] ?? $output['asset_path'] ?? '')),
            'destination_url' => $this->trackedDestinationUrl($asset, $output),
            'alt_text' => $output['alt_text'] ?? null,
        ]);
    }

    private function validatePinPayload(array $payload, string $environment): array
    {
        $errors = [];
        if (trim((string)($payload['board_id'] ?? '')) === '') $errors[] = 'Missing Pinterest board ID.';
        if (trim((string)($payload['title'] ?? '')) === '') $errors[] = 'Missing title.';
        if (trim((string)($payload['description'] ?? '')) === '') $errors[] = 'Missing description.';
        if (trim((string)($payload['link'] ?? '')) === '') $errors[] = 'Missing destination link.';
        if (trim((string)($payload['link'] ?? '')) !== '' && !preg_match('/^https?:\/\//i', (string)$payload['link'])) {
            $errors[] = 'Destination link must be an absolute URL.';
        }
        if (trim((string)($payload['media_source']['url'] ?? '')) === '') $errors[] = 'Missing public image URL.';
        return ['ready' => !$errors, 'errors' => $errors];
    }

    private function pinUrl(string $pinId, array $response): string
    {
        $pinId = trim($pinId);
        if ($pinId !== '') {
            return 'https://www.pinterest.com/pin/' . rawurlencode($pinId) . '/';
        }

        foreach (['url', 'pin_url', 'pinterest_url'] as $key) {
            $value = trim((string)($response[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function apiGet(string $path): array
    {
        return $this->request('GET', $path);
    }

    private function apiPost(string $path, array $payload, string $environment = 'production'): array
    {
        return $this->request('POST', $path, $payload, $environment);
    }

    private function request(string $method, string $path, ?array $payload = null, string $environment = 'production'): array
    {
        $token = $this->accessToken($environment);
        $baseUrl = $environment === 'test' ? self::API_SANDBOX_BASE : self::API_BASE;
        $ch = curl_init($baseUrl . $path);
        $headers = [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Pinterest API request failed: ' . $err);
        }
        $decoded = json_decode((string)$raw, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_SLASHES) : (string)$raw;
            throw new RuntimeException("Pinterest API error {$status} ({$baseUrl}): {$message}");
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function exchangeCode(string $code): array
    {
        $clientId = $this->requiredConfig('PINTEREST_APP_ID');
        $clientSecret = $this->requiredConfig('PINTEREST_APP_SECRET');
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
            ]),
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Pinterest token exchange failed: ' . $err);
        }
        $decoded = json_decode((string)$raw, true);
        if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['access_token'])) {
            $message = is_array($decoded) ? json_encode($decoded, JSON_UNESCAPED_SLASHES) : (string)$raw;
            throw new RuntimeException("Pinterest token exchange failed ({$status}): {$message}");
        }
        return $decoded;
    }

    private function accessToken(string $environment = 'production'): string
    {
        if ($environment === 'test') {
            $sandboxToken = trim((string)(EnvLoader::get('PINTEREST_SANDBOX_ACCESS_TOKEN') ?? ''));
            if ($sandboxToken === '') {
                throw new RuntimeException('Pinterest sandbox publish requires PINTEREST_SANDBOX_ACCESS_TOKEN. Production OAuth tokens do not authenticate against api-sandbox.pinterest.com.');
            }
            return $sandboxToken;
        }

        $channel = $this->publisherRepo->upsertPinterestChannel();
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

    private function applyEnvironmentPayloadOverrides(array $payload, string $environment): array
    {
        if ($environment !== 'test') {
            return $payload;
        }

        $sandboxBoardId = trim((string)(EnvLoader::get('PINTEREST_SANDBOX_BOARD_ID') ?? ''));
        if ($sandboxBoardId !== '') {
            $payload['board_id'] = $sandboxBoardId;
        }

        return $payload;
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
        return $this->requiredConfig('PINTEREST_REDIRECT_URI');
    }

    private function expiresAt(array $token): ?string
    {
        $expiresIn = isset($token['expires_in']) ? (int)$token['expires_in'] : 0;
        return $expiresIn > 0 ? gmdate('Y-m-d H:i:s', time() + $expiresIn) : null;
    }

    private function scopesFromToken(array $token): array
    {
        $scope = (string)($token['scope'] ?? '');
        if ($scope === '') return self::SCOPES;
        return array_values(array_filter(preg_split('/[\s,]+/', $scope) ?: []));
    }

    private function metadata(array $row): array
    {
        $value = $row['metadata_json'] ?? [];
        if (is_array($value)) return $value;
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function extractBoards(array $response): array
    {
        if (isset($response['items']) && is_array($response['items'])) return $response['items'];
        if (isset($response['data']) && is_array($response['data'])) return $response['data'];
        return [];
    }

    private function mergeBoards(array $boards): array
    {
        $merged = [];
        foreach ($boards as $board) {
            $id = trim((string)($board['id'] ?? ''));
            $key = $id !== '' ? $id : strtolower((string)($board['name'] ?? ''));
            if ($key === '') {
                continue;
            }
            $merged[$key] = $board;
        }
        return array_values($merged);
    }

    private function findBoardByName(array $boards, string $name): ?array
    {
        foreach ($boards as $board) {
            if ((string)($board['name'] ?? '') === $name) return $board;
        }
        return null;
    }

    private function boardUrl(array $board): ?string
    {
        foreach (['url', 'link'] as $key) {
            $value = trim((string)($board[$key] ?? ''));
            if ($value !== '') return $value;
        }
        return null;
    }

    private function boardSlug(array $board): ?string
    {
        $owner = trim((string)($board['owner']['username'] ?? $board['owner']['username'] ?? ''));
        $name = trim((string)($board['name'] ?? ''));
        if ($owner !== '' && $name !== '') {
            return $owner . '/' . strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
        }
        return null;
    }

    private function trackedDestinationUrl(array $asset, array $output): string
    {
        $url = UrlNormalizer::absoluteOrEmpty((string)($asset['destination_url'] ?: ($output['destination_url'] ?? '')));
        if ($url === '') return '';
        $publishingJobId = (int)($asset['package_batch_id'] ?? $asset['publishing_job_id'] ?? 0);
        if ($publishingJobId <= 0) return $url;
        return UrlNormalizer::appendQueryParam($url, 'job', (string)$publishingJobId);
    }
}
