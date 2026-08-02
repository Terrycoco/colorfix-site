<?php
declare(strict_types=1);

namespace App\Services;

use App\Lib\EnvLoader;
use InvalidArgumentException;
use RuntimeException;

final class PaletteViewerTokenService
{
    private const CIPHER = 'aes-256-gcm';

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

        return '/pv/' . rawurlencode($this->encode($payload));
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

        if (($payload['source'] ?? '') !== 'saved') {
            throw new InvalidArgumentException('invalid palette viewer token source');
        }
        if (trim((string)($payload['hash'] ?? '')) === '') {
            throw new InvalidArgumentException('invalid palette viewer token palette');
        }
        $payload['palette_viewer_key'] = $this->normalizePaletteViewerKey(
            (string)($payload['palette_viewer_key'] ?? 'full_palette')
        );

        return $payload;
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
        return in_array($value, ['full_palette', 'concept', 'none'], true) ? $value : 'full_palette';
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
