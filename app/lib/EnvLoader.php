<?php
declare(strict_types=1);

namespace App\Lib;

final class EnvLoader
{
    private static array $cache = [];

    public static function get(string $key, ?string $default = null): ?string
    {
        $existing = getenv($key);
        if ($existing !== false && $existing !== '') {
            return $existing;
        }

        self::loadProjectEnv();
        return self::$cache[$key] ?? $default;
    }

    private static function loadProjectEnv(): void
    {
        if (self::$cache) {
            return;
        }

        $root = dirname(__DIR__, 2);
        foreach ([$root . '/.env.local', $root . '/.env'] as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                if (
                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))
                ) {
                    $value = substr($value, 1, -1);
                }
                if ($name !== '') {
                    self::$cache[$name] = $value;
                }
            }
        }
    }
}
