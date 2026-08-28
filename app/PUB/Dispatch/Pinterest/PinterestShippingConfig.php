<?php
declare(strict_types=1);

namespace App\PUB\Dispatch\Pinterest;

use App\PUB\Repos\PdoPubChannelConnectionRepository;
use PDO;
use RuntimeException;

/**
 * PINTEREST SHIPPING CONFIG
 *
 * Shipping-station configuration only.
 * OAuth credentials and token decryption live in Dispatch/Auth.
 */
final class PinterestShippingConfig
{
    public const CHANNEL_KEY = 'pinterest_colorfix_makeovers';
    public const API_BASE = 'https://api.pinterest.com/v5';
    public const PINS_PATH = '/pins';
    public const REQUEST_TIMEOUT_SECONDS = 30;
    public const PRODUCTION_DESTINATION_KEY = 'production';

    private PdoPubChannelConnectionRepository $connectionRepo;

    public function __construct(PDO $pdo)
    {
        $this->connectionRepo = new PdoPubChannelConnectionRepository($pdo);
    }

    public function productionBoardId(): string
    {
        $channel = $this->connectionRepo->findChannelByKey(self::CHANNEL_KEY);
        if (!$channel) {
            throw new RuntimeException('Pinterest channel connection is not configured.');
        }

        $metadata = $this->metadata($channel);
        $boardId = trim((string)(
            $metadata['destinations'][self::PRODUCTION_DESTINATION_KEY]['board_id'] ?? ''
        ));

        if ($boardId === '') {
            throw new RuntimeException('Pinterest production board is not configured.');
        }

        return $boardId;
    }

    public function apiUrl(string $path): string
    {
        return rtrim(self::API_BASE, '/') . '/' . ltrim(trim($path), '/');
    }

    /** Safe shipping-station status. No credential inspection happens here. */
    public function status(): array
    {
        $channel = $this->connectionRepo->findChannelByKey(self::CHANNEL_KEY);
        if (!$channel) {
            return [
                'channel_key' => self::CHANNEL_KEY,
                'status' => 'pending_auth',
                'production_destination' => [
                    'board_id' => null,
                    'board_name' => null,
                    'board_url' => null,
                ],
            ];
        }

        $metadata = $this->metadata($channel);
        $board = $metadata['destinations'][self::PRODUCTION_DESTINATION_KEY] ?? [];

        return [
            'channel_key' => $channel['channel_key'] ?? self::CHANNEL_KEY,
            'status' => $channel['status'] ?? 'pending_auth',
            'production_destination' => [
                'board_id' => $board['board_id'] ?? null,
                'board_name' => $board['board_name'] ?? null,
                'board_url' => $board['board_url'] ?? null,
            ],
        ];
    }

    private function metadata(array $row): array
    {
        $value = $row['metadata_json'] ?? [];
        if (is_array($value)) return $value;
        $decoded = json_decode((string)$value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
