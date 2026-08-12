<?php
declare(strict_types=1);

namespace App\Services;

use App\Lib\EnvLoader;
use InvalidArgumentException;
use PDO;
use RuntimeException;

final class PaletteViewerTokenService
{
    private const CIPHER = 'aes-256-gcm';
    private const SHORT_CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public function __construct(
        private ?PDO $pdo = null
    ) {}

    public function createSavedPaletteUrl(
        string $paletteHash,
        ?int $setId,
        string $paletteViewerKey,
        ?int $playlistInstanceId = null
    ): string {
        $paletteHash = trim($paletteHash);
        if ($paletteHash === '') {
            throw new InvalidArgumentException('palette hash required');
        }

        $payload = [
            'v' => 1,
            'source' => 'saved',
            'hash' => $paletteHash,
            'set_id' => $setId && $setId > 0 ? $setId : null,
            'palette_viewer_key' => $this->normalizePaletteViewerKey($paletteViewerKey),
            'playlist_instance_id' => $playlistInstanceId && $playlistInstanceId > 0 ? $playlistInstanceId : null,
            'iat' => time(),
        ];

        return $this->createUrlFromPayload($payload);
    }

    public function createProjectColorPlanUrl(
        int $colorPlanId,
        string $paletteViewerKey,
        ?string $reservationToken = null
    ): string {
        if ($colorPlanId <= 0) {
            throw new InvalidArgumentException('project color plan id required');
        }

        $reservationToken = trim((string)$reservationToken);
        $payload = [
            'v' => 1,
            'source' => 'project_color_plan',
            'color_plan_id' => $colorPlanId,
            'palette_viewer_key' => $this->normalizePaletteViewerKey($paletteViewerKey),
            'reservation_token' => $reservationToken !== '' ? $reservationToken : null,
            'iat' => time(),
        ];

        return $this->createUrlFromPayload($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new InvalidArgumentException('palette viewer token required');
        }
        $token = $this->resolveShortCode($token) ?: $token;

        $envelopeJson = $this->base64UrlDecode($token);
        $envelope = json_decode($envelopeJson, true);
        if (!is_array($envelope)) {
            throw new InvalidArgumentException('invalid palette viewer token');
        }

        foreach (['c', 'n', 't'] as $key) {
            if (empty($envelope[$key]) || !is_string($envelope[$key])) {
                throw new InvalidArgumentException('invalid palette viewer token');
            }
        }

        $cipher = $this->base64UrlDecode($envelope['c']);
        $nonce = $this->base64UrlDecode($envelope['n']);
        $tag = $this->base64UrlDecode($envelope['t']);
        $plain = openssl_decrypt($cipher, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plain === false) {
            throw new InvalidArgumentException('invalid palette viewer token');
        }
        $payload = json_decode($plain, true);
        if (!is_array($payload)) {
            throw new InvalidArgumentException('invalid palette viewer token payload');
        }

        $source = trim((string)($payload['source'] ?? ''));
        if (!in_array($source, ['saved', 'project_color_plan'], true)) {
            throw new InvalidArgumentException('invalid palette viewer token source');
        }

        if ($source === 'saved') {
            if (trim((string)($payload['hash'] ?? '')) === '') {
                throw new InvalidArgumentException('invalid palette viewer token palette');
            }
        } else {
            if ((int)($payload['color_plan_id'] ?? 0) <= 0) {
                throw new InvalidArgumentException('invalid project color plan token');
            }
        }

        $payload['palette_viewer_key'] = $this->normalizePaletteViewerKey(
            (string)($payload['palette_viewer_key'] ?? 'full_palette')
        );

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createUrlFromPayload(array $payload): string
    {
        $token = $this->encode($payload);
        $shortCode = $this->createShortCode($token, $payload);
        return '/pv/' . rawurlencode($shortCode ?: $token);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $plain = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($plain === false) {
            throw new RuntimeException('Unable to encode palette viewer token payload.');
        }
        $cipher = openssl_encrypt($plain, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false || $tag === '') {
            throw new RuntimeException('Unable to encrypt palette viewer token.');
        }
        $envelope = [
            'c' => $this->base64UrlEncode($cipher),
            'n' => $this->base64UrlEncode($nonce),
            't' => $this->base64UrlEncode($tag),
            'a' => self::CIPHER,
        ];

        $json = json_encode($envelope, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Unable to encode palette viewer token.');
        }
        return $this->base64UrlEncode($json);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createShortCode(string $token, array $payload): ?string
    {
        if (!$this->pdo instanceof PDO) {
            return null;
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = $this->randomShortCode();
            try {
                $stmt = $this->pdo->prepare(
                    'INSERT INTO palette_viewer_links (code, token, payload_json) VALUES (:code, :token, :payload_json)'
                );
                $stmt->execute([
                    ':code' => $code,
                    ':token' => $token,
                    ':payload_json' => $payloadJson !== false ? $payloadJson : null,
                ]);
                return $code;
            } catch (\PDOException $e) {
                $message = $e->getMessage();
                if (str_contains($message, 'Duplicate entry') || (string)$e->getCode() === '23000') {
                    continue;
                }
                return null;
            }
        }

        return null;
    }

    private function resolveShortCode(string $tokenOrCode): ?string
    {
        if (!$this->pdo instanceof PDO) {
            return null;
        }
        if (!preg_match('/^[A-Za-z0-9]{6,16}$/', $tokenOrCode)) {
            return null;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT token FROM palette_viewer_links WHERE code = ? LIMIT 1');
            $stmt->execute([$tokenOrCode]);
            $token = $stmt->fetchColumn();
            if (!is_string($token) || trim($token) === '') {
                return null;
            }
            $touch = $this->pdo->prepare('UPDATE palette_viewer_links SET last_accessed_at = NOW() WHERE code = ?');
            $touch->execute([$tokenOrCode]);
            return $token;
        } catch (\PDOException) {
            return null;
        }
    }

    private function randomShortCode(int $length = 8): string
    {
        $alphabet = self::SHORT_CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }
        return $code;
    }

    private function key(): string
    {
        $value = EnvLoader::get('PALETTE_VIEWER_TOKEN_SECRET')
            ?? EnvLoader::get('PUBLISHING_AUTH_ENCRYPTION_KEY')
            ?? EnvLoader::get('APP_KEY')
            ?? 'colorfix-palette-viewer-token-v1';

        if (str_starts_with($value, 'base64:')) {
            $decoded = base64_decode(substr($value, 7), true);
            if ($decoded !== false) {
                $value = $decoded;
            }
        }

        return hash('sha256', $value, true);
    }

    private function normalizePaletteViewerKey(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['full_palette', 'concept', 'client', 'painter', 'none'], true) ? $value : 'full_palette';
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $raw = strtr($value, '-_', '+/');
        $pad = strlen($raw) % 4;
        if ($pad > 0) {
            $raw .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($raw, true);
        if ($decoded === false) {
            throw new InvalidArgumentException('invalid palette viewer token encoding');
        }
        return $decoded;
    }
}