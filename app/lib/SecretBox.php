<?php
declare(strict_types=1);

namespace App\Lib;

use RuntimeException;

final class SecretBox
{
    private const CIPHER = 'aes-256-gcm';
    private const KEY_REF = 'PUBLISHING_AUTH_ENCRYPTION_KEY';

    public function encryptJson(array $payload): array
    {
        $key = $this->key();
        $nonce = random_bytes(12);
        $tag = '';
        $plain = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($plain === false) {
            throw new RuntimeException('Unable to encode secret payload.');
        }

        $cipher = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false || $tag === '') {
            throw new RuntimeException('Unable to encrypt secret payload.');
        }

        return [
            'encrypted_payload' => $cipher,
            'nonce' => $nonce,
            'tag' => $tag,
            'key_ref' => self::KEY_REF,
            'algorithm' => self::CIPHER,
        ];
    }

    public function decryptJson(?string $cipher, ?string $nonce, ?string $tag): array
    {
        if ($cipher === null || $cipher === '' || $nonce === null || $nonce === '' || $tag === null || $tag === '') {
            return [];
        }

        $plain = openssl_decrypt($cipher, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plain === false) {
            throw new RuntimeException('Unable to decrypt secret payload.');
        }

        $decoded = json_decode($plain, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function key(): string
    {
        $value = EnvLoader::get(self::KEY_REF);
        if (!$value) {
            throw new RuntimeException(self::KEY_REF . ' is required before storing publisher auth tokens.');
        }

        if (str_starts_with($value, 'base64:')) {
            $decoded = base64_decode(substr($value, 7), true);
            if ($decoded === false) {
                throw new RuntimeException(self::KEY_REF . ' is not valid base64.');
            }
            $value = $decoded;
        }

        return hash('sha256', $value, true);
    }
}
