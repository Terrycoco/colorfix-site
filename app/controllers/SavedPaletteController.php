<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\SavedPaletteService;
use InvalidArgumentException;

class SavedPaletteController
{
    private SavedPaletteService $service;

    public function __construct(SavedPaletteService $service)
    {
        $this->service = $service;
    }

    /**
     * Handle "save palette" from a generic payload (typically JSON from POST).
     *
     * Expected keys:
     *  - brand         (string, required)
     *  - color_ids     (array<int>|array<array{color_id:int,order_index?:int}>, required)
     *  - nickname      (string|null)
     *  - notes         (string|null)
     *  - terry_fav     (bool|int|null)
     *  - palette_id    (int|null) if provided, overwrites existing palette
     *
     * Returns:
     *  [
     *    'palette' => [...],
     *    'members' => [...],
     *  ]
     *
     * Throws InvalidArgumentException on bad input.
     */
    public function saveFromPayload(array $payload): array
    {
        $paletteId = isset($payload['palette_id']) ? (int)$payload['palette_id'] : 0;
        $brand = isset($payload['brand']) ? trim((string)$payload['brand']) : '';
        if ($brand === '') {
            throw new InvalidArgumentException('brand is required');
        }

        if (empty($payload['color_ids']) || !is_array($payload['color_ids'])) {
            throw new InvalidArgumentException('color_ids must be a non-empty array');
        }

        $data = [
            'brand'         => $brand,
            'color_ids'     => $payload['color_ids'],
            'nickname'      => isset($payload['nickname']) ? (string)$payload['nickname'] : null,
            'notes'         => isset($payload['notes']) ? (string)$payload['notes'] : null,
            'private_notes' => isset($payload['private_notes']) ? (string)$payload['private_notes'] : null,
            'terry_fav'     => isset($payload['terry_fav']) ? (bool)$payload['terry_fav'] : false,
            'kicker_id'     => isset($payload['kicker_id']) && $payload['kicker_id'] !== ''
                ? (int)$payload['kicker_id']
                : null,
            'palette_type'  => isset($payload['palette_type']) ? (string)$payload['palette_type'] : null,
        ];

        if ($paletteId > 0) {
            return $this->service->overwriteSavedPalette($paletteId, $data);
        }

        return $this->service->createSavedPalette($data);
    }

    /**
     * Get a saved palette by id.
     *
     * If $withStats is true, includes view stats:
     *  - total_views
     *  - total_client_views
     *  - first_view
     *  - last_view
     *
     * Returns null if not found.
     */
    public function getById(int $id, bool $withStats = false): ?array
    {
        if ($withStats) {
            return $this->service->getSavedPaletteWithStats($id);
        }

        return $this->service->getSavedPalette($id);
    }

    /**
     * List saved palettes, with optional filters.
     *
     * $filters:
     *  - brand (string)
     *  - terry_fav (bool|int)
     *
     * Returns an array of saved_palettes rows.
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->service->listSavedPalettes($filters, $limit, $offset);
    }

    /**
     * Toggle Terry's favorite flag for a palette.
     */
    public function setFavorite(int $id, bool $fav): void
    {
        $this->service->setFavorite($id, $fav);
    }

    /**
     * Record a view of a saved palette.
     *
     * $viewerEmail may be null (anonymous).
     * $isOwner should be true when *you* (Terry/admin) are viewing,
     * so those can be excluded from client stats.
     */
    public function recordView(
        int $savedPaletteId,
        ?string $viewerEmail,
        bool $isOwner,
        ?string $ipAddress,
        ?string $userAgent
    ): void {
        $this->service->recordView(
            $savedPaletteId,
            $viewerEmail,
            $isOwner,
            $ipAddress,
            $userAgent
        );
    }

    /**
     * Update palette metadata.
     */
    public function updateFromPayload(int $paletteId, array $payload): array
    {
        if ($paletteId <= 0) {
            throw new InvalidArgumentException('palette_id required');
        }

        $allowed = [
            'nickname',
            'notes',
            'private_notes',
            'terry_fav',
            'kicker_id',
            'palette_type',
        ];

        $data = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $payload)) {
                if ($key === 'kicker_id') {
                    $data[$key] = ($payload[$key] === '' || $payload[$key] === null)
                        ? null
                        : (int)$payload[$key];
                } else {
                    $data[$key] = $payload[$key];
                }
            }
        }

        $members = null;
        if (array_key_exists('members', $payload)) {
            $members = $payload['members'];
            if (!is_array($members)) {
                throw new InvalidArgumentException('members must be an array');
            }
        }

        $photos = null;
        if (array_key_exists('photos', $payload)) {
            $photos = $payload['photos'];
            if (!is_array($photos)) {
                throw new InvalidArgumentException('photos must be an array');
            }
        }

        if (!$data && $members === null && $photos === null) {
            throw new InvalidArgumentException('No update fields provided');
        }

        if ($data) {
            $this->service->updateSavedPalette($paletteId, $data);
        }

        if ($members !== null) {
            $this->service->updateSavedPaletteMembers($paletteId, $members);
        }

        if ($photos !== null) {
            $this->service->updateSavedPalettePhotos($paletteId, $photos);
        }

        return $this->service->getSavedPalette($paletteId) ?? [];
    }

    public function deleteSavedPalette(int $paletteId): void
    {
        if ($paletteId <= 0) {
            throw new InvalidArgumentException('palette_id required');
        }
        $this->service->deleteSavedPalette($paletteId);
    }

    public function sendEmail(
        int $paletteId,
        string $toEmail,
        ?string $message = null,
        ?string $shareUrl = null,
        ?string $subjectOverride = null
    ): void
    {
        $this->service->sendPaletteEmail($paletteId, $toEmail, $message, $shareUrl, $subjectOverride);
    }
}
