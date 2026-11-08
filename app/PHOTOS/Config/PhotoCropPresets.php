<?php
declare(strict_types=1);

namespace App\PHOTOS\Config;

final class PhotoCropPresets
{
    public const DEFAULT_TYPE = 'yt_landscape';

    public const PRESETS = [
        'swatch' => [
            'label' => 'Gallery Swatch',
            'width' => 5,
            'height' => 4,
            'default' => 'center',
        ],
        'yt_landscape' => [
            'label' => 'YouTube Landscape',
            'width' => 16,
            'height' => 9,
            'default' => 'center',
        ],
        'yt_portrait' => [
            'label' => 'YouTube Portrait',
            'width' => 9,
            'height' => 16,
            'default' => 'center',
        ],
        'square' => [
            'label' => 'Square',
            'width' => 1,
            'height' => 1,
            'default' => 'center',
        ],
        'pinterest' => [
            'label' => 'Pinterest',
            'width' => 2,
            'height' => 3,
            'default' => 'center',
        ],
    ];
}