<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoSavedPaletteRepository;
use InvalidArgumentException;
use App\Lib\SmtpMailer;
use App\Services\EmailTemplateService;

class SavedPaletteService
{
    private PdoSavedPaletteRepository $repo;
    private ?SmtpMailer $mailer;
    private EmailTemplateService $emailTemplates;

    public function __construct(
        PdoSavedPaletteRepository $repo,
        ?SmtpMailer $mailer = null,
        ?EmailTemplateService $emailTemplates = null
    ) {
        $this->repo = $repo;
        $this->mailer = $mailer;
        $this->emailTemplates = $emailTemplates ?? new EmailTemplateService();
    }

    /**
     * Create (or re-use) a saved palette from a brand + list of color ids.
     *
     * $data keys:
     *  - brand         (string, required) e.g. 'de', 'behr'
     *  - color_ids     (int[] OR [['color_id' => int, 'order_index' => int], ...], required)
     *  - nickname      (string|null)
     *  - notes         (string|null)
     *  - terry_fav     (bool|int|null)
     *
     * Returns the full palette structure: ['palette' => [...], 'members' => [...]]
     */
    public function createSavedPalette(array $data): array
    {
        $brand = trim((string)($data['brand'] ?? ''));
        if ($brand === '') {
            throw new InvalidArgumentException('brand is required');
        }

        if (empty($data['color_ids'])) {
            throw new InvalidArgumentException('At least one color_id is required');
        }

        $members = $this->normalizeMembers($data['color_ids']);

        // Compute palette_hash based on brand + ordered color_ids
        $colorIdsForHash = array_column($members, 'color_id');
        $hashInput       = $brand . ':' . implode(',', $colorIdsForHash);
        $paletteHash     = hash('sha256', $hashInput);

        // Optional: re-use an existing palette with the same hash+brand
        $existing = $this->repo->getSavedPaletteByHashAndBrand($paletteHash, $brand);
        if ($existing) {
            $full = $this->repo->getFullPalette((int)$existing['id']);
            if ($full !== null) {
                return $full;
            }
        }

        // Create a new saved palette
        $paletteId = $this->repo->createSavedPalette([
            'palette_hash'  => $paletteHash,
            'brand'         => $brand,
            'nickname'      => $data['nickname']      ?? null,
            'notes'         => $data['notes']         ?? null,
            'terry_fav'     => $data['terry_fav']     ?? 0,
        ]);

        // Attach members
        $this->repo->addMembers($paletteId, $members);

        // Return the full hydrated palette
        $full = $this->repo->getFullPalette($paletteId);
        if ($full === null) {
            throw new \RuntimeException('Failed to load saved palette after creation');
        }

        return $full;
    }

    /**
     * Overwrite an existing saved palette, including members.
     */
    public function overwriteSavedPalette(int $paletteId, array $data): array
    {
        if ($paletteId <= 0) {
            throw new InvalidArgumentException('palette_id required');
        }

        $brand = trim((string)($data['brand'] ?? ''));
        if ($brand === '') {
            throw new InvalidArgumentException('brand is required');
        }

        if (empty($data['color_ids'])) {
            throw new InvalidArgumentException('At least one color_id is required');
        }

        $existing = $this->repo->getSavedPaletteById($paletteId);
        if (!$existing) {
            throw new InvalidArgumentException('Saved palette not found');
        }

        $members = $this->normalizeMembers($data['color_ids']);
        $colorIdsForHash = array_column($members, 'color_id');
        $hashInput       = $brand . ':' . implode(',', $colorIdsForHash);
        $paletteHash     = hash('sha256', $hashInput);

        $this->repo->updateSavedPalette($paletteId, [
            'palette_hash'  => $paletteHash,
            'brand'         => $brand,
            'nickname'      => $data['nickname']      ?? null,
            'notes'         => $data['notes']         ?? null,
            'terry_fav'     => $data['terry_fav']     ?? 0,
        ]);

        $this->repo->replaceMembers($paletteId, $members);

        $full = $this->repo->getFullPalette($paletteId);
        if ($full === null) {
            throw new \RuntimeException('Failed to load saved palette after overwrite');
        }

        return $full;
    }

