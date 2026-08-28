<?php
declare(strict_types=1);

namespace App\PUB\Dispatch\Pinterest;

use App\Lib\SecretBox;
use App\PUB\Dispatch\Auth\PinterestAuthService;
use App\PUB\Dispatch\ChannelConnectionContract;
use App\PUB\Repos\PdoPubChannelConnectionRepository;
use PDO;
use RuntimeException;
use Throwable;

/**
 * PINTEREST CONNECTION SERVICE
 *
 * Compatibility/front-desk service for existing admin endpoints.
 * All OAuth/badge work is delegated to Dispatch/Auth.
 * This class retains only Pinterest destination/board synchronization.
 */
final class PinterestConnectionService implements ChannelConnectionContract
{
    private PinterestAuthService $auth;
    private PinterestShippingConfig $config;
    private PdoPubChannelConnectionRepository $connectionRepo;

    public function __construct(PDO $pdo, ?SecretBox $secretBox = null)
    {
        $this->auth = new PinterestAuthService($pdo, $secretBox);
        $this->config = new PinterestShippingConfig($pdo);
        $this->connectionRepo = new PdoPubChannelConnectionRepository($pdo);
    }

    public function channelKey(): string
    {
        return $this->auth->channelKey();
    }

    public function status(): array
    {
        return $this->auth->status();
    }

    public function authorizationUrl(string $state): string
    {
        return $this->auth->authorizationUrl($state);
    }

    public function handleCallback(string $code): array
    {
        return $this->auth->handleCallback($code);
    }

    public function testConnection(): array
    {
        return $this->auth->testConnection();
    }

    public function disconnect(): void
    {
        $this->auth->disconnect();
    }

    public function markAuthError(string $message): void
    {
        $this->auth->markAuthError($message);
    }

    /**
     * Destination synchronization is not authentication, so it stays
     * with the Pinterest specialist instead of moving into Auth.
     */
    public function syncBoards(): array
    {
        $channel = $this->connectionRepo->findChannelByKey(PinterestShippingConfig::CHANNEL_KEY);
        if (!$channel) {
            throw new RuntimeException('Pinterest channel connection is not configured.');
        }

        $runId = $this->connectionRepo->createSyncRun(
            (int)$channel['publishing_channel_id'],
            'pinterest_boards',
            [
                'endpoints' => [
                    '/boards?page_size=100&privacy=public_and_secret',
                    '/boards?page_size=100&privacy=secret',
                    '/boards?page_size=100&privacy=protected',
                ],
                'match_names' => [
                    'ColorFix API Test',
                    'ColorFix by Terry',
                ],
            ]
        );

        try {
            $responses = [
                'public_and_secret' => $this->request(
                    'GET',
                    '/boards?page_size=100&privacy=public_and_secret'
                ),
                'secret' => $this->request(
                    'GET',
                    '/boards?page_size=100&privacy=secret'
                ),
                'protected' => $this->request(
                    'GET',
                    '/boards?page_size=100&privacy=protected'
                ),
            ];

            $boards = $this->mergeBoards([
                ...$this->extractBoards($responses['public_and_secret']),
                ...$this->extractBoards($responses['secret']),
                ...$this->extractBoards($responses['protected']),
            ]);

            $metadata = $this->metadata($channel);
            $metadata['destinations'] = is_array($metadata['destinations'] ?? null)
                ? $metadata['destinations']
                : [];

            $matches = [];
            foreach ([
                'test' => [
                    'board_name' => 'ColorFix API Test',
                    'destination_key' => 'colorfix_api_test',
                ],
                'production' => [
                    'board_name' => 'ColorFix by Terry',
                    'destination_key' => 'colorfix_makeovers',
                ],
            ] as $environment => $expected) {
                $board = $this->findBoardByName($boards, $expected['board_name']);
                $destination = array_merge(
                    is_array($metadata['destinations'][$environment] ?? null)
                        ? $metadata['destinations'][$environment]
                        : [],
                    $expected,
                    ['environment' => $environment]
                );

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

            $sourceCounts = [
                'public_and_secret' => count($this->extractBoards($responses['public_and_secret'])),
                'secret' => count($this->extractBoards($responses['secret'])),
                'protected' => count($this->extractBoards($responses['protected'])),
            ];

            $metadata['last_board_sync'] = [
                'synced_at' => gmdate('c'),
                'matched' => $matches,
            ];

            $this->connectionRepo->updateChannelMetadata(
                (int)$channel['publishing_channel_id'],
                $metadata,
                'connected'
            );

            $this->connectionRepo->finishSyncRun(
                $runId,
                'success',
                [
                    'matched' => $matches,
                    'board_count' => count($boards),
                    'source_counts' => $sourceCounts,
                ]
            );

            return [
                'ok' => true,
                'matches' => $matches,
                'board_count' => count($boards),
                'source_counts' => $sourceCounts,
            ];
        } catch (Throwable $e) {
            $this->connectionRepo->finishSyncRun(
                $runId,
                'failed',
                null,
                'pinterest_api_error',
                $e->getMessage()
            );
            throw $e;
        }
    }

    private function request(string $method, string $path): array
    {
        $ch = curl_init($this->config->apiUrl($path));
        if ($ch === false) {
            throw new RuntimeException('Could not initialize Pinterest connection request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->auth->validAccessToken(),
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => PinterestShippingConfig::REQUEST_TIMEOUT_SECONDS,
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
            $key = $id !== '' ? $id : strtolower(trim((string)($board['name'] ?? '')));
            if ($key !== '') $merged[$key] = $board;
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
        $owner = trim((string)($board['owner']['username'] ?? ''));
        $name = trim((string)($board['name'] ?? ''));
        if ($owner === '' || $name === '') return null;

        return $owner . '/' . strtolower(trim(
            (string)preg_replace('/[^a-z0-9]+/i', '-', $name),
            '-'
        ));
    }
}
