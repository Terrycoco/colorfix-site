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
     *  - sent_to_email (string|null)
     *  - sent_at       (string|null, 'Y-m-d H:i:s')
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
            'terry_fav'     => isset($payload['terry_fav']) ? (bool)$payload['terry_fav'] : false,
            'sent_to_email' => isset($payload['sent_to_email']) ? trim((string)$payload['sent_to_email']) : null,
            'sent_at'       => isset($payload['sent_at']) ? (string)$payload['sent_at'] : null,
            'client_id'     => isset($payload['client_id']) ? (int)$payload['client_id'] : null,
            'client_name'   => isset($payload['client_name']) ? (string)$payload['client_name'] : null,
            'client_email'  => isset($payload['client_email']) ? (string)$payload['client_email'] : null,
            'client_phone'  => isset($payload['client_phone']) ? (string)$payload['client_phone'] : null,
            'client_notes'  => isset($payload['client_notes']) ? (string)$payload['client_notes'] : null,
        ];

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
     * Convenience: find all palettes that have been sent to a given email.
     */
    public function findByEmail(string $email): array
    {
        $email = trim($email);
        if ($email === '') {
            return [];
        }

        return $this->service->findPalettesByEmail($email);
    }

    /**
     * Update palette metadata/client fields.
     */
    public function updateFromPayload(int $paletteId, array $payload): array
    {
        if ($paletteId <= 0) {
            throw new InvalidArgumentException('palette_id required');
        }

        $allowed = [
            'nickname',
            'notes',
            'terry_fav',
            'sent_to_email',
            'sent_at',
            'client_id',
            'client_name',
            'client_email',
            'client_phone',
            'client_notes',
        ];

        $data = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $payload)) {
                $data[$key] = $payload[$key];
            }
        }

        if (!$data) {
            throw new InvalidArgumentException('No update fields provided');
        }

        return $this->service->updateSavedPalette($paletteId, $data);
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
        ?string $clientNameOverride = null,
        ?string $subjectOverride = null
    ): void
    {
        $this->service->sendPaletteEmail($paletteId, $toEmail, $message, $shareUrl, $clientNameOverride, $subjectOverride);
    }
}
