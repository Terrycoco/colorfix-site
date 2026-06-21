<?php
declare(strict_types=1);

namespace App\Services;

final class PinterestBoardConfig
{
    public const BOARD_NAME = 'ColorFix Makeovers';
    public const BOARD_URL = 'https://www.pinterest.com/terrymarr/colorfix-makeovers/';
    public const BOARD_SLUG = 'terrymarr/colorfix-makeovers';
    public const BOARD_ID = null;

    public static function defaultBoard(): array
    {
        return [
            'board_name' => self::BOARD_NAME,
            'board_url' => self::BOARD_URL,
            'board_slug' => self::BOARD_SLUG,
            'board_id' => self::BOARD_ID,
        ];
    }
}
