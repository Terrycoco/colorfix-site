<?php
declare(strict_types=1);

namespace App\Lib;

use InvalidArgumentException;

final class UrlNormalizer
{
    private const DEFAULT_BASE_URL = 'https://colorfix.terrymarr.com';

    public static function baseUrl(?string $baseUrl = null): string
    {
        $baseUrl = trim((string)($baseUrl ?? EnvLoader::get('COLORFIX_BASE_URL', self::DEFAULT_BASE_URL)));
        if ($baseUrl === '') {
            $baseUrl = self::DEFAULT_BASE_URL;
        }
        return rtrim($baseUrl, '/');
    }

    public static function absolute(string $url, ?string $baseUrl = null): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new InvalidArgumentException('URL cannot be empty.');
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (!str_starts_with($url, '/')) {
            $url = '/' . $url;
        }
        return self::baseUrl($baseUrl) . $url;
    }

    public static function absoluteOrEmpty(?string $url, ?string $baseUrl = null): string
    {
        $url = trim((string)$url);
        return $url === '' ? '' : self::absolute($url, $baseUrl);
    }

    public static function absoluteOrNull(mixed $url, ?string $baseUrl = null): ?string
    {
        $url = trim((string)($url ?? ''));
        return $url === '' ? null : self::absolute($url, $baseUrl);
    }

    public static function appendQueryParam(string $url, string $key, string $value): string
    {
        if ($key === '') {
            return $url;
        }
        $parts = parse_url($url);
        $query = [];
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
        }
        if (array_key_exists($key, $query)) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . rawurlencode($key) . '=' . rawurlencode($value);
    }
}
