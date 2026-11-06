<?php
declare(strict_types=1);

namespace App\SYSTEM\Services;

use RuntimeException;

/** Bounded login attempts per source IP; stored alongside private session state. */
final class AdminLoginThrottle
{
    public function attempt(string $address): bool
    {
        $path = session_save_path() . '/login-' . hash('sha256', $address) . '.json';
        $file = fopen($path, 'c+');
        if (!$file || !flock($file, LOCK_EX)) throw new RuntimeException('Login temporarily unavailable.');
        try {
            $state = json_decode(stream_get_contents($file) ?: '{}', true) ?: [];
            if (($state['until'] ?? 0) <= time()) $state = ['until' => time() + 900, 'attempts' => 0];
            $allowed = $state['attempts'] < 10;
            $state['attempts']++;
            rewind($file);
            ftruncate($file, 0);
            fwrite($file, json_encode($state));
            return $allowed;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}
