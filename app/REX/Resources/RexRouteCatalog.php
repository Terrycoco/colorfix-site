<?php
declare(strict_types=1);

namespace App\REX\Resources;

final class RexRouteCatalog
{
    public const COLORFIX_HOME = 1;
    public const DIGITAL_BUSINESS_CARD = 2;

    private const ROUTES = [
        self::COLORFIX_HOME => [
            'title' => 'ColorFix Home',
            'path' => '/',
        ],
        self::DIGITAL_BUSINESS_CARD => [
            'title' => 'Digital Business Card',
            'path' => '/card',
        ],
    ];

    public static function get(int $resourceId): ?array
    {
        return self::ROUTES[$resourceId] ?? null;
    }

    public static function all(): array
    {
        return self::ROUTES;
    }
}