    public function deleteSavedPalette(int $savedPaletteId): void
    {
        if ($savedPaletteId <= 0) {
            throw new InvalidArgumentException('saved_palette_id required');
        }
        $photos = $this->repo->getPhotosForPalette($savedPaletteId);
        $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__ . '/../../..'), '/');
        foreach ($photos as $photo) {
            $rel = (string)($photo['rel_path'] ?? '');
            if ($rel === '' || !str_starts_with($rel, '/photos/')) {
                continue;
            }
            $abs = $docRoot . $rel;
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
        $this->repo->deletePhotosForPalette($savedPaletteId);
        $this->repo->deleteMembersForPalette($savedPaletteId);
        $this->repo->deleteViewsForPalette($savedPaletteId);
        $this->repo->deleteSavedPalette($savedPaletteId);
    }

    /**
     * Replace palette members (colors + roles) and update palette_hash.
     */
    public function updateSavedPaletteMembers(int $paletteId, array $members): array
    {
        if ($paletteId <= 0) {
            throw new InvalidArgumentException('palette_id required');
        }

        if (empty($members)) {
            throw new InvalidArgumentException('members must be a non-empty array');
        }

        $existing = $this->repo->getSavedPaletteById($paletteId);
        if (!$existing) {
            throw new InvalidArgumentException('Saved palette not found');
        }

        $normalized = $this->normalizeMembers($members);
        $colorIdsForHash = array_column($normalized, 'color_id');
        $brand = trim((string)($existing['brand'] ?? ''));
        if ($brand === '') {
            throw new InvalidArgumentException('brand is required');
        }

        $hashInput   = $brand . ':' . implode(',', $colorIdsForHash);
        $paletteHash = hash('sha256', $hashInput);

        $this->repo->updateSavedPalette($paletteId, [
            'palette_hash' => $paletteHash,
        ]);

        $this->repo->replaceMembers($paletteId, $normalized);

        $full = $this->repo->getFullPalette($paletteId);
        if ($full === null) {
            throw new \RuntimeException('Failed to load saved palette after update');
        }

        return $full;
    }

    /**
     * Update photo metadata for a palette.
     *
     * $photos: array of ['id' => int, 'photo_type' => string, 'trigger_color_id' => ?int, 'caption' => ?string]
     */
    public function updateSavedPalettePhotos(int $paletteId, array $photos): void
    {
        if ($paletteId <= 0) {
            throw new InvalidArgumentException('palette_id required');
        }

        if (empty($photos)) {
            return;
        }

        $existing = $this->repo->getSavedPaletteById($paletteId);
        if (!$existing) {
            throw new InvalidArgumentException('Saved palette not found');
        }

        foreach ($photos as $photo) {
            if (!is_array($photo)) {
                continue;
            }
            $photoId = isset($photo['id']) ? (int)$photo['id'] : 0;
            if ($photoId <= 0) {
                continue;
            }

            $photoType = isset($photo['photo_type']) ? trim((string)$photo['photo_type']) : '';
            if (!in_array($photoType, ['full', 'zoom'], true)) {
                $photoType = 'full';
            }

            $triggerId = null;
            if (array_key_exists('trigger_color_id', $photo)) {
                $triggerId = (int)$photo['trigger_color_id'];
                if ($triggerId <= 0) {
                    $triggerId = null;
                }
            }

            $caption = null;
            if (array_key_exists('caption', $photo)) {
                $cap = trim((string)$photo['caption']);
                $caption = $cap === '' ? null : $cap;
            }

            $this->repo->updatePhoto($photoId, $paletteId, [
                'photo_type' => $photoType,
                'trigger_color_id' => $triggerId,
                'caption' => $caption,
            ]);
        }
    }
    /**
     * Normalize color_ids into the shape expected by addMembers():
     *  - always an array of ['color_id' => int, 'order_index' => int, 'role' => ?string]
     */
    private function normalizeMembers(array $colorIds): array
    {
        $normalized = [];

        // Case 1: simple list [12, 34, 56]
        if (isset($colorIds[0]) && !is_array($colorIds[0])) {
            $order = 0;
            foreach ($colorIds as $cid) {
                if ($cid === null || $cid === '') {
                    continue;
                }
                if (!is_numeric($cid)) {
                    continue;
                }
            $normalized[] = [
                'color_id'    => (int)$cid,
                'order_index' => $order++,
                'role'        => null,
            ];
        }
            if (!empty($normalized)) {
                return $normalized;
            }
        }

        // Case 2: list of associative arrays
        $order = 0;
        foreach ($colorIds as $item) {
            if (!is_array($item) || !isset($item['color_id'])) {
                continue;
            }
            if (!is_numeric($item['color_id'])) {
                continue;
            }
            $role = null;
            if (array_key_exists('role', $item)) {
                $role = is_string($item['role']) ? trim($item['role']) : null;
                if ($role === '') {
                    $role = null;
                }
            }
            $normalized[] = [
                'color_id'    => (int)$item['color_id'],
                'order_index' => isset($item['order_index']) ? (int)$item['order_index'] : $order,
                'role'        => $role,
            ];
            $order++;
        }

        if (empty($normalized)) {
            throw new InvalidArgumentException('No valid color_ids provided for members');
        }

        return $normalized;
    }

    /**
     * Get a saved palette (without stats).
     *
     * Returns null if not found.
     */
    public function getSavedPalette(int $id): ?array
    {
        return $this->repo->getFullPalette($id);
    }

    /**
     * Get a saved palette plus view stats.
     *
     * Returns null if not found.
     *
     * Shape:
     * [
     *   'palette' => [...],
     *   'members' => [...],
     *   'view_stats' => [
     *      'total_views'        => int,
     *      'total_client_views' => int,
     *      'first_view'         => ?string,
     *      'last_view'          => ?string,
     *   ],
     * ]
     */
    public function getSavedPaletteWithStats(int $id): ?array
    {
        $full = $this->repo->getFullPalette($id);
        if ($full === null) {
            return null;
        }

        $stats = $this->repo->getViewStats($id);

        $full['view_stats'] = $stats;
        return $full;
    }

    /**
     * List saved palettes, optionally filtered by brand and/or Terry favorite.
     *
     * $filters:
     *   - brand (string)
     *   - terry_fav (bool|int)
     */
    public function listSavedPalettes(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->repo->listPalettes($filters, $limit, $offset);
    }

    /**
     * Toggle favorite flag for a palette.
     */
    public function setFavorite(int $id, bool $fav): void
    {
        $this->repo->setFavorite($id, $fav);
    }

    public function sendPaletteEmail(
        int $paletteId,
        string $toEmail,
        ?string $message = null,
        ?string $shareUrl = null,
        ?string $subjectOverride = null
    ): void
    {
        if ($paletteId <= 0) {
            throw new InvalidArgumentException('palette_id required');
        }
        $toEmail = trim($toEmail);
        if ($toEmail === '') {
            throw new InvalidArgumentException('Recipient email required');
        }
        if (!$this->mailer) {
            throw new \RuntimeException('SMTP mailer not configured');
        }

        $full = $this->repo->getFullPalette($paletteId);
        if ($full === null) {
            throw new InvalidArgumentException('saved palette not found');
        }

        $palette = $full['palette'];
        $members = $full['members'] ?? [];

        if (!$shareUrl) {
            $shareUrl = sprintf('https://colorfix.terrymarr.com/palette/%s/share', $palette['palette_hash'] ?? $paletteId);
        }

        [$subject, $html, $text] = $this->emailTemplates->renderPaletteEmail(
            $palette,
            $members,
            $shareUrl,
            $message,
            $subjectOverride
        );

        $this->mailer->send($toEmail, $subject, $html, $text);

    }

    /**
     * Record a view of a saved palette.
     *
     * $viewerEmail may be null (anonymous).
     * $isOwner should be TRUE when Terry/admin is viewing,
     * so you can ignore those in client view stats.
     */
    public function recordView(
        int $savedPaletteId,
        ?string $viewerEmail,
        bool $isOwner,
        ?string $ipAddress,
        ?string $userAgent
    ): void {
        if (!$this->repo->paletteExists($savedPaletteId)) {
            throw new InvalidArgumentException('saved palette not found');
        }

        $this->repo->recordView(
            $savedPaletteId,
            $viewerEmail !== null ? trim($viewerEmail) : null,
            $isOwner,
            $ipAddress !== null ? trim($ipAddress) : null,
            $userAgent !== null ? trim($userAgent) : null
        );
    }

    /**
     * Update metadata for an existing saved palette.
     */
    public function updateSavedPalette(int $paletteId, array $data): array
    {
        if ($paletteId <= 0) {
            throw new InvalidArgumentException('palette_id required');
        }

        $existing = $this->repo->getSavedPaletteById($paletteId);
        if (!$existing) {
            throw new InvalidArgumentException('saved palette not found');
        }

        $fields = [];

        if (array_key_exists('nickname', $data)) {
            $nickname = trim((string)$data['nickname']);
            $fields['nickname'] = $nickname === '' ? null : $nickname;
        }

        if (array_key_exists('notes', $data)) {
            $notes = trim((string)$data['notes']);
            $fields['notes'] = $notes === '' ? null : $notes;
        }

        if (array_key_exists('terry_fav', $data)) {
            $fields['terry_fav'] = (int) (bool) $data['terry_fav'];
        }

        if (!empty($fields)) {
            $this->repo->updateSavedPalette($paletteId, $fields);
        }

        $full = $this->repo->getFullPalette($paletteId);
        if ($full === null) {
            throw new \RuntimeException('Failed to load saved palette after update');
        }

        return $full;
    }
}
