<?php
declare(strict_types=1);

namespace App\Services;

use App\Repos\PdoViewerRepository;
use PDO;

class UserService
{
    private PdoViewerRepository $viewerRepo;

    public function __construct(PDO $pdo)
    {
        $this->viewerRepo = new PdoViewerRepository($pdo);
    }

    /**
     * Ensure we have a stable anonymous viewer_id.
     * - Check cookie/header
     * - If missing, generate UUID v4
     * - Upsert viewer record (created_at/last_seen_at/visit_count)
     * - Set cookie for 1 year
     */
    public function ensureViewerId(): string
    {
        $viewerId = $this->getIncomingViewerId();
        if (!$this->isValidUuid($viewerId)) {
            $viewerId = $this->generateUuidV4();
        }

        $this->viewerRepo->upsertViewer($viewerId);
        $this->setViewerCookie($viewerId);

        return $viewerId;
    }

    private function getIncomingViewerId(): ?string
    {
        if (!empty($_COOKIE['viewer_id'])) {
            return (string)$_COOKIE['viewer_id'];
        }
        $header = $_SERVER['HTTP_X_VIEWER_ID'] ?? null;
        return $header ? (string)$header : null;
    }

    private function setViewerCookie(string $viewerId): void
    {
        // 1 year
        $expires = time() + 365 * 24 * 60 * 60;
        // Best-effort cookie; ignore if headers already sent
        if (!headers_sent()) {
            setcookie('viewer_id', $viewerId, [
                'expires' => $expires,
                'path' => '/',
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                'httponly' => false, // can be read by client if needed
                'samesite' => 'Lax',
            ]);
        }
    }

    private function generateUuidV4(): string
    {
        $data = random_bytes(16);
        // Set version to 0100
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        $hex = bin2hex($data);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20)
        );
    }

    private function isValidUuid(?string $uuid): bool
    {
        if (!$uuid) return false;
        return (bool)preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/', $uuid);
    }
}
