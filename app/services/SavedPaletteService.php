<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoSavedPaletteRepository;
use App\Repos\PdoClientRepository;
use InvalidArgumentException;
use App\Lib\SmtpMailer;
use App\Services\EmailTemplateService;

class SavedPaletteService
{
    private PdoSavedPaletteRepository $repo;
    private ?PdoClientRepository $clientRepo;
    private ?SmtpMailer $mailer;
    private EmailTemplateService $emailTemplates;

    public function __construct(
        PdoSavedPaletteRepository $repo,
        ?PdoClientRepository $clientRepo = null,
        ?SmtpMailer $mailer = null,
        ?EmailTemplateService $emailTemplates = null
    ) {
        $this->repo = $repo;
        $this->clientRepo = $clientRepo;
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
     *  - sent_to_email (string|null)
     *  - sent_at       (string|null 'Y-m-d H:i:s')
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
        $clientId = $this->resolveClientId($data);

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
            'client_id'     => $clientId,
            'nickname'      => $data['nickname']      ?? null,
            'notes'         => $data['notes']         ?? null,
            'terry_fav'     => $data['terry_fav']     ?? 0,
            'sent_to_email' => $data['sent_to_email'] ?? null,
            'sent_at'       => $data['sent_at']       ?? null,
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
    private function resolveClientId(array $data): ?int
    {
        $email = trim((string)($data['client_email'] ?? ''));
        $name  = trim((string)($data['client_name'] ?? ''));
        $phone = trim((string)($data['client_phone'] ?? ''));
        $notes = trim((string)($data['client_notes'] ?? ''));
        $explicitId = isset($data['client_id']) ? (int)$data['client_id'] : null;

        if ($explicitId) {
            if ($this->clientRepo) {
                $existing = $this->clientRepo->findById($explicitId);
                if ($existing) {
                    $updates = [];
                    if ($name !== '' && $existing['name'] !== $name) {
                        $updates['name'] = $name;
                    }
                    if ($email !== '' && ($existing['email'] ?? '') !== $email) {
                        $updates['email'] = $email;
                    }
                    if ($phone !== '' && ($existing['phone'] ?? '') !== $phone) {
                        $updates['phone'] = $phone;
                    }
                    if ($notes !== '' && ($existing['notes'] ?? '') !== $notes) {
                        $updates['notes'] = $notes;
                    }
                    if ($updates) {
                        $this->clientRepo->update($explicitId, $updates);
                    }
                }
            }
            return $explicitId;
        }

        if (!$this->clientRepo) {
            return null;
        }

        if ($email === '' && $name === '' && $phone === '') {
            return null;
        }

        $existing = $email !== '' ? $this->clientRepo->findByEmail($email) : null;
        if ($existing) {
            $updates = [];
            if ($name !== '' && $existing['name'] !== $name) {
                $updates['name'] = $name;
            }
            if ($phone !== '' && ($existing['phone'] ?? '') !== $phone) {
                $updates['phone'] = $phone;
            }
            if ($notes !== '' && ($existing['notes'] ?? '') !== $notes) {
                $updates['notes'] = $notes;
            }
            if ($updates) {
                $this->clientRepo->update((int)$existing['id'], $updates);
            }
            return (int)$existing['id'];
        }

        if ($email === '' && $name === '') {
            return null;
        }

        return $this->clientRepo->create([
            'name'  => $name !== '' ? $name : ($email ?: 'Client'),
            'email' => $email !== '' ? $email : sprintf('unknown-%s@invalid.local', uniqid()),
            'phone' => $phone !== '' ? $phone : null,
            'notes' => $notes !== '' ? $notes : null,
        ]);
    }

    /**
     * Normalize color_ids into the shape expected by addMembers():
     *  - always an array of ['color_id' => int, 'order_index' => int]
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
            $normalized[] = [
                'color_id'    => (int)$item['color_id'],
                'order_index' => isset($item['order_index']) ? (int)$item['order_index'] : $order,
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
        ?string $clientNameOverride = null,
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

        if ($clientNameOverride !== null && $clientNameOverride !== '') {
            $palette['client_name'] = $clientNameOverride;
        } elseif ($clientName !== '' && empty($palette['client_name'])) {
            $palette['client_name'] = $clientName;
        }

        [$subject, $html, $text] = $this->emailTemplates->renderPaletteEmail(
            $palette,
            $members,
            $shareUrl,
            $message,
            $subjectOverride
        );

        $this->mailer->send($toEmail, $subject, $html, $text);

        $this->repo->updateSavedPalette($paletteId, [
            'sent_to_email' => $toEmail,
            'sent_at'       => date('Y-m-d H:i:s'),
        ]);
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
     * Convenience: find all palettes that were sent to a particular email.
     */
    public function findPalettesByEmail(string $email): array
    {
        $email = trim($email);
        if ($email === '') {
            return [];
        }

        return $this->repo->findPalettesByEmail($email);
    }

    /**
     * Update metadata/client info for an existing saved palette.
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

        if (array_key_exists('sent_to_email', $data)) {
            $email = trim((string)$data['sent_to_email']);
            $fields['sent_to_email'] = $email === '' ? null : $email;
        }

        if (array_key_exists('sent_at', $data)) {
            $sentAt = trim((string)$data['sent_at']);
            $fields['sent_at'] = $sentAt === '' ? null : $sentAt;
        }

        if (array_key_exists('terry_fav', $data)) {
            $fields['terry_fav'] = (int) (bool) $data['terry_fav'];
        }

        $clientKeys = ['client_id', 'client_name', 'client_email', 'client_phone', 'client_notes'];
        $shouldUpdateClient = false;
        foreach ($clientKeys as $ck) {
            if (array_key_exists($ck, $data)) {
                $shouldUpdateClient = true;
                break;
            }
        }
        if ($shouldUpdateClient) {
            $fields['client_id'] = $this->resolveClientId($data);
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
