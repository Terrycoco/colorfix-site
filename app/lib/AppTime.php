<?php
declare(strict_types=1);

namespace App\Lib;

use DateTimeImmutable;
use DateTimeZone;

final class AppTime
{
    private const APP_TIMEZONE = 'America/Los_Angeles';

    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone(self::APP_TIMEZONE);
    }

    public static function now(): string
    {
        return (new DateTimeImmutable('now', self::timezone()))
            ->format('Y-m-d H:i:s');
    }

    public static function normalizeDateTimeString(string $value): ?string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        try {
            if (preg_match('/(?:[zZ]|[+-]\d{2}:\d{2})$/', $normalized) === 1) {
                $parsed = new DateTimeImmutable($normalized);
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}$/', $normalized) === 1) {
                $parsed = new DateTimeImmutable(str_replace(' ', 'T', $normalized), self::timezone());
            } else {
                $parsed = new DateTimeImmutable($normalized, self::timezone());
            }
        } catch (\Throwable) {
            return $normalized;
        }

        return $parsed
            ->setTimezone(self::timezone())
            ->format('Y-m-d H:i:s');
    }
}
